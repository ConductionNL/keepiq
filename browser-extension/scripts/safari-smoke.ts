/**
 * Loads `.output/safari-mv3` into Safari over safaridriver and, when the extension has access to the test site,
 * checks that the `MAIN` world content script replaced `navigator.credentials.get` there (spec `safari-build`).
 * Expects `safaridriver --port 4444` and `npm run test-site` running; `npm run test:safari-smoke`.
 */
import { execFile } from 'node:child_process'
import { mkdtemp, readFile, rm } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'
import { promisify } from 'node:util'

export interface SmokeOptions {
	driver: string
	site: string
	extensionPath: string
	/** How long to wait for safaridriver and the test site to answer. */
	upMs: number
	/** How long to wait for the content script. */
	scriptMs: number
	pollMs: number
	/** The folder's contents as a base64 zip, `manifest.json` at its root. */
	zipFolder: (path: string) => Promise<string>
}

const NATIVE_CHECK = "return Function.prototype.toString.call(navigator.credentials.get).includes('[native code]')"
const ACCESS_CHECK = `const done = arguments[arguments.length - 1]
browser.permissions.contains({ origins: [arguments[0]] }).then(done, (error) => done(String(error)))`

const sleep = (ms: number) => new Promise((resolve) => setTimeout(resolve, ms))

/** A WebDriver error reply; `code` is its `error`, such as `timeout`. */
class WebDriverError extends Error {
	code: string

	constructor(message: string, code: string) {
		super(message)
		this.code = code
	}
}

async function step<T>(name: string, run: () => Promise<T>): Promise<T> {
	try {
		return await run()
	} catch (error) {
		throw new Error(`Safari smoke test failed at "${name}": ${error instanceof Error ? error.message : String(error)}`, { cause: error })
	}
}

async function waitUntilUp(url: string, options: SmokeOptions): Promise<void> {
	const deadline = Date.now() + options.upMs
	for (;;) {
		const ok = await fetch(url).then((response) => response.ok, () => false)
		if (ok) return
		if (Date.now() >= deadline) throw new Error(`${url} did not answer within ${options.upMs} ms`)
		await sleep(options.pollMs)
	}
}

/** WebDriver classic: the reply's `value`, or its error thrown. */
async function command(options: SmokeOptions, method: string, path: string, body?: object): Promise<unknown> {
	const response = await fetch(`${options.driver}${path}`, {
		method,
		headers: body ? { 'Content-Type': 'application/json' } : undefined,
		body: body ? JSON.stringify(body) : undefined,
	})
	const reply = (await response.json().catch(() => ({}))) as { value?: unknown }
	if (!response.ok) {
		const error = (reply.value ?? {}) as { error?: unknown; message?: unknown }
		const code = String(error.error ?? '')
		throw new WebDriverError(`${method} ${path}: HTTP ${response.status} ${code} ${String(error.message ?? '')}`.trim(), code)
	}
	return reply.value
}

/** The install reply is the id itself or `{ extension: id }`; which one Safari sends is unverified. */
export function extensionIdOf(value: unknown): string | undefined {
	if (typeof value === 'string') return value
	const extension = (value as { extension?: unknown } | null)?.extension
	return typeof extension === 'string' ? extension : undefined
}

/** By path first; whether Safari loads an unpacked folder that way is unverified, so a base64 zip after. */
async function install(options: SmokeOptions, sessionId: string): Promise<string> {
	const path = `/session/${sessionId}/webextension`
	let value: unknown
	try {
		value = await command(options, 'POST', path, { type: 'path', path: options.extensionPath })
	} catch (error) {
		console.log(`Install by path failed, trying a base64 zip: ${error instanceof Error ? error.message : String(error)}`)
		value = await command(options, 'POST', path, { type: 'base64', value: await options.zipFolder(options.extensionPath) })
	}
	const id = extensionIdOf(value)
	if (id === undefined) throw new Error(`no extension id in ${JSON.stringify(value)}`)
	return id
}

/**
 * Whether the extension may run on the test site in this session, asked from its own page.
 * `undefined` when that page cannot be opened or asked, which the smoke test only logs.
 */
async function websiteAccess(options: SmokeOptions, sessionId: string, extensionId: string): Promise<boolean | undefined> {
	try {
		await command(options, 'POST', `/session/${sessionId}/timeouts`, { pageLoad: 5_000 })
		// The popup page may never report loaded without a toolbar to open it from; the script runs either way.
		await command(options, 'POST', `/session/${sessionId}/url`, { url: `safari-web-extension://${extensionId}/popup.html` })
			.catch((error: unknown) => {
				if (!(error instanceof WebDriverError && error.code === 'timeout')) throw error
			})
		const answer = await command(options, 'POST', `/session/${sessionId}/execute/async`, { script: ACCESS_CHECK, args: [`${options.site}/*`] })
		if (typeof answer === 'boolean') return answer
		console.log(`The extension's page answered ${JSON.stringify(answer)} about website access`)
	} catch (error) {
		console.log(`Could not ask the extension's page about website access: ${error instanceof Error ? error.message : String(error)}`)
	}
	return undefined
}

export async function smoke(options: SmokeOptions): Promise<void> {
	await step('safaridriver status', () => waitUntilUp(`${options.driver}/status`, options))
	await step('test site', () => waitUntilUp(`${options.site}/`, options))
	const sessionId = await step('new session', async () => {
		const value = await command(options, 'POST', '/session', { capabilities: { alwaysMatch: { browserName: 'safari' } } })
		const id = (value as { sessionId?: unknown } | null)?.sessionId
		if (typeof id !== 'string') throw new Error(`no sessionId in ${JSON.stringify(value)}`)
		return id
	})
	try {
		const extensionId = await step('install the extension', () => install(options, sessionId))
		console.log(`Installed ${options.extensionPath} as ${extensionId}`)
		if (await websiteAccess(options, sessionId, extensionId) !== true) {
			console.log('SKIP: no website access in an automation session')
			return
		}
		await step('open passkey.html', () => command(options, 'POST', `/session/${sessionId}/url`, { url: `${options.site}/passkey.html` }))
		await step('passkey page script', async () => {
			const deadline = Date.now() + options.scriptMs
			for (;;) {
				if (await command(options, 'POST', `/session/${sessionId}/execute/sync`, { script: NATIVE_CHECK, args: [] }) === false) return
				if (Date.now() >= deadline) throw new Error(`navigator.credentials.get was still native after ${options.scriptMs} ms`)
				await sleep(options.pollMs)
			}
		})
	} finally {
		await command(options, 'DELETE', `/session/${sessionId}`).catch((error: unknown) => console.warn('Could not end the WebDriver session', error))
	}
}

/** `ditto -c -k` without `--keepParent`, so `manifest.json` sits at the zip's root. */
async function zipWithDitto(path: string): Promise<string> {
	const dir = await mkdtemp(join(tmpdir(), 'keepiq-safari-'))
	try {
		const zip = join(dir, 'extension.zip')
		await promisify(execFile)('ditto', ['-c', '-k', path, zip])
		return (await readFile(zip)).toString('base64')
	} finally {
		await rm(dir, { recursive: true, force: true })
	}
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
	try {
		await smoke({
			driver: process.env.SAFARIDRIVER_URL ?? 'http://localhost:4444',
			site: `http://localhost:${process.env.TEST_SITE_PORT ?? 8100}`,
			extensionPath: fileURLToPath(new URL('../.output/safari-mv3', import.meta.url)),
			upMs: 30_000,
			scriptMs: 10_000,
			pollMs: 500,
			zipFolder: zipWithDitto,
		})
		console.log('Safari smoke test passed')
	} catch (error) {
		console.error(error instanceof Error ? error.message : error)
		process.exitCode = 1
	}
}
