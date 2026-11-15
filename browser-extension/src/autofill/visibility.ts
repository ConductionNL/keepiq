/** The visibility guard's checks, shared by the inline list and the save bar (design.md § Visibility guard). */

/** How long a host must have stayed visible before a press counts. */
export const GUARD_MS = 500

/** Across shadow roots. Node types rather than `instanceof`, since ancestor frames are other realms. */
function composedParent(node: Node): Node | null {
	return node.nodeType === Node.DOCUMENT_FRAGMENT_NODE && 'host' in node ? (node as ShadowRoot).host : node.parentNode
}

export function* composedAncestors(element: Element): Generator<Element> {
	for (let node: Node | null = element; node; node = composedParent(node)) {
		if (node.nodeType === Node.ELEMENT_NODE) yield node as Element
	}
}

function isModal(element: Element): boolean {
	try {
		return element.matches(':modal')
	} catch {
		return false
	}
}

/**
 * The modal holding `element`, if any. `aria`: also a script-trapped `aria-modal` one, whose focus trap would
 * pull focus out of a host outside it; only for a popover host, which paints there whatever the modal's styling.
 */
export function modalOf(element: Element, aria: boolean): Element | null {
	for (const ancestor of composedAncestors(element)) {
		if (ancestor !== element && (isModal(ancestor) || (aria && ancestor.getAttribute('aria-modal') === 'true'))) return ancestor
	}
	return null
}

/** Whether a press at the point reaches `element`, through every shadow root above it, looking through what `skip` names. */
export function hits(element: Element, x: number, y: number, skip?: (found: Element) => boolean): boolean {
	let target = element
	for (;;) {
		const root = target.getRootNode() as Document | ShadowRoot
		if (root.nodeType !== Node.DOCUMENT_NODE && root.nodeType !== Node.DOCUMENT_FRAGMENT_NODE) return false
		const top = skip ? root.elementsFromPoint?.(x, y).find((found) => !skip(found)) : root.elementFromPoint(x, y)
		if (top !== target) return false
		if (root.nodeType === Node.DOCUMENT_NODE) return true
		target = (root as ShadowRoot).host
	}
}

export const none = (value: string | undefined) => !value || value === 'none'

const NONE_PROPERTIES = [
	'filter', 'scale', 'rotate', 'clip-path',
	'mask', 'mask-image', 'mask-border-source', '-webkit-mask-image', '-webkit-mask-box-image',
]

/** A computed `transform` that only moves, which the hit-test mapping follows through `getBoundingClientRect`. */
const isTranslation = (transform: string) => /^matrix\(1, 0, 0, 1, -?[\d.e+-]+, -?[\d.e+-]+\)$/.test(transform)

/**
 * No opacity, filter, blending, transform, zoom, hiding, clipping or mask of its own. `translation`: a plain
 * move is fine, as on an ancestor frame's container.
 */
export function untouched(element: Element, translation = false): boolean {
	const style = element.ownerDocument.defaultView!.getComputedStyle(element)
	return drawnAsIs(style) && movedAtMost(style, translation) && NONE_PROPERTIES.every((property) => none(style.getPropertyValue(property)))
}

/** Fully opaque, unblended, unzoomed and shown. */
const drawnAsIs = (style: CSSStyleDeclaration) => unblended(style) && unzoomed(style) && style.visibility !== 'hidden' && style.visibility !== 'collapse'

function unblended(style: CSSStyleDeclaration): boolean {
	const blend = style.getPropertyValue('mix-blend-mode')
	return (!style.opacity || Number(style.opacity) === 1) && (!blend || blend === 'normal')
}

function unzoomed(style: CSSStyleDeclaration): boolean {
	const zoom = style.getPropertyValue('zoom')
	return !zoom || zoom === 'normal' || Number(zoom) === 1
}

/** No transform or translation, or with `translation` a plain move only. */
const movedAtMost = (style: CSSStyleDeclaration, translation: boolean) => transformedAtMost(style, translation) && translatedAtMost(style, translation)

function transformedAtMost(style: CSSStyleDeclaration, translation: boolean): boolean {
	const transform = style.getPropertyValue('transform')
	return none(transform) || (translation && isTranslation(transform))
}

/** Two lengths at most: a third, depth, would scale under a perspective. */
function translatedAtMost(style: CSSStyleDeclaration, translation: boolean): boolean {
	const translate = style.getPropertyValue('translate')
	return none(translate) || (translation && translate.trim().split(/\s+/).length <= 2)
}

/** Generated content the page could draw over the host from inside its own box. */
export function noPseudoContent(element: Element): boolean {
	return ['::before', '::after'].every((pseudo) => {
		const content = getComputedStyle(element, pseudo).content
		return !content || content === 'none' || content === 'normal'
	})
}

/**
 * Whether a host shown in the top layer (`popover`) or fixed on the page is drawn untouched now. A showing popover
 * paints, and takes clicks, whatever its ancestors' opacity, filter, transform, mask or visibility; what can still
 * hide it shows in the rect or the hit test, except a `filter` on the root where `rootFilterReachesTopLayer`. Without
 * the popover API (Firefox below 125) every ancestor counts.
 */
export function drawnUntouched(host: Element, popover: boolean, rootFilterReachesTopLayer: boolean): boolean {
	if (!noPseudoContent(host) || !untouched(host)) return false
	if (popover && rootFilterReachesTopLayer && !none(getComputedStyle(document.documentElement).filter)) return false
	if (!popover) {
		for (const element of composedAncestors(host)) {
			if (!untouched(element)) return false
		}
	}
	return true
}
