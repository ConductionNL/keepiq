// @vitest-environment happy-dom
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { FillExecute } from '@/src/messages'
import { executeFill } from './execute'

const $ = (selector: string) => document.querySelector(selector) as HTMLInputElement
const message = (overrides: Partial<FillExecute> = {}): FillExecute =>
	({ kind: 'fill.execute', username: 'alice', password: 'hunter2', code: null, origin: location.origin, ...overrides })

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
		expect(executeFill(message())).toEqual({ filled: 'both', code: false })
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
		expect(executeFill(message())).toEqual({ filled: 'both', code: false })
		expect([submits, clicks, requestSubmit, submit].map((spy) => spy.mock.calls.length)).toEqual([0, 0, 0, 0])
	})

	it('fills nothing when the frame’s origin is not the one the background matched', () => {
		document.body.innerHTML = LOGIN
		expect(executeFill(message({ origin: 'https://example.test' }))).toEqual({ filled: 'none', code: false })
		expect([$('#user').value, $('#pass').value]).toEqual(['', ''])
	})

	it('fills nothing on a page without a login form', () => {
		document.body.innerHTML = '<form><input type="email" name="email"><button>Subscribe</button></form>'
		expect(executeFill(message())).toEqual({ filled: 'none', code: false })
		expect($('input').value).toBe('')
	})

	it('fills the username of a username-only step and discards the password', () => {
		document.body.innerHTML = '<form><input type="email" autocomplete="username" name="email"><button>Next</button></form>'
		expect(executeFill(message())).toEqual({ filled: 'username', code: false })
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
		expect(executeFill(message())).toEqual({ filled: 'none', code: false })
		expect($('input').value).toBe('')
	})

	describe('for an inline pick', () => {
		it('fills nothing without the open list’s key', () => {
			document.body.innerHTML = LOGIN
			expect(executeFill(message({ anchorKey: 'k1' }))).toEqual({ filled: 'none', code: false })
			expect(executeFill(message({ anchorKey: 'k1' }), { key: 'k2', field: $('#user') })).toEqual({ filled: 'none', code: false })
			expect($('#user').value).toBe('')
		})

		it('fills only the form of the field the list is open on', () => {
			document.body.innerHTML = `${LOGIN}<form><input id="user2" type="text" name="username"><input id="pass2" type="password"><button>Sign in</button></form>`
			expect(executeFill(message({ anchorKey: 'k1' }), { key: 'k1', field: $('#user2') })).toEqual({ filled: 'both', code: false })
			expect([$('#user').value, $('#user2').value]).toEqual(['', 'alice'])
		})
	})

	it('holds no reference to the credential afterwards', () => {
		document.body.innerHTML = LOGIN
		const filled = message()
		executeFill(filled)
		expect([filled.username, filled.password]).toEqual([null, null])
		const refused = message({ origin: 'https://example.test', code: '287082' })
		executeFill(refused)
		expect([refused.username, refused.password, refused.code]).toEqual([null, null, null])
	})
})

/** `test-site/otp.html`'s field. */
const OTP = '<form><label>Authentication code <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6"></label><button>Verify</button></form>'
const boxes = (count: number, attributes = '') =>
	`<form>${Array.from({ length: count }, (_, i) => `<input id="d${i}" type="text" inputmode="numeric" maxlength="1" aria-label="Digit ${i + 1}" ${attributes}>`).join('')}<button>Verify</button></form>`
const values = () => [...document.querySelectorAll('input')].map((input) => input.value)

describe('executeFill with a code', () => {
	const code = (overrides: Partial<FillExecute> = {}) => message({ username: null, password: null, code: '492039', ...overrides })

	it('fills the code field of a second step', () => {
		document.body.innerHTML = OTP
		expect(executeFill(message({ code: '492039' }))).toEqual({ filled: 'none', code: true })
		expect($('#code').value).toBe('492039')
	})

	it('fills six one-character inputs one digit each, with events on each', () => {
		document.body.innerHTML = boxes(6)
		const events: string[] = []
		for (const input of document.querySelectorAll('input')) input.addEventListener('input', () => events.push(input.id))
		expect(executeFill(code())).toEqual({ filled: 'none', code: true })
		expect(values()).toEqual(['4', '9', '2', '0', '3', '9'])
		expect(events).toEqual(['d0', 'd1', 'd2', 'd3', 'd4', 'd5'])
	})

	it('fills two halves of three, with text between them', () => {
		document.body.innerHTML = `<form>
			<input type="text" inputmode="numeric" maxlength="3" autocomplete="one-time-code" aria-label="Code, first half">
			<span>-</span>
			<input type="text" inputmode="numeric" maxlength="3" aria-label="Code, second half">
			<button>Verify</button></form>`
		expect(executeFill(code())).toEqual({ filled: 'none', code: true })
		expect(values()).toEqual(['492', '039'])
	})

	it('puts the whole code into the first input when the lengths do not agree', () => {
		document.body.innerHTML = boxes(5)
		executeFill(code())
		expect(values()).toEqual(['492039', '', '', '', ''])
	})

	it('treats a birth date in three inputs as no code group', () => {
		document.body.innerHTML = `<form>
			<input type="text" inputmode="numeric" maxlength="2" name="day"><input type="text" inputmode="numeric" maxlength="2" name="month">
			<input type="text" inputmode="numeric" maxlength="4" name="year"><button>Next</button></form>`
		expect(executeFill(code())).toEqual({ filled: 'none', code: false })
		expect(values()).toEqual(['', '', ''])
	})

	it('never fills a recovery or backup code field', () => {
		for (const label of ['Recovery code', 'Backup code', 'Herstelcode', 'Back-upcode']) {
			document.body.innerHTML = `<form><label>${label} <input type="text" autocomplete="one-time-code" inputmode="numeric" maxlength="8"></label><button>Verify</button></form>`
			expect(executeFill(code())).toEqual({ filled: 'none', code: false })
			expect($('input').value).toBe('')
		}
	})

	it('leaves a code field that holds a value alone', () => {
		document.body.innerHTML = OTP
		$('#code').value = '123456'
		expect(executeFill(code())).toEqual({ filled: 'none', code: false })
		expect($('#code').value).toBe('123456')
	})

	it('fills username, password and code of one form', () => {
		document.body.innerHTML = `<form>
			<input id="user" type="text" name="username"><input id="pass" type="password" name="password">
			<input id="code" name="otp" inputmode="numeric" autocomplete="one-time-code" maxlength="6"><button>Log in</button></form>`
		expect(executeFill(message({ code: '492039' }))).toEqual({ filled: 'both', code: true })
		expect(values()).toEqual(['alice', 'hunter2', '492039'])
	})

	it('drops the code on a login form without a code field', () => {
		document.body.innerHTML = LOGIN
		expect(executeFill(message({ code: '492039' }))).toEqual({ filled: 'both', code: false })
		expect(values()).toEqual(['alice', 'hunter2'])
	})

	it('fills the focused code group, else the first empty one', () => {
		document.body.innerHTML = `${OTP}<form><input id="other" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6"><button>Verify</button></form>`
		$('#other').focus()
		executeFill(code())
		expect(values()).toEqual(['', '492039'])
		$('#other').blur()
		executeFill(code({ code: '111111' }))
		expect(values()).toEqual(['111111', '492039'])
	})

	it('fills the code group of the list on an inline pick, whichever input it is open on', () => {
		document.body.innerHTML = `${OTP}${boxes(6)}`
		expect(executeFill(code({ anchorKey: 'k1' }), { key: 'k1', field: $('#d3') })).toEqual({ filled: 'none', code: true })
		expect(values()).toEqual(['', '4', '9', '2', '0', '3', '9'])
	})
})
