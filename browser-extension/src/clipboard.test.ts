// @vitest-environment happy-dom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fakeBrowser } from 'wxt/testing/fake-browser'
import { CLIPBOARD_ALARM, CLIPBOARD_CODE_KEY, clearClipboard, clearCodeAfterLock, scheduleClipboardClear, writeClipboard, writeFromBackground } from './clipboard'

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
	it('schedules the clear after the delay', async () => {
		await scheduleClipboardClear(20_000, 1_000)
		expect(await browser.alarms.get(CLIPBOARD_ALARM)).toMatchObject({ scheduledTime: 21_000 })
	})

	it('schedules nothing when the delay is never', async () => {
		await scheduleClipboardClear(0)
		expect(await browser.alarms.get(CLIPBOARD_ALARM)).toBeUndefined()
	})

	it('cancels the clear an earlier copy scheduled when the delay is never', async () => {
		await scheduleClipboardClear(30_000, 1_000)
		await scheduleClipboardClear(0)
		expect(await browser.alarms.get(CLIPBOARD_ALARM)).toBeUndefined()
	})
})

describe('writeClipboard', () => {
	it('writes the text through the copy event', () => {
		const { written } = fakeCopyCommand()
		expect(writeClipboard('hunter2')).toBe(true)
		expect(writeClipboard('')).toBe(true)
		expect(written).toEqual(['hunter2', ''])
	})

	it('answers false when the browser refuses', () => {
		Object.assign(document, { execCommand: vi.fn(() => false) })
		expect(writeClipboard('hunter2')).toBe(false)
	})
})

function fakeOffscreen(hasDocument = false) {
	let open = hasDocument
	const offscreen = {
		hasDocument: vi.fn(async () => open),
		createDocument: vi.fn(async () => {
			if (open) throw new Error('Only a single offscreen document may be created.')
			open = true
		}),
		closeDocument: vi.fn(async () => {
			open = false
		}),
	}
	Object.assign(browser, { offscreen })
	return offscreen
}

describe('writeFromBackground', () => {
	it('writes from the background page where there is no offscreen API (Firefox), then schedules the clear', async () => {
		const { written } = fakeCopyCommand()
		expect(await writeFromBackground('hunter2', 30_000, { now: 1_000 })).toBe(true)
		expect(written).toEqual(['hunter2'])
		expect(await browser.alarms.get(CLIPBOARD_ALARM)).toMatchObject({ scheduledTime: 31_000 })
	})

	it('writes through an offscreen document on Chrome and closes it again', async () => {
		const offscreen = fakeOffscreen()
		const send = vi.spyOn(browser.runtime, 'sendMessage').mockResolvedValue(true as never)
		expect(await writeFromBackground('hunter2', 30_000)).toBe(true)
		expect(offscreen.createDocument).toHaveBeenCalledWith(expect.objectContaining({ reasons: ['CLIPBOARD'], url: expect.stringContaining('/offscreen.html') }))
		expect(send).toHaveBeenCalledWith({ kind: 'offscreen.writeClipboard', text: 'hunter2' })
		expect(offscreen.closeDocument).toHaveBeenCalled()
		expect(await browser.alarms.get(CLIPBOARD_ALARM)).toBeDefined()
	})

	it('schedules no clear under Never, and cancels an earlier one', async () => {
		fakeCopyCommand()
		await scheduleClipboardClear(30_000)
		expect(await writeFromBackground('hunter2', 0)).toBe(true)
		expect(await browser.alarms.get(CLIPBOARD_ALARM)).toBeUndefined()
	})

	it('answers false and leaves the clear alone when the write fails', async () => {
		vi.spyOn(console, 'warn').mockImplementation(() => {})
		const offscreen = fakeOffscreen()
		vi.spyOn(browser.runtime, 'sendMessage').mockRejectedValue(new Error('No receiver'))
		await scheduleClipboardClear(30_000, 1_000)
		expect(await writeFromBackground('hunter2', 10_000, { now: 5_000 })).toBe(false)
		expect(offscreen.closeDocument).toHaveBeenCalled()
		expect(await browser.alarms.get(CLIPBOARD_ALARM)).toMatchObject({ scheduledTime: 31_000 })
	})

	it('answers false when the offscreen document could not write', async () => {
		vi.spyOn(console, 'warn').mockImplementation(() => {})
		fakeOffscreen()
		vi.spyOn(browser.runtime, 'sendMessage').mockResolvedValue(false as never)
		expect(await writeFromBackground('hunter2', 30_000)).toBe(false)
	})

	it('runs one offscreen document at a time when a clear fires during a write', async () => {
		const offscreen = fakeOffscreen()
		vi.spyOn(browser.runtime, 'sendMessage').mockResolvedValue(true as never)
		const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
		const [written] = await Promise.all([writeFromBackground('hunter2', 30_000), clearClipboard()])
		expect(written).toBe(true)
		expect(offscreen.createDocument).toHaveBeenCalledTimes(2)
		expect(warn).not.toHaveBeenCalled()
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

describe('clearCodeAfterLock', () => {
	const locked = async () => false
	const unlocked = async () => true
	const stored = async () => (await browser.storage.session.get(CLIPBOARD_CODE_KEY))[CLIPBOARD_CODE_KEY]

	it('clears a copied code at once when its account locks or logs out, before the delay', async () => {
		const { written } = fakeCopyCommand()
		await writeFromBackground('287082', 120_000, { codeOf: 'a' })
		expect(await stored()).toBe('a')
		await clearCodeAfterLock(locked)
		expect(written).toEqual(['287082', ''])
		expect(await browser.alarms.get(CLIPBOARD_ALARM)).toBeUndefined()
		expect(await stored()).toBeUndefined()
	})

	it('clears it under the delay Never too', async () => {
		const { written } = fakeCopyCommand()
		await writeFromBackground('287082', 0, { codeOf: 'a' })
		await clearCodeAfterLock(locked)
		expect(written).toEqual(['287082', ''])
	})

	it('leaves the clipboard alone once another copy replaced the code', async () => {
		const { written } = fakeCopyCommand()
		await writeFromBackground('287082', 120_000, { codeOf: 'a' })
		await scheduleClipboardClear(30_000)
		await clearCodeAfterLock(locked)
		await writeFromBackground('287082', 120_000, { codeOf: 'a' })
		await writeFromBackground('hunter2', 120_000)
		await clearCodeAfterLock(locked)
		expect(written).toEqual(['287082', '287082', 'hunter2'])
	})

	it('keeps the code when another account locked, and forgets it once the clear ran', async () => {
		const { written } = fakeCopyCommand()
		await writeFromBackground('287082', 120_000, { codeOf: 'a' })
		await clearCodeAfterLock(unlocked)
		expect(await stored()).toBe('a')
		await clearClipboard()
		expect(await stored()).toBeUndefined()
		expect(written).toEqual(['287082', ''])
	})

	it('keeps the account in memory where there is no storage.session (Firefox below 115)', async () => {
		const { written } = fakeCopyCommand()
		const session = browser.storage.session
		Object.assign(browser.storage, { session: undefined })
		try {
			await writeFromBackground('287082', 120_000, { codeOf: 'a' })
			await clearCodeAfterLock(locked)
		} finally {
			Object.assign(browser.storage, { session })
		}
		expect(written).toEqual(['287082', ''])
	})
})
