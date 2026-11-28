/**
 * The notice a menu fill without a form leaves in the top frame: `notification.html?notice=<code>` on `frame-host.ts`, in
 * the save bar's corner and beside an open bar. It offers no action, so it has none of the bar's guard; it never takes focus.
 */
import { i18n } from '#i18n'
import { BAR_INSET_PX, BAR_RADIUS_PX, BAR_WIDTH_PX, barWidth, PAGE_NOTICE_CODES, type PageNoticeCode } from '@/src/messages'
import { deepActiveElement } from './execute'
import { createFrameHost, type FrameHost, viewport } from './frame-host'

export const NOTICE_MS = 8000
/** Two lines of text at the bar's width, four on a narrower one; a longer text scrolls. */
export const NOTICE_HEIGHT_PX = 64
export const NOTICE_NARROW_HEIGHT_PX = 100
const GAP_PX = 8

export interface BarPlace {
	top: number
	height: number
	bottom: boolean
}

type Place = { top: number; left: number; width: number; height: number }

interface Shown {
	host: FrameHost
	place: Place | null
	frame: number
	timer?: ReturnType<typeof setTimeout>
	/** Of `NOTICE_MS`, as of `since` while the timer runs. */
	left: number
	since: number
	pointer: boolean
	focus: boolean
	/** Moved to the bottom corner while the page's focused element sits under the top one (WCAG 2.4.11). */
	bottom: boolean
}

export interface NoticeHost {
	show(notice: unknown): void
	hide(): void
}

const covers = (place: Place, rect: DOMRect) =>
	rect.bottom > place.top && rect.top < place.top + place.height && rect.right > place.left && rect.left < place.left + place.width

/** Above the bar in the bottom corner when it shares it, else at the corner's inset; below it in the top corner likewise. */
function noticeTop(bottom: boolean, { view, height, shown }: { view: { height: number }; height: number; shown: BarPlace | null }): number {
	const sharing = shown !== null && shown.bottom === bottom
	if (bottom) return (sharing ? shown.top - GAP_PX : view.height - BAR_INSET_PX) - height
	return sharing ? shown.top + shown.height + GAP_PX : BAR_INSET_PX
}

/** Focus that is the notice itself, or the page as a whole, never moves it. */
const isOutsideFocus = (active: Element, shown: Shown) => active === shown.host.element || active === document.body || active === document.documentElement

const hasBox = (rect: DOMRect) => !(rect.width === 0 && rect.height === 0)

function samePlace(old: Place | null, place: Place): boolean {
	return !!old && old.top === place.top && old.left === place.left && old.width === place.width && old.height === place.height
}

/** `bar`: where the save bar is now, so the notice sits beside it in its corner, never over it. */
export function createNoticeHost(bar: () => BarPlace | null): NoticeHost {
	let current: Shown | null = null

	function placeNow(bottom: boolean): Place {
		const view = viewport()
		const width = barWidth(view.width)
		const height = width < BAR_WIDTH_PX ? NOTICE_NARROW_HEIGHT_PX : NOTICE_HEIGHT_PX
		const top = noticeTop(bottom, { view, height, shown: bar() })
		return { top: Math.max(0, top), left: Math.max(0, view.width - BAR_INSET_PX - width), width, height }
	}

	/** Moves only when the focused element is under it here and would not be in the other corner, so it never flips back and forth. */
	function avoidFocus(shown: Shown): void {
		const active = deepActiveElement()
		if (!active || isOutsideFocus(active, shown)) return
		const rect = active.getBoundingClientRect()
		if (!hasBox(rect)) return
		if (coveredOnlyHere(shown.bottom, rect)) shown.bottom = !shown.bottom
	}

	function coveredOnlyHere(bottom: boolean, rect: DOMRect): boolean {
		return covers(placeNow(bottom), rect) && !covers(placeNow(!bottom), rect)
	}

	function style(): void {
		if (!current) return
		avoidFocus(current)
		const place = placeNow(current.bottom)
		if (samePlace(current.place, place)) return
		current.place = place
		current.host.style({
			'position': 'fixed', 'top': `${place.top}px`, 'left': `${place.left}px`, 'width': `${place.width}px`, 'height': `${place.height}px`,
			'margin': '0', 'padding': '0', 'border': '0', 'display': 'block', 'overflow': 'clip', 'z-index': '2147483647',
			'border-radius': `${BAR_RADIUS_PX}px`, 'box-shadow': '0 4px 16px rgba(0, 0, 0, 0.18), 0 1px 4px rgba(0, 0, 0, 0.12)',
			'background': 'transparent', 'color-scheme': 'normal',
		})
	}

	function hide(): void {
		if (!current) return
		cancelAnimationFrame(current.frame)
		clearTimeout(current.timer)
		current.host.remove()
		current = null
	}

	/** Counts down only while neither pointer nor focus is in the notice. */
	function time(): void {
		if (!current) return
		const running = current.timer !== undefined
		const shouldRun = !current.pointer && !current.focus
		if (running === shouldRun) return
		if (shouldRun) {
			current.since = Date.now()
			current.timer = setTimeout(hide, current.left)
		} else {
			clearTimeout(current.timer)
			current.timer = undefined
			current.left = Math.max(0, current.left - (Date.now() - current.since))
		}
	}

	function tick(): void {
		if (!current) return
		current.frame = requestAnimationFrame(tick)
		style()
	}

	function show(notice: unknown): void {
		if (!PAGE_NOTICE_CODES.includes(notice as PageNoticeCode)) return
		hide()
		const host = createFrameHost(`/notification.html?notice=${notice as PageNoticeCode}`, i18n.t('pageNotice.label'))
		const shown: Shown = { host, place: null, frame: 0, left: NOTICE_MS, since: 0, pointer: false, focus: false, bottom: false }
		current = shown
		let loads = 0
		// The page navigated the frame: the new document gets nothing.
		host.frame.addEventListener('load', () => {
			if (current === shown && ++loads > 1) hide()
		})
		const set = (change: Partial<Pick<Shown, 'pointer' | 'focus'>>) => {
			if (current !== shown) return
			Object.assign(shown, change)
			time()
		}
		host.element.addEventListener('mouseenter', () => set({ pointer: true }))
		host.element.addEventListener('mouseleave', () => set({ pointer: false }))
		host.element.addEventListener('focusin', () => set({ focus: true }))
		host.element.addEventListener('focusout', () => setTimeout(() => set({ focus: deepActiveElement() === host.element }), 0))
		style()
		const popover = typeof host.element.showPopover === 'function'
		if (popover) host.element.setAttribute('popover', 'manual')
		document.documentElement.append(host.element)
		if (popover) host.element.showPopover()
		shown.frame = requestAnimationFrame(tick)
		time()
	}

	return { show, hide }
}
