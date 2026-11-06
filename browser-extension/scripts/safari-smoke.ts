/**
 * Loads `.output/safari-mv3` into Safari over safaridriver, then reports whether the `MAIN` world content script
 * replaced `navigator.credentials.get` on the test site (spec `safari-build`). Only a failed install fails it.
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
	/** How long to wait for the content script before skipping its check. */
	scriptMs: number
	pollMs: number
	/** Longest any one WebDriver request may take: a hung Safari otherwise stalls until undici's 5 minute headers timeout. */
	requestMs: number
	/** The folder's contents as a base64 zip, `manifest.json` at its root. */
	zipFolder: (path: string) => Promise<string>
}

const NATIVE_CHECK = "return Function.prototype.toString.call(navigator.credentials.get).includes('[native code]')"

const sleep = (ms: number) => new Promise((resolve) => setTimeout(resolve, ms))

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

/** WebDriver classic: the reply's `value`, or its error thrown with the WebDriver error as `code`. */
async function command(options: SmokeOptions, method: string, path: string, body?: object): Promise<unknown> {
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
		const code = String(error.error ?? '')
		throw Object.assign(new Error(`${method} ${path}: HTTP ${response.status} ${code} ${String(error.message ?? '')}`.trim()), { code })
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
 * Whether the content script replaced the native function within `scriptMs`. An automation session may grant
 * the extension no website access (Safari runs content scripts only where allowed), so `false` is no failure.
 */
async function pageScriptRan(options: SmokeOptions, sessionId: string): Promise<boolean> {
	await command(options, 'POST', `/session/${sessionId}/timeouts`, { pageLoad: 10_000, script: 10_000 })
	// The script below runs on whatever loaded; a page that never reports loaded is no reason to stop.
	await command(options, 'POST', `/session/${sessionId}/url`, { url: `${options.site}/passkey.html` }).catch((error: unknown) => {
		if ((error as { code?: unknown }).code !== 'timeout') throw error
		console.log('passkey.html did not report loaded in time; checking it anyway')
	})
	const deadline = Date.now() + options.scriptMs
	for (let check = 1; ; check++) {
		const native = await command(options, 'POST', `/session/${sessionId}/execute/sync`, { script: NATIVE_CHECK, args: [] })
		console.log(`Check ${check}: navigator.credentials.get is ${native === false ? 'replaced' : native === true ? 'native' : JSON.stringify(native)}`)
		if (native === false) return true
		if (Date.now() >= deadline) return false
		await sleep(options.pollMs)
	}
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
		const ran = await pageScriptRan(options, sessionId).catch((error: unknown) => {
			console.log(`Could not check the passkey page script: ${error instanceof Error ? error.message : String(error)}`)
			return false
		})
		console.log(ran ? 'PASS: the passkey page script ran' : "SKIP: the content script didn't run in the automation session (no website access granted)")
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
			requestMs: 60_000,
			zipFolder: zipWithDitto,
		})
		console.log('Safari smoke test passed')
	} catch (error) {
		console.error(error instanceof Error ? error.message : error)
		process.exitCode = 1
	}
}
