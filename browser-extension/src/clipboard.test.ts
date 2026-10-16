// @vitest-environment happy-dom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fakeBrowser } from 'wxt/testing/fake-browser'
import { settingsKey } from '@/src/accounts/settings'
import { CLIPBOARD_ALARM, clearClipboard, overwriteClipboard, scheduleClipboardClear } from './clipboard'

beforeEach(() => {
	fakeBrowser.reset()
})
afterEach(() => {
	Object.assign(browser, { offscreen: undefined })
})

/** `execCommand('copy')` as a browser runs it: fires `copy` and lets the listener fill the data. */
function fakeCopyCommand() {
	const written: string[] = []
	const execCommand = vi.fn(() => {
		const data = { value: 'secret', setData: (_type: string, value: string) => (data.value = value) }
		const event = new Event('copy') as Event & { clipboardData: typeof data }
		Object.assign(event, { clipboardData: data })
		document.dispatchEvent(event)
		written.push(data.value)
		return true
	})
	Object.assign(document, { execCommand })
	return { execCommand, written }
}

describe('scheduleClipboardClear', () => {
	it('clears after 30 seconds when no delay is stored', async () => {
		await scheduleClipboardClear('a', 1_000)
		expect(await browser.alarms.get(CLIPBOARD_ALARM)).toMatchObject({ scheduledTime: 31_000 })
	})

	it('schedules the clear after the stored delay', async () => {
		await browser.storage.local.set({ [settingsKey('a')]: { clearClipboardMs: 20_000 } })
		await scheduleClipboardClear('a', 1_000)
		expect(await browser.alarms.get(CLIPBOARD_ALARM)).toMatchObject({ scheduledTime: 21_000 })
	})

	it('schedules nothing when the delay is never', async () => {
		await browser.storage.local.set({ [settingsKey('a')]: { clearClipboardMs: 0 } })
		await scheduleClipboardClear('a')
		expect(await browser.alarms.get(CLIPBOARD_ALARM)).toBeUndefined()
	})

	it('cancels the clear an earlier copy scheduled when the delay is never', async () => {
		await scheduleClipboardClear('a', 1_000)
		await browser.storage.local.set({ [settingsKey('b')]: { clearClipboardMs: 0 } })
		await scheduleClipboardClear('b')
		expect(await browser.alarms.get(CLIPBOARD_ALARM)).toBeUndefined()
	})

	it.each(['soon', -1, Number.POSITIVE_INFINITY])('reads an invalid delay (%s) as the default', async (clearClipboardMs) => {
		await browser.storage.local.set({ [settingsKey('a')]: { clearClipboardMs } })
		await scheduleClipboardClear('a', 1_000)
		expect(await browser.alarms.get(CLIPBOARD_ALARM)).toMatchObject({ scheduledTime: 31_000 })
	})
})

describe('overwriteClipboard', () => {
	it('writes an empty string through the copy event', () => {
		const { written } = fakeCopyCommand()
		expect(overwriteClipboard()).toBe(true)
		expect(written).toEqual([''])
	})
})

describe('clearClipboard', () => {
	it('clears from the background page where there is no offscreen API (Firefox)', async () => {
		const { execCommand } = fakeCopyCommand()
		await clearClipboard()
		expect(execCommand).toHaveBeenCalledWith('copy')
	})

	it('clears through an offscreen document on Chrome and closes it again', async () => {
		const offscreen = { hasDocument: vi.fn(async () => false), createDocument: vi.fn(async () => {}), closeDocument: vi.fn(async () => {}) }
		Object.assign(browser, { offscreen })
		const send = vi.spyOn(browser.runtime, 'sendMessage').mockResolvedValue(true as never)
		await clearClipboard()
		expect(offscreen.createDocument).toHaveBeenCalledWith(expect.objectContaining({ reasons: ['CLIPBOARD'], url: expect.stringContaining('/offscreen.html') }))
		expect(send).toHaveBeenCalledWith({ kind: 'offscreen.clearClipboard' })
		expect(offscreen.closeDocument).toHaveBeenCalled()
	})

	it('logs when the offscreen document could not clear', async () => {
		const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
		Object.assign(browser, { offscreen: { hasDocument: vi.fn(async () => true), closeDocument: vi.fn(async () => {}) } })
		vi.spyOn(browser.runtime, 'sendMessage').mockResolvedValue(false as never)
		await clearClipboard()
		expect(warn).toHaveBeenCalledWith('[keepiq] could not clear the clipboard')
	})

	it('closes the offscreen document even when the message fails', async () => {
		vi.spyOn(console, 'warn').mockImplementation(() => {})
		const offscreen = { hasDocument: vi.fn(async () => true), closeDocument: vi.fn(async () => {}) }
		Object.assign(browser, { offscreen })
		vi.spyOn(browser.runtime, 'sendMessage').mockRejectedValue(new Error('No receiver'))
		await clearClipboard()
		expect(offscreen.closeDocument).toHaveBeenCalled()
	})

	it('only logs when clearing fails', async () => {
		const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
		Object.assign(browser, { offscreen: { hasDocument: vi.fn(async () => { throw new Error('nope') }) } })
		await expect(clearClipboard()).resolves.toBeUndefined()
		expect(warn).toHaveBeenCalled()
	})
})
