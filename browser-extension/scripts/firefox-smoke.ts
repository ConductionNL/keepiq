/**
 * Loads `.output/firefox-mv2` into headless Firefox over geckodriver, requires its background to be running and to
 * answer a request from the extension's own popup page (spec `clients-browser-builds` § Firefox starts the background, task 2.1 of `clients-extension-firefox-and-safari-builds`).
 * Playwright cannot load a WebExtension into Firefox, so this speaks WebDriver classic to geckodriver directly. WebDriver
 * may not navigate to a `moz-extension://` page, so the chrome context opens the popup in a tab of its own.
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
					prefs: { 'extensions.webextensions.uuids': JSON.stringify({ [GECKO_ID]: uuid }) },
				},
			},
		},
	}
}

/**
 * The script the popup page runs: one request the background answers (`settings.get`, read-only), and its reply.
 *
 * @spec openspec/changes/clients-extension-firefox-and-safari-builds/tasks.md#task-2.1
 */
export const ASK_BACKGROUND = `const done = arguments[arguments.length - 1];
// WebDriver's sandbox sees the page through Xray wrappers, which hide the page's own \`browser\`.
const api = (typeof window !== 'undefined' && window.wrappedJSObject && window.wrappedJSObject.browser) || (typeof browser !== 'undefined' ? browser : undefined);
if (!api) return done({ ok: false, error: 'no browser API on ' + (typeof location !== 'undefined' ? location.href : 'the page') });
api.runtime.sendMessage({ kind: 'settings.get' }).then(
	(reply) => done({ ok: reply !== undefined, reply, error: reply === undefined ? 'no reply' : undefined }),
	(error) => done({ ok: false, error: String(error) }),
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
 * Opens the popup page in a new tab from the chrome context and moves WebDriver onto that tab.
 *
 * @spec openspec/changes/clients-extension-firefox-and-safari-builds/tasks.md#task-2.1
 */
async function openPopup(options: FirefoxSmokeOptions, sessionId: string): Promise<void> {
	const before = await command(options, 'GET', `/session/${sessionId}/window/handles`) as string[]
	const url = await inChrome(options, sessionId, OPEN_TAB, [GECKO_ID, 'popup.html'])
	if (!openedExtensionPage(url)) throw new Error(`no popup page to open: ${JSON.stringify(url)}`)
	console.log(`Opened ${url}`)
	await command(options, 'POST', `/session/${sessionId}/moz/context`, { context: 'content' })
	const handles = await until(options, 'no new tab opened', () => command(options, 'GET', `/session/${sessionId}/window/handles`),
		(value) => Array.isArray(value) && value.length > before.length) as string[]
	await command(options, 'POST', `/session/${sessionId}/window`, { handle: handles.find((handle) => !before.includes(handle)) })
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
			() => command(options, 'POST', `/session/${sessionId}/execute/async`, { script: ASK_BACKGROUND, args: [] }), answered))
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
