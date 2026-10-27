// @vitest-environment happy-dom
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { FillExecute } from '@/src/messages'
import { executeFill } from './execute'

const $ = (selector: string) => document.querySelector(selector) as HTMLInputElement
const message = (overrides: Partial<FillExecute> = {}): FillExecute =>
	({ kind: 'fill.execute', username: 'alice', password: 'hunter2', origin: location.origin, ...overrides })

const LOGIN = `
	<form id="login">
		<input id="user" type="text" name="username">
		<input id="pass" type="password" name="password">
		<button type="submit">Log in</button>
	</form>`

beforeEach(() => {
	// happy-dom has no layout; every element gets a size.
	vi.spyOn(Element.prototype, 'getBoundingClientRect').mockReturnValue({ x: 0, y: 0, top: 0, left: 0, right: 120, bottom: 20, width: 120, height: 20, toJSON: () => ({}) } as DOMRect)
	document.body.innerHTML = ''
})

describe('executeFill', () => {
	it('fills username and password with input and change events', () => {
		document.body.innerHTML = LOGIN
		const events: string[] = []
		for (const type of ['input', 'change']) $('#pass').addEventListener(type, () => events.push(type))
		expect(executeFill(message())).toEqual({ filled: 'both' })
		expect([$('#user').value, $('#pass').value]).toEqual(['alice', 'hunter2'])
		expect(events).toEqual(['input', 'change'])
	})

	it('never submits the form or clicks its button', () => {
		document.body.innerHTML = LOGIN
		const form = $('#login') as unknown as HTMLFormElement
		const submits = vi.fn((event: Event) => event.preventDefault())
		const clicks = vi.fn()
		form.addEventListener('submit', submits)
		form.querySelector('button')!.addEventListener('click', clicks)
		const requestSubmit = vi.spyOn(form, 'requestSubmit')
		const submit = vi.spyOn(form, 'submit').mockImplementation(() => {})
		expect(executeFill(message())).toEqual({ filled: 'both' })
		expect([submits, clicks, requestSubmit, submit].map((spy) => spy.mock.calls.length)).toEqual([0, 0, 0, 0])
	})

	it('fills nothing when the frame’s origin is not the one the background matched', () => {
		document.body.innerHTML = LOGIN
		expect(executeFill(message({ origin: 'https://example.test' }))).toEqual({ filled: 'none' })
		expect([$('#user').value, $('#pass').value]).toEqual(['', ''])
	})

	it('fills nothing on a page without a login form', () => {
		document.body.innerHTML = '<form><input type="email" name="email"><button>Subscribe</button></form>'
		expect(executeFill(message())).toEqual({ filled: 'none' })
		expect($('input').value).toBe('')
	})

	it('fills the username of a username-only step and discards the password', () => {
		document.body.innerHTML = '<form><input type="email" autocomplete="username" name="email"><button>Next</button></form>'
		expect(executeFill(message())).toEqual({ filled: 'username' })
		expect($('input').value).toBe('alice')
	})

	it('prefers the login form holding the focused field', () => {
		document.body.innerHTML = `${LOGIN}<form id="second"><input id="user2" type="text" name="username"><input id="pass2" type="password"><button>Sign in</button></form>`
		$('#user2').focus()
		executeFill(message())
		expect([$('#user').value, $('#user2').value, $('#pass2').value]).toEqual(['', 'alice', 'hunter2'])
	})

	it('never puts a credential in a one-time code field', () => {
		document.body.innerHTML = '<form><input name="code" inputmode="numeric" maxlength="6" autocomplete="one-time-code"><button>Verify</button></form>'
		expect(executeFill(message())).toEqual({ filled: 'none' })
		expect($('input').value).toBe('')
	})

	describe('for an inline pick', () => {
		it('fills nothing without the open list’s key', () => {
			document.body.innerHTML = LOGIN
			expect(executeFill(message({ anchorKey: 'k1' }))).toEqual({ filled: 'none' })
			expect(executeFill(message({ anchorKey: 'k1' }), { key: 'k2', field: $('#user') })).toEqual({ filled: 'none' })
			expect($('#user').value).toBe('')
		})

		it('fills only the form of the field the list is open on', () => {
			document.body.innerHTML = `${LOGIN}<form><input id="user2" type="text" name="username"><input id="pass2" type="password"><button>Sign in</button></form>`
			expect(executeFill(message({ anchorKey: 'k1' }), { key: 'k1', field: $('#user2') })).toEqual({ filled: 'both' })
			expect([$('#user').value, $('#user2').value]).toEqual(['', 'alice'])
		})
	})

	it('holds no reference to the credential afterwards', () => {
		document.body.innerHTML = LOGIN
		const filled = message()
		executeFill(filled)
		expect([filled.username, filled.password]).toEqual([null, null])
		const refused = message({ origin: 'https://example.test' })
		executeFill(refused)
		expect([refused.username, refused.password]).toEqual([null, null])
	})
})
