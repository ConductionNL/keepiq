// @vitest-environment happy-dom
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { composedAncestors, drawnUntouched, hits, modalOf, noPseudoContent, untouched } from './visibility'

/** Computed styles as set here, per element and pseudo-element; happy-dom computes few of these. */
let styles: Map<Element, Map<string, Record<string, string>>>
function set(element: Element, properties: Record<string, string>, pseudo = '') {
	const own = styles.get(element) ?? new Map<string, Record<string, string>>()
	styles.set(element, own.set(pseudo, { ...own.get(pseudo), ...properties }))
}

beforeEach(() => {
	styles = new Map()
	vi.spyOn(window, 'getComputedStyle').mockImplementation((element: Element, pseudo?: string | null) => {
		const values = styles.get(element)?.get(pseudo ?? '') ?? {}
		return new Proxy({ getPropertyValue: (name: string) => values[name] ?? '' }, {
			get: (target, name) => (name === 'getPropertyValue' ? target.getPropertyValue : values[String(name)] ?? ''),
		}) as unknown as CSSStyleDeclaration
	})
	document.body.innerHTML = '<div id="outer"><div id="inner"><div id="host"></div></div></div>'
})

const $ = (id: string) => document.getElementById(id)!

describe('untouched', () => {
	it('passes an element drawn as it is', () => {
		set($('host'), { opacity: '1', zoom: '1', transform: 'none', visibility: 'visible', 'mix-blend-mode': 'normal' })
		expect(untouched($('host'))).toBe(true)
	})

	it.each([
		['opacity', '0.99'], ['zoom', '2'], ['visibility', 'hidden'], ['visibility', 'collapse'], ['mix-blend-mode', 'multiply'],
		['transform', 'matrix(2, 0, 0, 2, 0, 0)'], ['translate', '10px 10px'],
		['filter', 'opacity(0)'], ['scale', '0.5'], ['rotate', '1deg'], ['clip-path', 'inset(50%)'],
		['mask', 'url(#m)'], ['mask-image', 'linear-gradient(transparent, transparent)'], ['mask-border-source', 'url(m.png)'],
		['-webkit-mask-image', 'linear-gradient(transparent, transparent)'], ['-webkit-mask-box-image', 'url(m.png)'],
	])('refuses %s: %s', (property, value) => {
		set($('host'), { [property]: value })
		expect(untouched($('host'))).toBe(false)
	})

	it('accepts a plain translation only where asked, as on an ancestor frame\'s container', () => {
		set($('host'), { transform: 'matrix(1, 0, 0, 1, 10, -20)', translate: '10px 20px' })
		expect(untouched($('host'))).toBe(false)
		expect(untouched($('host'), true)).toBe(true)
		set($('host'), { translate: '10px 20px 5px' })
		expect(untouched($('host'), true)).toBe(false)
	})

	it.each(['matrix(2, 0, 0, 2, 0, 0)', 'matrix(1, 0.5, 0, 1, 0, 0)', 'matrix3d(1, 0, 0, 0, 0, 1, 0, 0, 0, 0, 1, 0, 0, 0, 5, 1)'])(
		'refuses transform %s even where a translation is fine', (transform) => {
			set($('host'), { transform })
			expect(untouched($('host'), true)).toBe(false)
		})
})

describe('noPseudoContent', () => {
	it.each(['::before', '::after'])('refuses content generated in %s, which could draw over the host', (pseudo) => {
		expect(noPseudoContent($('host'))).toBe(true)
		set($('host'), { content: 'none' }, pseudo)
		expect(noPseudoContent($('host'))).toBe(true)
		set($('host'), { content: '""' }, pseudo)
		expect(noPseudoContent($('host'))).toBe(false)
	})
})

describe('drawnUntouched', () => {
	it('refuses a host with generated content or a style of its own, in or out of the top layer', () => {
		set($('host'), { content: '"x"' }, '::after')
		expect(drawnUntouched($('host'), true, false)).toBe(false)
		styles.clear()
		set($('host'), { opacity: '0' })
		expect(drawnUntouched($('host'), true, false)).toBe(false)
		expect(drawnUntouched($('host'), false, false)).toBe(false)
	})

	it('without the popover API, refuses any touched ancestor up to <html>, across shadow roots', () => {
		const shadowHost = $('inner')
		const shadow = shadowHost.attachShadow({ mode: 'open' })
		const host = document.createElement('div')
		host.id = 'deep'
		shadow.append(host)
		expect(drawnUntouched(host, false, false)).toBe(true)
		set($('outer'), { opacity: '0' })
		expect(drawnUntouched(host, false, false)).toBe(false)
		styles.clear()
		set(document.documentElement, { filter: 'invert(1)' })
		expect(drawnUntouched(host, false, false)).toBe(false)
		expect([...composedAncestors(host)].map((element) => element.id || element.localName)).toEqual(['deep', 'inner', 'outer', 'body', 'html'])
	})

	it('in the top layer, ignores ancestors, except a filter on <html> on Firefox', () => {
		set($('outer'), { opacity: '0', transform: 'scale(0.1)' })
		expect(drawnUntouched($('host'), true, false)).toBe(true)
		set(document.documentElement, { filter: 'invert(1)' })
		expect(drawnUntouched($('host'), true, false)).toBe(true)
		expect(drawnUntouched($('host'), true, true)).toBe(false)
	})
})

describe('modalOf', () => {
	it('finds an aria-modal ancestor only when asked, and never the element itself', () => {
		$('outer').setAttribute('aria-modal', 'true')
		expect(modalOf($('host'), true)).toBe($('outer'))
		expect(modalOf($('host'), false)).toBeNull()
		expect(modalOf($('outer'), true)).toBeNull()
	})
})

describe('hits', () => {
	it('passes only when the point reaches the element, through its shadow root', () => {
		const shadow = $('inner').attachShadow({ mode: 'open' })
		const host = document.createElement('div')
		shadow.append(host)
		const fromPoint = vi.spyOn(document, 'elementFromPoint').mockReturnValue($('inner'))
		Object.defineProperty(shadow, 'elementFromPoint', { value: () => host })
		expect(hits(host, 1, 1)).toBe(true)
		fromPoint.mockReturnValue($('outer'))
		expect(hits(host, 1, 1)).toBe(false)
	})
})
