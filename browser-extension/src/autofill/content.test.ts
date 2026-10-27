// @vitest-environment happy-dom
// @vitest-environment-options {"settings":{"navigation":{"disableChildFrameNavigation":true}}}
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fakeBrowser } from 'wxt/testing/fake-browser'
// Here, not beside the entrypoint: WXT reads every top-level file in entrypoints/ as one.
import content from '@/entrypoints/content'
import { inlineHeight } from '@/src/messages'

type Listener = (msg: unknown, sender: object, sendResponse: (reply: unknown) => void) => unknown

const fromBackground = { id: fakeBrowser.runtime.id }
let listener: Listener
let sent: unknown[]

/** The reply the content script gives, or `undefined` when it gives none. */
function ask(message: unknown, sender: object = fromBackground): unknown {
	let reply: unknown
	listener(message, sender, (value) => (reply = value))
	return reply
}

/** `null` keeps the page as it is, focus included. */
function start(html: string | null = '') {
	if (html !== null) document.body.innerHTML = html
	const addListener = vi.spyOn(browser.runtime.onMessage, 'addListener')
	content.main({} as never)
	listener = addListener.mock.calls[0]![0] as Listener
}

beforeEach(() => {
	fakeBrowser.reset()
	vi.spyOn(Element.prototype, 'getBoundingClientRect').mockReturnValue({ x: 0, y: 0, top: 0, left: 0, right: 120, bottom: 20, width: 120, height: 20, toJSON: () => ({}) } as DOMRect)
	sent = []
	vi.spyOn(browser.runtime, 'sendMessage').mockImplementation(async (message: unknown) => void sent.push(message) as never)
})

describe('content script', () => {
	it('runs in every frame of every http(s) page', () => {
		expect(content).toMatchObject({ matches: ['*://*/*'], allFrames: true, runAt: 'document_idle' })
	})

	it('announces itself, saying whether the page has a password field', () => {
		start('<form><input name="username"><input type="password"><button>Log in</button></form>')
		expect(sent).toEqual([{ kind: 'page_ready', url: location.href, passwordField: true }])
		start('<input name="q">')
		expect(sent.at(-1)).toEqual({ kind: 'page_ready', url: location.href, passwordField: false })
	})

	it('fills on fill.execute and answers what it filled', () => {
		start('<form><input id="u" name="username"><input id="p" type="password"><button>Log in</button></form>')
		expect(ask({ kind: 'fill.execute', username: 'alice', password: 'hunter2', origin: location.origin })).toEqual({ filled: 'both' })
		expect((document.querySelector('#p') as HTMLInputElement).value).toBe('hunter2')
	})

	it('asks the insecure question with window.confirm and answers the choice', () => {
		start()
		// happy-dom has no dialogs.
		const confirm = vi.fn<(text?: string) => boolean>().mockReturnValueOnce(false).mockReturnValueOnce(true)
		vi.stubGlobal('confirm', confirm)
		expect(ask({ kind: 'fill.confirmInsecure', itemHost: 'example.test' })).toBe(false)
		expect(ask({ kind: 'fill.confirmInsecure', itemHost: 'example.test' })).toBe(true)
		expect(confirm.mock.calls[0]![0]).toContain('example.test')
		expect(confirm.mock.calls[0]![0]).toContain('unsecured HTTP page')
	})

	it('words the question without a host when it has none', () => {
		start()
		const confirm = vi.fn<(text?: string) => boolean>(() => true)
		vi.stubGlobal('confirm', confirm)
		ask({ kind: 'fill.confirmInsecure', itemHost: '' })
		expect(confirm.mock.calls[0]![0]).toContain('secure (HTTPS) page.')
		expect(confirm.mock.calls[0]![0]).not.toContain('page, .')
	})

	it('announces a prerendered page only once it is shown', () => {
		Object.defineProperty(document, 'prerendering', { value: true, configurable: true })
		try {
			start()
			expect(sent).toEqual([])
			document.dispatchEvent(new Event('prerenderingchange'))
			expect(sent).toEqual([expect.objectContaining({ kind: 'page_ready' })])
		} finally {
			delete (document as { prerendering?: boolean }).prerendering
		}
	})

	it('registers again when restored from the back/forward cache, without asking to be filled on load', () => {
		start('<form><input name="username"><input type="password"><button>Log in</button></form>')
		expect(sent).toEqual([expect.objectContaining({ passwordField: true })])
		// Earlier tests' scripts listen on the same window too, so only what this dispatch adds counts.
		sent = []
		window.dispatchEvent(Object.assign(new Event('pageshow'), { persisted: false }))
		expect(sent).toEqual([])
		window.dispatchEvent(Object.assign(new Event('pageshow'), { persisted: true }))
		expect(sent.length).toBeGreaterThan(0)
		// The background fills on load only for `passwordField: true` (background.test.ts, fill on page load).
		expect(sent.every((m) => (m as { kind: string; passwordField: boolean }).kind === 'page_ready' && !(m as { passwordField: boolean }).passwordField)).toBe(true)
	})

	describe('inline list', () => {
		const KEY = 'c'.repeat(32)
		// A new one each time: the content script drops the credential from the message it filled from.
		const fill = () => ({ kind: 'fill.execute', username: 'alice', password: 'hunter2', origin: location.origin, anchorKey: KEY })

		it('refuses inline.verify and an inline fill while no list is open', () => {
			start('<form><input name="username"><input id="p" type="password"><button>Log in</button></form>')
			expect(ask({ kind: 'inline.verify', key: KEY, input: { kind: 'pointer', at: Date.now() - 10_000, x: 1, y: 1 } })).toBe(false)
			expect(ask(fill())).toEqual({ filled: 'none' })
			expect((document.querySelector('#p') as HTMLInputElement).value).toBe('')
		})

		it('opens on a field focused before it started, and fills that form with the list’s key', async () => {
			vi.mocked(browser.runtime.sendMessage).mockImplementation(async (message: unknown) => {
				sent.push(message)
				return ((message as { kind: string }).kind === 'inline.open' ? { key: KEY, height: inlineHeight(1) } : undefined) as never
			})
			// happy-dom loads the frame as about:blank and throws on the targetOrigin, where a browser drops the message.
			vi.spyOn(HTMLIFrameElement.prototype, 'contentWindow', 'get').mockReturnValue(null)
			document.body.innerHTML = '<form><input id="u" name="username"><input id="p" type="password"><button>Log in</button></form>'
			;(document.querySelector('#u') as HTMLInputElement).focus()
			start(null)
			await vi.waitFor(() => expect(sent).toContainEqual({ kind: 'inline.open' }))
			await vi.waitFor(() => expect(ask(fill())).toEqual({ filled: 'both' }))
			expect((document.querySelector('#p') as HTMLInputElement).value).toBe('hunter2')
			const host = [...document.documentElement.children].find((element) => element.localName === 'div')!
			expect(host.getAttribute('style')).toContain(`height: ${inlineHeight(1)}px !important;`)
			ask({ kind: 'inline.resize', key: KEY, height: 130 })
			expect(host.getAttribute('style')).toContain('height: 130px !important;')
			ask({ kind: 'inline.close', key: KEY, refocus: false })
			expect(sent).toContainEqual({ kind: 'inline.closed', key: KEY })
		})
	})

	describe('login capture', () => {
		const bars = () => [...document.documentElement.children].filter((element) => element.localName === 'div')

		const KEY = 'b'.repeat(32)
		const trusted = <E extends Event>(event: E): E => Object.defineProperty(event, 'isTrusted', { value: true })

		it('reports a login the user submitted, and not one a script submitted', () => {
			start('<form><input id="u" name="username"><input id="p" type="password"><button id="b" type="button">Log in</button></form>')
			sent = []
			;(document.querySelector('#u') as HTMLInputElement).value = 'alice'
			;(document.querySelector('#p') as HTMLInputElement).value = 'hunter2'
			const button = document.querySelector('#b') as HTMLButtonElement
			button.click()
			expect(sent).toEqual([])
			button.dispatchEvent(trusted(new MouseEvent('mousedown', { bubbles: true, composed: true })))
			button.dispatchEvent(trusted(new MouseEvent('click', { bubbles: true, composed: true })))
			expect(sent).toContainEqual({ kind: 'capture.submitted', username: 'alice', password: 'hunter2', changed: false })
		})

		it('shows, verifies and hides the bar in the top frame, by its key', () => {
			start()
			ask({ kind: 'capture.show', key: KEY })
			expect(bars()).toHaveLength(1)
			// Not loaded yet, so no press counts.
			expect(ask({ kind: 'capture.verify', key: KEY, input: { kind: 'pointer', at: Date.now(), x: 1, y: 1 } })).toBe(false)
			ask({ kind: 'capture.hide', key: 'c'.repeat(32) })
			expect(bars()).toHaveLength(1)
			ask({ kind: 'capture.hide', key: KEY })
			expect(bars()).toHaveLength(0)
		})

		it('never hosts the bar in a subframe', () => {
			vi.spyOn(window, 'top', 'get').mockReturnValue({} as Window)
			start()
			ask({ kind: 'capture.show', key: KEY })
			expect(bars()).toHaveLength(0)
			expect(ask({ kind: 'capture.verify', key: KEY, input: { kind: 'pointer', at: Date.now(), x: 1, y: 1 } })).toBe(false)
		})

		it('ignores capture.show from anything but its own extension', () => {
			start()
			ask({ kind: 'capture.show', key: KEY }, { id: 'other-extension' })
			expect(bars()).toHaveLength(0)
		})
	})

	it('ignores messages from anything but its own extension', () => {
		start('<form><input name="username"><input id="p" type="password"><button>Log in</button></form>')
		expect(ask({ kind: 'fill.execute', username: 'a', password: 'b', origin: location.origin }, { id: 'other-extension' })).toBeUndefined()
		expect((document.querySelector('#p') as HTMLInputElement).value).toBe('')
	})

	it('stops sending once the extension context is gone', () => {
		Object.assign(browser.runtime, { id: undefined })
		try {
			start()
			expect(sent).toEqual([])
		} finally {
			Object.assign(browser.runtime, { id: fromBackground.id })
		}
	})
})
