// @vitest-environment happy-dom
// @vitest-environment-options {"settings":{"navigation":{"disableChildFrameNavigation":true}}}
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fakeBrowser } from 'wxt/testing/fake-browser'
import { INLINE_MAX_HEIGHT_PX, INLINE_MIN_HEIGHT_PX, inlineClampHeight, inlineHeight, type ContentToBackground, type PickInput } from '@/src/messages'
import { executeFill } from './execute'
import type { FrameHost } from './frame-host'
import { createInlineHost, GUARD_MS, READY_TIMEOUT_MS, sameOriginAncestors, type InlineHost, type InlineHostOptions } from './inline-host'

const hosts = vi.hoisted(() => [] as FrameHost[])
vi.mock('./frame-host', async (importOriginal) => {
	const actual = await importOriginal<typeof import('./frame-host')>()
	return {
		...actual,
		createFrameHost: (...args: Parameters<typeof actual.createFrameHost>) => {
			const host = actual.createFrameHost(...args)
			// happy-dom fires a load of its own on insertion; only the tests' loads stand for the page's.
			host.frame.addEventListener('load', (event) => {
				if (!(event as Event & { real?: boolean }).real) event.stopImmediatePropagation()
			})
			vi.spyOn(host, 'post').mockImplementation(() => {})
			hosts.push(host)
			return host
		},
	}
})

const KEY = 'a'.repeat(32)
const FIVE_ROWS = inlineHeight(5)
const LOGIN = `
	<form id="first"><input id="u" name="username"><input id="p" type="password"><button>Log in</button></form>
	<form id="second"><input id="u2" name="username"><input id="p2" type="password"><button>Log in</button></form>`
const SIGN_UP = '<form><input id="u" name="username"><input id="p" type="password" autocomplete="new-password"><input id="c" type="password" autocomplete="new-password"><button>Create account</button></form>'

interface Box { top: number; left: number; width: number; height: number }

const rects = new Map<string, Box>()
let viewport: { width: number; height: number }
/** How the browser draws the host compared to what was set: a page zoom or transform scales it. */
let hostScale: number
let hit: Element | null
let trusted: boolean
let request: ReturnType<typeof vi.fn<InlineHostOptions['request']>>
let sent: ContentToBackground[]
let inline: InlineHost

const $ = <T extends Element = HTMLInputElement>(selector: string) => document.querySelector(selector) as T
const host = () => hosts.at(-1)!
const posted = () => vi.mocked(host().post).mock.calls.map(([message]) => message)
const isOpen = () => hosts.length > 0 && host().element.isConnected
const px = (style: string, name: string) => Number(new RegExp(`(?:^|; )${name}: ([\\d.-]+)px`).exec(style)?.[1] ?? Number.NaN)
const hostBox = (): Box => ({ top: px(host().written, 'top'), left: px(host().written, 'left'), width: px(host().written, 'width'), height: px(host().written, 'height') })

function domRect({ top, left, width, height }: Box): DOMRect {
	return { x: left, y: top, top, left, width, height, right: left + width, bottom: top + height, toJSON: () => ({}) } as DOMRect
}

function trustedEvent<T extends Event>(event: T, isTrusted: boolean): T {
	Object.defineProperty(event, 'isTrusted', { value: isTrusted })
	return event
}

const key = (target: Element, name: string, init: KeyboardEventInit = {}, isTrusted = true) =>
	target.dispatchEvent(trustedEvent(new KeyboardEvent('keydown', { key: name, bubbles: true, cancelable: true, composed: true, ...init }), isTrusted))

/** Each test's host, so the next test can take its listeners off the shared document again. */
let added: Array<() => void> = []

function track(target: Document | Window) {
	const add = target.addEventListener.bind(target)
	vi.spyOn(target, 'addEventListener').mockImplementation((type: string, listener: EventListenerOrEventListenerObject, options?: boolean | AddEventListenerOptions) => {
		add(type, listener, options)
		added.push(() => target.removeEventListener(type, listener, options))
	})
}

function start(html = LOGIN, options: Partial<InlineHostOptions> = {}) {
	document.body.innerHTML = html
	inline = createInlineHost({ request, send: (message) => void sent.push(message), firefox: false, ...options })
	inline.start()
}

const load = () => host().frame.dispatchEvent(Object.assign(new Event('load'), { real: true }))

/** Lets `inline.open` answer. */
const settle = () => vi.advanceTimersByTimeAsync(0)

/** Focuses the field, then the iframe loads and the background accepts the list. */
async function openOn(selector = '#u') {
	$(selector).focus()
	await settle()
	load()
	inline.handle({ kind: 'inline.ready', key: KEY })
}

/** Long enough for the guard to have seen the list visible. */
const shown = () => vi.advanceTimersByTime(GUARD_MS + 50)
const press = (overrides: Partial<PickInput> = {}): PickInput => ({ kind: 'pointer', at: Date.now(), x: 20, y: 20, ...overrides })
const verify = (input = press()) => inline.handle({ kind: 'inline.verify', key: KEY, input })

/** happy-dom has neither popovers nor `:popover-open` and `:modal`. */
const popoverOpen = new WeakSet<Element>()
const modal = new WeakSet<Element>()

function installPopover() {
	const proto = HTMLElement.prototype as unknown as Record<string, unknown>
	const toggle = (element: HTMLElement, newState: string) => {
		element.dispatchEvent(new Event('beforetoggle'))
		if (newState === 'open') popoverOpen.add(element)
		else popoverOpen.delete(element)
		setTimeout(() => element.dispatchEvent(Object.assign(new Event('toggle'), { newState, oldState: newState === 'open' ? 'closed' : 'open' })), 0)
	}
	const matches = Element.prototype.matches
	vi.spyOn(Element.prototype, 'matches').mockImplementation(function (this: Element, selector: string) {
		if (selector === ':popover-open') return popoverOpen.has(this)
		if (selector === ':modal') return modal.has(this)
		return matches.call(this, selector)
	})
	proto.showPopover = function (this: HTMLElement) {
		toggle(this, 'open')
	}
	proto.hidePopover = function (this: HTMLElement) {
		toggle(this, 'closed')
	}
}

beforeEach(() => {
	fakeBrowser.reset()
	vi.useFakeTimers()
	hosts.length = 0
	document.head.innerHTML = ''
	document.documentElement.removeAttribute('style')
	rects.clear()
	rects.set('u', { top: 100, left: 100, width: 200, height: 30 })
	rects.set('p', { top: 140, left: 100, width: 200, height: 30 })
	rects.set('u2', { top: 400, left: 100, width: 200, height: 30 })
	rects.set('p2', { top: 440, left: 100, width: 200, height: 30 })
	viewport = { width: 1024, height: 768 }
	hostScale = 1
	hit = null
	trusted = true
	sent = []
	request = vi.fn(async () => ({ key: KEY, height: FIVE_ROWS }))
	installPopover()
	vi.spyOn(window, 'innerWidth', 'get').mockImplementation(() => viewport.width)
	vi.spyOn(window, 'innerHeight', 'get').mockImplementation(() => viewport.height)
	// happy-dom leaves `isTrusted` undefined; browsers mark every event they dispatch, script focus included.
	Object.defineProperty(Event.prototype, 'isTrusted', { get: () => trusted, configurable: true })
	vi.spyOn(Element.prototype, 'getBoundingClientRect').mockImplementation(function (this: Element) {
		if (hosts.some((h) => h.element === this)) {
			const box = hostBox()
			return domRect({ top: box.top * hostScale, left: box.left * hostScale, width: box.width * hostScale, height: box.height * hostScale })
		}
		const box = rects.get(this.id) ?? (this.tagName === 'BUTTON' ? { top: 180, left: 100, width: 80, height: 30 } : undefined)
		return domRect(box ?? { top: 0, left: 0, width: 0, height: 0 })
	})
	vi.spyOn(document, 'elementFromPoint').mockImplementation(() => hit ?? host().element)
	track(document)
	track(window)
})

afterEach(() => {
	for (const remove of added) remove()
	added = []
	for (const h of hosts) h.remove()
	const proto = HTMLElement.prototype as unknown as Record<string, unknown>
	delete proto.showPopover
	delete proto.hidePopover
	delete (Event.prototype as { isTrusted?: boolean }).isTrusted
	vi.useRealTimers()
})

describe('opening', () => {
	it('opens beneath an empty login field on focus, keeping focus and caret in the field', async () => {
		start()
		await openOn('#u')
		expect(request).toHaveBeenCalledExactlyOnceWith({ kind: 'inline.open' })
		expect(host().element.parentNode).toBe(document.documentElement)
		expect(host().element.getAttribute('popover')).toBe('manual')
		expect(hostBox()).toEqual({ top: 134, left: 100, width: 240, height: FIVE_ROWS })
		expect(document.activeElement).toBe($('#u'))
	})

	it('opens on the password field too, and on a field focused before the content script started', async () => {
		document.body.innerHTML = LOGIN
		$('#p').focus()
		inline = createInlineHost({ request, send: (message) => void sent.push(message), firefox: false })
		inline.start()
		await settle()
		expect(isOpen()).toBe(true)
		expect(hostBox().top).toBe(174)
	})

	it('opens on any focus of the empty field, script focus included, but not for one the page only claims', async () => {
		start()
		$('#u').dispatchEvent(trustedEvent(new FocusEvent('focusin', { bubbles: true }), false))
		await settle()
		expect(request).not.toHaveBeenCalled()
		trusted = true
		$('#u').focus()
		await settle()
		expect(request).toHaveBeenCalledOnce()
	})

	it('opens no list on focus of a filled field, and does on a trusted click in it', async () => {
		start()
		$('#u').value = 'alice'
		$('#u').focus()
		await settle()
		expect(request).not.toHaveBeenCalled()
		$('#u').dispatchEvent(trustedEvent(new MouseEvent('click', { bubbles: true }), false))
		await settle()
		expect(request).not.toHaveBeenCalled()
		$('#u').dispatchEvent(new MouseEvent('click', { bubbles: true }))
		await settle()
		expect(isOpen()).toBe(true)
	})

	it.each([
		['the username of a sign-up form', SIGN_UP, '#u'],
		['a new-password field', SIGN_UP, '#p'],
		['a confirm field', SIGN_UP, '#c'],
		['a newsletter field', '<form><input id="u" type="email" name="email"><button>Subscribe</button></form>', '#u'],
		['a one-time code field', '<form><input id="u" name="code" inputmode="numeric" maxlength="6" autocomplete="one-time-code"><button>Verify</button></form>', '#u'],
		['a disabled password field', '<form><input id="u" name="username"><input id="p" type="password" disabled><button>Log in</button></form>', '#p'],
	])('opens nothing on %s', async (_, html, selector) => {
		start(html)
		$(selector).focus()
		$(selector).dispatchEvent(new MouseEvent('click', { bubbles: true }))
		await settle()
		expect(request).not.toHaveBeenCalled()
	})

	it('opens on a login form with autocomplete off', async () => {
		start('<form autocomplete="off"><input id="u" name="username" autocomplete="off"><input id="p" type="password"><button>Log in</button></form>')
		await openOn('#u')
		expect(isOpen()).toBe(true)
	})

	it('inserts nothing when the background answers null', async () => {
		request.mockResolvedValue(null)
		start()
		$('#u').focus()
		await settle()
		expect(hosts).toEqual([])
	})

	it('opens nothing on a frame that is not http(s)', async () => {
		const location = vi.spyOn(window, 'location', 'get').mockReturnValue({ ...window.location, protocol: 'file:' } as Location)
		start()
		location.mockRestore()
		$('#u').focus()
		await settle()
		expect(request).not.toHaveBeenCalled()
	})

	it('drops a reply that arrives after focus left the field for nowhere', async () => {
		let answer: (reply: unknown) => void = () => {}
		request.mockImplementation(() => new Promise((resolve) => (answer = resolve)))
		start()
		$('#u').focus()
		$('#u').blur()
		answer({ key: KEY, height: FIVE_ROWS })
		await settle()
		expect(hosts).toEqual([])
		expect(sent).toEqual([{ kind: 'inline.closed', key: KEY }])
	})

	it('drops a reply that arrives after focus moved on, telling the background', async () => {
		let answer: (reply: unknown) => void = () => {}
		request.mockImplementation(() => new Promise((resolve) => (answer = resolve)))
		start()
		$('#u').focus()
		$<HTMLButtonElement>('button').focus()
		answer({ key: KEY, height: FIVE_ROWS })
		await settle()
		expect(hosts).toEqual([])
		expect(sent).toContainEqual({ kind: 'inline.closed', key: KEY })
	})

	it.each([{ key: KEY }, { key: KEY, height: '260' }, { key: KEY, height: Number.NaN }])('opens nothing for a reply without a usable height, telling the background: %o', async (reply) => {
		request.mockResolvedValue(reply)
		start()
		$('#u').focus()
		await settle()
		expect(hosts).toEqual([])
		expect(sent).toEqual([{ kind: 'inline.closed', key: KEY }])
	})
})

describe('size and position', () => {
	it('sizes the list by what it shows: up to five rows, and one row for a message', () => {
		expect([1, 3, 5, 7].map(inlineHeight)).toEqual([60, 160, 260, 260])
		expect(inlineHeight(0)).toBe(inlineHeight(1))
		expect([INLINE_MIN_HEIGHT_PX, INLINE_MAX_HEIGHT_PX]).toEqual([60, 260])
		expect([inlineClampHeight(10), inlineClampHeight(130.2), inlineClampHeight(900), inlineClampHeight('130'), inlineClampHeight(Number.POSITIVE_INFINITY)]).toEqual([60, 131, 260, null, null])
	})

	it.each([1, 3, 5, 7])('is as tall as the background said for %i rows', async (rows) => {
		request.mockResolvedValue({ key: KEY, height: inlineHeight(rows) })
		start()
		await openOn('#u')
		expect(hostBox()).toEqual({ top: 134, left: 100, width: 240, height: inlineHeight(rows) })
	})

	it('is placed above a field near the bottom, never over it', async () => {
		rects.set('u', { top: 658, left: 100, width: 200, height: 30 })
		start()
		await openOn('#u')
		expect(hostBox()).toEqual({ top: 658 - 4 - FIVE_ROWS, left: 100, width: 240, height: FIVE_ROWS })
	})

	it('shrinks to the larger free side in a small frame, and is as wide as a wide field', async () => {
		viewport = { width: 1024, height: 200 }
		rects.set('u', { top: 20, left: 10, width: 500, height: 30 })
		start()
		await openOn('#u')
		expect(hostBox()).toEqual({ top: 54, left: 10, width: 500, height: 146 })
	})

	it('opens a list of a single row where only one row fits, whatever it holds', async () => {
		viewport = { width: 400, height: 140 }
		rects.set('u', { top: 45, left: 10, width: 200, height: 30 })
		start()
		await openOn('#u')
		expect(hostBox()).toMatchObject({ top: 79, height: 61 })
	})

	it('opens nothing in a frame too small for one row, whatever the vault holds', async () => {
		viewport = { width: 400, height: 120 }
		rects.set('u', { top: 45, left: 10, width: 200, height: 30 })
		start()
		$('#u').focus()
		await settle()
		expect(request).not.toHaveBeenCalled()
	})

	it('opens nothing in a frame narrower than 240 px plus the margins', async () => {
		viewport = { width: 250, height: 768 }
		rects.set('u', { top: 45, left: 0, width: 200, height: 30 })
		start()
		$('#u').focus()
		await settle()
		expect(request).not.toHaveBeenCalled()
	})

	it('keeps its height whatever the frame does after it opened', async () => {
		viewport = { width: 1024, height: 200 }
		rects.set('u', { top: 20, left: 100, width: 200, height: 30 })
		start()
		await openOn('#u')
		const { height } = hostBox()
		viewport = { width: 1024, height: 768 }
		rects.set('u', { top: 300, left: 100, width: 200, height: 30 })
		vi.advanceTimersByTime(20)
		expect(hostBox()).toMatchObject({ top: 334, height })
	})

	it('follows the field and tells the list it moved', async () => {
		start()
		await openOn('#u')
		rects.set('u', { top: 80, left: 120, width: 200, height: 30 })
		vi.advanceTimersByTime(20)
		expect(hostBox()).toMatchObject({ top: 114, left: 120 })
		expect(posted()).toEqual([{ kind: 'inline.init', key: KEY }, { kind: 'inline.moved', key: KEY }])
		expect(isOpen()).toBe(true)
	})

	it('closes once the field is scrolled fully out of view, or no side fits it any more', async () => {
		start()
		await openOn('#u')
		rects.set('u', { top: -40, left: 100, width: 200, height: 30 })
		vi.advanceTimersByTime(20)
		expect(isOpen()).toBe(false)
		expect(sent).toEqual([{ kind: 'inline.closed', key: KEY }])
		await openOn('#u2')
		vi.advanceTimersByTime(20)
		expect(isOpen()).toBe(true)
		// Five rows were fixed at opening; 186 px below and 196 px above hold neither.
		rects.set('u2', { top: 200, left: 100, width: 200, height: 30 })
		viewport = { width: 1024, height: 420 }
		vi.advanceTimersByTime(20)
		expect(isOpen()).toBe(false)
	})

	it('closes when the field is removed or hidden', async () => {
		start()
		await openOn('#u')
		$('#u').style.visibility = 'hidden'
		vi.advanceTimersByTime(20)
		expect(isOpen()).toBe(false)
		$('#u').style.visibility = ''
		$('#u').blur()
		await openOn('#u')
		$('#u').remove()
		vi.advanceTimersByTime(20)
		expect(isOpen()).toBe(false)
	})

	it('draws its rounded corners and shadow on the host, whose box is exactly the list', async () => {
		start()
		await openOn('#u')
		expect(host().written).toContain('border-radius: 10px !important;')
		expect(host().written).toMatch(/box-shadow: [^;]+ !important;/)
		expect(host().written).toContain('overflow: clip !important;')
		expect(host().written).toContain('background: transparent !important;')
	})

	it('falls back to a fixed host with the highest z-index where popovers are missing', async () => {
		const proto = HTMLElement.prototype as unknown as Record<string, unknown>
		delete proto.showPopover
		start()
		await openOn('#u')
		expect(host().element.hasAttribute('popover')).toBe(false)
		expect(host().written).toContain('z-index: 2147483647 !important;')
		expect(host().written).toContain('position: fixed !important;')
		expect(host().written).toContain('transform: none !important;')
		expect(host().written).not.toMatch(/translate\(|scale\(/)
	})
})

describe('inline.resize', () => {
	const resize = (height: unknown, key = KEY) => inline.handle({ kind: 'inline.resize', key, height } as never)

	it('grows for content that needs it, as a move: the list hears inline.moved and a pick within 500 ms is refused', async () => {
		request.mockResolvedValue({ key: KEY, height: inlineHeight(1) })
		start()
		await openOn('#u')
		shown()
		expect(verify()).toBe(true)
		resize(130)
		expect(hostBox()).toEqual({ top: 134, left: 100, width: 240, height: 130 })
		expect(posted()).toEqual([{ kind: 'inline.init', key: KEY }, { kind: 'inline.moved', key: KEY }])
		expect(verify()).toBe(false)
		vi.advanceTimersByTime(GUARD_MS - 50)
		expect(verify()).toBe(false)
		shown()
		expect(verify()).toBe(true)
		expect(isOpen()).toBe(true)
	})

	it('goes above the field when only that side has room, and back below when it shrinks', async () => {
		rects.set('u', { top: 500, left: 100, width: 200, height: 30 })
		request.mockResolvedValue({ key: KEY, height: inlineHeight(1) })
		start()
		await openOn('#u')
		expect(hostBox()).toMatchObject({ top: 534, height: 60 })
		resize(260)
		expect(hostBox()).toMatchObject({ top: 500 - 4 - 260, height: 260 })
		resize(60)
		expect(hostBox()).toMatchObject({ top: 534, height: 60 })
	})

	it('grows no further than the larger free side', async () => {
		viewport = { width: 1024, height: 250 }
		rects.set('u', { top: 20, left: 100, width: 200, height: 30 })
		request.mockResolvedValue({ key: KEY, height: inlineHeight(1) })
		start()
		await openOn('#u')
		resize(260)
		expect(hostBox()).toMatchObject({ top: 54, height: 196 })
	})

	it('ignores one for another key or without a number, and clamps one out of bounds', async () => {
		start()
		await openOn('#u')
		resize(100, 'b'.repeat(32))
		resize('100')
		resize(Number.NaN)
		expect(hostBox().height).toBe(FIVE_ROWS)
		expect(posted()).toEqual([{ kind: 'inline.init', key: KEY }])
		resize(9999)
		expect(hostBox().height).toBe(FIVE_ROWS)
		resize(1)
		expect(hostBox().height).toBe(INLINE_MIN_HEIGHT_PX)
	})
})

describe('in a modal dialog', () => {
	const DIALOG = `<dialog id="d" open><form><input id="u" name="username"><input id="p" type="password"><button>Log in</button></form></dialog>`

	it('lives inside the dialog, since everything outside it is inert', async () => {
		start(DIALOG)
		modal.add($('#d'))
		await openOn('#u')
		expect(host().element.parentNode).toBe($('#d'))
		shown()
		expect(verify()).toBe(true)
	})

	it('finds the dialog across shadow roots', async () => {
		start('<div id="app"></div>')
		const shadow = $('#app').attachShadow({ mode: 'open' })
		shadow.innerHTML = DIALOG
		const dialog = shadow.querySelector('dialog')!
		modal.add(dialog)
		rects.set('u', { top: 100, left: 100, width: 200, height: 30 })
		const field = shadow.querySelector<HTMLInputElement>('#u')!
		let hitInShadow: Element | null = null
		Object.defineProperty(shadow, 'elementFromPoint', { value: () => hitInShadow ?? host().element })
		vi.mocked(document.elementFromPoint).mockImplementation(() => $('#app'))
		field.focus()
		await settle()
		load()
		inline.handle({ kind: 'inline.ready', key: KEY })
		expect(host().element.parentNode).toBe(dialog)
		shown()
		expect(verify()).toBe(true)
		// Something over the host inside the shadow root is caught though the document hits its host.
		hitInShadow = field
		expect(verify()).toBe(false)
	})

	it('closes when the page moves the host out of the dialog', async () => {
		start(DIALOG)
		modal.add($('#d'))
		await openOn('#u')
		document.documentElement.append(host().element)
		await settle()
		expect(isOpen()).toBe(false)
	})

	it('closes when the page removes the dialog after moving the field out of it', async () => {
		start(DIALOG)
		modal.add($('#d'))
		await openOn('#u')
		document.body.append($('#u'))
		$('#u').focus()
		// The observer watches the dialog's children, not the dialog leaving; the next sample sees it.
		$('#d').remove()
		vi.advanceTimersByTime(20)
		expect(inline.openList()).toBeNull()
		expect(sent).toEqual([{ kind: 'inline.closed', key: KEY }])
	})

	const ARIA = '<div id="m" role="dialog" aria-modal="true"><form><input id="u" name="username"><input id="p" type="password"><button>Log in</button></form></div>'

	it('lives inside a script-trapped aria-modal, whose focus trap would pull focus out of it', async () => {
		start(ARIA)
		await openOn('#u')
		expect(host().element.parentNode).toBe($('#m'))
		shown()
		expect(verify()).toBe(true)
		document.documentElement.append(host().element)
		await settle()
		expect(inline.openList()).toBeNull()
	})

	it('prefers the nearest modal, aria or not', async () => {
		start(`<dialog id="d" open>${ARIA}</dialog>`)
		modal.add($('#d'))
		await openOn('#u')
		expect(host().element.parentNode).toBe($('#m'))
	})

	it('stays on <html> for an aria-modal without the popover API, whose styling the host would inherit', async () => {
		delete (HTMLElement.prototype as unknown as Record<string, unknown>).showPopover
		start(ARIA)
		await openOn('#u')
		expect(host().element.parentNode).toBe(document.documentElement)
	})

	it('stays on <html> for a dialog that is not modal', async () => {
		start(DIALOG)
		await openOn('#u')
		expect(host().element.parentNode).toBe(document.documentElement)
	})
})

describe('key hand-off', () => {
	it('posts the key only once the iframe loaded, and closes on its second load', async () => {
		start()
		$('#u').focus()
		await settle()
		expect(posted()).toEqual([])
		load()
		expect(posted()).toEqual([{ kind: 'inline.init', key: KEY }])
		load()
		expect(isOpen()).toBe(false)
		expect(posted()).toHaveLength(1)
		expect(sent).toEqual([{ kind: 'inline.closed', key: KEY }])
	})

	it('removes itself without inline.ready within 3 seconds of the load', async () => {
		start()
		$('#u').focus()
		await settle()
		vi.advanceTimersByTime(READY_TIMEOUT_MS + 1000)
		expect(isOpen()).toBe(true)
		load()
		vi.advanceTimersByTime(READY_TIMEOUT_MS - 10)
		expect(isOpen()).toBe(true)
		vi.advanceTimersByTime(20)
		expect(isOpen()).toBe(false)
	})

	it('stays once the background sent inline.ready, and ignores one for another key', async () => {
		start()
		$('#u').focus()
		await settle()
		load()
		inline.handle({ kind: 'inline.ready', key: 'b'.repeat(32) })
		inline.handle({ kind: 'inline.ready', key: KEY })
		vi.advanceTimersByTime(READY_TIMEOUT_MS + 10)
		expect(isOpen()).toBe(true)
	})
})

describe('keyboard', () => {
	it('relays Down after the field held focus for 500 ms, focusing the list', async () => {
		start()
		await openOn('#u')
		vi.advanceTimersByTime(GUARD_MS)
		const frameFocus = vi.spyOn(host().frame, 'focus')
		expect(key($('#u'), 'ArrowDown')).toBe(false)
		expect(frameFocus).toHaveBeenCalledOnce()
		expect(posted()).toContainEqual({ kind: 'inline.focus', key: KEY })
	})

	it('lets Down within 500 ms of script focus reach the page', async () => {
		start()
		await openOn('#u')
		vi.advanceTimersByTime(GUARD_MS - 100)
		expect(key($('#u'), 'ArrowDown')).toBe(true)
		expect(posted()).not.toContainEqual({ kind: 'inline.focus', key: KEY })
	})

	it('relays Down right after the user tabbed or clicked into the field', async () => {
		start()
		key(document.body, 'Tab')
		await openOn('#u')
		expect(key($('#u'), 'ArrowDown')).toBe(false)
		inline.handle({ kind: 'inline.close', key: KEY, refocus: false })
		$('#u').blur()
		$('#u').dispatchEvent(new Event('pointerdown', { bubbles: true }))
		await openOn('#u')
		expect(key($('#u'), 'ArrowDown')).toBe(false)
	})

	it('does not count a Tab the page cancelled to move focus itself', async () => {
		start()
		const cancel = (event: Event) => event.preventDefault()
		document.body.addEventListener('keydown', cancel)
		key(document.body, 'Tab')
		document.body.removeEventListener('keydown', cancel)
		await openOn('#u')
		expect(key($('#u'), 'ArrowDown')).toBe(true)
		expect(posted()).not.toContainEqual({ kind: 'inline.focus', key: KEY })
	})

	it('does not count a pointerdown the page dispatched', async () => {
		start()
		$('#u').dispatchEvent(trustedEvent(new Event('pointerdown', { bubbles: true }), false))
		await openOn('#u')
		$('#u').dispatchEvent(trustedEvent(new Event('pointerdown', { bubbles: true }), false))
		expect(key($('#u'), 'ArrowDown')).toBe(true)
	})

	it('lets a held-down Down repeat reach the page', async () => {
		start()
		await openOn('#u')
		shown()
		expect(key($('#u'), 'ArrowDown', { repeat: true })).toBe(true)
		expect(posted()).not.toContainEqual({ kind: 'inline.focus', key: KEY })
	})

	it('closes on Tab in the field, letting focus move on to the page’s next control', async () => {
		start()
		await openOn('#u')
		expect(key($('#u'), 'Tab')).toBe(true)
		expect(isOpen()).toBe(false)
		await openOn('#p')
		expect(key($('#p'), 'Tab', { shiftKey: true })).toBe(true)
		expect(isOpen()).toBe(false)
	})

	it('never relays a Down the page dispatched, or one with a modifier', async () => {
		start()
		await openOn('#u')
		shown()
		expect(key($('#u'), 'ArrowDown', {}, false)).toBe(true)
		expect(key($('#u'), 'ArrowDown', { altKey: true })).toBe(true)
		expect(posted()).not.toContainEqual({ kind: 'inline.focus', key: KEY })
	})

	it('closes on Escape in the field, consuming it, with focus and value unchanged', async () => {
		start()
		await openOn('#u')
		expect(key($('#u'), 'Escape')).toBe(false)
		expect(isOpen()).toBe(false)
		expect(document.activeElement).toBe($('#u'))
		expect($('#u').value).toBe('')
		expect(key($('#u'), 'Escape')).toBe(true)
	})

	it('passes other keys to the page and closes when the user types', async () => {
		start()
		await openOn('#u')
		expect(key($('#u'), 'a')).toBe(true)
		$('#u').dispatchEvent(trustedEvent(new Event('input', { bubbles: true }), false))
		expect(isOpen()).toBe(true)
		$('#u').dispatchEvent(new Event('input', { bubbles: true }))
		expect(isOpen()).toBe(false)
	})

	it('returns focus to the field on inline.refocus, the list staying open and not reopening', async () => {
		start()
		await openOn('#u')
		host().frame.focus()
		vi.advanceTimersByTime(1)
		expect(isOpen()).toBe(true)
		inline.handle({ kind: 'inline.refocus', key: KEY })
		expect(document.activeElement).toBe($('#u'))
		expect(isOpen()).toBe(true)
		expect(request).toHaveBeenCalledOnce()
		// Back from the list counts as the user's own move.
		expect(key($('#u'), 'ArrowDown')).toBe(false)
	})

	it('closes on inline.close, giving focus back to the field without reopening the list', async () => {
		start()
		await openOn('#u')
		host().frame.focus()
		inline.handle({ kind: 'inline.close', key: KEY, refocus: true })
		await settle()
		expect(isOpen()).toBe(false)
		expect(document.activeElement).toBe($('#u'))
		expect(request).toHaveBeenCalledOnce()
		expect(sent).toEqual([{ kind: 'inline.closed', key: KEY }])
		// The suppression does not outlive that one focus.
		$('#u').blur()
		$('#u').focus()
		await settle()
		expect(request).toHaveBeenCalledTimes(2)
	})

	it.each([
		['Unlock or a lock', { refocus: false }],
		['a pick', { refocus: true }],
	])('gives focus in the list back to the field when it closes for %s', async (_, { refocus }) => {
		start()
		await openOn('#u')
		host().frame.focus()
		vi.advanceTimersByTime(1)
		inline.handle({ kind: 'inline.close', key: KEY, refocus })
		expect(document.activeElement).toBe($('#u'))
		expect(request).toHaveBeenCalledOnce()
	})

	it('leaves focus where it is when it was not in the list', async () => {
		start()
		await openOn('#u')
		$('#u').blur()
		inline.handle({ kind: 'inline.close', key: KEY, refocus: false })
		expect(document.activeElement).not.toBe($('#u'))
	})

	it('ignores inline.close and inline.refocus for another key', async () => {
		start()
		await openOn('#u')
		inline.handle({ kind: 'inline.close', key: 'b'.repeat(32), refocus: true })
		expect(isOpen()).toBe(true)
	})
})

describe('closing', () => {
	it('closes when focus moves to another control or nowhere, and stays while focus is in the list', async () => {
		start()
		await openOn('#u')
		host().frame.focus()
		vi.advanceTimersByTime(1)
		expect(isOpen()).toBe(true)
		$('#u').focus()
		$('#p2').focus()
		expect(isOpen()).toBe(false)
		await openOn('#u')
		$('#u').blur()
		vi.advanceTimersByTime(1)
		expect(isOpen()).toBe(false)
	})

	it('closes when the tab is hidden, and opens again only on the next focus', async () => {
		start()
		await openOn('#u')
		const state = vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('hidden')
		document.dispatchEvent(new Event('visibilitychange'))
		state.mockRestore()
		expect(isOpen()).toBe(false)
		expect(request).toHaveBeenCalledOnce()
	})

	it('closes when the page leaves', async () => {
		start()
		await openOn('#u')
		window.dispatchEvent(new Event('pagehide'))
		expect(isOpen()).toBe(false)
	})
})

describe('pick', () => {
	it('fills only the form of the list’s field, and only while its list has the key', async () => {
		start()
		await openOn('#p2')
		trusted = false
		const fill = { kind: 'fill.execute', username: 'alice', password: 'hunter2', origin: location.origin, anchorKey: KEY } as const
		expect(executeFill({ ...fill }, inline.openList())).toEqual({ filled: 'both' })
		expect([$('#u2').value, $('#p2').value, $('#u').value, $('#p').value]).toEqual(['alice', 'hunter2', '', ''])
		expect(isOpen()).toBe(true)
		expect(executeFill({ ...fill, anchorKey: 'b'.repeat(32) }, inline.openList())).toEqual({ filled: 'none' })
		inline.handle({ kind: 'inline.close', key: KEY, refocus: true })
		expect(executeFill({ ...fill }, inline.openList())).toEqual({ filled: 'none' })
	})
})

describe('visibility guard', () => {
	it('confirms a pick on a list visible for 500 ms, at a point that hits the host', async () => {
		start()
		await openOn('#u')
		shown()
		expect(verify()).toBe(true)
		expect(document.elementFromPoint).toHaveBeenLastCalledWith(120, 154)
	})

	it('refuses the second click of a double-click on the field, landing as the list appears', async () => {
		start()
		await openOn('#u')
		vi.advanceTimersByTime(120)
		expect(verify()).toBe(false)
	})

	it('refuses a pick for another key, before inline.ready, outside the list, or while something else is hit', async () => {
		start()
		$('#u').focus()
		await settle()
		load()
		shown()
		expect(verify()).toBe(false)
		inline.handle({ kind: 'inline.ready', key: KEY })
		expect(inline.handle({ kind: 'inline.verify', key: 'b'.repeat(32), input: press() })).toBe(false)
		expect(verify(press({ x: 240 }))).toBe(false)
		expect(verify(press({ y: -1 }))).toBe(false)
		expect(verify({ kind: 'pointer', at: 'now' } as never)).toBe(false)
		hit = $('#u')
		expect(verify()).toBe(false)
		hit = null
		expect(verify()).toBe(true)
	})

	const ROOT_STYLES = [
		['opacity', '0'], ['filter', 'blur(0px)'], ['mix-blend-mode', 'multiply'], ['transform', 'scale(1)'],
		['clip-path', 'inset(0 0 0 0)'], ['mask-image', 'linear-gradient(black, black)'],
	]

	it('closes when the page root is visibility: hidden, which hides the field too', async () => {
		start()
		await openOn('#u')
		document.documentElement.style.visibility = 'hidden'
		vi.advanceTimersByTime(20)
		expect(inline.openList()).toBeNull()
	})

	describe('with a showing popover, which paints and takes clicks whatever its ancestors look like', () => {
		it.each(ROOT_STYLES)('accepts a pick while the page root has %s', async (property, value) => {
			start()
			await openOn('#u')
			document.documentElement.style.setProperty(property!, value!)
			shown()
			expect(verify()).toBe(true)
		})

		it.each(ROOT_STYLES.filter(([property]) => property !== 'filter'))('accepts a pick on Firefox while the page root has %s', async (property, value) => {
			start(LOGIN, { firefox: true, ancestors: () => [] })
			await openOn('#u')
			document.documentElement.style.setProperty(property!, value!)
			shown()
			expect(verify()).toBe(true)
		})

		it.each(['opacity(0)', 'invert(1)'])('refuses a pick on Firefox while the page root has filter: %s, which reaches its top layer', async (filter) => {
			start(LOGIN, { firefox: true, ancestors: () => [] })
			await openOn('#u')
			document.documentElement.style.filter = filter
			shown()
			expect(verify()).toBe(false)
			document.documentElement.style.filter = ''
			shown()
			expect(verify()).toBe(true)
		})

		it('accepts that root filter on Chrome, whose top layer it does not reach', async () => {
			start()
			await openOn('#u')
			document.documentElement.style.filter = 'invert(1)'
			shown()
			expect(verify()).toBe(true)
		})

		it('still refuses a pick when the host itself is translated, which only frame ancestors may be', async () => {
			start()
			await openOn('#u')
			const computed = window.getComputedStyle
			vi.spyOn(window, 'getComputedStyle').mockImplementation((element, pseudo) => {
				const style = computed(element, pseudo)
				if (element !== host().element || pseudo) return style
				const getPropertyValue = (name: string) => (name === 'translate' ? '10px' : style.getPropertyValue(name))
				return new Proxy(style, { get: (target, name) => (name === 'getPropertyValue' ? getPropertyValue : Reflect.get(target, name)) })
			})
			shown()
			expect(verify()).toBe(false)
		})

		it('accepts a pick in a modal dialog centred with a transform', async () => {
			start('<dialog id="d" open style="transform: translate(-50%, -50%)"><form><input id="u" name="username"><input id="p" type="password"><button>Log in</button></form></dialog>')
			modal.add($('#d'))
			await openOn('#u')
			shown()
			expect(verify()).toBe(true)
		})

		it('still refuses a pick when the host itself is styled however the page managed it', async () => {
			start()
			await openOn('#u')
			const computed = window.getComputedStyle
			vi.spyOn(window, 'getComputedStyle').mockImplementation((element, pseudo) => {
				const style = computed(element, pseudo)
				return element === host().element && !pseudo ? ({ ...style, opacity: '0', getPropertyValue: () => '' } as unknown as CSSStyleDeclaration) : style
			})
			shown()
			expect(verify()).toBe(false)
		})
	})

	describe('without the popover API (Firefox below 125), where the host is under its ancestors’ styling', () => {
		beforeEach(() => {
			delete (HTMLElement.prototype as unknown as Record<string, unknown>).showPopover
		})

		it.each(ROOT_STYLES)('refuses a pick while the page root has %s, closing nothing by itself', async (property, value) => {
			start()
			await openOn('#u')
			document.documentElement.style.setProperty(property!, value!)
			shown()
			expect(verify()).toBe(false)
			expect(isOpen()).toBe(true)
		})

		it('refuses a decoy restored when the field loses focus: the list was not visible in the 500 ms before the press', async () => {
			start()
			await openOn('#u')
			document.documentElement.style.opacity = '0'
			shown()
			const at = Date.now()
			// The press takes focus from the field; the page restores the opacity before the pick arrives.
			document.documentElement.style.opacity = ''
			vi.advanceTimersByTime(100)
			expect(verify(press({ at }))).toBe(false)
			// Visible from long enough before a later press.
			shown()
			expect(verify()).toBe(true)
		})
	})

	it('refuses a pick when the drawn rect differs from the one set, as a zoom or transform makes it', async () => {
		start()
		await openOn('#u')
		hostScale = 1.0015
		shown()
		expect(verify()).toBe(true)
		hostScale = 1.25
		shown()
		expect(verify()).toBe(false)
	})

	it('refuses a pick within 500 ms of the list moving', async () => {
		start()
		await openOn('#u')
		shown()
		rects.set('u', { top: 90, left: 100, width: 200, height: 30 })
		vi.advanceTimersByTime(100)
		expect(verify()).toBe(false)
		shown()
		expect(verify()).toBe(true)
	})

	it('refuses a pick when the page draws generated content from the host', async () => {
		start()
		await openOn('#u')
		const computed = window.getComputedStyle
		vi.spyOn(window, 'getComputedStyle').mockImplementation((element, pseudo) => {
			const style = computed(element, pseudo)
			return pseudo === '::after' && element === host().element ? ({ ...style, content: '"decoy"', getPropertyValue: () => '' } as CSSStyleDeclaration) : style
		})
		shown()
		expect(verify()).toBe(false)
	})

	it('treats a press time in the future as now', async () => {
		start()
		await openOn('#u')
		document.documentElement.style.opacity = '0'
		vi.advanceTimersByTime(100)
		document.documentElement.style.opacity = ''
		vi.advanceTimersByTime(20)
		expect(verify(press({ at: Date.now() + 10_000 }))).toBe(false)
	})

	describe('tampering closes the list', () => {
		it('when the page calls hidePopover(), or hides and shows it again', async () => {
			start()
			await openOn('#u')
			host().element.hidePopover()
			expect(isOpen()).toBe(false)
			expect(sent).toEqual([{ kind: 'inline.closed', key: KEY }])
			$('#u').blur()
			await openOn('#u')
			await settle()
			host().element.hidePopover()
			host().element.showPopover()
			expect(isOpen()).toBe(false)
		})

		it('when the page changes the host’s style or attributes, or removes it', async () => {
			start()
			await openOn('#u')
			host().element.style.setProperty('opacity', '0', 'important')
			await settle()
			expect(isOpen()).toBe(false)
			$('#u').blur()
			await openOn('#u')
			host().element.classList.add('x')
			await settle()
			expect(isOpen()).toBe(false)
			$('#u').blur()
			await openOn('#u')
			const element = host().element
			element.remove()
			await settle()
			expect(sent.at(-1)).toEqual({ kind: 'inline.closed', key: KEY })
			expect(element.isConnected).toBe(false)
		})

		it('when the page changes the host style and restores it before the next frame', async () => {
			start()
			await openOn('#u')
			const before = host().element.getAttribute('style')!
			host().element.setAttribute('style', `${before} opacity: 0 !important;`)
			host().element.setAttribute('style', before)
			await settle()
			expect(isOpen()).toBe(false)
		})

		it('when a toggle event the content script did not cause reaches the host', async () => {
			start()
			await openOn('#u')
			await settle()
			expect(isOpen()).toBe(true)
			host().element.dispatchEvent(Object.assign(new Event('toggle'), { newState: 'closed', oldState: 'open' }))
			expect(isOpen()).toBe(false)
		})

		it('when the pick arrives before the observer saw the page’s change', async () => {
			start()
			await openOn('#u')
			shown()
			host().element.setAttribute('style', `${host().written} outline: 0;`)
			expect(verify()).toBe(false)
			expect(isOpen()).toBe(false)
		})

		it('and refuses a pick when the host is drawn transparent however the page managed it', async () => {
			start()
			await openOn('#u')
			const computed = window.getComputedStyle
			vi.spyOn(window, 'getComputedStyle').mockImplementation((element, pseudo) => {
				const style = computed(element, pseudo)
				return element === host().element && !pseudo ? ({ ...style, opacity: '0.5', getPropertyValue: () => '' } as unknown as CSSStyleDeclaration) : style
			})
			shown()
			expect(verify()).toBe(false)
		})

		it('but not for a page stylesheet, which cannot reach it', async () => {
			start()
			await openOn('#u')
			document.head.innerHTML = '<style>html > div { opacity: 0 !important; transform: scale(2) !important; }</style>'
			shown()
			expect(isOpen()).toBe(true)
			expect(verify()).toBe(true)
		})

		it('but not for its own moves', async () => {
			start()
			await openOn('#u')
			rects.set('u', { top: 90, left: 100, width: 200, height: 30 })
			vi.advanceTimersByTime(20)
			await settle()
			expect(isOpen()).toBe(true)
		})
	})

	describe('on Chrome', () => {
		let report: (visible: boolean) => void

		beforeEach(() => {
			class TrackingObserver {
				constructor(callback: (entries: Array<{ isVisible: boolean }>) => void, readonly init: object) {
					report = (isVisible) => callback([{ isVisible }])
				}

				observe() {}
				disconnect() {}
			}
			vi.stubGlobal('IntersectionObserver', TrackingObserver)
			vi.stubGlobal('IntersectionObserverEntry', class { isVisible = false })
			Object.defineProperty(IntersectionObserverEntry.prototype, 'isVisible', { value: false, configurable: true })
		})

		afterEach(() => vi.unstubAllGlobals())

		it('refuses a pick while the browser reports the host occluded, as by a pointer-events: none overlay', async () => {
			start()
			await openOn('#u')
			shown()
			// Not reported visible yet.
			expect(verify()).toBe(false)
			report(true)
			shown()
			expect(verify()).toBe(true)
			// An overlay that lets clicks through: elementFromPoint still hits the host.
			report(false)
			report(true)
			expect(verify()).toBe(false)
			shown()
			expect(verify()).toBe(true)
		})

	})

	describe.each([['Chrome', false], ['Firefox', true]])('in a subframe on %s', (_, firefox) => {
		let frameElement: HTMLElement

		function startInFrame(ancestors: () => HTMLElement[] | null = () => [$('#frame')]) {
			start(`${LOGIN}<div id="outer"><div id="inner"><iframe id="frame"></iframe></div></div>`, { firefox, ancestors })
			frameElement = $('#frame')
			rects.set('frame', { top: 10, left: 30, width: 600, height: 600 })
		}

		it('opens no list when an ancestor frame is cross-origin, which could mask it unseen', async () => {
			startInFrame(() => null)
			$('#u').focus()
			await settle()
			expect(request).not.toHaveBeenCalled()
		})

		it('refuses a pick while an ancestor element of the frame is transparent, transformed or masked', async () => {
			startInFrame()
			await openOn('#u')
			vi.mocked(document.elementFromPoint).mockImplementation((x) => (x > 140 ? frameElement : host().element))
			shown()
			expect(verify()).toBe(true)
			// happy-dom computes none of these, so the page's value reaches the guard through a computed-style fake.
			let styled: [string, string] | null = null
			const computed = window.getComputedStyle
			vi.spyOn(window, 'getComputedStyle').mockImplementation((element, pseudo) => {
				const style = computed(element, pseudo)
				if (element !== $('#outer') || !styled || pseudo) return style
				const [property, value] = styled
				const getPropertyValue = (name: string) => (name === property ? value : style.getPropertyValue(name))
				return new Proxy(style, { get: (target, name) => (name === 'getPropertyValue' ? getPropertyValue : name === 'opacity' && property === 'opacity' ? value : Reflect.get(target, name)) })
			})
			for (const property of [
				['opacity', '0.01'], ['transform', 'matrix(1.01, 0, 0, 1.01, 0, 0)'], ['transform', 'matrix(1, 0.1, 0, 1, 0, 0)'],
				['transform', 'matrix3d(1, 0, 0, 0, 0, 1, 0, 0, 0, 0, 1, 0, 0, 0, 5, 1)'], ['translate', '1px 1px 5px'],
				['scale', '1.01'], ['rotate', '1deg'], ['zoom', '2'],
				['mask-image', 'linear-gradient(black, transparent)'], ['-webkit-mask-image', 'url(x.png)'],
				['-webkit-mask-box-image', 'url(x.png) 1'], ['mask-border-source', 'url(x.png)'], ['filter', 'opacity(0)'], ['clip-path', 'inset(0 99% 0 0)'],
			] as Array<[string, string]>) {
				styled = property
				shown()
				expect(verify(), property.join(': ')).toBe(false)
			}
			// A container that is only moved, which the mapped press point follows through its rect.
			for (const property of [['transform', 'matrix(1, 0, 0, 1, 12.5, -3)'], ['translate', '-50% 10px'], ['translate', '4px']] as Array<[string, string]>) {
				styled = property
				shown()
				expect(verify(), property.join(': ')).toBe(true)
			}
			styled = null
			shown()
			expect(verify()).toBe(true)
		})

		it('refuses a pick when the point mapped into the parent document does not hit the frame', async () => {
			startInFrame()
			await openOn('#u')
			const calls: Array<[number, number]> = []
			vi.mocked(document.elementFromPoint).mockImplementation((x, y) => {
				calls.push([x, y])
				return calls.length === 1 ? host().element : $('#outer')
			})
			shown()
			expect(verify()).toBe(false)
			expect(calls).toEqual([[120, 154], [150, 164]])
		})

		it('checks a frame inside a shadow root through its host', async () => {
			document.body.innerHTML = `${LOGIN}<div id="outer"></div>`
			const shadow = $('#outer').attachShadow({ mode: 'open' })
			shadow.innerHTML = '<div><iframe id="frame"></iframe></div>'
			frameElement = shadow.querySelector('iframe')!
			rects.set('frame', { top: 10, left: 30, width: 600, height: 600 })
			Object.defineProperty(shadow, 'elementFromPoint', { value: () => frameElement })
			inline = createInlineHost({ request, send: (message) => void sent.push(message), firefox, ancestors: () => [frameElement] })
			inline.start()
			await openOn('#u')
			vi.mocked(document.elementFromPoint).mockImplementation((x) => (x > 140 ? $('#outer') : host().element))
			shown()
			expect(verify()).toBe(true)
			// Hitting the frame inside its shadow root is not enough when the document hits something over its host.
			vi.mocked(document.elementFromPoint).mockImplementation((x) => (x > 140 ? $('#u') : host().element))
			expect(verify()).toBe(false)
			vi.mocked(document.elementFromPoint).mockImplementation((x) => (x > 140 ? $('#outer') : host().element))
			$('#outer').style.opacity = '0.5'
			shown()
			expect(verify()).toBe(false)
		})
	})

	it('closes when the page hid the popover while stopping its toggle events', async () => {
		start()
		await openOn('#u')
		vi.advanceTimersByTime(20)
		expect(isOpen()).toBe(true)
		popoverOpen.delete(host().element)
		vi.advanceTimersByTime(20)
		expect(isOpen()).toBe(false)
	})

	it('refuses a pick and closes when the host was moved off its parent, before the observer saw it', async () => {
		start()
		await openOn('#u')
		shown()
		document.body.append(host().element)
		expect(verify()).toBe(false)
		expect(isOpen()).toBe(false)
	})

	it('misses a pointer-events: none overlay on Firefox, as documented', async () => {
		start(LOGIN, { firefox: true, ancestors: () => [] })
		await openOn('#u')
		shown()
		// Hit testing skips such an overlay and Firefox has no occlusion report.
		expect(verify()).toBe(true)
	})
})

describe('sameOriginAncestors', () => {
	const frameIn = (origin: string) => ({ ownerDocument: { defaultView: { location: { origin } } } })

	/** A window two frames deep: `win` in `middle` in `top`. */
	function nested(middleOrigin: string, ownOrigin = 'https://example.test', topOrigin = 'https://example.test') {
		const top = {} as Record<string, unknown>
		top.top = top
		const middle = { top, parent: top, frameElement: frameIn(topOrigin), location: { origin: middleOrigin } }
		const win = { top, parent: middle, frameElement: frameIn(middleOrigin), location: { origin: ownOrigin } }
		return win as unknown as Window
	}

	it('lists the frame elements up to the top when every ancestor is same-origin', () => {
		expect(sameOriginAncestors(nested('https://example.test'))).toHaveLength(2)
		expect(sameOriginAncestors(window)).toEqual([])
	})

	it('refuses a cross-origin ancestor, even one that hands out its frame element', () => {
		expect(sameOriginAncestors(nested('https://ads.other.test'))).toBeNull()
		expect(sameOriginAncestors({ ...nested('https://example.test'), frameElement: null } as unknown as Window)).toBeNull()
	})

	it('refuses an opaque origin and an ancestor that throws', () => {
		expect(sameOriginAncestors(nested('https://example.test', 'null'))).toBeNull()
		// Opaque origins are never the same, though their strings are.
		expect(sameOriginAncestors(nested('null', 'null', 'null'))).toBeNull()
		const throwing = { location: { origin: 'https://example.test' }, get top(): Window {
			throw new DOMException('blocked', 'SecurityError')
		} }
		expect(sameOriginAncestors(throwing as unknown as Window)).toBeNull()
	})
})
