// @vitest-environment happy-dom
import { beforeAll, describe, expect, it, vi } from 'vitest'

type Listener = (message: unknown, sender: unknown, sendResponse: (reply: unknown) => void) => unknown
let listener: Listener

beforeAll(async () => {
	const addListener = vi.spyOn(browser.runtime.onMessage, 'addListener')
	await import('./main')
	listener = addListener.mock.calls[0]![0] as Listener
})

const background = { id: browser.runtime.id, url: browser.runtime.getURL('/background.js') }

/** What each `execCommand('copy')` put on the clipboard. */
function fakeCopyCommand(): string[] {
	const written: string[] = []
	Object.assign(document, {
		execCommand: vi.fn(() => {
			const event = Object.assign(new Event('copy'), { clipboardData: { setData: (_type: string, value: string) => written.push(value) } })
			document.dispatchEvent(event)
			return true
		}),
	})
	return written
}

describe('offscreen clipboard page', () => {
	it('clears the clipboard and answers whether it worked', () => {
		const written = fakeCopyCommand()
		const sendResponse = vi.fn()
		expect(listener({ kind: 'offscreen.clearClipboard' }, background, sendResponse)).toBe(true)
		expect(sendResponse).toHaveBeenCalledWith(true)
		expect(written).toEqual([''])
	})

	it('writes the text it is sent', () => {
		const written = fakeCopyCommand()
		const sendResponse = vi.fn()
		expect(listener({ kind: 'offscreen.writeClipboard', text: 'hunter2' }, background, sendResponse)).toBe(true)
		expect(sendResponse).toHaveBeenCalledWith(true)
		expect(written).toEqual(['hunter2'])
	})

	it('writes nothing for anyone but the background', () => {
		const written = fakeCopyCommand()
		const sendResponse = vi.fn()
		expect(listener({ kind: 'offscreen.writeClipboard', text: 'planted' }, { id: browser.runtime.id, url: 'https://evil.example/', tab: { id: 7 } }, sendResponse)).toBeUndefined()
		expect(listener({ kind: 'offscreen.writeClipboard', text: 'planted' }, { id: browser.runtime.id, url: browser.runtime.getURL('/popup.html') }, sendResponse)).toBeUndefined()
		expect(listener({ kind: 'offscreen.writeClipboard', text: 'planted' }, { id: browser.runtime.id, url: browser.runtime.getURL('/popup.html').replace('popup', 'inline-list'), tab: { id: 7 } }, sendResponse)).toBeUndefined()
		expect(listener({ kind: 'offscreen.clearClipboard' }, { id: 'other-extension', url: background.url }, sendResponse)).toBeUndefined()
		expect(sendResponse).not.toHaveBeenCalled()
		expect(written).toEqual([])
	})

	it('leaves every other message alone', () => {
		const sendResponse = vi.fn()
		expect(listener({ kind: 'vault.locked' }, background, sendResponse)).toBeUndefined()
		expect(listener(null, background, sendResponse)).toBeUndefined()
		expect(sendResponse).not.toHaveBeenCalled()
	})
})
