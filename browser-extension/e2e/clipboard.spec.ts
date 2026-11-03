/**
 * The offscreen document writes the clipboard only for the worker, by its exact `sender.url`
 * (`entrypoints/offscreen/main.ts`); this proves the built worker's messages pass that check in real Chromium.
 */
import { expect, test } from './fixtures'
import type { BrowserContext, Worker } from '@playwright/test'

const workerOf = (context: BrowserContext): Worker => context.serviceWorkers().find((sw) => sw.url().startsWith('chrome-extension://'))!

interface Chrome {
	offscreen: { hasDocument(): Promise<boolean>; createDocument(options: object): Promise<void>; closeDocument(): Promise<void> }
	runtime: { getURL(path: string): string; sendMessage(message: object): Promise<unknown> }
	alarms: { create(name: string, info: object): Promise<void> }
}

/** What `inDocument` in `src/clipboard.ts` sends, from the worker. */
const fromWorker = (worker: Worker, message: object) => worker.evaluate(async (message) => {
	const { offscreen, runtime } = (globalThis as unknown as { chrome: Chrome }).chrome
	if (!(await offscreen.hasDocument())) {
		await offscreen.createDocument({ url: runtime.getURL('/offscreen.html'), reasons: ['CLIPBOARD'], justification: 'e2e' })
	}
	try {
		return await runtime.sendMessage(message)
	} finally {
		await offscreen.closeDocument()
	}
}, message)

test('the worker writes and clears the clipboard through the offscreen document', async ({ context }) => {
	const worker = workerOf(context)
	expect(worker.url()).toMatch(/^chrome-extension:\/\/[a-z]+\/background\.js$/)
	expect(await fromWorker(worker, { kind: 'offscreen.writeClipboard', text: 'e2e-secret' })).toBe(true)
	expect(await fromWorker(worker, { kind: 'offscreen.clearClipboard' })).toBe(true)
})

test('the background\'s clipboard alarm clears what the worker wrote', async ({ context, page, baseURL }) => {
	await context.grantPermissions(['clipboard-read', 'clipboard-write'], { origin: baseURL })
	await page.goto('/')
	const worker = workerOf(context)
	expect(await fromWorker(worker, { kind: 'offscreen.writeClipboard', text: 'e2e-secret' })).toBe(true)
	const clipboard = () => page.evaluate(() => navigator.clipboard.readText())
	await expect.poll(clipboard).toBe('e2e-secret')
	await worker.evaluate(() => (globalThis as unknown as { chrome: Chrome }).chrome.alarms.create('clipboard-clear', { when: Date.now() }))
	await expect.poll(clipboard).toBe('')
})
