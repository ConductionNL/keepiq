// @vitest-environment happy-dom
// @vitest-environment-options {"settings":{"navigation":{"disableChildFrameNavigation":true}}}
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { BAR_COMPACT_HEIGHT_PX, BAR_MEDIUM_HEIGHT_PX, BAR_NARROW_HEIGHT_PX, BAR_WIDE_HEIGHT_PX, type PickInput } from '@/src/messages'
import { createNotificationHost, type NotificationHost } from './notification-host'
import { GUARD_MS } from './visibility'

const KEY = 'a'.repeat(32)
const hosts = () => [...document.querySelectorAll('div')].filter((element) => element.getAttribute('style')?.startsWith('all: initial'))
let posted: unknown[]
let sent: unknown[]
let frames: HTMLIFrameElement[]
let host: NotificationHost
let now: number

const show = (key = KEY) => host.handle({ kind: 'capture.show', key })
const verify = (input: PickInput, key = KEY) => host.handle({ kind: 'capture.verify', key, input })
const press = (x = 10, y = 10): PickInput => ({ kind: 'pointer', at: now, x, y })
const load = (frame = frames.at(-1)!) => frame.dispatchEvent(new Event('load'))
const flush = () => new Promise((resolve) => setTimeout(resolve, 0))
const styleOf = () => hosts()[0]!.getAttribute('style')!
/** Animation frames run only when a test steps them. */
let rafs: FrameRequestCallback[]
const nextFrame = () => rafs.splice(0).forEach((callback) => callback(now))

function setWidth(width: number, height = 800) {
	Object.defineProperty(window, 'innerWidth', { value: width, configurable: true })
	Object.defineProperty(window, 'innerHeight', { value: height, configurable: true })
}

/** The host's rect as it styled itself, which happy-dom does not lay out. */
function layOut() {
	vi.spyOn(Element.prototype, 'getBoundingClientRect').mockImplementation(function (this: Element) {
		const style = (this as HTMLElement).getAttribute?.('style') ?? ''
		const px = (name: string) => Number(new RegExp(`(?:^|; )${name}: ([\\d.]+)px`).exec(style)?.[1] ?? 0)
		const [top, left, width, height] = [px('top'), px('left'), px('width'), px('height')]
		return { x: left, y: top, top, left, width, height, right: left + width, bottom: top + height, toJSON: () => ({}) } as DOMRect
	})
}

beforeEach(() => {
	setWidth(1000)
	now = 1_000_000
	vi.spyOn(Date, 'now').mockImplementation(() => now)
	posted = []
	frames = []
	const attach = Element.prototype.attachShadow
	vi.spyOn(Element.prototype, 'attachShadow').mockImplementation(function (this: Element, init: ShadowRootInit) {
		const shadow = attach.call(this, init)
		queueMicrotask(() => {
			const frame = shadow.querySelector('iframe')
			if (!frame) return
			frames.push(frame)
			vi.spyOn(frame, 'focus')
		})
		return shadow
	})
	vi.spyOn(HTMLIFrameElement.prototype, 'contentWindow', 'get').mockReturnValue({ postMessage: (message: unknown) => posted.push(message) } as never)
	layOut()
	// Rendered when connected; a dialog only while open.
	vi.spyOn(Element.prototype, 'getClientRects').mockImplementation(function (this: Element) {
		const rendered = this.isConnected && (this.localName !== 'dialog' || this.hasAttribute('open'))
		return (rendered ? [{}] : []) as unknown as DOMRectList
	})
	rafs = []
	vi.spyOn(window, 'requestAnimationFrame').mockImplementation((callback) => rafs.push(callback))
	sent = []
	host = createNotificationHost({ firefox: false, send: (message) => sent.push(message) })
})

afterEach(() => {
	host.handle({ kind: 'capture.hide', key: KEY })
	for (const element of hosts()) element.remove()
	document.body.innerHTML = ''
})

describe('showing', () => {
	it('shows notification.html in a closed shadow root, fixed full width at the top, focusing nothing', async () => {
		const active = document.activeElement
		show()
		await flush()
		const [element] = hosts()
		expect(element?.parentElement).toBe(document.documentElement)
		expect(element!.shadowRoot).toBeNull()
		expect(frames[0]!.src).toBe(browser.runtime.getURL('/notification.html' as '/popup.html'))
		expect(frames[0]!.title).toBe('Keepiq notification')
		for (const rule of ['position: fixed', 'top: 0px', 'left: 0px', 'width: 1000px', `height: ${BAR_WIDE_HEIGHT_PX}px`, 'z-index: 2147483647']) {
			expect(styleOf()).toContain(`${rule} !important;`)
		}
		expect(document.activeElement).toBe(active)
	})

	it.each([[1200, BAR_WIDE_HEIGHT_PX], [900, BAR_WIDE_HEIGHT_PX], [899, BAR_MEDIUM_HEIGHT_PX], [420, BAR_MEDIUM_HEIGHT_PX], [419, BAR_NARROW_HEIGHT_PX]])(
		'is %i px wide and so %i px tall, whatever the bar shows', (width, height) => {
			setWidth(width)
			show()
			expect(styleOf()).toContain(`height: ${height}px !important;`)
		})

	it('posts the key to its own frame after it loads, at the extension origin only', async () => {
		const post = vi.fn()
		vi.spyOn(HTMLIFrameElement.prototype, 'contentWindow', 'get').mockReturnValue({ postMessage: post } as never)
		show()
		expect(post).not.toHaveBeenCalled()
		// happy-dom loads the frame by itself.
		await flush()
		expect(post).toHaveBeenCalledWith({ kind: 'capture.init', key: KEY }, 'chrome-extension://test-extension-id')
	})

	it('removes the bar when the page navigates its frame', async () => {
		show()
		await flush()
		expect(hosts()).toHaveLength(1)
		load()
		expect(hosts()).toHaveLength(0)
	})

	it('shows one bar for any number of shows, keeping its key, and a new one after the page removed it', async () => {
		show()
		show('b'.repeat(32))
		expect(hosts()).toHaveLength(1)
		await flush()
		expect(posted).toEqual([{ kind: 'capture.init', key: KEY }])
		hosts()[0]!.remove()
		await flush()
		show('b'.repeat(32))
		expect(hosts()).toHaveLength(1)
	})

	it('hides only for its own key', () => {
		show()
		host.handle({ kind: 'capture.hide', key: 'b'.repeat(32) })
		expect(hosts()).toHaveLength(1)
		host.handle({ kind: 'capture.hide', key: KEY })
		expect(hosts()).toHaveLength(0)
	})

	it('closes as soon as the page restyles or moves the host, before the next frame samples it', async () => {
		vi.spyOn(window, 'requestAnimationFrame').mockReturnValue(0)
		const element = (show(), hosts()[0]!)
		element.setAttribute('style', 'all: initial !important; opacity: 0.01 !important;')
		await Promise.resolve()
		expect(element.isConnected).toBe(false)
		show()
		const moved = hosts()[0]!
		document.body.append(moved)
		await Promise.resolve()
		expect(moved.isConnected).toBe(false)
	})
})

describe('the top layer', () => {
	let open: boolean

	beforeEach(() => {
		open = false
		HTMLElement.prototype.showPopover = function () {
			this.dispatchEvent(new Event('beforetoggle'))
			open = true
			this.dispatchEvent(Object.assign(new Event('toggle'), { newState: 'open' }))
		}
		const matches = Element.prototype.matches
		vi.spyOn(Element.prototype, 'matches').mockImplementation(function (this: Element, selector: string) {
			if (selector === ':popover-open') return open && this.hasAttribute('popover')
			if (selector === ':modal') return this.localName === 'dialog' && this.hasAttribute('open')
			return matches.call(this, selector)
		})
	})

	afterEach(() => {
		delete (HTMLElement.prototype as Partial<HTMLElement>).showPopover
	})

	it('is a manual popover, inside the modal dialog the page is in', () => {
		document.body.innerHTML = '<dialog open><input id="f"></dialog>'
		;(document.querySelector('#f') as HTMLInputElement).focus()
		show()
		expect(hosts()[0]!.getAttribute('popover')).toBe('manual')
		expect(hosts()[0]!.parentElement).toBe(document.querySelector('dialog'))
	})

	it('goes inside a script-trapped aria-modal around focus', () => {
		document.body.innerHTML = '<div aria-modal="true" id="m"><input id="f"></div>'
		;(document.querySelector('#f') as HTMLInputElement).focus()
		show()
		expect(hosts()[0]!.parentElement).toBe(document.querySelector('#m'))
	})

	it('closes when the page hides the popover', () => {
		show()
		open = false
		hosts()[0]!.dispatchEvent(Object.assign(new Event('toggle'), { newState: 'closed' }))
		expect(hosts()).toHaveLength(0)
	})

	it('refuses on Firefox while <html> has a filter, which reaches its top layer', async () => {
		host = createNotificationHost({ firefox: true, send: () => {} })
		show()
		await flush()
		vi.spyOn(document, 'elementFromPoint').mockImplementation(() => hosts()[0]!)
		now += GUARD_MS + 1
		expect(verify(press())).toBe(true)
		document.documentElement.style.filter = 'invert(1)'
		now += GUARD_MS + 1
		expect(verify(press())).toBe(false)
		document.documentElement.style.filter = ''
	})
})

describe('capture.verify', () => {
	beforeEach(async () => {
		show()
		await flush()
		vi.spyOn(document, 'elementFromPoint').mockImplementation(() => hosts()[0] ?? null)
	})

	it('confirms a press on the bar once it has shown for 500 ms', () => {
		now += GUARD_MS - 1
		expect(verify(press())).toBe(false)
		now += 2
		expect(verify(press())).toBe(true)
	})

	it('refuses another key, a press outside the bar, or a malformed one', () => {
		now += GUARD_MS + 1
		expect(verify(press(), 'b'.repeat(32))).toBe(false)
		expect(verify(press(10, BAR_WIDE_HEIGHT_PX))).toBe(false)
		expect(verify(press(-1, 10))).toBe(false)
		expect(host.handle({ kind: 'capture.verify', key: KEY, input: { kind: 'pointer', at: now } as never })).toBe(false)
	})

	it('refuses a press whose point reaches something the page put over the bar', () => {
		now += GUARD_MS + 1
		const overlay = document.createElement('div')
		document.body.append(overlay)
		vi.mocked(document.elementFromPoint).mockReturnValue(overlay)
		expect(verify(press())).toBe(false)
	})

	it('refuses a press before the frame loaded', () => {
		host.handle({ kind: 'capture.hide', key: KEY })
		show()
		now += GUARD_MS + 1
		expect(verify(press())).toBe(false)
	})

	it('refuses a press within 500 ms of the bar moving or the tab coming back', () => {
		now += GUARD_MS + 1
		document.body.innerHTML = '<input id="f">'
		const field = document.querySelector('#f') as HTMLInputElement
		field.getBoundingClientRect = () => ({ top: 10, bottom: 30, left: 0, right: 100, width: 100, height: 20, x: 0, y: 10, toJSON: () => ({}) })
		field.focus()
		expect(verify(press())).toBe(false)
		now += GUARD_MS + 1
		expect(verify(press())).toBe(true)
		document.dispatchEvent(new Event('visibilitychange'))
		expect(verify(press())).toBe(false)
	})

	it('refuses, and closes, once the page tampered with the host', () => {
		now += GUARD_MS + 1
		hosts()[0]!.setAttribute('style', `${hosts()[0]!.getAttribute('style')} outline: 0;`)
		expect(verify(press())).toBe(false)
		expect(hosts()).toHaveLength(0)
	})
})

describe('focus', () => {
	const BOTTOM = `top: ${800 - BAR_WIDE_HEIGHT_PX}px !important;`
	const field = (id: string, top: number, height = 20) => {
		const element = document.querySelector(`#${id}`) as HTMLInputElement
		element.getBoundingClientRect = () => ({ top, bottom: top + height, left: 0, right: 100, width: 100, height, x: 0, y: top, toJSON: () => ({}) })
		return element
	}

	it('moves to the bottom when the focused page element is under the bar, telling the bar', async () => {
		document.body.innerHTML = '<input id="f">'
		show()
		await flush()
		field('f', 10).focus()
		expect(styleOf()).toContain(BOTTOM)
		expect(posted.at(-1)).toEqual({ kind: 'capture.moved', key: KEY })
	})

	it('stays where it is while focus moves elsewhere, and moves back only for an element under it', async () => {
		document.body.innerHTML = '<input id="top"><input id="mid"><input id="low">'
		show()
		await flush()
		field('top', 10).focus()
		field('mid', 400).focus()
		field('top', 10).focus()
		expect(styleOf()).toContain(BOTTOM)
		field('low', 760).focus()
		expect(styleOf()).toContain('top: 0px !important;')
	})

	it('does not move for an element under both places, such as a tall one', async () => {
		document.body.innerHTML = '<textarea id="f"></textarea>'
		show()
		await flush()
		field('f', 0, 800).focus()
		expect(styleOf()).toContain('top: 0px !important;')
	})

	it('moves on the next frame when the focused element scrolls under it, with no focus event', async () => {
		document.body.innerHTML = '<input id="f">'
		show()
		await flush()
		field('f', 400).focus()
		expect(styleOf()).toContain('top: 0px !important;')
		field('f', 20)
		nextFrame()
		expect(styleOf()).toContain(BOTTOM)
	})

	it('focuses its frame and posts capture.focus on the focus command', async () => {
		show()
		await flush()
		host.handle({ kind: 'capture.focus' })
		expect(frames[0]!.focus).toHaveBeenCalled()
		expect(posted).toContainEqual({ kind: 'capture.focus', key: KEY })
	})

	it('gives focus back to the page element it came from when the bar goes while focused', async () => {
		document.body.innerHTML = '<input id="f">'
		const field = document.querySelector('#f') as HTMLInputElement
		field.focus()
		show()
		hosts()[0]!.tabIndex = -1
		hosts()[0]!.focus()
		host.handle({ kind: 'capture.hide', key: KEY })
		expect(document.activeElement).toBe(field)
	})

	it('leaves focus alone when it was not in the bar', () => {
		document.body.innerHTML = '<input id="f"><input id="g">'
		;(document.querySelector('#f') as HTMLInputElement).focus()
		show()
		;(document.querySelector('#g') as HTMLInputElement).focus()
		host.handle({ kind: 'capture.hide', key: KEY })
		expect(document.activeElement).toBe(document.querySelector('#g'))
	})
})

describe('a short viewport', () => {
	const trustedFocusIn = (element: Element) => element.dispatchEvent(Object.defineProperty(new FocusEvent('focusin', { bubbles: true, composed: true }), 'isTrusted', { value: true }))

	it.each([[1000, 150, BAR_COMPACT_HEIGHT_PX], [1000, 192, BAR_WIDE_HEIGHT_PX], [400, 300, BAR_COMPACT_HEIGHT_PX], [400, 400, BAR_NARROW_HEIGHT_PX]])(
		'at %i × %i px is %i px tall, at most a third of it unless compact', (width, height, expected) => {
			setWidth(width, height)
			show()
			expect(styleOf()).toContain(`height: ${expected}px !important;`)
		})

	/** Focus in the bar's frame, which the page's document sees as its host; an attribute on the host would close it as tampering. */
	const focusBar = () => vi.spyOn(document, 'activeElement', 'get').mockReturnValue(hosts()[0]!)

	it('grows to its full height when the bar asks, telling the bar, and stays while focus is in it', async () => {
		setWidth(1000, 150)
		show()
		await flush()
		focusBar()
		host.handle({ kind: 'capture.expand', key: 'b'.repeat(32) })
		expect(styleOf()).toContain(`height: ${BAR_COMPACT_HEIGHT_PX}px !important;`)
		host.handle({ kind: 'capture.expand', key: KEY })
		expect(styleOf()).toContain(`height: ${BAR_WIDE_HEIGHT_PX}px !important;`)
		expect(posted.at(-1)).toEqual({ kind: 'capture.moved', key: KEY })
		nextFrame()
		expect(styleOf()).toContain(`height: ${BAR_WIDE_HEIGHT_PX}px !important;`)
	})

	it('shrinks at once when focus moves to the page', async () => {
		setWidth(1000, 150)
		document.body.innerHTML = '<a id="link" href="#">Back</a>'
		show()
		await flush()
		const inBar = focusBar()
		host.handle({ kind: 'capture.expand', key: KEY })
		inBar.mockRestore()
		;(document.querySelector('#link') as HTMLElement).focus()
		expect(styleOf()).toContain(`height: ${BAR_COMPACT_HEIGHT_PX}px !important;`)
	})

	it('shrinks on the next frame when focus leaves for nothing focusable, which sends the host no event', async () => {
		setWidth(1000, 150)
		show()
		await flush()
		const inBar = focusBar()
		host.handle({ kind: 'capture.expand', key: KEY })
		inBar.mockRestore()
		expect(styleOf()).toContain(`height: ${BAR_WIDE_HEIGHT_PX}px !important;`)
		nextFrame()
		expect(styleOf()).toContain(`height: ${BAR_COMPACT_HEIGHT_PX}px !important;`)
	})

	it('grows when focus enters it, as by the focus command, but not for a focus event the page dispatches', async () => {
		setWidth(1000, 150)
		show()
		await flush()
		hosts()[0]!.dispatchEvent(new FocusEvent('focusin', { bubbles: true, composed: true }))
		expect(styleOf()).toContain(`height: ${BAR_COMPACT_HEIGHT_PX}px !important;`)
		trustedFocusIn(hosts()[0]!)
		expect(styleOf()).toContain(`height: ${BAR_WIDE_HEIGHT_PX}px !important;`)
	})

	it('refuses a press within 500 ms of growing', async () => {
		setWidth(1000, 150)
		show()
		await flush()
		vi.spyOn(document, 'elementFromPoint').mockImplementation(() => hosts()[0] ?? null)
		now += GUARD_MS + 1
		expect(verify(press())).toBe(true)
		host.handle({ kind: 'capture.expand', key: KEY })
		expect(verify(press())).toBe(false)
	})
})

describe('a modal the page opens after the bar', () => {
	let open: boolean

	beforeEach(() => {
		open = false
		HTMLElement.prototype.showPopover = function () {
			open = true
		}
		const matches = Element.prototype.matches
		vi.spyOn(Element.prototype, 'matches').mockImplementation(function (this: Element, selector: string) {
			if (selector === ':popover-open') return open && this.hasAttribute('popover')
			if (selector === ':modal') return this.localName === 'dialog' && this.hasAttribute('open')
			return matches.call(this, selector)
		})
	})

	afterEach(() => {
		delete (HTMLElement.prototype as Partial<HTMLElement>).showPopover
		delete (Element.prototype as { moveBefore?: unknown }).moveBefore
	})

	it('moves the bar into the modal holding focus, keeping its frame, and back out once it closes', async () => {
		// The real one keeps the frame's document; happy-dom's `append` reloads it, so that load is dropped.
		const move = vi.fn(function (this: Element, node: Node) {
			frames[0]!.addEventListener('load', (event) => event.stopImmediatePropagation(), { once: true, capture: true })
			this.append(node)
		})
		Object.assign(Element.prototype, { moveBefore: move })
		show()
		await flush()
		const frame = frames[0]
		document.body.innerHTML = '<dialog open><input id="f"></dialog>'
		;(document.querySelector('#f') as HTMLInputElement).focus()
		nextFrame()
		await flush()
		const dialog = document.querySelector('dialog')!
		expect(hosts()[0]!.parentElement).toBe(dialog)
		expect(move).toHaveBeenCalledOnce()
		expect(frames[0]).toBe(frame)
		expect(posted.at(-1)).toEqual({ kind: 'capture.moved', key: KEY })
		dialog.removeAttribute('open')
		;(document.activeElement as HTMLElement | null)?.blur()
		nextFrame()
		await flush()
		expect(hosts()[0]!.parentElement).toBe(document.documentElement)
		expect(sent).toEqual([])
	})

	it('asks for a new bar inside the modal where an iframe cannot move without reloading', async () => {
		show()
		await flush()
		document.body.innerHTML = '<div aria-modal="true"><input id="f"></div>'
		;(document.querySelector('#f') as HTMLInputElement).focus()
		nextFrame()
		expect(hosts()).toHaveLength(0)
		expect(sent).toEqual([{ kind: 'capture.reshow' }])
		show('b'.repeat(32))
		expect(hosts()[0]!.parentElement).toBe(document.querySelector('[aria-modal]'))
	})
})

describe('the hide after a save', () => {
	it('waits while the pointer is on the bar, until it leaves', () => {
		show()
		hosts()[0]!.dispatchEvent(new Event('mouseenter'))
		host.handle({ kind: 'capture.hide', key: KEY, done: true })
		expect(hosts()).toHaveLength(1)
		hosts()[0]!.dispatchEvent(new Event('mouseleave'))
		expect(hosts()).toHaveLength(0)
	})

	it('waits while focus is in the bar, until it leaves', async () => {
		document.body.innerHTML = '<input id="f">'
		show()
		const element = hosts()[0]!
		element.tabIndex = -1
		element.focus()
		host.handle({ kind: 'capture.hide', key: KEY, done: true })
		expect(hosts()).toHaveLength(1)
		;(document.querySelector('#f') as HTMLInputElement).focus()
		element.dispatchEvent(new Event('focusout'))
		await flush()
		expect(hosts()).toHaveLength(0)
	})

	it('goes at once when nobody is on it', () => {
		show()
		host.handle({ kind: 'capture.hide', key: KEY, done: true })
		expect(hosts()).toHaveLength(0)
	})
})
