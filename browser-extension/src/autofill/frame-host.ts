/**
 * An extension page in an iframe inside a closed shadow root, shared by the inline list and the save bar
 * (design.md § Host). Page scripts cannot reach the frame, and page styles cannot restyle the host.
 */
import { noPseudoContent } from './visibility'

export interface FrameHost {
	/** The one element in the page. */
	readonly element: HTMLElement
	readonly frame: HTMLIFrameElement
	/** The origin every message to the frame is posted to, so a frame navigated elsewhere never gets one. */
	readonly origin: string
	/** The style attribute exactly as last written. */
	readonly written: string
	/** Replaces the host's whole style attribute. */
	style(properties: Readonly<Record<string, string>>): void
	post(message: unknown): void
	remove(): void
}

/** `all: initial` first, so no page rule reaches a property this does not set. */
export function importantStyle(properties: Readonly<Record<string, string>>): string {
	return ['all: initial !important;', ...Object.entries(properties).map(([name, value]) => `${name}: ${value} !important;`)].join(' ')
}

/** `light dark`, so a page set to follow the system theme gets the OS's scheme in the frame. */
const FRAME_STYLE = { 'display': 'block', 'width': '100%', 'height': '100%', 'border': '0', 'margin': '0', 'padding': '0', 'background': 'transparent', 'color-scheme': 'light dark' }

const hosts = new WeakMap<Element, () => string>()

/** Our own list or bar, shown as we drew it; a form field under it is still one the user saw. A host the page kept, restyled or decorated is not. */
export function isOwnHost(element: Element): boolean {
	const written = hosts.get(element)
	return written !== undefined && element.isConnected && element.getAttribute('style') === written() && noPseudoContent(element)
}

/** Not appended yet, so the caller can place it and add listeners first. */
export function createFrameHost(page: `/${string}.html`, title: string): FrameHost {
	// A `div`, not a custom element name the page could have defined first.
	const element = document.createElement('div')
	let written = ''
	hosts.set(element, () => written)
	const shadow = element.attachShadow({ mode: 'closed' })
	const frame = document.createElement('iframe')
	frame.setAttribute('style', importantStyle(FRAME_STYLE))
	frame.title = title
	const url = browser.runtime.getURL(page as '/popup.html')
	frame.src = url
	shadow.append(frame)
	// Not `new URL(url).origin`, which the URL standard makes "null" for a non-special scheme.
	const origin = browser.runtime.getURL('/').replace(/\/$/, '')
	return {
		element,
		frame,
		origin,
		get written() {
			return written
		},
		style(properties) {
			written = importantStyle(properties)
			element.setAttribute('style', written)
		},
		post(message) {
			frame.contentWindow?.postMessage(message, origin)
		},
		remove() {
			hosts.delete(element)
			element.remove()
		},
	}
}
