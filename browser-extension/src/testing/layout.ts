import { vi } from 'vitest'

const SLOT = Symbol('slot')
type Placed = Element & { [SLOT]?: number }

const px = (value: string | undefined) => {
	const number = Number.parseFloat(value ?? '')
	return Number.isNaN(number) ? undefined : number
}

const boxOf = (element: Placed) => {
	const style = (element as HTMLElement).style
	return { left: px(style?.left) ?? element[SLOT]!, top: px(style?.top) ?? 0, width: px(style?.width) ?? 120, height: px(style?.height) ?? 20 }
}

/** Every element below `root`, open shadow roots included. */
const deepElements = (root: Document | ShadowRoot): Element[] =>
	[...root.querySelectorAll('*')].flatMap((element) => [element, ...(element.shadowRoot ? deepElements(element.shadowRoot) : [])])

/** `element` and the shadow hosts above it, innermost first. */
function hostsOf(element: Element): Element[] {
	const chain: Element[] = []
	for (let at: Element | undefined = element; at; at = (at.getRootNode() as Partial<ShadowRoot>).host) chain.push(at)
	return chain
}

/** `element` as seen from `root`: itself, or the shadow host above it that lives in `root`. */
const retarget = (element: Element, root: Node): Element | null => hostsOf(element).find((at) => at.getRootNode() === root) ?? null

/**
 * happy-dom has no layout. Each element measured gets a 120 by 20 box of its own, one pixel right of the one before, unless its inline `left`, `top`, `width` or `height` says otherwise.
 * A hit test at a box's centre finds that element, under any element whose `data-covers` names its id or a shadow host's above it.
 * An element scrolls through its `data-scroll-width` and `data-scroll-height`, else its box. Call it in `beforeEach`.
 */
export function mockLayout(): void {
	// A symbol on the node, not a map by identity: happy-dom hands a `<select>`'s methods its target, not the proxy the page holds.
	let slots = 0
	vi.spyOn(Element.prototype, 'getBoundingClientRect').mockImplementation(function (this: Placed) {
		this[SLOT] ??= slots++
		const { left, top, width, height } = boxOf(this)
		return { x: left, y: top, left, top, width, height, right: left + width, bottom: top + height, toJSON: () => ({}) } as DOMRect
	})
	vi.spyOn(Element.prototype, 'scrollWidth', 'get').mockImplementation(function (this: Placed) {
		return px(this.getAttribute('data-scroll-width') ?? undefined) ?? boxOf(this).width
	})
	vi.spyOn(Element.prototype, 'scrollHeight', 'get').mockImplementation(function (this: Placed) {
		return px(this.getAttribute('data-scroll-height') ?? undefined) ?? boxOf(this).height
	})
	function elementsFromPoint(this: Document | ShadowRoot, ...[x, y]: number[]): Element[] {
		const at = (element: Placed) => element[SLOT] !== undefined && boxOf(element).left + boxOf(element).width / 2 === x && boxOf(element).top + boxOf(element).height / 2 === y
		const element = deepElements(document).find(at)
		if (!element) return []
		const covers = hostsOf(element).flatMap((each) => (each.id ? [...document.querySelectorAll(`[data-covers~="${each.id}"]`)] : []))
		return [...covers, element].map((each) => retarget(each, this)).filter((each): each is Element => each !== null)
	}
	// The window's own classes, which happy-dom's globals `Document` and `ShadowRoot` are not.
	const shadow = document.createElement('div').attachShadow({ mode: 'open' })
	for (const prototype of [Object.getPrototypeOf(document), Object.getPrototypeOf(shadow)]) {
		Object.defineProperty(prototype, 'elementsFromPoint', { configurable: true, value: elementsFromPoint })
	}
}
