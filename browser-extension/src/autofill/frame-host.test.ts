// @vitest-environment happy-dom
// @vitest-environment-options {"settings":{"navigation":{"disableChildFrameNavigation":true}}}
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createFrameHost, importantStyle, isOwnHost } from './frame-host'

afterEach(() => {
	document.documentElement.querySelectorAll(':scope > div').forEach((element) => element.remove())
	document.head.innerHTML = ''
})

describe('createFrameHost', () => {
	it('puts the extension page in an iframe inside a closed shadow root', () => {
		const attach = vi.spyOn(Element.prototype, 'attachShadow')
		const host = createFrameHost('/inline-list.html', 'Keepiq logins')
		expect(host.element.tagName).toBe('DIV')
		expect(attach).toHaveBeenCalledWith({ mode: 'closed' })
		expect(host.element.shadowRoot).toBeNull()
		expect(host.frame.src).toBe(browser.runtime.getURL('/inline-list.html' as '/popup.html'))
		expect(host.frame.title).toBe('Keepiq logins')
		// So the list page's "System" theme follows the OS.
		expect(host.frame.getAttribute('style')).toContain('color-scheme: light dark !important;')
		expect(host.origin).toBe('chrome-extension://test-extension-id')
		expect(host.element.isConnected).toBe(false)
	})

	it('writes the whole style attribute, all: initial first and every property important', () => {
		const host = createFrameHost('/inline-list.html', 'Keepiq logins')
		host.style({ position: 'fixed', top: '10px' })
		expect(host.element.getAttribute('style')).toBe('all: initial !important; position: fixed !important; top: 10px !important;')
		expect(host.written).toBe(host.element.getAttribute('style'))
		expect(importantStyle({})).toBe('all: initial !important;')
	})

	it('keeps page stylesheets from restyling the host', () => {
		document.head.innerHTML = '<style>html > div { opacity: 0 !important; top: 500px !important; }</style>'
		const host = createFrameHost('/inline-list.html', 'Keepiq logins')
		host.style({ position: 'fixed', top: '10px', opacity: '1' })
		document.documentElement.append(host.element)
		expect(getComputedStyle(host.element).opacity).toBe('1')
		expect(getComputedStyle(host.element).top).toBe('10px')
	})

	it('posts only to the extension origin, so a frame navigated elsewhere gets nothing', () => {
		const host = createFrameHost('/inline-list.html', 'Keepiq logins')
		const postMessage = vi.fn()
		Object.defineProperty(host.frame, 'contentWindow', { value: { postMessage } })
		host.post({ kind: 'inline.init', key: 'k' })
		expect(postMessage).toHaveBeenCalledWith({ kind: 'inline.init', key: 'k' }, host.origin)
	})

	it('removes itself', () => {
		const host = createFrameHost('/inline-list.html', 'Keepiq logins')
		document.documentElement.append(host.element)
		host.remove()
		expect(host.element.isConnected).toBe(false)
	})
})

describe('isOwnHost', () => {
	const shown = () => {
		const host = createFrameHost('/inline-list.html', 'Keepiq logins')
		host.style({ position: 'fixed', top: '10px' })
		document.documentElement.append(host.element)
		return host
	}

	it('is a host we created, connected and styled as we wrote it', () => {
		expect(isOwnHost(shown().element)).toBe(true)
	})

	it('is no element of the page, even one that copies a host', () => {
		const copy = document.createElement('div')
		copy.setAttribute('style', shown().written)
		document.documentElement.append(copy)
		expect(isOwnHost(copy)).toBe(false)
	})

	it('is no host before it is styled or appended', () => {
		const host = createFrameHost('/inline-list.html', 'Keepiq logins')
		expect(isOwnHost(host.element)).toBe(false)
		host.style({ position: 'fixed' })
		expect(isOwnHost(host.element)).toBe(false)
	})

	it('is no host the page restyled, decorated or kept after we removed it', () => {
		const restyled = shown()
		restyled.element.setAttribute('style', `${restyled.written} opacity: 0.5 !important;`)
		expect(isOwnHost(restyled.element)).toBe(false)
		const decorated = shown()
		const pseudo = vi.spyOn(window, 'getComputedStyle').mockReturnValue({ content: '"x"' } as CSSStyleDeclaration)
		expect(isOwnHost(decorated.element)).toBe(false)
		pseudo.mockRestore()
		expect(isOwnHost(decorated.element)).toBe(true)
		const removed = shown()
		removed.remove()
		document.documentElement.append(removed.element)
		expect(isOwnHost(removed.element)).toBe(false)
	})
})
