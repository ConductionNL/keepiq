/**
 * Installs `.output/safari-mv3` in Safari over safaridriver and requires an extension id back (spec `safari-build`).
 * It does not check the content scripts: Safari asks a person for website access first (design.md § CI).
 * Expects `safaridriver --port 4444` running; `npm run test:safari-smoke`.
 */
import { execFile } from 'node:child_process'
import { mkdtemp, readFile, rm } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'
import { promisify } from 'node:util'

export interface SmokeOptions {
	driver: string
	extensionPath: string
	/** How long to wait for safaridriver to answer. */
	upMs: number
	pollMs: number
	/** Longest any one WebDriver request may take: a hung Safari otherwise stalls until undici's 5 minute headers timeout. */
	requestMs: number
	/** The folder's contents as a base64 zip, `manifest.json` at its root. */
	zipFolder: (path: string) => Promise<string>
}

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

/** WebDriver classic: the reply's `value`, or its error thrown. */
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
		throw new Error(`${method} ${path}: HTTP ${response.status} ${String(error.error ?? '')} ${String(error.message ?? '')}`.trim())
	}
	return reply.value
}

/** The install reply is the id itself or `{ extension: id }`; Safari 26 sends the latter. */
export function extensionIdOf(value: unknown): string | undefined {
	if (typeof value === 'string') return value
	const extension = (value as { extension?: unknown } | null)?.extension
	return typeof extension === 'string' ? extension : undefined
}

/** By path, which Safari 26 accepts; a base64 zip in case a later Safari does not. */
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

export async function smoke(options: SmokeOptions): Promise<void> {
	await step('safaridriver status', () => waitUntilUp(`${options.driver}/status`, options))
	const sessionId = await step('new session', async () => {
		const value = await command(options, 'POST', '/session', { capabilities: { alwaysMatch: { browserName: 'safari' } } })
		const id = (value as { sessionId?: unknown } | null)?.sessionId
		if (typeof id !== 'string') throw new Error(`no sessionId in ${JSON.stringify(value)}`)
		return id
	})
	try {
		const extensionId = await step('install the extension', () => install(options, sessionId))
		console.log(`Installed ${options.extensionPath} as ${extensionId}`)
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
			extensionPath: fileURLToPath(new URL('../.output/safari-mv3', import.meta.url)),
			upMs: 30_000,
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
