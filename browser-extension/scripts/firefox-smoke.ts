/**
 * Loads `.output/firefox-mv2` into headless Firefox over geckodriver, requires its background to be running and to
 * answer a request from the extension's own popup page (spec `clients-browser-builds` § Firefox starts the background, task 2.1 of `clients-extension-firefox-and-safari-builds`).
 * Playwright cannot load a WebExtension into Firefox, so this speaks WebDriver classic to geckodriver directly. WebDriver
 * may not navigate to a `moz-extension://` page, so the chrome context opens the popup in a tab of its own and asks from it.
 * Expects `geckodriver --port 4444 --allow-system-access` running; `npm run test:firefox-smoke`.
 */
import { randomUUID } from 'node:crypto'
import { fileURLToPath, pathToFileURL } from 'node:url'
import { command, type DriverOptions, endSession, newSession, sleep, step as driverStep, waitUntilUp } from './webdriver.ts'

/** The id in `browser_specific_settings.gecko`, which the uuid pref maps to a fixed `moz-extension://` host. */
export const GECKO_ID = 'keepiq@conduction.nl'

/** `upMs` also bounds the wait for the background to answer. */
export interface FirefoxSmokeOptions extends DriverOptions {
	extensionPath: string
	/** The `moz-extension://` host the extension gets, so the test knows its popup's address. */
	uuid: string
}

const step = <T>(name: string, run: () => Promise<T>) => driverStep('Firefox', name, run)

/** What the popup page's script hands back: the background's reply, or why the message went unanswered. */
export type BackgroundAnswer = { ok: true; reply: unknown } | { ok: false; error: string }

/**
 * Headless Firefox with the extension's `moz-extension://` host fixed to `uuid`.
 *
 * @spec openspec/changes/clients-extension-firefox-and-safari-builds/tasks.md#task-2.1
 */
export function sessionCapabilities(uuid: string): object {
	return {
		capabilities: {
			alwaysMatch: {
				browserName: 'firefox',
				'moz:firefoxOptions': {
					// geckodriver runs with `--allow-system-access`, so the chrome context can read the background's state and open the popup page.
					args: ['-headless'],
					prefs: {
						'extensions.webextensions.uuids': JSON.stringify({ [GECKO_ID]: uuid }),
						// In process, the chrome context reaches the popup page; WebDriver's content context never follows a tab into the extension process.
						'extensions.webextensions.remote': false,
					},
				},
			},
		},
	}
}

/**
 * Chrome context: the selected tab's popup page sends one request the background answers (`settings.get`, read-only),
 * and hands back its reply. Xray wrappers hide the page's own `browser`, so it is read through `wrappedJSObject`.
 *
 * @spec openspec/changes/clients-extension-firefox-and-safari-builds/tasks.md#task-2.1
 */
export const ASK_BACKGROUND = `const done = arguments[arguments.length - 1];
const page = Services.wm.getMostRecentWindow('navigator:browser').gBrowser.selectedBrowser.contentWindow;
if (!page) return done({ ok: false, error: 'the popup page runs out of process' });
const href = String(page.location.href);
const api = href.startsWith('moz-extension://') && page.document.readyState === 'complete' ? page.wrappedJSObject.browser : undefined;
if (!api) return done({ ok: false, error: 'no browser API on ' + href });
// The page's API refuses chrome objects, so the message and the callbacks are made the page's own.
api.runtime.sendMessage(Cu.cloneInto({ kind: 'settings.get' }, page)).then(
	Cu.exportFunction((reply) => done({ ok: reply !== undefined, reply: reply === undefined ? undefined : JSON.parse(JSON.stringify(reply)), error: reply === undefined ? 'no reply' : undefined }), page),
	Cu.exportFunction((error) => done({ ok: false, error: String(error) }), page),
);`

/**
 * Whether the script's result says the background answered; a reply of `undefined` means no listener took it.
 *
 * @spec openspec/changes/clients-extension-firefox-and-safari-builds/tasks.md#task-2.1
 */
export function answered(value: unknown): value is { ok: true; reply: unknown } {
	return (value as { ok?: unknown } | null)?.ok === true
}

/**
 * Chrome context: the extension's background state (`running` once its script ran), or why there is none.
 *
 * @spec openspec/changes/clients-extension-firefox-and-safari-builds/tasks.md#task-2.1
 */
export const BACKGROUND_STATE = `const policy = WebExtensionPolicy.getByID(arguments[0]);
if (!policy) return 'not installed';
return policy.extension?.backgroundState ?? 'no background state';`

/**
 * Chrome context: opens the extension's page `arguments[1]` in a new trusted tab and selects it; returns its URL, or why
 * there is none. The URL comes from the extension's own policy, since a guessed host left the tab on `about:blank`.
 *
 * @spec openspec/changes/clients-extension-firefox-and-safari-builds/tasks.md#task-2.1
 */
export const OPEN_TAB = `const policy = WebExtensionPolicy.getByID(arguments[0]);
if (!policy) return 'not installed';
const url = policy.getURL(arguments[1]);
const win = Services.wm.getMostRecentWindow('navigator:browser');
win.gBrowser.selectedTab = win.gBrowser.addTrustedTab(url);
return url;`

/**
 * Whether `OPEN_TAB` answered a `moz-extension://` URL rather than a reason.
 *
 * @spec openspec/changes/clients-extension-firefox-and-safari-builds/tasks.md#task-2.1
 */
export function openedExtensionPage(value: unknown): value is string {
	return typeof value === 'string' && value.startsWith('moz-extension://')
}

/**
 * Runs `script` with `args` in the chrome context, and returns its value.
 *
 * @spec openspec/changes/clients-extension-firefox-and-safari-builds/tasks.md#task-2.1
 */
async function inChrome(options: FirefoxSmokeOptions, sessionId: string, script: string, args: unknown[]): Promise<unknown> {
	await command(options, 'POST', `/session/${sessionId}/moz/context`, { context: 'chrome' })
	return await command(options, 'POST', `/session/${sessionId}/execute/sync`, { script, args })
}

/**
 * Runs `script` in the chrome context without arguments, and returns the value it hands to its callback.
 *
 * @spec openspec/changes/clients-extension-firefox-and-safari-builds/tasks.md#task-2.1
 */
async function inChromeAsync(options: FirefoxSmokeOptions, sessionId: string, script: string): Promise<unknown> {
	await command(options, 'POST', `/session/${sessionId}/moz/context`, { context: 'chrome' })
	return await command(options, 'POST', `/session/${sessionId}/execute/async`, { script, args: [] })
}

/**
 * Calls `attempt` until `done` holds for its value, or fails after `upMs` with the last value or error.
 *
 * @spec openspec/changes/clients-extension-firefox-and-safari-builds/tasks.md#task-2.1
 */
async function until(options: FirefoxSmokeOptions, what: string, attempt: () => Promise<unknown>, done: (value: unknown) => boolean): Promise<unknown> {
	const deadline = Date.now() + options.upMs
	for (;;) {
		let last: unknown
		try {
			last = await attempt()
			if (done(last)) return last
		} catch (error) {
			last = error instanceof Error ? error.message : String(error)
		}
		if (Date.now() >= deadline) throw new Error(`${what} within ${options.upMs} ms: ${JSON.stringify(last)}`)
		await sleep(options.pollMs)
	}
}

/**
 * Opens the popup page in a new selected tab from the chrome context.
 *
 * @spec openspec/changes/clients-extension-firefox-and-safari-builds/tasks.md#task-2.1
 */
async function openPopup(options: FirefoxSmokeOptions, sessionId: string): Promise<void> {
	const url = await inChrome(options, sessionId, OPEN_TAB, [GECKO_ID, 'popup.html'])
	if (!openedExtensionPage(url)) throw new Error(`no popup page to open: ${JSON.stringify(url)}`)
	console.log(`Opened ${url}`)
}

/**
 * Installs the build as a temporary add-on, requires its background running, opens its popup page and requires the
 * background's answer to it.
 *
 * @spec openspec/changes/clients-extension-firefox-and-safari-builds/tasks.md#task-2.1
 */
export async function smoke(options: FirefoxSmokeOptions): Promise<void> {
	await step('geckodriver status', () => waitUntilUp(`${options.driver}/status`, options))
	const sessionId = await step('new session', () => newSession(options, sessionCapabilities(options.uuid)))
	try {
		const addonId = await step('install the extension', () =>
			command(options, 'POST', `/session/${sessionId}/moz/addon/install`, { path: options.extensionPath, temporary: true }))
		if (addonId !== GECKO_ID) throw new Error(`Firefox smoke test failed at "install the extension": installed as ${JSON.stringify(addonId)}, not ${GECKO_ID}`)
		console.log(`Installed ${options.extensionPath} as ${GECKO_ID}`)
		await step('start the background', () => until(options, 'the background is not running',
			() => inChrome(options, sessionId, BACKGROUND_STATE, [GECKO_ID]), (state) => state === 'running'))
		console.log('The background is running')
		await step('open the popup page', () => openPopup(options, sessionId))
		const answer = await step('ask the background', () => until(options, 'the background did not answer',
			() => inChromeAsync(options, sessionId, ASK_BACKGROUND), answered))
		console.log(`The background answered settings.get: ${JSON.stringify((answer as { reply: unknown }).reply)}`)
	} finally {
		await endSession(options, sessionId)
	}
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
	try {
		await smoke({
			driver: process.env.GECKODRIVER_URL ?? 'http://localhost:4444',
			extensionPath: fileURLToPath(new URL('../.output/firefox-mv2', import.meta.url)),
			uuid: randomUUID(),
			upMs: 30_000,
			pollMs: 500,
			requestMs: 60_000,
		})
		console.log('Firefox smoke test passed')
	} catch (error) {
		console.error(error instanceof Error ? error.message : error)
		process.exitCode = 1
	}
}
