/**
 * The save bar's place in the top frame: `notification.html` on `frame-host.ts`, with the inline list's guard
 * (design.md § Notification bar): a top-layer popover, closed on tampering, sampled every frame, and a press counts
 * only once `capture.verify` confirms it. It never gets the password, only the key it posts to its own frame.
 */
import { i18n } from '#i18n'
import {
	BAR_INSET_PX, BAR_RADIUS_PX, barHostHeight, barWidth,
	type BackgroundToContent, type ContentToBackground, type ContentToNotification, type PickInput,
} from '@/src/messages'
import { deepActiveElement } from './execute'
import { createFrameHost, type FrameHost } from './frame-host'
import { drawnUntouched, GUARD_MS, hits, modalOf } from './visibility'

const TOLERANCE_PX = 0.5

interface Box {
	top: number
	left: number
	width: number
	height: number
}

interface Bar {
	key: string
	capture: string
	host: FrameHost
	/** `<html>`, or the modal the page is in, outside which everything is inert. */
	parent: Element
	popover: boolean
	observer: MutationObserver
	box: Box
	/** Moved to the bottom while the page's focused element sits under the top one (WCAG 2.4.11). */
	bottom: boolean
	/** Full height while the user is in a bar that is otherwise compact. */
	expanded: boolean
	loads: number
	frame: number
	lastNotVisibleAt: number
	pointerInside: boolean
	/** A save's hide, waiting for focus and pointer to leave. */
	doneWaiting: boolean
}

export interface NotificationHostOptions {
	/** For the `<html>` filter that reaches Firefox's top layer. */
	firefox: boolean
	send: (message: ContentToBackground) => void
}

type CaptureMessage = Extract<BackgroundToContent, { kind: `capture.${string}` }>

export interface NotificationHost {
	/** `capture.verify` answers a boolean, `capture.show` the key its bar holds; the other kinds answer nothing. */
	handle(message: CaptureMessage): boolean | string | undefined
}

const isPickInput = (input: unknown): input is PickInput => {
	const value = input as Partial<PickInput> | null
	return (value?.kind === 'pointer' || value?.kind === 'key') && [value.at, value.x, value.y].every((n) => typeof n === 'number' && Number.isFinite(n))
}

function viewport(): { width: number; height: number } {
	const root = document.documentElement
	const standards = document.compatMode === 'CSS1Compat'
	return { width: (standards && root.clientWidth) || window.innerWidth, height: (standards && root.clientHeight) || window.innerHeight }
}

function isOpenModal(element: Element): boolean {
	let modal = element.getAttribute('aria-modal') === 'true'
	try {
		modal ||= element.matches(':modal')
	} catch {
		// No `:modal` support.
	}
	return modal && element.isConnected && element.getClientRects().length > 0
}

/** The modal the page is in, so the bar is not inert: around focus, else the last `:modal` shown. */
function modalParent(): Element | null {
	const active = deepActiveElement()
	const around = active ? modalOf(active, true) : null
	if (around) return around
	try {
		return [...document.querySelectorAll(':modal')].at(-1) ?? null
	} catch {
		return null
	}
}

type Movable = Element & { moveBefore?: (node: Node, child: Node | null) => void }

export function createNotificationHost(options: NotificationHostOptions): NotificationHost {
	const { firefox, send } = options
	let bar: Bar | null = null
	let ownToggle = false
	/** The page's last focused element, given focus back when the bar goes while focus is in it. */
	let pageFocus: Element | null = null

	const placeBox = (bottom: boolean, expanded: boolean): Box => {
		const view = viewport()
		const width = barWidth(view.width)
		const height = barHostHeight(view.width, view.height, expanded)
		return {
			top: bottom ? Math.max(0, view.height - BAR_INSET_PX - height) : BAR_INSET_PX,
			left: Math.max(0, view.width - BAR_INSET_PX - width),
			width,
			height,
		}
	}
	const sameBox = (a: Box, b: Box) => a.top === b.top && a.left === b.left && a.width === b.width && a.height === b.height

	/** The shadow paints outside the host's box and takes no clicks, so the host is exactly the card. */
	function style(current: Bar): void {
		const { top, left, width, height } = current.box
		current.host.style({
			'position': 'fixed', 'top': `${top}px`, 'left': `${left}px`, 'width': `${width}px`, 'height': `${height}px`,
			'margin': '0', 'padding': '0', 'border': '0', 'display': 'block', 'overflow': 'clip', 'z-index': '2147483647',
			'border-radius': `${BAR_RADIUS_PX}px`, 'box-shadow': '0 4px 16px rgba(0, 0, 0, 0.18), 0 1px 4px rgba(0, 0, 0, 0.12)',
			'opacity': '1', 'visibility': 'visible', 'pointer-events': 'auto', 'background': 'transparent', 'color-scheme': 'normal',
			'transform': 'none', 'scale': 'none', 'rotate': 'none', 'translate': 'none', 'zoom': '1',
			'filter': 'none', 'mix-blend-mode': 'normal', 'clip-path': 'none', 'mask': 'none',
		})
	}

	const focusInBar = (current: Bar) => deepActiveElement() === current.host.element

	function close(): void {
		const current = bar
		if (!current) return
		const hadFocus = focusInBar(current)
		bar = null
		current.observer.disconnect()
		cancelAnimationFrame(current.frame)
		current.host.remove()
		if (hadFocus && pageFocus?.isConnected) (pageFocus as HTMLElement).focus?.({ preventScroll: true })
	}

	function onMutations(records: MutationRecord[]): void {
		const current = bar
		if (!current) return
		const element = current.host.element
		if (records.some((record) => record.target === element || [...record.removedNodes].includes(element))) close()
	}

	/** Our own writes are dropped from the observer; anything the page did before them still closes the bar. */
	function move(current: Bar, bottom: boolean, expanded = current.expanded): void {
		onMutations(current.observer.takeRecords())
		if (bar !== current) return
		const box = placeBox(bottom, expanded)
		current.bottom = bottom
		current.expanded = expanded
		if (sameBox(box, current.box)) return
		current.box = box
		style(current)
		current.observer.takeRecords()
		current.lastNotVisibleAt = Date.now()
		if (current.loads > 0) current.host.post({ kind: 'capture.moved', key: current.key } satisfies ContentToNotification)
	}

	/**
	 * WCAG 2.4.11: the page's focused element is never hidden under the bar. It moves only when that element is under
	 * where the bar is now and would not be under the other corner too, so it never flips back and forth.
	 */
	function avoid(current: Bar, element: Element): void {
		const rect = element.getBoundingClientRect()
		if (rect.width === 0 && rect.height === 0) return
		const under = (box: Box) => rect.bottom > box.top && rect.top < box.top + box.height && rect.right > box.left && rect.left < box.left + box.width
		if (under(current.box) && !under(placeBox(!current.bottom, current.expanded))) move(current, !current.bottom)
	}

	/** Moves the host into the modal the page opened, or back out of one it closed; an iframe moved otherwise reloads. */
	function relocate(current: Bar, target: Movable): void {
		if (typeof target.moveBefore !== 'function') {
			close()
			send({ kind: 'capture.reshow' })
			return
		}
		onMutations(current.observer.takeRecords())
		if (bar !== current) return
		const element = current.host.element
		current.observer.disconnect()
		ownToggle = true
		try {
			target.moveBefore(element, null)
			if (current.popover && !element.matches(':popover-open')) element.showPopover()
		} finally {
			ownToggle = false
		}
		current.parent = target
		current.observer.observe(element, { attributes: true })
		current.observer.observe(target, { childList: true })
		current.observer.takeRecords()
		current.lastNotVisibleAt = Date.now()
		if (current.loads > 0) current.host.post({ kind: 'capture.moved', key: current.key } satisfies ContentToNotification)
	}

	/** A modal opened after the bar showed, holding focus, or the one the bar is in no longer open. */
	function modalChange(current: Bar): Element | null {
		if (!current.popover) return null
		const active = deepActiveElement()
		const holder = active && active !== current.host.element ? modalOf(active, true) : null
		if (holder && holder !== current.parent) return holder
		return current.parent !== document.documentElement && !isOpenModal(current.parent) ? document.documentElement : null
	}

	/** Whether the bar is drawn as set, now; closes it when the page tampered with the host. */
	function visibleNow(current: Bar): boolean {
		const element = current.host.element
		if (!element.isConnected || element.parentNode !== current.parent || element.getAttribute('style') !== current.host.written
			|| (current.popover && !element.matches(':popover-open'))) {
			close()
			return false
		}
		const rect = element.getBoundingClientRect()
		const { top, left, width, height } = current.box
		if (Math.abs(rect.top - top) > TOLERANCE_PX || Math.abs(rect.left - left) > TOLERANCE_PX
			|| Math.abs(rect.width - width) > TOLERANCE_PX || Math.abs(rect.height - height) > TOLERANCE_PX) return false
		return drawnUntouched(element, current.popover, firefox)
	}

	function sample(current: Bar): void {
		if (!visibleNow(current)) current.lastNotVisibleAt = Date.now()
	}

	function tick(): void {
		const current = bar
		if (!current) return
		current.frame = requestAnimationFrame(tick)
		const modal = modalChange(current)
		if (modal) relocate(current, modal)
		if (bar !== current) return
		// Focus leaving the frame for nothing focusable sends no `focusout` to the host.
		const inBar = focusInBar(current)
		move(current, current.bottom, current.expanded && inBar)
		if (!inBar) finishWhenLeft(current)
		if (bar !== current) return
		// Scrolling with focus kept, or Shift+Tab scrolling a field in, moves the field without a focus event.
		const active = deepActiveElement()
		if (active && active !== current.host.element && active !== document.body && active !== document.documentElement) avoid(current, active)
		if (bar === current) sample(current)
	}

	function finishWhenLeft(current: Bar): void {
		if (bar === current && current.doneWaiting && !current.pointerInside && !focusInBar(current)) close()
	}

	/**
	 * The background shows a capture again on every tab load that completes, so that keeps the bar and its key. Answers
	 * the key the bar holds, which the background keeps valid and checks it issued.
	 */
	function show(key: string, capture: string): string {
		if (bar?.host.element.isConnected && bar.capture === capture) return bar.key
		close()
		const host = createFrameHost('/notification.html', i18n.t('notificationBar.label'))
		const popover = typeof host.element.showPopover === 'function'
		const current: Bar = {
			key, capture, host, parent: (popover ? modalParent() : null) ?? document.documentElement, popover,
			observer: new MutationObserver(onMutations), box: placeBox(false, false), bottom: false, expanded: false, loads: 0, frame: 0,
			lastNotVisibleAt: Date.now(), pointerInside: false, doneWaiting: false,
		}
		bar = current
		host.frame.addEventListener('load', () => {
			if (bar !== current) return
			// The page navigated the frame: the new document gets nothing.
			if (++current.loads > 1) return close()
			host.post({ kind: 'capture.init', key } satisfies ContentToNotification)
		})
		host.element.addEventListener('beforetoggle', () => {
			if (!ownToggle && bar === current) close()
		})
		// Checked when it arrives, after our own moves have shown the popover again.
		host.element.addEventListener('toggle', () => {
			if (bar === current && !host.element.matches(':popover-open')) close()
		})
		host.element.addEventListener('mouseenter', () => (current.pointerInside = true))
		host.element.addEventListener('mouseleave', () => {
			current.pointerInside = false
			finishWhenLeft(current)
		})
		host.element.addEventListener('focusout', () => setTimeout(() => {
			if (bar !== current || focusInBar(current)) return
			move(current, current.bottom, false)
			finishWhenLeft(current)
		}, 0))
		style(current)
		if (popover) host.element.setAttribute('popover', 'manual')
		current.parent.append(host.element)
		if (popover) {
			ownToggle = true
			try {
				host.element.showPopover()
			} finally {
				ownToggle = false
			}
		}
		current.observer.observe(host.element, { attributes: true })
		current.observer.observe(current.parent, { childList: true })
		current.frame = requestAnimationFrame(tick)
		return key
	}

	function hide(key: string, done: boolean): void {
		const current = bar
		if (!current || current.key !== key) return
		// After a save, someone still reading or about to leave the bar keeps it until they do.
		if (done && (current.pointerInside || focusInBar(current))) {
			current.doneWaiting = true
			return
		}
		close()
	}

	function verify(key: string, input: unknown): boolean {
		const current = bar
		if (!current || current.key !== key || current.loads === 0 || !isPickInput(input)) return false
		sample(current)
		if (bar !== current) return false
		const at = Math.min(input.at, Date.now())
		if (current.lastNotVisibleAt >= at - GUARD_MS) return false
		const { top, left, width, height } = current.box
		if (input.x < 0 || input.y < 0 || input.x >= width || input.y >= height) return false
		return hits(current.host.element, left + input.x, top + input.y)
	}

	function focus(): void {
		const current = bar
		if (!current) return
		current.host.frame.focus()
		current.host.post({ kind: 'capture.focus', key: current.key } satisfies ContentToNotification)
	}

	function expand(key: string): void {
		const current = bar
		if (current?.key === key) move(current, current.bottom, true)
	}

	document.addEventListener('focusin', (event) => {
		const current = bar
		if (current && event.target === current.host.element) {
			if (event.isTrusted) move(current, current.bottom, true)
			return
		}
		const target = event.composedPath()[0]
		if (!(target instanceof Element)) return
		pageFocus = target
		if (!current) return
		if (current.doneWaiting) return finishWhenLeft(current)
		move(current, current.bottom, false)
		avoid(current, target)
	}, true)
	document.addEventListener('visibilitychange', () => {
		if (bar) bar.lastNotVisibleAt = Date.now()
	})

	return {
		handle(message) {
			switch (message.kind) {
				case 'capture.show':
					return typeof message.key === 'string' && typeof message.capture === 'string' ? show(message.key, message.capture) : undefined
				case 'capture.hide':
					hide(message.key, message.done === true)
					return undefined
				case 'capture.verify':
					return verify(message.key, message.input)
				case 'capture.focus':
					focus()
					return undefined
				case 'capture.expand':
					expand(message.key)
					return undefined
			}
		},
	}
}
