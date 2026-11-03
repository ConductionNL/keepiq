// @vitest-environment happy-dom
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { BAR_RADIUS_PX, type CaptureStateReply, type NotificationToBackground, type WriteErrorCode, type WriteResult } from '@/src/messages'
import { setLocale } from '@/src/testing/i18n'
import { startNotificationBar } from './bar'

// The page's own markup, without the script happy-dom would try to load.
const BODY = /<body>([\s\S]*)<\/body>/.exec(readFileSync(resolve('entrypoints/notification/index.html'), 'utf8'))![1]!.replace(/<script[\s\S]*?<\/script>/, '')
const STYLES = readFileSync(resolve('entrypoints/notification/notification.css'), 'utf8')

const KEY = 'bar-key'
const SECOND_KEY = 'second-key'
const GUARD_MS = 500
const NOT_VISIBLE = 'Keepiq can\'t do this here because this page changes how it is shown. Close this and add the login from the Keepiq button in the toolbar.'
const FOLDERS = [{ id: 'f-work', name: 'Work' }, { id: 'f-home', name: 'Home' }]
const add = (): CaptureStateReply => ({ mode: 'add', host: 'login.example.test', folders: FOLDERS, theme: 'system' })
const update = (extra: Partial<CaptureStateReply> = {}): CaptureStateReply =>
	({ mode: 'update', host: 'login.example.test', itemName: 'Example mail', username: 'alice', folders: FOLDERS, theme: 'system', ...extra })
const failure = (code: WriteErrorCode): WriteResult<null> => ({ ok: false, error: { code } })
const OK: WriteResult<null> = { ok: true, value: null }

let sent: NotificationToBackground[]
let stateReply: unknown
let writeReply: unknown
let dismissReply: unknown
let stop: (() => void) | undefined

beforeEach(() => {
	vi.useFakeTimers({ now: 1_000_000 })
	document.body.innerHTML = BODY
	delete document.documentElement.dataset.theme
	sent = []
	stateReply = add()
	writeReply = OK
	dismissReply = null
	vi.spyOn(browser.runtime, 'sendMessage').mockImplementation((async (message: NotificationToBackground) => {
		sent.push(message)
		// SECOND_KEY is valid too, as if the background had shown a second bar; this one must keep its first.
		if (message.kind === 'capture.state') return message.key === KEY || message.key === SECOND_KEY ? stateReply : null
		if (message.kind === 'capture.dismiss') return dismissReply
		return writeReply
	}) as never)
})

afterEach(() => {
	stop?.()
	stop = undefined
	vi.useRealTimers()
	vi.unstubAllGlobals()
})

const flush = () => vi.advanceTimersByTimeAsync(0)
const wait = (ms: number) => vi.advanceTimersByTimeAsync(ms)

function post(data: unknown, source: unknown = window.parent) {
	window.dispatchEvent(new MessageEvent('message', { data, source: source as Window }))
}

async function open(reply: unknown = add(), { settle = true } = {}) {
	stateReply = reply
	stop = startNotificationBar()
	post({ kind: 'capture.init', key: KEY })
	await flush()
	if (settle) await wait(GUARD_MS)
}

const bar = () => document.getElementById('bar')!
const status = () => document.querySelector('[role="status"]')!
const buttons = () => [...bar().querySelectorAll('button')]
const button = (name: string) => buttons().find((b) => (b.getAttribute('aria-label') ?? b.textContent) === name)!
/** As shown at full height, where the expand control is hidden. */
const buttonNames = () => buttons().filter((b) => !b.classList.contains('bar__expand')).map((b) => b.getAttribute('aria-label') ?? b.textContent)
const select = () => bar().querySelector('select')
const title = () => bar().querySelector<HTMLElement>('.bar__title')
const error = () => bar().querySelector<HTMLElement>('.bar__error')
const notice = () => bar().querySelector<HTMLElement>('.bar__notice')
const kinds = () => sent.map((m) => m.kind)
const decisions = () => sent.filter((m) => m.kind !== 'capture.state')

function press(target: Element, x = 10, y = 20, which = 0) {
	target.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true, button: which, clientX: x, clientY: y }))
}

async function click(target: Element, x = 10, y = 20, which = 0) {
	press(target, x, y, which)
	target.dispatchEvent(new MouseEvent('click', { bubbles: true, button: which, clientX: x, clientY: y }))
	await flush()
}

function key(target: Element, name: string, init: KeyboardEventInit = {}) {
	const event = new KeyboardEvent('keydown', { key: name, bubbles: true, cancelable: true, ...init })
	target.dispatchEvent(event)
	return event
}

describe('key hand-off', () => {
	it('presents the key from its parent and renders the reply', async () => {
		await open()
		expect(sent[0]).toEqual({ kind: 'capture.state', key: KEY })
		expect(button('Save')).toBeTruthy()
	})

	it('ignores a key posted by any window but its parent', async () => {
		stop = startNotificationBar()
		post({ kind: 'capture.init', key: KEY }, { postMessage() {} })
		post({ kind: 'capture.init', key: KEY }, null)
		await flush()
		expect(sent).toEqual([])
		expect(bar().children).toHaveLength(0)
	})

	it('tries a false key the page posted first, then takes the real one', async () => {
		stop = startNotificationBar()
		post({ kind: 'capture.init', key: 'planted' })
		await flush()
		expect(bar().children).toHaveLength(0)
		post({ kind: 'capture.init', key: KEY })
		await flush()
		expect(sent).toEqual([{ kind: 'capture.state', key: 'planted' }, { kind: 'capture.state', key: KEY }])
		expect(button('Save')).toBeTruthy()
	})

	it('keeps the first accepted key, and sends it with every decision', async () => {
		stop = startNotificationBar()
		post({ kind: 'capture.init', key: KEY })
		post({ kind: 'capture.init', key: SECOND_KEY })
		await flush()
		post({ kind: 'capture.init', key: 'another' })
		await flush()
		await wait(GUARD_MS)
		await click(button('Save'))
		expect(kinds()).toEqual(['capture.state', 'capture.state', 'capture.save'])
		expect(decisions().map((m) => m.key)).toEqual([KEY])
	})

	it('ignores malformed messages and commands without its key', async () => {
		await open()
		post('capture.focus')
		post(null)
		post({ kind: 'capture.focus', key: 'planted' })
		post({ kind: 'capture.focus' })
		await flush()
		expect(document.activeElement).toBe(document.body)
	})

	it.each([['add', add, () => select()], ['update', update, () => button('Update')]])('focuses its first control on capture.focus in %s mode', async (_mode, reply, first) => {
		await open(reply())
		post({ kind: 'capture.focus', key: KEY })
		expect(document.activeElement).toBe(first())
	})

	it('applies a focus that arrived before the state', async () => {
		stop = startNotificationBar()
		post({ kind: 'capture.init', key: KEY })
		post({ kind: 'capture.focus', key: KEY })
		await flush()
		expect(document.activeElement).toBe(select())
	})

	it('shows nothing when the background refuses, answers nonsense or cannot be reached', async () => {
		for (const reply of [null, { mode: 'add' }, { ...update(), itemName: undefined }, { ...update(), username: 3 }, { ...add(), folders: [{ id: 1 }] }, { ...add(), theme: 'pink' }, 'add']) {
			document.body.innerHTML = BODY
			await open(reply)
			expect(bar().children).toHaveLength(0)
			stop!()
		}
		vi.mocked(browser.runtime.sendMessage).mockRejectedValue(new Error('no receiver'))
		document.body.innerHTML = BODY
		await open()
		expect(bar().children).toHaveLength(0)
	})

	it('registers no runtime message listener', async () => {
		const addListener = vi.spyOn(browser.runtime.onMessage, 'addListener')
		await open()
		expect(addListener).not.toHaveBeenCalled()
	})

	it('follows the theme in the reply, and the mirror before it', async () => {
		localStorage.setItem('keepiq.theme', 'light')
		stop = startNotificationBar()
		expect(document.documentElement.dataset.theme).toBe('light')
		stop()
		localStorage.removeItem('keepiq.theme')
		await open({ ...add(), theme: 'dark' })
		expect(document.documentElement.dataset.theme).toBe('dark')
	})
})

describe('add mode', () => {
	it('asks to save for the host, with a labelled folder choice defaulting to No folder, Never for this site, Save and Close', async () => {
		await open()
		expect(title()!.textContent).toBe('Save login for login.example.test to Keepiq?')
		expect([...select()!.options].map((o) => o.textContent)).toEqual(['No folder', 'Work', 'Home'])
		expect(select()!.selectedOptions[0]!.textContent).toBe('No folder')
		expect(buttonNames()).toEqual(['Never for this site', 'Save', 'Close'])
		expect(button('Save').className).toBe('btn btn--primary')
		expect(button('Never for this site').className).toBe('btn btn--secondary')
		expect(bar().querySelector('input')).toBeNull()
	})

	it('saves to No folder as null, with the press', async () => {
		await open()
		await click(button('Save'), 30, 40)
		expect(decisions()).toEqual([{ kind: 'capture.save', key: KEY, folderId: null, input: { kind: 'pointer', at: 1_000_500, x: 30, y: 40 } }])
	})

	it('saves to the chosen folder', async () => {
		await open()
		select()!.value = 'f-home'
		await click(button('Save'))
		expect(decisions()).toMatchObject([{ kind: 'capture.save', folderId: 'f-home' }])
	})

	it('sends Never for this site as a dismiss that excludes the site, with the press', async () => {
		await open()
		await click(button('Never for this site'), 5, 6)
		expect(decisions()).toEqual([{ kind: 'capture.dismiss', key: KEY, never: true, input: { kind: 'pointer', at: 1_000_500, x: 5, y: 6 } }])
	})

	it('sends one decision while one is pending, and shows the pressed button busy', async () => {
		await open()
		let answer!: (reply: unknown) => void
		writeReply = new Promise((resolve) => {
			answer = resolve
		})
		await click(button('Save'))
		await click(button('Save'))
		await click(button('Never for this site'))
		key(document.body, 'Escape')
		await flush()
		expect(kinds()).toEqual(['capture.state', 'capture.save'])
		expect(button('Save').getAttribute('aria-busy')).toBe('true')
		answer(failure('offline'))
		await flush()
		expect(button('Save').hasAttribute('aria-busy')).toBe(false)
	})
})

describe('update mode', () => {
	it.each<[Partial<CaptureStateReply>, string]>([
		[{}, 'Update password for Example mail (alice)?'],
		[{ username: undefined }, 'Update password for Example mail?'],
		[{ tabHost: 'shop.test' }, 'Update password for Example mail (alice) on login.example.test?'],
		[{ tabHost: 'shop.test', username: undefined }, 'Update password for Example mail on login.example.test?'],
	])('asks %j as %s, with Update and Close only', async (extra, text) => {
		await open(update(extra))
		expect(title()!.textContent).toBe(text)
		expect(buttonNames()).toEqual(['Update', 'Close'])
		expect(select()).toBeNull()
	})

	it('sends the update with the press', async () => {
		await open(update())
		await click(button('Update'), 7, 8)
		expect(decisions()).toEqual([{ kind: 'capture.update', key: KEY, input: { kind: 'pointer', at: 1_000_500, x: 7, y: 8 } }])
	})

	describe('item row', () => {
		const row = () => bar().querySelector<HTMLElement>('.bar__item')
		const lines = () => [...row()!.querySelectorAll('.bar__item-text > span')].map((line) => [line.className, line.textContent])
		/** The stylesheet hides the row then (stylesheet tests). */
		const gaveWay = () => bar().classList.contains('bar--long-question')

		it('shows the item under the question, its username under its name, after an icon, hidden from screen readers', async () => {
			await open(update())
			expect(row()!.getAttribute('aria-hidden')).toBe('true')
			expect(gaveWay()).toBe(false)
			expect(row()!.querySelector('.bar__item-icon > svg')).toBeTruthy()
			expect(lines()).toEqual([['bar__item-name', 'Example mail'], ['bar__item-user', 'alice']])
			expect([...bar().children].map((child) => child.getAttribute('class'))).toEqual(['logo', 'bar__text', 'bar__item', 'bar__buttons', 'icon-btn bar__expand', 'icon-btn bar__close'])
		})

		it('shows the name alone for an item without a username', async () => {
			await open(update({ username: undefined }))
			expect(lines()).toEqual([['bar__item-name', 'Example mail']])
		})

		it('loads no icon from anywhere', async () => {
			await open(update())
			expect(row()!.querySelectorAll('img, image, use, [href], [src]')).toHaveLength(0)
		})

		it('is not in add mode', async () => {
			await open()
			expect(row()).toBeNull()
		})

		it('stays beside an error', async () => {
			await open(update())
			writeReply = failure('offline')
			await click(button('Update'))
			expect(error()!.hidden).toBe(false)
			expect(row()).toBeTruthy()
		})

		it('gives its room to a question that needs it, and takes it back once the question fits', async () => {
			let questionLines = 4
			// Four lines fit only without the row.
			vi.spyOn(HTMLElement.prototype, 'scrollHeight', 'get').mockImplementation(function (this: HTMLElement) {
				return this.classList.contains('bar__text') ? questionLines * 20 : 0
			})
			vi.spyOn(HTMLElement.prototype, 'clientHeight', 'get').mockImplementation(function (this: HTMLElement) {
				return this.classList.contains('bar__text') ? (gaveWay() ? 82 : 40) : 0
			})
			const text = () => bar().querySelector<HTMLElement>('.bar__text')!
			await open(update())
			expect(gaveWay()).toBe(true)
			expect(text().hasAttribute('tabindex')).toBe(false)
			questionLines = 2
			window.dispatchEvent(new Event('resize'))
			expect(gaveWay()).toBe(false)
			questionLines = 6
			window.dispatchEvent(new Event('resize'))
			expect(gaveWay()).toBe(true)
			expect(text().tabIndex).toBe(0)
		})

		it('takes its room back for an error that fits beside it', async () => {
			vi.spyOn(HTMLElement.prototype, 'scrollHeight', 'get').mockImplementation(function (this: HTMLElement) {
				return this.classList.contains('bar__text') ? (error()!.hidden ? 60 : 36) : 0
			})
			vi.spyOn(HTMLElement.prototype, 'clientHeight', 'get').mockImplementation(function (this: HTMLElement) {
				return this.classList.contains('bar__text') ? 40 : 0
			})
			await open(update())
			expect(gaveWay()).toBe(true)
			writeReply = failure('offline')
			await click(button('Update'))
			expect(gaveWay()).toBe(false)
		})
	})
})

describe('close', () => {
	it.each([[add], [update]])('sends a dismiss that keeps the site, without a press, once', async (reply) => {
		await open(reply())
		button('Close').click()
		await flush()
		button('Close').click()
		await flush()
		expect(decisions()).toEqual([{ kind: 'capture.dismiss', key: KEY, never: false }])
	})

	it('needs no guard', async () => {
		await open(add(), { settle: false })
		button('Close').click()
		await flush()
		expect(decisions()).toEqual([{ kind: 'capture.dismiss', key: KEY, never: false }])
	})

	it('lets Close be pressed again when the background was not reached', async () => {
		await open()
		vi.mocked(browser.runtime.sendMessage).mockRejectedValueOnce(new Error('no receiver'))
		button('Close').click()
		await flush()
		button('Close').click()
		await flush()
		expect(vi.mocked(browser.runtime.sendMessage)).toHaveBeenCalledTimes(3)
	})

	it.each([['a button', () => button('Save') as Element], ['the select', () => select()!], ['its body', () => document.body]])('Escape on %s acts as Close', async (_where, target) => {
		await open()
		const event = key(target(), 'Escape')
		await flush()
		expect(event.defaultPrevented).toBe(true)
		expect(decisions()).toEqual([{ kind: 'capture.dismiss', key: KEY, never: false }])
	})

	it('Escape does nothing before the state', async () => {
		stop = startNotificationBar()
		key(document.body, 'Escape')
		await flush()
		expect(sent).toEqual([])
	})

	it('Escape on the saved message closes the bar, which returns focus to the page', async () => {
		await open()
		button('Save').focus()
		key(button('Save'), 'Enter')
		await flush()
		const saved = bar().querySelector('.bar__saved')!
		expect(document.activeElement).toBe(saved)
		expect(key(saved, 'Escape').defaultPrevented).toBe(true)
		await flush()
		expect(decisions()).toEqual([expect.objectContaining({ kind: 'capture.save' }), { kind: 'capture.dismiss', key: KEY, never: false }])
	})
})

describe('guard', () => {
	it('ignores a click within 500 ms of the bar showing, without a word', async () => {
		await open(add(), { settle: false })
		await wait(GUARD_MS - 1)
		await click(button('Save'))
		await click(button('Never for this site'))
		expect(decisions()).toEqual([])
		expect(notice()).toBeNull()
		await wait(1)
		await click(button('Save'))
		expect(kinds()).toEqual(['capture.state', 'capture.save'])
	})

	it('measures from the press, not the release', async () => {
		await open(add(), { settle: false })
		await wait(GUARD_MS - 100)
		press(button('Save'))
		await wait(300)
		button('Save').dispatchEvent(new MouseEvent('click', { bubbles: true }))
		await flush()
		expect(decisions()).toEqual([])
	})

	it('restarts on capture.moved with its key only', async () => {
		await open()
		post({ kind: 'capture.moved', key: KEY })
		await wait(GUARD_MS - 1)
		await click(button('Save'))
		expect(decisions()).toEqual([])
		post({ kind: 'capture.moved', key: 'planted' })
		await wait(1)
		await click(button('Save'))
		expect(decisions()).toHaveLength(1)
	})

	it.each([['middle', 1], ['right', 2]])('acts on nothing with the %s button', async (_name, which) => {
		await open()
		await click(button('Save'), 10, 20, which)
		await click(button('Never for this site'), 10, 20, which)
		expect(decisions()).toEqual([])
	})

	it('acts on no click without its own press, such as a script\'s', async () => {
		await open()
		button('Save').click()
		press(button('Never for this site'))
		button('Save').dispatchEvent(new MouseEvent('click', { bubbles: true }))
		await flush()
		expect(decisions()).toEqual([])
	})

	it.each(['Enter', ' '])('acts on %j with the key time and the button centre', async (name) => {
		await open(update())
		vi.spyOn(button('Update'), 'getBoundingClientRect').mockReturnValue(new DOMRect(100, 16, 80, 32))
		const event = key(button('Update'), name)
		await flush()
		expect(event.defaultPrevented).toBe(true)
		expect(decisions()).toEqual([{ kind: 'capture.update', key: KEY, input: { kind: 'key', at: 1_000_500, x: 140, y: 32 } }])
	})

	it('ignores a held key and a key within 500 ms', async () => {
		await open(add(), { settle: false })
		key(button('Save'), 'Enter')
		await wait(GUARD_MS)
		key(button('Save'), 'Enter', { repeat: true })
		key(button('Never for this site'), ' ', { repeat: true })
		await flush()
		expect(decisions()).toEqual([])
	})

	it('blurs a focused button when its window loses focus, so a page cannot aim Enter at it', async () => {
		await open()
		button('Save').focus()
		window.dispatchEvent(new Event('blur'))
		expect(document.activeElement).toBe(document.body)
		key(document.body, 'Enter')
		await flush()
		expect(decisions()).toEqual([])
	})

	it('restarts after an error moves the buttons', async () => {
		await open()
		writeReply = failure('offline')
		await click(button('Save'))
		await wait(GUARD_MS - 1)
		await click(button('Save'))
		expect(decisions()).toHaveLength(1)
		await wait(1)
		await click(button('Save'))
		expect(decisions()).toHaveLength(2)
	})

	describe('refused as not visible', () => {
		it.each<[string, () => unknown, () => Promise<void>]>([
			['Save', () => (writeReply = { refused: 'not_visible' }), () => click(button('Save'))],
			['Update', () => (writeReply = { refused: 'not_visible' }), () => click(button('Update'))],
			['Never for this site', () => (dismissReply = { refused: 'not_visible' }), () => click(button('Never for this site'))],
		])('by the background on %s: says why, takes focus, and acts on nothing more', async (name, refuse, act) => {
			await open(name === 'Update' ? update() : add())
			refuse()
			await act()
			expect(notice()!.textContent).toBe(NOT_VISIBLE)
			expect(document.activeElement).toBe(notice())
			expect(buttonNames()).toEqual(['Close'])
			expect(bar().className).toBe('bar bar--notice')
			await wait(GUARD_MS)
			key(notice()!, 'Enter')
			await flush()
			expect(decisions()).toHaveLength(1)
			await wait(250)
			expect(status().textContent).not.toBe(NOT_VISIBLE)
		})

		it('still closes with Close and Escape', async () => {
			await open()
			writeReply = { refused: 'not_visible' }
			await click(button('Save'))
			key(notice()!, 'Escape')
			await flush()
			expect(decisions().at(-1)).toEqual({ kind: 'capture.dismiss', key: KEY, never: false })
		})

		it('says it in Dutch', async () => {
			setLocale('nl')
			await open()
			writeReply = { refused: 'not_visible' }
			await click(button('Opslaan'))
			expect(notice()!.textContent).toBe('Keepiq kan dit hier niet doen, omdat deze pagina aanpast hoe ze wordt weergegeven. Sluit dit en voeg de login toe via Keepiq in de werkbalk.')
		})
	})

	describe('on Chrome', () => {
		let report: (visible: boolean) => void

		beforeEach(() => {
			vi.stubGlobal('IntersectionObserverEntry', class { get isVisible() { return false } })
			vi.stubGlobal('IntersectionObserver', class {
				constructor(callback: IntersectionObserverCallback, readonly init: IntersectionObserverInit & { trackVisibility?: boolean; delay?: number }) {
					expect(init).toMatchObject({ trackVisibility: true, delay: 100 })
					report = (visible) => callback([{ isIntersecting: true, isVisible: visible } as never], this as never)
				}

				observe() {}
				disconnect() {}
			})
		})

		it('refuses until the browser reports the bar visible, and says why without asking the background', async () => {
			await open()
			await click(button('Save'))
			expect(decisions()).toEqual([])
			expect(document.activeElement).toBe(notice())
		})

		it('acts once the bar has been visible for 500 ms', async () => {
			await open(add(), { settle: false })
			report(true)
			await wait(GUARD_MS)
			await click(button('Save'))
			expect(decisions()).toHaveLength(1)
		})

		it.each([[GUARD_MS - 1, 0], [GUARD_MS, 1]])('after a not-visible report, a press %i ms after it shows again sends %i decisions', async (after, count) => {
			await open(add(), { settle: false })
			report(true)
			await wait(GUARD_MS * 2)
			report(false)
			await wait(10)
			report(true)
			await wait(after)
			await click(button('Never for this site'))
			expect(decisions()).toHaveLength(count)
		})
	})
})

describe('saved', () => {
	it('reads Login saved without the question or buttons, and asks for nothing more', async () => {
		await open()
		await click(button('Save'))
		expect(bar().textContent).toBe('Login saved')
		expect(buttons()).toEqual([])
		expect(bar().className).toBe('bar bar--saved')
		await wait(5000)
		expect(kinds()).toEqual(['capture.state', 'capture.save'])
	})

	it('reads Password updated after an update', async () => {
		await open(update())
		await click(button('Update'))
		expect(bar().textContent).toBe('Password updated')
	})

	it('announces it when focus was not in the bar', async () => {
		await open()
		await click(button('Save'))
		expect(status().textContent).toBe('')
		await wait(250)
		expect(status().textContent).toBe('Login saved')
	})

	it('moves focus from the removed button to the message, which then reads itself', async () => {
		await open()
		button('Save').focus()
		key(button('Save'), 'Enter')
		await flush()
		expect(document.activeElement).toBe(bar().querySelector('.bar__saved'))
		await wait(250)
		expect(status().textContent).not.toBe('Login saved')
	})
})

describe('busy', () => {
	it.each([['Save', add, 'Saving…'], ['Update', update, 'Updating…']] as const)('announces a slow %s after a second, and not a quick one', async (name, reply, text) => {
		await open(reply())
		writeReply = new Promise(() => {})
		await click(button(name))
		await wait(999)
		expect(status().textContent).not.toBe(text)
		await wait(251)
		expect(status().textContent).toBe(text)
		stop!()
		writeReply = OK
		await open(reply())
		await click(button(name))
		await wait(2000)
		expect(status().textContent).not.toBe(text)
	})
})

describe('errors', () => {
	it('puts the error in the question\'s place, keeps the question as the button\'s description, and keeps Save', async () => {
		await open()
		writeReply = failure('write_locked')
		await click(button('Save'))
		expect(error()!.hidden).toBe(false)
		expect(error()!.textContent).toBe('The vault is temporarily locked for changes. Try again later.')
		expect(title()!.hidden).toBe(true)
		const described = button('Save').getAttribute('aria-describedby')!.split(' ').map((id) => document.getElementById(id))
		expect(described).toEqual([error(), title()])
		expect(button('Save').disabled).toBe(false)
		expect(kinds()).not.toContain('capture.dismiss')
		writeReply = OK
		await wait(GUARD_MS)
		await click(button('Save'))
		expect(bar().textContent).toBe('Login saved')
	})

	it.each<[WriteErrorCode, string]>([
		['unverified', 'Edit this login in the Keepiq popup or web app to change its password.'],
		['view_only', 'This login is shared with you to view only, so its password can\'t be updated here.'],
		['locked', 'Keepiq is locked. Unlock it from the Keepiq button in the toolbar, then try again.'],
		['offline', 'Could not reach the server'],
		['server_error', 'Keepiq is unavailable right now, try again later'],
		['policy', 'Your organisation\'s policy does not allow this change'],
		['session_revoked', 'Session revoked, please log in again'],
		['partner_unreachable', 'Something went wrong'],
		['account_changed', 'You switched accounts, so nothing was saved.'],
		['key_mismatch', 'Keepiq\'s key does not match your vault. Nothing was saved; tell your administrator.'],
		['stale_source', 'The original of this shared login changed. Nothing was saved; try again.'],
		['incomplete', 'The people this login is shared with kept changing. Nothing was saved; try again.'],
		['bad_certificate', 'A recipient\'s encryption key could not be read. Nothing was saved.'],
	])('words an update failing with %s', async (code, text) => {
		await open(update())
		writeReply = failure(code)
		await click(button('Update'))
		expect(error()!.textContent).toBe(text)
		expect(button('Update')).toBeTruthy()
	})

	it('names the version for an outdated Keepiq', async () => {
		await open()
		writeReply = failure('keepiq_outdated')
		await click(button('Save'))
		expect(error()!.textContent).toMatch(/^Your Keepiq server is too old\. Nothing was saved; ask your administrator for Keepiq \d+\.\d+\.\d+ or later\.$/)
	})

	it('says when the background cannot be reached, and when it has no capture any more', async () => {
		await open()
		vi.mocked(browser.runtime.sendMessage).mockRejectedValueOnce(new Error('no receiver'))
		await click(button('Save'))
		expect(error()!.textContent).toBe('Keepiq is not responding. Try again.')
		writeReply = null
		await wait(GUARD_MS)
		await click(button('Save'))
		expect(error()!.textContent).toBe('Something went wrong')
	})

	it('announces the error and keeps focus on the button', async () => {
		await open()
		await wait(250)
		writeReply = failure('write_locked')
		button('Save').focus()
		key(button('Save'), 'Enter')
		await flush()
		expect(document.activeElement).toBe(button('Save'))
		await wait(250)
		expect(status().textContent).toBe('The vault is temporarily locked for changes. Try again later.')
	})

	describe('item deleted meanwhile', () => {
		async function gone() {
			await open(update())
			writeReply = failure('not_found')
			button('Update').focus()
			key(button('Update'), 'Enter')
			await flush()
		}

		it('says so and offers Save with the folder choice instead, focus on Save', async () => {
			await gone()
			expect(error()!.textContent).toBe('This login no longer exists. Save it as a new login?')
			expect(buttonNames()).toEqual(['Never for this site', 'Save', 'Close'])
			expect(select()).toBeTruthy()
			expect(bar().querySelector('.bar__item')).toBeNull()
			expect(document.activeElement).toBe(button('Save'))
		})

		it('then saves to the chosen folder after the guard and reads Login saved', async () => {
			await gone()
			writeReply = OK
			select()!.value = 'f-work'
			await click(button('Save'))
			expect(decisions()).toHaveLength(1)
			await wait(GUARD_MS)
			await click(button('Save'))
			expect(decisions().at(-1)).toMatchObject({ kind: 'capture.save', key: KEY, folderId: 'f-work' })
			expect(bar().textContent).toBe('Login saved')
		})

		it('on Save, names the folder instead', async () => {
			await open()
			writeReply = failure('not_found')
			await click(button('Save'))
			expect(error()!.textContent).toBe('This folder no longer exists')
			expect(buttonNames()).toEqual(['Never for this site', 'Save', 'Close'])
		})
	})
})

describe('accessibility', () => {
	it('is a region named Keepiq notification', async () => {
		await open()
		expect(bar().getAttribute('role')).toBe('region')
		expect(bar().getAttribute('aria-label')).toBe('Keepiq notification')
		expect(document.title).toBe('Keepiq notification')
	})

	it('takes no focus when it shows', async () => {
		await open()
		await wait(1000)
		expect(document.activeElement).toBe(document.body)
	})

	it('announces its question 250 ms after it shows', async () => {
		await open(update(), { settle: false })
		expect(status().textContent).toBe('')
		await wait(249)
		expect(status().textContent).toBe('')
		await wait(1)
		expect(status().textContent).toBe('Update password for Example mail (alice)?')
	})

	it('labels the folder select with visible text', async () => {
		await open()
		const label = document.querySelector<HTMLElement>(`label[for="${select()!.id}"]`)!
		expect(label.textContent).toBe('Folder')
		expect(label.classList.contains('sr-only')).toBe(false)
	})

	it('names Close and hides its icons', async () => {
		await open()
		expect(button('Close').textContent).toBe('')
		expect([...bar().querySelectorAll('svg')].every((icon) => icon.closest('[aria-hidden="true"]'))).toBe(true)
	})

	it('reaches every control with Tab, in reading order', async () => {
		await open()
		// The expand control is `display: none` outside compact mode (stylesheet tests).
		const tabbable = [...bar().querySelectorAll<HTMLElement>('button:not(.bar__expand), select, [tabindex]')].filter((el) => el.tabIndex >= 0)
		expect(tabbable.map((el) => el.tagName === 'SELECT' ? 'select' : el.getAttribute('aria-label') ?? el.textContent)).toEqual(['select', 'Never for this site', 'Save', 'Close'])
		expect(buttons().every((b) => b.type === 'button')).toBe(true)
	})
})

describe('shortcut', () => {
	it.each<[string, string | undefined, string]>([
		['en', 'Alt+Shift+K', 'Save login for login.example.test to Keepiq? Press Alt+Shift+K to answer.'],
		['en', undefined, 'Save login for login.example.test to Keepiq?'],
		['nl', 'Alt+Shift+K', 'Login voor login.example.test opslaan in Keepiq? Druk op Alt+Shift+K om te antwoorden.'],
	])('announces it in %s when it is %s', async (locale, shortcut, text) => {
		setLocale(locale as 'en' | 'nl')
		await open({ ...add(), ...(shortcut ? { shortcut } : {}) }, { settle: false })
		await wait(250)
		expect(status().textContent).toBe(text)
		expect(bar().textContent).not.toContain('Alt+Shift+K')
	})

	it('shows nothing for a shortcut that is not a string', async () => {
		await open({ ...add(), shortcut: 3 })
		expect(bar().children).toHaveLength(0)
	})
})

describe('compact', () => {
	const resize = (height: number) => {
		vi.stubGlobal('innerHeight', height)
		window.dispatchEvent(new Event('resize'))
	}
	const compact = () => document.documentElement.classList.contains('is-compact')
	const expands = () => sent.filter((m) => m.kind === 'capture.expand')
	const expandControl = () => bar().querySelector<HTMLButtonElement>('.bar__expand')!

	beforeEach(() => vi.stubGlobal('innerHeight', 40))

	it('follows the host\'s height, compact at exactly 40 px', async () => {
		await open()
		expect(compact()).toBe(true)
		resize(128)
		expect(compact()).toBe(false)
		resize(41)
		expect(compact()).toBe(false)
		resize(40)
		expect(compact()).toBe(true)
	})

	it('has a named expand control, and keeps the whole question in the page for screen readers', async () => {
		await open(update())
		expect(expandControl().getAttribute('aria-label')).toBe('Show options')
		expect(expandControl().getAttribute('aria-expanded')).toBe('false')
		expect(title()!.textContent).toBe('Update password for Example mail (alice)?')
	})

	it('asks to expand once when it gets focus, and again after the host shrank it', async () => {
		await open()
		expect(expands()).toEqual([])
		button('Close').focus()
		expandControl().focus()
		await flush()
		expect(expands()).toEqual([{ kind: 'capture.expand', key: KEY }])
		resize(128)
		resize(40)
		expandControl().focus()
		await flush()
		expect(expands()).toHaveLength(2)
	})

	it.each([
		['a pointer press', () => press(title()!)],
		['a key', () => key(document.body, 'ArrowDown')],
	])('asks to expand on %s', async (_name, act) => {
		await open()
		act()
		await flush()
		expect(expands()).toEqual([{ kind: 'capture.expand', key: KEY }])
	})

	it('closes on Escape rather than expanding', async () => {
		await open()
		key(document.body, 'Escape')
		await flush()
		expect(kinds()).toEqual(['capture.state', 'capture.dismiss'])
	})

	it('asks again when the background was not reached', async () => {
		await open()
		vi.mocked(browser.runtime.sendMessage).mockRejectedValueOnce(new Error('no receiver'))
		press(title()!)
		await flush()
		press(title()!)
		await flush()
		expect(vi.mocked(browser.runtime.sendMessage).mock.calls.map(([m]) => (m as unknown as NotificationToBackground).kind)).toEqual(['capture.state', 'capture.expand', 'capture.expand'])
	})

	it('asks nothing at full height', async () => {
		vi.stubGlobal('innerHeight', 64)
		await open()
		button('Save').focus()
		press(button('Save'))
		key(button('Save'), 'ArrowDown')
		await flush()
		expect(expands()).toEqual([])
	})

	it('focuses the expand control on capture.focus, then the first control once grown', async () => {
		await open()
		post({ kind: 'capture.focus', key: KEY })
		expect(document.activeElement).toBe(expandControl())
		await flush()
		expect(expands()).toHaveLength(1)
		resize(128)
		expect(document.activeElement).toBe(select())
	})

	it('keeps focus where it is when it grows with focus on Close', async () => {
		await open()
		button('Close').focus()
		resize(128)
		expect(document.activeElement).toBe(button('Close'))
	})

	it('names the expand control in Dutch', async () => {
		setLocale('nl')
		await open()
		expect(expandControl().getAttribute('aria-label')).toBe('Opties tonen')
	})
})

describe('Dutch', () => {
	beforeEach(() => setLocale('nl'))

	it('asks to save in Dutch', async () => {
		await open()
		expect(document.documentElement.lang).toBe('nl')
		expect(bar().getAttribute('aria-label')).toBe('Keepiq-melding')
		expect(title()!.textContent).toBe('Login voor login.example.test opslaan in Keepiq?')
		expect(document.querySelector('label')!.textContent).toBe('Map')
		expect(select()!.options[0]!.textContent).toBe('Geen map')
		expect(buttonNames()).toEqual(['Nooit voor deze site', 'Opslaan', 'Sluiten'])
	})

	it.each<[Partial<CaptureStateReply>, string]>([
		[{}, 'Wachtwoord van Example mail (alice) bijwerken?'],
		[{ username: undefined }, 'Wachtwoord van Example mail bijwerken?'],
		[{ tabHost: 'shop.test' }, 'Wachtwoord van Example mail (alice) op login.example.test bijwerken?'],
		[{ tabHost: 'shop.test', username: undefined }, 'Wachtwoord van Example mail op login.example.test bijwerken?'],
	])('asks %j as %s', async (extra, text) => {
		await open(update(extra))
		expect(title()!.textContent).toBe(text)
	})

	it('confirms and words the errors in Dutch', async () => {
		await open(update())
		writeReply = failure('locked')
		await click(button('Bijwerken'))
		expect(error()!.textContent).toBe('Keepiq is vergrendeld. Ontgrendel het met de Keepiq-knop in de werkbalk en probeer het daarna opnieuw.')
		writeReply = OK
		await wait(GUARD_MS)
		await click(button('Bijwerken'))
		expect(bar().textContent).toBe('Wachtwoord bijgewerkt')
	})
})

describe('stylesheet', () => {
	const POPUP_STYLES = readFileSync(resolve('entrypoints/popup/popup.css'), 'utf8')
	const rules = () => [...STYLES.replace(/\/\*[\s\S]*?\*\//g, '').matchAll(/([^{}]+)\{([^{}]*)\}/g)]
	const rule = (selector: string, css = STYLES) => new RegExp(`(?:^|\\n)\\s*${selector.replace(/[.[\]()]/g, '\\$&')}\\s*\\{([^}]*)\\}`).exec(css)?.[1] ?? ''

	/** Custom properties per selector, so the dark media block and the pinned themes compare separately. */
	function tokens(css: string): Map<string, Map<string, string>> {
		const blocks = new Map<string, Map<string, string>>()
		for (const [, selector, body] of css.replace(/\/\*[\s\S]*?\*\//g, '').matchAll(/([^{}]+)\{([^{}]*)\}/g)) {
			const found = new Map([...body!.matchAll(/(--[\w-]+):\s*([^;]+);/g)].map(([, name, value]) => [name!, value!.trim()]))
			if (found.size > 0) blocks.set(selector!.trim(), found)
		}
		return blocks
	}

	it('copies the popup tokens unchanged, in light and both dark blocks', () => {
		const popup = tokens(POPUP_STYLES)
		const copied = tokens(STYLES)
		expect([...copied.keys()].sort()).toEqual([':root', ':root:not([data-theme=\'light\'])', ':root[data-theme=\'dark\']'])
		for (const [selector, values] of copied) {
			for (const [name, value] of values) expect({ selector, name, value }).toEqual({ selector, name, value: popup.get(selector)?.get(name) })
		}
		expect([...copied.get(':root[data-theme=\'dark\']')!.keys()].sort()).toEqual([...copied.get(':root:not([data-theme=\'light\'])')!.keys()].sort())
	})

	it('paints nothing over the bar with a positioned pseudo-element', () => {
		const overlays = rules().filter(([, selector, body]) => /::?(before|after)/.test(selector!) && /position:\s*(fixed|absolute)/.test(body!))
		expect(overlays.map(([, selector]) => selector!.trim())).toEqual([])
	})

	it('has one layout for every card width, which the grid fits', () => {
		expect(STYLES).not.toMatch(/@media[^{]*width/)
	})

	it('gives the question the room the folder or the item row and the buttons leave, the same in both modes', () => {
		expect(rule('.bar')).toMatch(/grid-template-rows: minmax\(0, max-content\) auto auto;/)
		expect(rule('.bar')).toMatch(/'logo text close'\s*'detail detail detail'\s*'buttons buttons buttons'/)
		expect(rule('.bar__folder')).toMatch(/grid-area: detail;/)
		expect(rule('.bar__item')).toMatch(/grid-area: detail;/)
		const height = (selector: string) => /(?:^|\s)height: (\d+)px/.exec(rule(selector))![1]
		expect(height('.bar__item')).toBe(height('.select'))
		// A question too long to share the room leaves out the row and its gap.
		expect(rule('.bar.bar--long-question')).toMatch(/grid-template-rows: minmax\(0, max-content\) auto;\s*grid-template-areas:\s*'logo text close'\s*'buttons buttons buttons';/)
		expect(rule('.bar--long-question .bar__item')).toMatch(/display: none/)
	})

	it('pins the question and Close to the top, and splits what a short question leaves between the gaps', () => {
		expect(rule('.bar')).toMatch(/align-items: start;/)
		expect(rule('.bar')).toMatch(/align-content: space-between;/)
		// The logo no taller than the question's first line, which sets the row's height for a short question.
		expect(rule('.bar__title')).toMatch(/line-height: 20px;/)
		expect(rule('.logo')).toMatch(/margin-block: -2px;/)
	})

	it('lines the item\'s name up with the question, and cuts a long name or username off with an ellipsis', () => {
		const logoWidth = /width: '(\d+)'/.exec(readFileSync(resolve('entrypoints/notification/bar.ts'), 'utf8').split('function logo')[1]!)![1]
		expect(rule('.bar__item-icon')).toContain(`width: ${logoWidth}px;`)
		expect(rule('.bar__item')).toMatch(/gap: 10px;/)
		expect(rule('.bar')).toMatch(/column-gap: 10px;/)
		expect(rule('.bar__item')).toMatch(/min-width: 0;/)
		expect(rule('.bar__item-text')).toMatch(/min-width: 0;/)
		const line = rule('.bar__item-name,\n.bar__item-user')
		expect(line).toMatch(/overflow-x: clip;/)
		expect(line).toMatch(/white-space: nowrap;/)
		expect(line).toMatch(/text-overflow: ellipsis;/)
		expect(STYLES).toMatch(/\n\.bar__item-user \{[^}]*color: var\(--fg-muted\)/)
	})

	it('gives the notice one row from the top, and the saved message one row in the middle', () => {
		expect(rule('.bar.bar--notice')).toMatch(/grid-template-rows: minmax\(0, max-content\);\s*grid-template-areas: 'logo text close';\s*align-content: start;/)
		expect(rule('.bar.bar--saved')).toMatch(/grid-template-rows: minmax\(0, 1fr\);\s*grid-template-areas: 'logo text';/)
		expect(rule('.bar.bar--saved')).toMatch(/align-items: center;/)
	})

	it('shows the expand control only compact, where it hides the folder, the item row and the buttons and puts the question on one line', () => {
		expect(rule('.bar__expand')).toMatch(/display: none/)
		expect(rule(':root.is-compact .bar__expand')).toMatch(/display: inline-flex/)
		expect(rule(':root.is-compact :is(.bar__folder, .bar__item, .bar__buttons)')).toMatch(/display: none/)
		const line = rule(':root.is-compact :is(.bar__title, .bar__error, .bar__notice)')
		expect(line).toMatch(/white-space: nowrap/)
		expect(line).toMatch(/text-overflow: ellipsis/)
		expect(rule(':root.is-compact .bar,\n:root.is-compact .bar.bar--saved,\n:root.is-compact .bar.bar--notice')).toMatch(/grid-template-areas: 'logo text expand close'/)
	})

	it('puts the compact layout after every other, so it wins', () => {
		const selectors = rules().map(([, selector]) => selector!.trim())
		const firstCompact = selectors.findIndex((selector) => selector.includes('is-compact'))
		expect(selectors.slice(firstCompact).every((selector) => selector.includes('is-compact'))).toBe(true)
		expect(STYLES.lastIndexOf('@media')).toBeLessThan(STYLES.indexOf('is-compact'))
	})

	it('draws its edge in --fg-muted, round as the host clips it, while the bar still loads', () => {
		const body = rules().find(([, selector]) => selector!.trim() === 'body')![2]!
		expect(body).toMatch(/border: 1px solid var\(--fg-muted\)/)
		expect(body).toContain(`border-radius: ${BAR_RADIUS_PX}px;`)
		expect(body).toMatch(/box-sizing: border-box/)
	})

	it('shows a busy button in its own box', () => {
		const busy = rule('.btn[aria-busy=\'true\']::after')
		expect(busy).toMatch(/animation: spin/)
		expect(busy).not.toMatch(/position/)
	})

	it('keeps every control at least 24 px tall and the icon button 24 px wide', () => {
		for (const [selector, body] of rules()) {
			if (!/\.(btn|icon-btn|select)\b(?!\[aria-busy)/.test(selector!)) continue
			for (const [, size] of body!.matchAll(/(?:^|[\s;])(?:min-)?(?:height|width):\s*(\d+)px/g)) {
				expect(Number(size), selector!.trim()).toBeGreaterThanOrEqual(24)
			}
		}
	})

	it('shows focus on every control', () => {
		expect(STYLES).toMatch(/:focus-visible\s*\{[^}]*outline: 2px solid var\(--accent\)/)
		expect(STYLES).toMatch(/\.select:focus-within\s*\{[^}]*outline: 2px solid var\(--accent\)/)
	})

	it.each<[string, () => unknown]>([
		['saved', () => (writeReply = OK)],
		['notice', () => (writeReply = { refused: 'not_visible' })],
	])('draws no ring on the %s message the script focuses, also after a key press', async (_name, reply) => {
		// Above `:focus-visible` in specificity, so a key press before the focus cannot bring the ring back.
		expect(rule('.bar__text[tabindex="-1"]:focus')).toMatch(/outline: none/)
		expect(STYLES).not.toMatch(/\.bar__(saved|notice)[^{]*\{[^}]*outline/)
		await open()
		reply()
		button('Save').focus()
		key(button('Save'), 'Enter')
		await flush()
		expect(document.activeElement!.matches('.bar__text[tabindex="-1"]:focus')).toBe(true)
	})

	it('fills the frame the host sizes', () => {
		expect(STYLES).toMatch(/html,\s*body\s*\{[^}]*height: 100%/)
		expect(rule('.bar')).toMatch(/height: 100%/)
	})
})
