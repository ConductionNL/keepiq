/**
 * @spec openspec/specs/browser-extension-autofill/spec.md
 *
 * A fill reaches every frame of the tab; only frames on the matched host may
 * fill (#740). The content-script cases run the real message handler.
 */
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import { frameMayFill } from '../../browser-extension/src/lib/fillScope.js'

describe('frameMayFill', () => {
	it('allows the matched host', () => {
		expect(frameMayFill('login.example.org', 'login.example.org')).toBe(true)
		expect(frameMayFill('Login.Example.org', 'login.example.org')).toBe(true)
	})

	it('refuses another site, a sibling or parent domain, and an empty host', () => {
		expect(frameMayFill('ads.tracker.test', 'login.example.org')).toBe(false)
		expect(frameMayFill('example.org', 'login.example.org')).toBe(false)
		expect(frameMayFill('www.example.org', 'login.example.org')).toBe(false)
		expect(frameMayFill('login.example.org', '')).toBe(false)
		expect(frameMayFill('', '')).toBe(false)
	})
})

describe('content script fill handler', () => {
	let listener

	beforeAll(async () => {
		globalThis.chrome = {
			runtime: {
				onMessage: { addListener: (fn) => { listener = fn } },
				sendMessage: vi.fn().mockResolvedValue(null),
				getURL: (p) => p,
			},
		}
		await import('../../browser-extension/src/content/content-script.js')
	})

	beforeEach(() => {
		document.body.innerHTML = '<form><input type="text" name="username"><input type="password" name="password"></form>'
		// jsdom lays nothing out; give the fields a size so they count as visible.
		for (const input of document.querySelectorAll('input')) {
			input.getBoundingClientRect = () => ({ width: 100, height: 20 })
		}
	})

	/**
	 * Send one message through the real handler.
	 *
	 * @param {object} msg The runtime message.
	 * @return {{handled: boolean, response: object|undefined}}
	 */
	function send(msg) {
		let response
		const handled = listener(msg, {}, (r) => { response = r })
		return { handled, response }
	}

	it('stays silent and fills nothing in a frame of another site', () => {
		const { handled, response } = send({
			type: 'fill-credential',
			payload: { login: 'ann', secret: 'pw', host: 'bank.example' },
		})

		expect(handled).toBe(false)
		expect(response).toBeUndefined()
		expect(document.querySelector('input[type="password"]').value).toBe('')
	})

	it('refuses a fill that names no host', () => {
		const { handled } = send({ type: 'fill-credential', payload: { login: 'ann', secret: 'pw' } })

		expect(handled).toBe(false)
		expect(document.querySelector('input[type="password"]').value).toBe('')
	})

	it('fills a frame on the matched host', () => {
		const { response } = send({
			type: 'fill-credential',
			payload: { login: 'ann', secret: 'pw', host: location.hostname },
		})

		expect(location.hostname).not.toBe('')
		expect(response).toEqual({ filled: true })
		expect(document.querySelector('input[type="password"]').value).toBe('pw')
	})
})
