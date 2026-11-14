// @vitest-environment happy-dom
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { FillConfirmForm, FillExecute, FillExecuteForm, FillNewPassword, FillProbeReply, FormRole, FormValues } from '@/src/messages'
import { mockLayout } from '@/src/testing/layout'
import { answerFill, confirmForm, executeFill, executeForm, fillNewPassword, isFillMessage, probeForm } from './execute'

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
	mockLayout()
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

/** `test-site/card-split.html`'s shape. */
const CHECKOUT = `<form id="pay">
	<label>Name on card <input id="holder"></label>
	<label>Card number <input id="n1" maxlength="4"></label><input id="n2" maxlength="4"><input id="n3" maxlength="4"><input id="n4" maxlength="4">
	<label>Month <select id="month"><option value="">MM</option><option>01</option><option>02</option><option>03</option></select></label>
	<label>Year <select id="year"><option value="">YYYY</option><option>2026</option><option>2027</option></select></label>
	<label>Card type <select id="brand"><option>VISA</option><option>MC</option></select></label>
	<label>CVC <input id="cvc" type="password" maxlength="4"></label>
	<label>PIN <input id="pin" maxlength="4"></label>
	<button>Pay</button>
</form>`
const CARD_VALUES: FormValues = { cardholder: 'Anna de Vries', number: '5555555555554444', expMonth: '03', expYear: '2027', cvv: '123', brand: 'Mastercard', brandId: 'mastercard' }
const form = (overrides: Partial<FillExecuteForm> = {}): FillExecuteForm =>
	({ kind: 'fill.executeForm', form: 'card', values: { ...CARD_VALUES }, origin: location.origin, ...overrides })
const selected = (id: string) => (document.getElementById(id) as HTMLSelectElement).value
/** A probe's reply names the frame's own origin. */
const reply = (roles: FormRole[]): FillProbeReply => ({ roles, origin: location.origin })

describe('probeForm', () => {
	it('reports the roles of the form a fill would write, once each', () => {
		document.body.innerHTML = CHECKOUT
		expect(probeForm({ kind: 'fill.probe', form: 'card' })).toEqual(reply(['cardholder', 'number', 'expMonth', 'expYear', 'brand', 'securityCode']))
		expect(probeForm({ kind: 'fill.probe', form: 'identity' })).toEqual(reply([]))
	})

	it('reports and fills only what the user sees of a form that hides the card number and CVC', () => {
		document.body.innerHTML = `<form><label>Name on card <input id="holder"></label>
			<input id="n" autocomplete="cc-number" style="opacity: 0; position: absolute; left: -9999px">
			<input id="c" autocomplete="cc-csc" style="width: 1px; height: 1px"><button>Pay</button></form>`
		expect(probeForm({ kind: 'fill.probe', form: 'card' })).toEqual(reply(['cardholder']))
		expect(executeForm(form())).toEqual({ filled: 1 })
		expect(values()).toEqual(['Anna de Vries', '', ''])
	})

	it('dispatches no event in the page and changes no field', () => {
		document.body.innerHTML = CHECKOUT
		const events = vi.fn()
		for (const type of ['input', 'change', 'focus', 'focusin', 'keydown', 'click']) document.addEventListener(type, events, true)
		probeForm({ kind: 'fill.probe', form: 'card' })
		expect(events).not.toHaveBeenCalled()
		expect(values()).toEqual(['', '', '', '', '', '', ''])
	})

	it('answers nothing for a key that is not the open list’s, or an unknown kind', () => {
		document.body.innerHTML = CHECKOUT
		expect(probeForm({ kind: 'fill.probe', form: 'card', anchorKey: 'k1' }, { key: 'k2', field: $('#n1') })).toEqual(reply([]))
		expect(probeForm({ kind: 'fill.probe', form: 'login' as never })).toEqual(reply([]))
	})

	it('prefers an identity form with two fields over a newsletter before it, and the focused form over both', () => {
		document.body.innerHTML = `<form><label>Email <input id="news" type="email"></label><button>Subscribe</button></form>
			<form><label>First name <input id="f"></label><label>Email <input id="e" type="email"></label></form>`
		expect(probeForm({ kind: 'fill.probe', form: 'identity' })).toEqual(reply(['firstName', 'email']))
		$('#news').focus()
		expect(probeForm({ kind: 'fill.probe', form: 'identity' })).toEqual(reply(['email']))
	})
})

describe('executeForm', () => {
	it('fills a split number, the selects and the security code, never the PIN', () => {
		document.body.innerHTML = CHECKOUT
		const events: string[] = []
		for (const id of ['n4', 'month']) for (const type of ['input', 'change']) document.getElementById(id)!.addEventListener(type, () => events.push(`${id}:${type}`))
		expect(executeForm(form())).toEqual({ filled: 9 })
		expect(values()).toEqual(['Anna de Vries', '5555', '5555', '5555', '4444', '123', ''])
		expect([selected('month'), selected('year'), selected('brand')]).toEqual(['03', '2027', 'MC'])
		expect(events).toEqual(['n4:input', 'n4:change', 'month:input', 'month:change'])
	})

	it('fills the other roles when the expiry is missing', () => {
		document.body.innerHTML = '<form><label>Card number <input id="n"></label><label>Expiry <input id="e" placeholder="MM/YY"></label></form>'
		expect(executeForm(form({ values: { number: CARD_VALUES.number } }))).toEqual({ filled: 1 })
		expect(values()).toEqual(['5555555555554444', ''])
	})

	it('writes "03 / 2027" into a combined field with that placeholder', () => {
		document.body.innerHTML = '<form><label>Card number <input id="n"></label><input id="e" placeholder="MM / YYYY"></form>'
		executeForm(form())
		expect($('#e').value).toBe('03 / 2027')
	})

	it('fills a full name and an address as each field wants it', () => {
		document.body.innerHTML = `<form><label>Full name <input id="n"></label><input id="s" autocomplete="street-address"><input id="a" autocomplete="address-line1">
			<textarea autocomplete="street-address"></textarea></form>`
		const identity = { fullName: 'Anna de Vries', streetAddress: 'Kerkstraat 1\n1234 AB Utrecht', addressLine1: 'Kerkstraat 1' }
		expect(executeForm(form({ form: 'identity', values: identity }))).toEqual({ filled: 4 })
		expect(values()).toEqual(['Anna de Vries', 'Kerkstraat 1, 1234 AB Utrecht', 'Kerkstraat 1'])
		expect(document.querySelector('textarea')!.value).toBe('Kerkstraat 1\n1234 AB Utrecht')
	})

	it('fills nothing when the frame’s origin is not the one the background probed', () => {
		document.body.innerHTML = CHECKOUT
		expect(executeForm(form({ origin: 'https://example.test' }))).toEqual({ filled: 0 })
		expect(values().every((value) => value === '')).toBe(true)
	})

	it('fills only the form the list is open on, and nothing for another key', () => {
		document.body.innerHTML = `${CHECKOUT}<form><label>Card number <input id="other" autocomplete="cc-number"></label></form>`
		expect(executeForm(form({ anchorKey: 'k1' }), { key: 'k2', field: $('#other') })).toEqual({ filled: 0 })
		expect(executeForm(form({ anchorKey: 'k1' }), { key: 'k1', field: $('#other') })).toEqual({ filled: 1 })
		expect([$('#n1').value, $('#other').value]).toEqual(['', '5555555555554444'])
	})

	it('holds no reference to the values afterwards', () => {
		document.body.innerHTML = CHECKOUT
		const filled = form()
		executeForm(filled)
		const refused = form({ origin: 'https://example.test' })
		executeForm(refused)
		expect([filled.values, refused.values]).toEqual([{}, {}])
	})
})

describe('confirmForm', () => {
	const question = (overrides: Partial<FillConfirmForm> = {}): FillConfirmForm =>
		({ kind: 'fill.confirmForm', insecure: true, host: 'shop.test', origin: 'https://shop.test', frameHosts: ['js.stripe.test', 'ads.test'], ...overrides })
	// happy-dom has no `confirm()`.
	const stubConfirm = (answer: boolean) => {
		const confirm = vi.fn<(text?: string) => boolean>(() => answer)
		Object.defineProperty(window, 'confirm', { value: confirm, configurable: true, writable: true })
		return confirm
	}

	it('asks the insecure question, then names the other sites', () => {
		const confirm = stubConfirm(true)
		expect(confirmForm(question())).toBe(true)
		expect(confirm.mock.calls.map(([text]) => text)).toEqual([
			'Fill this card on an insecure site?\n\nThis site is not secure. Anyone on the network could read the card details that are filled in.',
			'Also fill on js.stripe.test, ads.test?\n\nPart of this form comes from js.stripe.test, ads.test, not from shop.test. Keepiq fills it there only if you agree.',
		])
	})

	it('stops at the first Cancel', () => {
		const confirm = stubConfirm(false)
		expect(confirmForm(question())).toBe(false)
		expect(confirm).toHaveBeenCalledTimes(1)
	})

	it('asks only the questions it was sent', () => {
		const confirm = stubConfirm(true)
		expect(confirmForm(question({ insecure: false }))).toBe(true)
		expect(confirm).toHaveBeenCalledTimes(1)
	})

	it('never asks in a subframe', () => {
		const confirm = stubConfirm(true)
		vi.spyOn(window, 'top', 'get').mockReturnValue({} as Window)
		expect(confirmForm(question())).toBe(false)
		expect(confirm).not.toHaveBeenCalled()
	})

	it('is how the content script answers each card and identity message', () => {
		document.body.innerHTML = CHECKOUT
		const confirm = stubConfirm(true)
		expect(answerFill({ kind: 'fill.probe', form: 'card' }, null)).toEqual(reply(['cardholder', 'number', 'expMonth', 'expYear', 'brand', 'securityCode']))
		expect(answerFill(form(), null)).toEqual({ filled: 9 })
		expect(answerFill(question(), null)).toBe(true)
		expect(confirm).toHaveBeenCalledTimes(2)
	})
})

describe('answerFill', () => {
	it('answers every fill kind and nothing else', () => {
		const kinds = ['fill.confirmInsecure', 'fill.execute', 'fill.probe', 'fill.executeForm', 'fill.confirmForm']
		for (const kind of kinds) expect(isFillMessage({ kind } as never)).toBe(true)
		for (const other of [null, undefined, 'fill.execute', { kind: 'inline.verify' }, { kind: 'capture.show' }]) expect(isFillMessage(other as never)).toBe(false)
	})

	it('asks the insecure question of a login fill, naming the item host when there is one', () => {
		const confirm = vi.fn<(text?: string) => boolean>(() => true)
		Object.defineProperty(window, 'confirm', { value: confirm, configurable: true, writable: true })
		expect(answerFill({ kind: 'fill.confirmInsecure', itemHost: 'example.test' }, null)).toBe(true)
		answerFill({ kind: 'fill.confirmInsecure', itemHost: 7 as never }, null)
		expect(confirm.mock.calls.map(([text]) => text)).toEqual([expect.stringContaining('example.test'), expect.not.stringContaining('example.test')])
	})

	it('fills a login on fill.execute', () => {
		document.body.innerHTML = LOGIN
		expect(answerFill(message(), null)).toEqual({ filled: 'both', code: false })
	})
})

// Spec `extension-generator`, Suggest a strong password in a sign-up field.
describe('fillNewPassword', () => {
	const SIGN_UP = `
		<form>
			<input id="u" name="username" autocomplete="username">
			<input id="p" type="password" autocomplete="new-password">
			<input id="c" type="password" autocomplete="new-password">
			<button type="submit">Create account</button>
		</form>`
	const CHANGE = `
		<form>
			<input id="u" name="username" autocomplete="username">
			<input id="cur" type="password" autocomplete="current-password">
			<input id="new" type="password" autocomplete="new-password">
			<input id="c" type="password" autocomplete="new-password">
			<button type="submit">Change password</button>
		</form>`
	const generated = (overrides: Partial<FillNewPassword> = {}): FillNewPassword =>
		({ kind: 'fill.newPassword', password: 'G3n3rated!pass-word', origin: location.origin, anchorKey: 'k1', ...overrides })

	it('fills the new password and its confirmation of a sign-up with the same value', () => {
		document.body.innerHTML = SIGN_UP
		expect(fillNewPassword(generated(), { key: 'k1', field: $('#p') })).toEqual({ filled: 2 })
		expect([$('#u').value, $('#p').value, $('#c').value]).toEqual(['', 'G3n3rated!pass-word', 'G3n3rated!pass-word'])
	})

	it('leaves the current password of a change-password form alone', () => {
		document.body.innerHTML = CHANGE
		$('#cur').value = 'old-secret'
		expect(fillNewPassword(generated(), { key: 'k1', field: $('#new') })).toEqual({ filled: 2 })
		expect([$('#cur').value, $('#new').value, $('#c').value]).toEqual(['old-secret', 'G3n3rated!pass-word', 'G3n3rated!pass-word'])
	})

	it('fills nothing from the current-password field, another list, another origin or without a list', () => {
		document.body.innerHTML = CHANGE
		expect(fillNewPassword(generated(), { key: 'k1', field: $('#cur') })).toEqual({ filled: 0 })
		expect(fillNewPassword(generated(), { key: 'k2', field: $('#new') })).toEqual({ filled: 0 })
		expect(fillNewPassword(generated({ origin: 'https://elsewhere.test' }), { key: 'k1', field: $('#new') })).toEqual({ filled: 0 })
		expect(fillNewPassword(generated())).toEqual({ filled: 0 })
		expect([$('#cur').value, $('#new').value, $('#c').value]).toEqual(['', '', ''])
	})

	it('keeps nothing of the password once the fields hold it, and answers through answerFill', () => {
		document.body.innerHTML = SIGN_UP
		const message = generated()
		expect(isFillMessage(message)).toBe(true)
		expect(answerFill(message, { key: 'k1', field: $('#p') })).toEqual({ filled: 2 })
		expect(message.password).toBe('')
	})
})
