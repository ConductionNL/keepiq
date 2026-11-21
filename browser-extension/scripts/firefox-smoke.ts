/**
 * Loads `.output/firefox-mv2` into headless Firefox over geckodriver and requires its background to answer a request
 * from the extension's own popup page (spec `clients-browser-builds` § Firefox starts the background, task 2.1 of `clients-extension-firefox-and-safari-builds`).
 * Playwright cannot load a WebExtension into Firefox, so this speaks WebDriver classic to geckodriver directly.
 * Expects `geckodriver --port 4444` running; `npm run test:firefox-smoke`.
 */
import { randomUUID } from 'node:crypto'
import { fileURLToPath, pathToFileURL } from 'node:url'

/** The id in `browser_specific_settings.gecko`, which the uuid pref maps to a fixed `moz-extension://` host. */
export const GECKO_ID = 'keepiq@conduction.nl'

export interface FirefoxSmokeOptions {
	driver: string
	extensionPath: string
	/** The `moz-extension://` host the extension gets, so the test knows its popup's address. */
	uuid: string
	/** How long to wait for geckodriver, and then for the background, to answer. */
	upMs: number
	pollMs: number
	/** Longest any one WebDriver request may take. */
	requestMs: number
}

/** What the popup page's script hands back: the background's reply, or why the message went unanswered. */
export type BackgroundAnswer = { ok: true; reply: unknown } | { ok: false; error: string }

const sleep = (ms: number) => new Promise((resolve) => setTimeout(resolve, ms))

/**
 * Runs one step, and names it in the error when it fails.
 *
 * @spec openspec/changes/clients-extension-firefox-and-safari-builds/tasks.md#task-2.1
 */
async function step<T>(name: string, run: () => Promise<T>): Promise<T> {
	try {
		return await run()
	} catch (error) {
		throw new Error(`Firefox smoke test failed at "${name}": ${error instanceof Error ? error.message : String(error)}`, { cause: error })
	}
}

/**
 * Polls `url` until it answers OK, or fails after `upMs`.
 *
 * @spec openspec/changes/clients-extension-firefox-and-safari-builds/tasks.md#task-2.1
 */
async function waitUntilUp(url: string, options: FirefoxSmokeOptions): Promise<void> {
	const deadline = Date.now() + options.upMs
	for (;;) {
		const ok = await fetch(url).then((response) => response.ok, () => false)
		if (ok) return
		if (Date.now() >= deadline) throw new Error(`${url} did not answer within ${options.upMs} ms`)
		await sleep(options.pollMs)
	}
}

/**
 * WebDriver classic: the reply's `value`, or its error thrown.
 *
 * @spec openspec/changes/clients-extension-firefox-and-safari-builds/tasks.md#task-2.1
 */
async function command(options: FirefoxSmokeOptions, method: string, path: string, body?: object): Promise<unknown> {
	let response: Response
	try {
		response = await fetch(`${options.driver}${path}`, {
			method,
			headers: body ? { 'Content-Type': 'application/json' } : undefined,
			body: body ? JSON.stringify(body) : undefined,
			signal: AbortSignal.timeout(options.requestMs),
		})
	} catch (error) {
		const timedOut = error instanceof Error && error.name === 'TimeoutError'
		throw new Error(`${method} ${path}: ${timedOut ? `no answer within ${options.requestMs} ms` : String(error)}`, { cause: error })
	}
	const reply = (await response.json().catch(() => ({}))) as { value?: unknown }
	if (!response.ok) {
		const error = (reply.value ?? {}) as { error?: unknown; message?: unknown }
		throw new Error(`${method} ${path}: HTTP ${response.status} ${String(error.error ?? '')} ${String(error.message ?? '')}`.trim())
	}
	return reply.value
}

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
browser.runtime.sendMessage({ kind: 'settings.get' }).then(
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
 * Asks the background from the popup page until it answers, or fails after `upMs` with the last reason.
 *
 * @spec openspec/changes/clients-extension-firefox-and-safari-builds/tasks.md#task-2.1
 */
async function askBackground(options: FirefoxSmokeOptions, sessionId: string): Promise<unknown> {
	const deadline = Date.now() + options.upMs
	for (;;) {
		const value = await command(options, 'POST', `/session/${sessionId}/execute/async`, { script: ASK_BACKGROUND, args: [] })
		if (answered(value)) return value.reply
		if (Date.now() >= deadline) throw new Error(`the background did not answer within ${options.upMs} ms: ${JSON.stringify(value)}`)
		await sleep(options.pollMs)
	}
}

/**
 * Installs the build as a temporary add-on, opens its popup page and requires the background's answer.
 *
 * @spec openspec/changes/clients-extension-firefox-and-safari-builds/tasks.md#task-2.1
 */
export async function smoke(options: FirefoxSmokeOptions): Promise<void> {
	await step('geckodriver status', () => waitUntilUp(`${options.driver}/status`, options))
	const sessionId = await step('new session', async () => {
		const value = await command(options, 'POST', '/session', sessionCapabilities(options.uuid))
		const id = (value as { sessionId?: unknown } | null)?.sessionId
		if (typeof id !== 'string') throw new Error(`no sessionId in ${JSON.stringify(value)}`)
		return id
	})
	try {
		const addonId = await step('install the extension', () =>
			command(options, 'POST', `/session/${sessionId}/moz/addon/install`, { path: options.extensionPath, temporary: true }))
		if (addonId !== GECKO_ID) throw new Error(`Firefox smoke test failed at "install the extension": installed as ${JSON.stringify(addonId)}, not ${GECKO_ID}`)
		console.log(`Installed ${options.extensionPath} as ${GECKO_ID}`)
		await step('open the popup page', () => command(options, 'POST', `/session/${sessionId}/url`, { url: `moz-extension://${options.uuid}/popup.html` }))
		const reply = await step('ask the background', () => askBackground(options, sessionId))
		console.log(`The background answered settings.get: ${JSON.stringify(reply)}`)
	} finally {
		await command(options, 'DELETE', `/session/${sessionId}`).catch((error: unknown) => console.warn('Could not end the WebDriver session', error))
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
