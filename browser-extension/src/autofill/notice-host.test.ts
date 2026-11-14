// @vitest-environment happy-dom
// @vitest-environment-options {"settings":{"navigation":{"disableChildFrameNavigation":true}}}
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { BAR_INSET_PX, BAR_WIDTH_PX } from '@/src/messages'
import { createNoticeHost, NOTICE_HEIGHT_PX, NOTICE_MS, NOTICE_NARROW_HEIGHT_PX, type BarPlace, type NoticeHost } from './notice-host'

const hosts = () => [...document.querySelectorAll('div')].filter((element) => element.getAttribute('style')?.startsWith('all: initial'))
const styleOf = () => hosts()[0]!.getAttribute('style')!
let frames: HTMLIFrameElement[]
let bar: BarPlace | null
let host: NoticeHost
let rafs: FrameRequestCallback[]
const nextFrame = () => rafs.splice(0).forEach((callback) => callback(0))

function setWidth(width: number) {
	Object.defineProperty(window, 'innerWidth', { value: width, configurable: true })
}

beforeEach(() => {
	vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout', 'Date'] })
	setWidth(1000)
	frames = []
	const attach = Element.prototype.attachShadow
	vi.spyOn(Element.prototype, 'attachShadow').mockImplementation(function (this: Element, init: ShadowRootInit) {
		const shadow = attach.call(this, init)
		queueMicrotask(() => {
			const frame = shadow.querySelector('iframe')
			if (frame) frames.push(frame)
		})
		return shadow
	})
	rafs = []
	vi.spyOn(window, 'requestAnimationFrame').mockImplementation((callback) => rafs.push(callback))
	bar = null
	host = createNoticeHost(() => bar)
})

afterEach(() => {
	host.hide()
	vi.useRealTimers()
	document.body.innerHTML = ''
})

describe('the notice host', () => {
	it('shows the notice page for its code in the top-right corner, focusing nothing', async () => {
		const active = document.activeElement
		host.show('no_login_form')
		await Promise.resolve()
		expect(hosts()).toHaveLength(1)
		expect(hosts()[0]!.parentElement).toBe(document.documentElement)
		expect(frames[0]!.src).toBe(browser.runtime.getURL('/notification.html?notice=no_login_form' as '/popup.html'))
		expect(frames[0]!.title).toBe('Keepiq notice')
		const left = 1000 - BAR_INSET_PX - BAR_WIDTH_PX
		for (const rule of ['position: fixed', `top: ${BAR_INSET_PX}px`, `left: ${left}px`, `width: ${BAR_WIDTH_PX}px`, `height: ${NOTICE_HEIGHT_PX}px`, 'z-index: 2147483647']) {
			expect(styleOf()).toContain(`${rule} !important;`)
		}
		expect(document.activeElement).toBe(active)
	})

	it('is taller in a viewport narrower than the bar', () => {
		setWidth(320)
		host.show('no_identity_form')
		expect(styleOf()).toContain(`height: ${NOTICE_NARROW_HEIGHT_PX}px !important;`)
	})

	it('shows nothing for an unknown code', () => {
		host.show('steal_the_vault')
		expect(hosts()).toEqual([])
	})

	it('sits below an open save bar at the top, follows it, and takes the corner when the bar is at the bottom', () => {
		bar = { top: 12, height: 148, bottom: false }
		host.show('no_card_form')
		expect(styleOf()).toContain('top: 168px !important;')
		bar = { top: 600, height: 148, bottom: true }
		nextFrame()
		expect(styleOf()).toContain(`top: ${BAR_INSET_PX}px !important;`)
	})

	it('moves to the bottom corner while the focused field is under it, above a save bar there, and back when the field is under that one', () => {
		Object.defineProperty(window, 'innerHeight', { value: 800, configurable: true })
		document.body.innerHTML = '<input id="search">'
		const input = document.getElementById('search')!
		let rect = { top: 20, left: 700, width: 200, height: 30 }
		vi.spyOn(input, 'getBoundingClientRect').mockImplementation(() => ({ ...rect, right: rect.left + rect.width, bottom: rect.top + rect.height }) as DOMRect)
		input.focus()
		host.show('no_login_form')
		expect(styleOf()).toContain(`top: ${800 - BAR_INSET_PX - NOTICE_HEIGHT_PX}px !important;`)
		expect(document.activeElement).toBe(input)
		bar = { top: 600, height: 148, bottom: true }
		nextFrame()
		expect(styleOf()).toContain(`top: ${600 - 8 - NOTICE_HEIGHT_PX}px !important;`)
		rect = { top: 740, left: 700, width: 200, height: 30 }
		bar = null
		nextFrame()
		expect(styleOf()).toContain(`top: ${BAR_INSET_PX}px !important;`)
	})

	it('stays put for a field it would cover in either corner', () => {
		Object.defineProperty(window, 'innerHeight', { value: 100, configurable: true })
		document.body.innerHTML = '<textarea></textarea>'
		const area = document.querySelector('textarea')!
		vi.spyOn(area, 'getBoundingClientRect').mockReturnValue({ top: 0, left: 0, width: 1000, height: 100, right: 1000, bottom: 100 } as DOMRect)
		area.focus()
		host.show('no_login_form')
		expect(styleOf()).toContain(`top: ${BAR_INSET_PX}px !important;`)
	})

	it('is replaced by a later notice', async () => {
		host.show('no_login_form')
		host.show('no_card_form')
		await Promise.resolve()
		expect(hosts()).toHaveLength(1)
		expect(frames.at(-1)!.src).toContain('notice=no_card_form')
	})

	it('closes after eight seconds, and on hide', () => {
		host.show('no_login_form')
		vi.advanceTimersByTime(NOTICE_MS - 1)
		expect(hosts()).toHaveLength(1)
		vi.advanceTimersByTime(1)
		expect(hosts()).toEqual([])
		host.show('no_login_form')
		host.hide()
		expect(hosts()).toEqual([])
	})

	it('counts the eight seconds only while neither pointer nor focus is in it', () => {
		host.show('no_login_form')
		const element = hosts()[0]!
		vi.advanceTimersByTime(5000)
		element.dispatchEvent(new Event('mouseenter'))
		vi.advanceTimersByTime(60_000)
		expect(hosts()).toHaveLength(1)
		element.dispatchEvent(new Event('focusin'))
		element.dispatchEvent(new Event('mouseleave'))
		vi.advanceTimersByTime(60_000)
		expect(hosts()).toHaveLength(1)
		element.dispatchEvent(new Event('focusout'))
		vi.advanceTimersByTime(2999)
		expect(hosts()).toHaveLength(1)
		vi.advanceTimersByTime(1)
		expect(hosts()).toEqual([])
	})

	it('closes when the page navigates its frame', async () => {
		host.show('no_login_form')
		// happy-dom loads the frame itself first.
		await vi.advanceTimersByTimeAsync(0)
		expect(hosts()).toHaveLength(1)
		frames[0]!.dispatchEvent(new Event('load'))
		expect(hosts()).toEqual([])
	})
})
