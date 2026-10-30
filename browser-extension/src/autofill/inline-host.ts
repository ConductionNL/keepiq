/**
 * The content-script half of the inline list (design.md § Inline list): when it opens, where it sits, the
 * keyboard relay and the visibility guard. It knows the session key and the list's size, never its contents.
 */
import { i18n } from '#i18n'
import {
	INLINE_MIN_HEIGHT_PX, INLINE_MIN_WIDTH_PX, INLINE_RADIUS_PX, inlineClampHeight,
	type BackgroundToContent, type ContentToBackground, type ContentToInlineList, type InlineMode, type PickInput,
} from '@/src/messages'
import { deepActiveElement, type OpenList } from './execute'
import { isQualifying } from './fields'
import { createFrameHost, type FrameHost } from './frame-host'
import { composedAncestors, drawnUntouched, GUARD_MS, hits, modalOf, untouched } from './visibility'

/** Also how long focus must have stayed before Down. */
export { GUARD_MS }
export const READY_TIMEOUT_MS = 3000
/** Half the 16 px the list keeps clear of the viewport's sides. */
const EDGE_PX = 8
/** Between the field and the list. */
const OFFSET_PX = 4
const TOLERANCE_PX = 0.5

export interface InlineHostOptions {
	/** `inline.open`; resolves to the reply, or anything else when the background cannot be reached. */
	request: (message: ContentToBackground) => Promise<unknown>
	send: (message: ContentToBackground) => void
	/** Firefox has no occlusion report (`trackVisibility`). */
	firefox: boolean
	/** The frame elements above this frame, nearest first, or `null` when one is cross-origin. */
	ancestors?: () => HTMLElement[] | null
}

interface Box {
	top: number
	left: number
	width: number
	height: number
}

interface Open {
	key: string
	field: HTMLInputElement
	host: FrameHost
	/** `<html>`, or the modal holding the field, outside which everything is inert or focus-trapped. */
	parent: Element
	popover: boolean
	observer: MutationObserver
	/** The height the background or the list asked for; `box.height` is that, or less where the frame has less room. */
	wanted: number
	box: Box
	loads: number
	ready: boolean
	readyTimer?: ReturnType<typeof setTimeout>
	frame: number
	lastNotVisibleAt: number
	/** Chrome's own occlusion report; `undefined` where the browser has none. */
	reportedVisible?: boolean
	intersection?: IntersectionObserver
}

/**
 * Only where the guard can read every frame above (design.md § Frames): a cross-origin ancestor could
 * mask or fade the list in ways neither browser reports.
 */
export function sameOriginAncestors(win: Window = window): HTMLElement[] | null {
	const frames: HTMLElement[] = []
	try {
		const origin = win.location.origin
		if (origin === 'null') return null
		for (let current = win; current !== current.top; current = current.parent) {
			const element = current.frameElement as HTMLElement | null
			if (!element || element.ownerDocument.defaultView?.location.origin !== origin) return null
			frames.push(element)
		}
	} catch {
		return null
	}
	return frames
}

function viewport(): { width: number; height: number } {
	const root = document.documentElement
	const standards = document.compatMode === 'CSS1Compat'
	return { width: (standards && root.clientWidth) || window.innerWidth, height: (standards && root.clientHeight) || window.innerHeight }
}

/**
 * Below the field when `height` fits there, else above. `fit`, when the list opens or asks for a height: shrink to the
 * larger free side, down to one row. Otherwise the height is fixed, and a side that cannot hold it is no place.
 */
export function placement(field: Element, height: number, fit = false): Box | null {
	const rect = field.getBoundingClientRect()
	const view = viewport()
	if (rect.width === 0 && rect.height === 0) return null
	if (rect.bottom <= 0 || rect.top >= view.height || rect.right <= 0 || rect.left >= view.width) return null
	const width = Math.min(Math.max(rect.width, INLINE_MIN_WIDTH_PX), view.width - 2 * EDGE_PX)
	if (width < INLINE_MIN_WIDTH_PX) return null
	const below = view.height - rect.bottom - OFFSET_PX
	const above = rect.top - OFFSET_PX
	const fitted = fit ? Math.min(height, Math.floor(Math.max(below, above))) : height
	if (fitted < INLINE_MIN_HEIGHT_PX) return null
	const top = below >= fitted ? rect.bottom + OFFSET_PX : above >= fitted ? rect.top - OFFSET_PX - fitted : null
	if (top === null) return null
	return { top, left: Math.max(0, Math.min(rect.left, view.width - EDGE_PX - width)), width, height: fitted }
}

const isInput = (target: EventTarget | null | undefined): target is HTMLInputElement => target instanceof HTMLInputElement
const deepTarget = (event: Event) => event.composedPath()[0] ?? event.target
const sameBox = (a: Box, b: Box) =>
	Math.abs(a.top - b.top) <= TOLERANCE_PX && Math.abs(a.left - b.left) <= TOLERANCE_PX
	&& Math.abs(a.width - b.width) <= TOLERANCE_PX && Math.abs(a.height - b.height) <= TOLERANCE_PX

const isPickInput = (input: unknown): input is PickInput => {
	const value = input as Partial<PickInput> | null
	return (value?.kind === 'pointer' || value?.kind === 'key') && [value.at, value.x, value.y].every((n) => typeof n === 'number' && Number.isFinite(n))
}

export interface InlineHost {
	start(): void
	/** For `fill.execute`'s `anchorKey`. */
	openList(): OpenList | null
	/** The host-bound `inline.*` kinds; `inline.verify` answers a boolean. */
	handle(message: Extract<BackgroundToContent, { kind: `inline.${string}` }>): boolean | undefined
}

export function createInlineHost(options: InlineHostOptions): InlineHost {
	const { request, send, firefox } = options
	let ancestors: HTMLElement[] | null = []
	let allowed = false
	let open: Open | null = null
	let pending: { field: HTMLInputElement; token: object } | null = null
	/** Focus the content script itself gives back to the field, which must not reopen the list. */
	let suppress: HTMLInputElement | null = null
	let ownToggle = false
	/**
	 * How focus reached the field: `tab` is the user's Tab keydown, which counts only if the page did not
	 * cancel it (and move focus itself); `own` is our refocus.
	 */
	let focused: { field: HTMLInputElement; at: number; pointer: boolean; tab: KeyboardEvent | null; own: boolean } | null = null
	let tabEvent: KeyboardEvent | null = null
	let pointerField: EventTarget | null = null

	const byUser = (state: NonNullable<typeof focused>) => state.own || state.pointer || (state.tab !== null && !state.tab.defaultPrevented)

	/** Focus inside the list goes back to the field whatever `refocus` says, so it is never lost with the frame. */
	function close(refocus = false): void {
		pending = null
		if (!open) return
		const { key, field, host, observer, readyTimer, frame, intersection } = open
		const inList = deepActiveElement() === host.element
		open = null
		observer.disconnect()
		intersection?.disconnect()
		clearTimeout(readyTimer)
		cancelAnimationFrame(frame)
		host.remove()
		send({ kind: 'inline.closed', key })
		if ((refocus || inList) && field.isConnected) refocusField(field)
	}

	/** `focusin` fires synchronously inside `focus()`, so the suppression cannot outlive it. */
	function refocusField(field: HTMLInputElement): void {
		suppress = field
		try {
			field.focus()
		} finally {
			suppress = null
		}
	}

	/** The shadow paints outside the host's box and takes no clicks, so the host is exactly the list. */
	function style(list: Open, box: Box): void {
		list.host.style({
			'position': 'fixed', 'top': `${box.top}px`, 'left': `${box.left}px`, 'width': `${box.width}px`, 'height': `${box.height}px`,
			'margin': '0', 'padding': '0', 'border': '0', 'display': 'block', 'overflow': 'clip', 'z-index': '2147483647',
			'border-radius': `${INLINE_RADIUS_PX}px`, 'box-shadow': '0 6px 20px rgba(0, 0, 0, 0.16), 0 1px 4px rgba(0, 0, 0, 0.12)',
			'opacity': '1', 'visibility': 'visible', 'pointer-events': 'auto', 'background': 'transparent', 'color-scheme': 'normal',
			'transform': 'none', 'scale': 'none', 'rotate': 'none', 'translate': 'none', 'zoom': '1',
			'filter': 'none', 'mix-blend-mode': 'normal', 'clip-path': 'none', 'mask': 'none',
		})
	}

	function onMutations(records: MutationRecord[]): void {
		if (!open) return
		const host = open.host.element
		if (records.some((record) => record.target === host || [...record.removedNodes].includes(host))) close()
	}

	/** Our own writes are dropped from the observer; anything the page did before them still closes the list. */
	function move(list: Open, box: Box): void {
		onMutations(list.observer.takeRecords())
		if (open !== list) return
		style(list, box)
		list.observer.takeRecords()
		list.box = box
		list.lastNotVisibleAt = Date.now()
		if (list.loads > 0) list.host.post({ kind: 'inline.moved', key: list.key } satisfies ContentToInlineList)
	}

	/** Whether the list is drawn as set, now; closes it when the page tampered with the host. */
	function visibleNow(list: Open): boolean {
		const host = list.host.element
		// A popover the page hid while stopping its toggle events still hit-tests with our `display: block`.
		if (!host.isConnected || host.parentNode !== list.parent || host.getAttribute('style') !== list.host.written
			|| (list.popover && !host.matches(':popover-open'))) {
			close()
			return false
		}
		const rect = host.getBoundingClientRect()
		if (!sameBox({ top: rect.top, left: rect.left, width: rect.width, height: rect.height }, list.box)) return false
		if (list.reportedVisible === false) return false
		if (!drawnUntouched(host, list.popover, firefox)) return false
		for (const frameElement of ancestors ?? []) {
			for (const element of composedAncestors(frameElement)) {
				if (!untouched(element, true)) return false
			}
		}
		return true
	}

	function sample(list: Open): void {
		if (!visibleNow(list)) list.lastNotVisibleAt = Date.now()
	}

	function fieldShown(field: HTMLInputElement): boolean {
		const style = getComputedStyle(field)
		return field.isConnected && style.visibility !== 'hidden' && style.display !== 'none'
	}

	function tick(): void {
		const list = open
		if (!list) return
		list.frame = requestAnimationFrame(tick)
		const box = fieldShown(list.field) ? placement(list.field, list.box.height) : null
		if (!box) return close()
		if (!sameBox(box, list.box)) move(list, box)
		if (open === list) sample(list)
	}

	/** Content that needs another height, such as the insecure question; a move like any other for the guard. */
	function resize(list: Open, height: unknown): void {
		const wanted = inlineClampHeight(height)
		if (wanted === null || wanted === list.wanted) return
		list.wanted = wanted
		const box = fieldShown(list.field) ? placement(list.field, wanted, true) : null
		if (!box) return close()
		if (!sameBox(box, list.box)) move(list, box)
	}

	function watchIntersection(list: Open): void {
		if (firefox || typeof IntersectionObserverEntry === 'undefined' || !('isVisible' in IntersectionObserverEntry.prototype)) return
		list.reportedVisible = false
		const init = { threshold: [1], trackVisibility: true, delay: 100 } as IntersectionObserverInit
		list.intersection = new IntersectionObserver((entries) => {
			for (const entry of entries) list.reportedVisible = (entry as IntersectionObserverEntry & { isVisible?: boolean }).isVisible === true
			if (!list.reportedVisible) list.lastNotVisibleAt = Date.now()
		}, init)
		list.intersection.observe(list.host.element)
	}

	function show(field: HTMLInputElement, mode: InlineMode, key: string, wanted: number, box: Box): void {
		const host = createFrameHost('/inline-list.html', i18n.t(mode === 'code' ? 'inlineList.code.frameTitle' : 'inlineList.frameTitle'))
		const popover = typeof host.element.showPopover === 'function'
		const list: Open = {
			key, field, host, parent: modalOf(field, popover) ?? document.documentElement, popover,
			wanted, box, loads: 0, ready: false, frame: 0, lastNotVisibleAt: Date.now(),
			observer: new MutationObserver(onMutations),
		}
		open = list
		host.frame.addEventListener('load', () => {
			if (open !== list) return
			// The page navigated the frame: the new document gets nothing.
			if (++list.loads > 1) return close()
			host.post({ kind: 'inline.init', key } satisfies ContentToInlineList)
			list.readyTimer = setTimeout(() => {
				if (open === list && !list.ready) close()
			}, READY_TIMEOUT_MS)
		})
		host.element.addEventListener('beforetoggle', () => {
			if (!ownToggle && open === list) close()
		})
		host.element.addEventListener('toggle', (event) => {
			if (open === list && (event as ToggleEvent).newState !== 'open') close()
		})
		style(list, box)
		if (popover) host.element.setAttribute('popover', 'manual')
		list.parent.append(host.element)
		if (popover) {
			ownToggle = true
			try {
				host.element.showPopover()
			} finally {
				ownToggle = false
			}
		}
		list.observer.observe(host.element, { attributes: true })
		list.observer.observe(list.parent, { childList: true })
		watchIntersection(list)
		list.frame = requestAnimationFrame(tick)
	}

	function maybeOpen(field: HTMLInputElement, how: 'focus' | 'click'): void {
		if (!allowed || open?.field === field || pending?.field === field) return
		if (how === 'focus' && field.value !== '') return
		const mode = isQualifying(field)
		if (!mode) return
		close()
		// Whatever the list will show, it needs room for one row.
		if (!placement(field, INLINE_MIN_HEIGHT_PX)) return
		const token = {}
		pending = { field, token }
		void request({ kind: 'inline.open', mode }).then((reply) => {
			const { key, height } = (reply ?? {}) as { key?: unknown; height?: unknown }
			if (typeof key !== 'string') {
				if (pending?.token === token) pending = null
				return
			}
			const wanted = inlineClampHeight(height)
			// A split code's fill moves focus box by box, and fills each box before this reply arrives.
			const stillEmpty = how === 'click' || field.value === ''
			const box = wanted !== null && pending?.token === token && deepActiveElement() === field && fieldShown(field) && stillEmpty
				? placement(field, wanted, true) : null
			if (pending?.token === token) pending = null
			if (box && wanted !== null) show(field, mode, key, wanted, box)
			else send({ kind: 'inline.closed', key })
		})
	}

	function onFocusIn(event: FocusEvent): void {
		// Script focus is trusted too; only events the page dispatches itself are not.
		if (!event.isTrusted) return
		const target = deepTarget(event)
		const tab = tabEvent
		const pointer = pointerField === target
		tabEvent = null
		pointerField = null
		// Browsers retarget focus inside the closed shadow root to the host.
		if (open && (event.target === open.host.element || target === open.host.frame)) return
		if (open && target !== open.field) close()
		if (pending && target !== pending.field) pending = null
		if (!isInput(target)) {
			focused = null
			return
		}
		focused = { field: target, at: Date.now(), pointer, tab, own: target === suppress }
		if (target !== suppress) maybeOpen(target, 'focus')
	}

	function onFocusOut(event: FocusEvent): void {
		const list = open
		if (!event.isTrusted || !list || deepTarget(event) !== list.field) return
		// Focus into the list is the host in this document; a window losing focus keeps the field active.
		setTimeout(() => {
			const active = deepActiveElement()
			if (open === list && active !== list.field && active !== list.host.element) close()
		}, 0)
	}

	function onKeyDown(event: KeyboardEvent): void {
		if (!event.isTrusted) return
		if (event.key === 'Tab') tabEvent = event
		const list = open
		if (!list || deepTarget(event) !== list.field) return
		const noCommand = !event.altKey && !event.ctrlKey && !event.metaKey
		// The list comes after the page's controls in tab order, so Tab out of the field must not land in it.
		if (event.key === 'Tab' && noCommand) return close()
		if (event.key === 'ArrowDown' && noCommand && !event.shiftKey && !event.repeat && list.ready) {
			const held = focused?.field === list.field && (byUser(focused) || Date.now() - focused.at >= GUARD_MS)
			if (!held) return
			event.preventDefault()
			event.stopImmediatePropagation()
			list.host.frame.focus()
			list.host.post({ kind: 'inline.focus', key: list.key } satisfies ContentToInlineList)
		} else if (event.key === 'Escape') {
			event.preventDefault()
			event.stopImmediatePropagation()
			close()
		}
	}

	function onPointerDown(event: PointerEvent): void {
		if (!event.isTrusted) return
		pointerField = deepTarget(event)
		if (focused && focused.field === pointerField) focused.pointer = true
	}

	function onClick(event: MouseEvent): void {
		const target = deepTarget(event)
		if (event.isTrusted && isInput(target)) maybeOpen(target, 'click')
	}

	function onInput(event: Event): void {
		// The fill's own events are not trusted, and the list closes after it anyway.
		if (!event.isTrusted) return
		const target = deepTarget(event)
		if (pending?.field === target) pending = null
		if (open?.field === target) close()
	}

	function verify(key: string, input: unknown): boolean {
		const list = open
		if (!list || list.key !== key || !list.ready || !isPickInput(input)) return false
		sample(list)
		if (open !== list) return false
		const at = Math.min(input.at, Date.now())
		if (list.lastNotVisibleAt >= at - GUARD_MS) return false
		if (input.x < 0 || input.y < 0 || input.x >= list.box.width || input.y >= list.box.height) return false
		let x = list.box.left + input.x
		let y = list.box.top + input.y
		if (!hits(list.host.element, x, y)) return false
		for (const frameElement of ancestors ?? []) {
			const rect = frameElement.getBoundingClientRect()
			const style = frameElement.ownerDocument.defaultView!.getComputedStyle(frameElement)
			x += rect.left + (frameElement.clientLeft || 0) + (Number.parseFloat(style.paddingLeft) || 0)
			y += rect.top + (frameElement.clientTop || 0) + (Number.parseFloat(style.paddingTop) || 0)
			if (!hits(frameElement, x, y)) return false
		}
		return true
	}

	return {
		start() {
			ancestors = (options.ancestors ?? sameOriginAncestors)()
			allowed = /^https?:$/.test(location.protocol) && ancestors !== null
			if (!allowed) return
			document.addEventListener('focusin', onFocusIn, true)
			document.addEventListener('focusout', onFocusOut, true)
			document.addEventListener('keydown', onKeyDown, true)
			document.addEventListener('keyup', () => (tabEvent = null), true)
			document.addEventListener('pointerdown', onPointerDown, true)
			document.addEventListener('click', onClick, true)
			document.addEventListener('input', onInput, true)
			document.addEventListener('visibilitychange', () => {
				if (document.visibilityState === 'hidden') close()
			})
			window.addEventListener('pagehide', () => close())
			// A field focused before `document_idle`, `autofocus` included.
			const active = deepActiveElement()
			if (isInput(active)) {
				focused = { field: active, at: Date.now(), pointer: false, tab: null, own: false }
				maybeOpen(active, 'focus')
			}
		},
		openList() {
			return open ? { key: open.key, field: open.field } : null
		},
		handle(message) {
			const list = open
			if (message.kind === 'inline.verify') return verify(message.key, message.input)
			if (!list || message.key !== list.key) return undefined
			if (message.kind === 'inline.ready') {
				list.ready = true
				clearTimeout(list.readyTimer)
			} else if (message.kind === 'inline.refocus') refocusField(list.field)
			else if (message.kind === 'inline.close') close(message.refocus === true)
			else if (message.kind === 'inline.resize') resize(list, message.height)
			return undefined
		},
	}
}
