/**
 * WebDriver classic over `fetch`, shared by the browser smoke tests (`safari-smoke.ts`, `firefox-smoke.ts`), which
 * speak to safaridriver and geckodriver directly as Playwright cannot load an extension into either browser.
 */

export interface DriverOptions {
	driver: string
	/** How long to wait for the driver to answer. */
	upMs: number
	pollMs: number
	/** Longest any one WebDriver request may take: a hung browser otherwise stalls until undici's 5 minute headers timeout. */
	requestMs: number
}

export const sleep = (ms: number) => new Promise((resolve) => setTimeout(resolve, ms))

/**
 * Runs one step, and names the browser and the step in the error when it fails.
 *
 * @spec openspec/changes/clients-extension-firefox-and-safari-builds/tasks.md#task-2.1
 */
export async function step<T>(browserName: string, name: string, run: () => Promise<T>): Promise<T> {
	try {
		return await run()
	} catch (error) {
		throw new Error(`${browserName} smoke test failed at "${name}": ${error instanceof Error ? error.message : String(error)}`, { cause: error })
	}
}

/**
 * Polls `url` until it answers OK, or fails after `upMs`.
 *
 * @spec openspec/changes/clients-extension-firefox-and-safari-builds/tasks.md#task-2.1
 */
export async function waitUntilUp(url: string, options: DriverOptions): Promise<void> {
	const deadline = Date.now() + options.upMs
	for (;;) {
		const ok = await fetch(url).then((response) => response.ok, () => false)
		if (ok) return
		if (Date.now() >= deadline) throw new Error(`${url} did not answer within ${options.upMs} ms`)
		await sleep(options.pollMs)
	}
}

/**
 * The reply's `value`, or its error thrown.
 *
 * @spec openspec/changes/clients-extension-firefox-and-safari-builds/tasks.md#task-2.1
 */
export async function command(options: DriverOptions, method: string, path: string, body?: object): Promise<unknown> {
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
 * Opens a session with `capabilities` and returns its id.
 *
 * @spec openspec/changes/clients-extension-firefox-and-safari-builds/tasks.md#task-2.1
 */
export async function newSession(options: DriverOptions, capabilities: object): Promise<string> {
	const value = await command(options, 'POST', '/session', capabilities)
	const id = (value as { sessionId?: unknown } | null)?.sessionId
	if (typeof id !== 'string') throw new Error(`no sessionId in ${JSON.stringify(value)}`)
	return id
}

/**
 * Ends the session, and only warns when the driver cannot.
 *
 * @spec openspec/changes/clients-extension-firefox-and-safari-builds/tasks.md#task-2.1
 */
export async function endSession(options: DriverOptions, sessionId: string): Promise<void> {
	await command(options, 'DELETE', `/session/${sessionId}`).catch((error: unknown) => console.warn('Could not end the WebDriver session', error))
}
