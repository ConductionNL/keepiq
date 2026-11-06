// @vitest-environment happy-dom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fakeBrowser } from 'wxt/testing/fake-browser'
import { CLIPBOARD_ALARM, CLIPBOARD_CODE_KEY, CLIPBOARD_COUNT_KEY, clearClipboard, clearCodeAfterLock, copyText, scheduleClipboardClear, writeClipboard, writeFromBackground } from './clipboard'
import { NATIVE_TIMEOUT_MS } from './safari-native'

beforeEach(() => {
	fakeBrowser.reset()
})
afterEach(() => {
	Object.assign(browser, { offscreen: undefined })
	vi.unstubAllEnvs()
	vi.useRealTimers()
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

	it('writes through an offscreen document on Chrome and keeps it open', async () => {
		const offscreen = fakeOffscreen()
		const send = vi.spyOn(browser.runtime, 'sendMessage').mockResolvedValue(true as never)
		expect(await writeFromBackground('hunter2', 30_000)).toBe(true)
		expect(offscreen.createDocument).toHaveBeenCalledWith(expect.objectContaining({ reasons: ['CLIPBOARD'], url: expect.stringContaining('/offscreen.html') }))
		expect(send).toHaveBeenCalledWith({ kind: 'offscreen.writeClipboard', text: 'hunter2' })
		expect(offscreen.closeDocument).not.toHaveBeenCalled()
		expect(await browser.alarms.get(CLIPBOARD_ALARM)).toBeDefined()
	})

	it('never closes the document it wrote through, and reuses it for the next write and the clear', async () => {
		vi.spyOn(console, 'warn').mockImplementation(() => {})
		const offscreen = fakeOffscreen()
		const send = vi.spyOn(browser.runtime, 'sendMessage').mockResolvedValue(true as never)
		expect(await writeFromBackground('hunter2', 30_000)).toBe(true)
		expect(await writeFromBackground('287082', 30_000)).toBe(true)
		send.mockRejectedValueOnce(new Error('No receiver'))
		expect(await writeFromBackground('lost', 30_000)).toBe(false)
		await clearClipboard()
		expect(offscreen.createDocument).toHaveBeenCalledTimes(1)
		expect(offscreen.closeDocument).not.toHaveBeenCalled()
		expect(send).toHaveBeenLastCalledWith({ kind: 'offscreen.clearClipboard' })
	})

	it('schedules no clear under Never, and cancels an earlier one', async () => {
		fakeCopyCommand()
		await scheduleClipboardClear(30_000)
		expect(await writeFromBackground('hunter2', 0)).toBe(true)
		expect(await browser.alarms.get(CLIPBOARD_ALARM)).toBeUndefined()
	})

	it('answers false and leaves the clear alone when the write fails', async () => {
		vi.spyOn(console, 'warn').mockImplementation(() => {})
		fakeOffscreen()
		vi.spyOn(browser.runtime, 'sendMessage').mockRejectedValue(new Error('No receiver'))
		await scheduleClipboardClear(30_000, 1_000)
		expect(await writeFromBackground('hunter2', 10_000, { now: 5_000 })).toBe(false)
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
		expect(offscreen.createDocument).toHaveBeenCalledTimes(1)
		expect(warn).not.toHaveBeenCalled()
	})
})

describe('clearClipboard', () => {
	it('clears from the background page where there is no offscreen API (Firefox)', async () => {
		const { execCommand } = fakeCopyCommand()
		await clearClipboard()
		expect(execCommand).toHaveBeenCalledWith('copy')
	})

	it('clears through an offscreen document on Chrome and keeps it open', async () => {
		const offscreen = fakeOffscreen()
		const send = vi.spyOn(browser.runtime, 'sendMessage').mockResolvedValue(true as never)
		await clearClipboard()
		expect(offscreen.createDocument).toHaveBeenCalledWith(expect.objectContaining({ reasons: ['CLIPBOARD'], url: expect.stringContaining('/offscreen.html') }))
		expect(send).toHaveBeenCalledWith({ kind: 'offscreen.clearClipboard' })
		expect(offscreen.closeDocument).not.toHaveBeenCalled()
	})

	it('logs when the offscreen document could not clear', async () => {
		const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
		fakeOffscreen(true)
		vi.spyOn(browser.runtime, 'sendMessage').mockResolvedValue(false as never)
		await clearClipboard()
		expect(warn).toHaveBeenCalledWith('[keepiq] could not clear the clipboard')
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

/** The containing app over a pasteboard that counts like `NSPasteboard`; `copyElsewhere` is another app copying. */
function fakeApp() {
	const board = { changeCount: 7, text: 'before' }
	const send = vi.spyOn(browser.runtime, 'sendNativeMessage').mockImplementation(async (_app: string, message: object) => {
		const request = message as { kind: string; text?: string; changeCount?: number }
		if (request.kind === 'clipboard.write') {
			board.changeCount += 1
			board.text = request.text ?? ''
			return { ok: true, changeCount: board.changeCount }
		}
		if (request.changeCount !== undefined && request.changeCount !== board.changeCount) return { ok: true, cleared: false }
		board.changeCount += 1
		board.text = ''
		return { ok: true, cleared: true }
	})
	const copyElsewhere = (text: string) => {
		board.changeCount += 1
		board.text = text
	}
	return { board, send, copyElsewhere }
}

const storedCount = async () => (await browser.storage.session.get(CLIPBOARD_COUNT_KEY))[CLIPBOARD_COUNT_KEY]

describe('copyText', () => {
	function fakeClipboard(refuse = false) {
		const writeText = vi.fn(async () => {
			if (refuse) throw new DOMException('Denied', 'NotAllowedError')
		})
		Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true })
		return writeText
	}

	it.each(['chrome', 'firefox'])('writes with navigator.clipboard on %s, never through an app', async (target) => {
		vi.stubEnv('BROWSER', target)
		const writeText = fakeClipboard()
		const send = vi.spyOn(browser.runtime, 'sendNativeMessage')
		expect(await copyText('hunter2')).toBeUndefined()
		expect(writeText).toHaveBeenCalledWith('hunter2')
		expect(send).not.toHaveBeenCalled()
	})

	it('writes through the containing app on Safari and resolves to its count', async () => {
		vi.stubEnv('BROWSER', 'safari')
		const writeText = fakeClipboard()
		const { board } = fakeApp()
		expect(await copyText('hunter2')).toBe(8)
		expect(board.text).toBe('hunter2')
		expect(writeText).not.toHaveBeenCalled()
	})

	it('falls back to navigator.clipboard on Safari when the app fails', async () => {
		vi.stubEnv('BROWSER', 'safari')
		vi.spyOn(console, 'warn').mockImplementation(() => {})
		const writeText = fakeClipboard()
		vi.spyOn(browser.runtime, 'sendNativeMessage').mockRejectedValue(new Error('No native host'))
		expect(await copyText('hunter2')).toBeUndefined()
		expect(writeText).toHaveBeenCalledWith('hunter2')
	})

	it('rejects on Safari when both fail', async () => {
		vi.stubEnv('BROWSER', 'safari')
		vi.spyOn(console, 'warn').mockImplementation(() => {})
		fakeClipboard(true)
		vi.spyOn(browser.runtime, 'sendNativeMessage').mockResolvedValue({ ok: false, error: 'write_failed' } as never)
		await expect(copyText('hunter2')).rejects.toThrow('Denied')
	})
})

describe('Safari', () => {
	beforeEach(() => {
		vi.stubEnv('BROWSER', 'safari')
	})

	it('writes through the containing app, schedules the clear and keeps the count, never the text', async () => {
		const { board } = fakeApp()
		expect(await writeFromBackground('hunter2', 30_000, { now: 1_000 })).toBe(true)
		expect(board.text).toBe('hunter2')
		expect(await browser.alarms.get(CLIPBOARD_ALARM)).toMatchObject({ scheduledTime: 31_000 })
		expect(await browser.storage.session.get(null)).toEqual({ [CLIPBOARD_COUNT_KEY]: 8 })
	})

	it('clears what Keepiq wrote when nothing was copied since', async () => {
		const { board, send } = fakeApp()
		await writeFromBackground('hunter2', 30_000)
		await clearClipboard()
		expect(send).toHaveBeenLastCalledWith('nl.conduction.keepiq.safari', { kind: 'clipboard.clear', changeCount: 8 })
		expect(board.text).toBe('')
		expect(await storedCount()).toBeUndefined()
	})

	it('leaves what the user copied since', async () => {
		const warn = vi.spyOn(console, 'warn')
		const { board, copyElsewhere } = fakeApp()
		await writeFromBackground('hunter2', 30_000)
		copyElsewhere('a sentence')
		await clearClipboard()
		expect(board.text).toBe('a sentence')
		expect(await storedCount()).toBeUndefined()
		expect(warn).not.toHaveBeenCalled()
	})

	it('clears unconditionally without a stored count, as after the popup\'s fallback', async () => {
		const { board, send } = fakeApp()
		await writeFromBackground('hunter2', 30_000)
		await scheduleClipboardClear(30_000)
		expect(await storedCount()).toBeUndefined()
		await clearClipboard()
		expect(send).toHaveBeenLastCalledWith('nl.conduction.keepiq.safari', { kind: 'clipboard.clear' })
		expect(board.text).toBe('')
	})

	it('keeps the popup\'s count with the alarm', async () => {
		await scheduleClipboardClear(30_000, 1_000, 12)
		expect(await storedCount()).toBe(12)
		expect(await browser.alarms.get(CLIPBOARD_ALARM)).toBeDefined()
	})

	it('answers false and leaves the earlier clear and count alone when the app refuses', async () => {
		vi.spyOn(console, 'warn').mockImplementation(() => {})
		await scheduleClipboardClear(30_000, 1_000, 5)
		vi.spyOn(browser.runtime, 'sendNativeMessage').mockResolvedValue({ ok: false, error: 'write_failed' } as never)
		expect(await writeFromBackground('hunter2', 10_000, { now: 5_000 })).toBe(false)
		expect(await browser.alarms.get(CLIPBOARD_ALARM)).toMatchObject({ scheduledTime: 31_000 })
		expect(await storedCount()).toBe(5)
	})

	it('answers false but schedules a clear without a count when the app does not answer in time', async () => {
		vi.spyOn(console, 'warn').mockImplementation(() => {})
		await scheduleClipboardClear(60_000, 1_000, 5)
		vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
		vi.spyOn(browser.runtime, 'sendNativeMessage').mockReturnValue(new Promise(() => {}) as never)
		const written = writeFromBackground('hunter2', 30_000, { now: 2_000, codeOf: 'a' })
		await vi.advanceTimersByTimeAsync(NATIVE_TIMEOUT_MS)
		expect(await written).toBe(false)
		expect(await browser.alarms.get(CLIPBOARD_ALARM)).toMatchObject({ scheduledTime: 32_000 })
		expect(await storedCount()).toBeUndefined()
		expect((await browser.storage.session.get(CLIPBOARD_CODE_KEY))[CLIPBOARD_CODE_KEY]).toBeUndefined()
	})

	it.each([
		['a malformed count', { ok: true, changeCount: 'eight' }],
		['no ok', { changeCount: 8 }],
		['nothing', undefined],
	])('answers false but schedules a clear without a count on a reply with %s', async (_, reply) => {
		vi.spyOn(console, 'warn').mockImplementation(() => {})
		await scheduleClipboardClear(60_000, 1_000, 5)
		vi.spyOn(browser.runtime, 'sendNativeMessage').mockResolvedValue(reply as never)
		expect(await writeFromBackground('hunter2', 30_000, { now: 2_000 })).toBe(false)
		expect(await browser.alarms.get(CLIPBOARD_ALARM)).toMatchObject({ scheduledTime: 32_000 })
		expect(await storedCount()).toBeUndefined()
	})

	it('answers false and leaves the earlier clear alone when the app cannot be reached', async () => {
		vi.spyOn(console, 'warn').mockImplementation(() => {})
		await scheduleClipboardClear(30_000, 1_000, 5)
		vi.spyOn(browser.runtime, 'sendNativeMessage').mockRejectedValue(new Error('No native host'))
		expect(await writeFromBackground('hunter2', 10_000, { now: 5_000 })).toBe(false)
		expect(await browser.alarms.get(CLIPBOARD_ALARM)).toMatchObject({ scheduledTime: 31_000 })
		expect(await storedCount()).toBe(5)
	})

	it('runs a clear that fires during a copy before or after it, never between the write and its count', async () => {
		const { board, send } = fakeApp()
		await writeFromBackground('first', 30_000)
		let release!: () => void
		const answered = new Promise<void>((resolve) => (release = resolve))
		const reply = send.getMockImplementation() as (app: string, message: object) => Promise<unknown>
		send.mockImplementationOnce(async (app: string, message: object) => {
			await answered
			return reply(app, message)
		})
		const writing = writeFromBackground('second', 30_000)
		const clearing = clearClipboard()
		await new Promise((resolve) => setTimeout(resolve, 0))
		release()
		expect(await writing).toBe(true)
		await clearing
		// The clear waited for the write, then used the first copy's count: the second copy stays, with its count.
		expect(send.mock.calls.at(-1)?.[1]).toEqual({ kind: 'clipboard.clear', changeCount: 8 })
		expect(board.text).toBe('second')
		expect(await storedCount()).toBe(9)
	})

	it('only logs when the clear fails', async () => {
		const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
		vi.spyOn(browser.runtime, 'sendNativeMessage').mockRejectedValue(new Error('No native host'))
		await expect(clearClipboard()).resolves.toBeUndefined()
		expect(warn).toHaveBeenCalled()
	})

	it('clears a copied code at its account\'s lock, unless something was copied since', async () => {
		const { board, copyElsewhere } = fakeApp()
		await writeFromBackground('287082', 120_000, { codeOf: 'a' })
		await clearCodeAfterLock(async () => false)
		expect(board.text).toBe('')
		await writeFromBackground('287082', 120_000, { codeOf: 'a' })
		copyElsewhere('a sentence')
		await clearCodeAfterLock(async () => false)
		expect(board.text).toBe('a sentence')
	})
})

describe('Chrome and Firefox', () => {
	it.each(['chrome', 'firefox'])('%s neither messages an app nor keeps a count', async (target) => {
		vi.stubEnv('BROWSER', target)
		const { execCommand } = fakeCopyCommand()
		const send = vi.spyOn(browser.runtime, 'sendNativeMessage')
		expect(await writeFromBackground('hunter2', 30_000)).toBe(true)
		await scheduleClipboardClear(30_000, Date.now(), 8)
		await clearClipboard()
		expect(execCommand).toHaveBeenCalledTimes(2)
		expect(send).not.toHaveBeenCalled()
		expect(await storedCount()).toBeUndefined()
	})
})
