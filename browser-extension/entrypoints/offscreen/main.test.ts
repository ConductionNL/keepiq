// @vitest-environment happy-dom
import { beforeAll, describe, expect, it, vi } from 'vitest'

type Listener = (message: unknown, sender: unknown, sendResponse: (reply: unknown) => void) => unknown
let listener: Listener

beforeAll(async () => {
	const addListener = vi.spyOn(browser.runtime.onMessage, 'addListener')
	await import('./main')
	listener = addListener.mock.calls[0]![0] as Listener
})

describe('offscreen clipboard page', () => {
	it('clears the clipboard and answers whether it worked', () => {
		Object.assign(document, { execCommand: vi.fn(() => true) })
		const sendResponse = vi.fn()
		expect(listener({ kind: 'offscreen.clearClipboard' }, {}, sendResponse)).toBe(true)
		expect(sendResponse).toHaveBeenCalledWith(true)
	})

	it('leaves every other message alone', () => {
		const sendResponse = vi.fn()
		expect(listener({ kind: 'vault.locked' }, {}, sendResponse)).toBeUndefined()
		expect(sendResponse).not.toHaveBeenCalled()
	})
})
