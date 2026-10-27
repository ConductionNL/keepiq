// @vitest-environment happy-dom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { CaptureSubmitted } from '@/src/messages'
import { ACTIVATION_MS, createCaptureCollector, type CaptureCollector } from './capture-collector'

const $ = <T extends HTMLElement = HTMLInputElement>(selector: string, root: Document | ShadowRoot = document) => root.querySelector(selector) as T
let sent: CaptureSubmitted[]
let collector: CaptureCollector

/** What the browser marks on the user's own input; a page can neither set it nor dispatch such an event. */
function trusted<E extends Event>(event: E): E {
	Object.defineProperty(event, 'isTrusted', { value: true })
	return event
}

/** What the user does: the value, then the events typing sends. */
function type(field: HTMLInputElement, value: string) {
	field.value = value
	field.dispatchEvent(trusted(new Event('input', { bubbles: true, composed: true })))
}

/** A user's click, with the input that activates the page before it. */
function click(element: HTMLElement) {
	for (const kind of ['pointerdown', 'mousedown', 'pointerup', 'mouseup']) element.dispatchEvent(trusted(new MouseEvent(kind, { bubbles: true, composed: true })))
	element.dispatchEvent(trusted(new MouseEvent('click', { bubbles: true, composed: true, cancelable: true })))
}

const enter = (field: HTMLElement, init: KeyboardEventInit = {}) =>
	field.dispatchEvent(trusted(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, composed: true, cancelable: true, ...init })))
/** The browser's submit after the user's Enter in a field of the form. */
function userSubmit(form: HTMLFormElement) {
	enter(form.querySelector('input')!)
	form.dispatchEvent(trusted(new Event('submit', { bubbles: true, cancelable: true })))
}

function mount(html: string) {
	document.body.innerHTML = html
	// Pages here never navigate.
	document.addEventListener('submit', (event) => event.preventDefault())
}

/** `test-site/spa.html`. */
const SPA = `<div class="card" id="login">
	<label>Username <input id="username" type="text"></label>
	<label>Password <input id="password" type="password"></label>
	<button type="button" id="submit">Sign in</button>
</div>`

/** `test-site/formless-mixed.html`. */
const MIXED = `<header><span>Shop</span><button type="button" id="header-signup">Sign up</button></header>
<main>
	<section id="login-panel">
		<label>Username <input id="lu" type="text"></label>
		<label>Password <input id="lp" type="password"></label>
		<button type="button" id="login-submit">Sign in</button>
	</section>
	<section id="signup-panel">
		<label>Username <input id="su" type="text"></label>
		<label>Password <input id="sp" type="password"></label>
		<label>Confirm password <input id="sc" type="password"></label>
		<button type="button" id="signup-submit">Create account</button>
	</section>
</main>
<footer><label>Email <input id="ne" type="email"></label><button type="button" id="subscribe">Subscribe</button></footer>`

const LOGIN = { kind: 'capture.submitted', username: 'alice', password: 'hunter2', changed: false }

beforeEach(() => {
	vi.spyOn(Element.prototype, 'getBoundingClientRect').mockReturnValue({ x: 0, y: 0, top: 0, left: 0, right: 120, bottom: 20, width: 120, height: 20, toJSON: () => ({}) } as DOMRect)
	document.body.innerHTML = ''
	sent = []
	collector = createCaptureCollector((message) => sent.push(message))
})

afterEach(() => {
	collector.stop()
	delete (navigator as { userActivation?: unknown }).userActivation
})

describe('formless login (test-site/spa.html)', () => {
	beforeEach(() => {
		mount(SPA)
		type($('#username'), 'alice')
		type($('#password'), 'hunter2')
	})

	it('reports once at a click on its "Sign in" button', () => {
		click($('#submit'))
		expect(sent).toEqual([LOGIN])
	})

	it('reports once at Enter in the password field', () => {
		enter($('#password'))
		expect(sent).toEqual([LOGIN])
	})

	it('reports one submission once, whatever triggers follow', () => {
		enter($('#password'))
		click($('#submit'))
		click($('#submit'))
		expect(sent).toHaveLength(1)
	})

	it('reports again once the fields changed', () => {
		click($('#submit'))
		type($('#password'), 'hunter3')
		click($('#submit'))
		expect(sent.map((message) => message.password)).toEqual(['hunter2', 'hunter3'])
	})

	it('reports nothing for Enter in the username field or while composing', () => {
		enter($('#username'))
		enter($('#password'), { isComposing: true })
		$<HTMLInputElement>('#password').dispatchEvent(trusted(new KeyboardEvent('keydown', { key: 'a', bubbles: true, composed: true })))
		expect(sent).toEqual([])
	})

	it('reports nothing after stop', () => {
		collector.stop()
		click($('#submit'))
		expect(sent).toEqual([])
	})
})

describe('only the user submits', () => {
	beforeEach(() => {
		mount(SPA)
		type($('#username'), 'alice')
		type($('#password'), 'hunter2')
	})

	it('reports nothing for a script\'s click() or dispatched Enter', () => {
		$<HTMLButtonElement>('#submit').click()
		$('#password').dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, composed: true }))
		expect(sent).toEqual([])
	})

	it('reports nothing for a script\'s click() or dispatched Enter, even while the user has activated the page', () => {
		Object.defineProperty(navigator, 'userActivation', { value: { isActive: true }, configurable: true })
		$<HTMLButtonElement>('#submit').click()
		$('#password').dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, composed: true }))
		expect(sent).toEqual([])
	})

	it('counts no activation from input a script dispatches', () => {
		mount('<form id="f"><input id="u" name="username"><input id="p" type="password"><button type="submit">Log in</button></form>')
		type($('#u'), 'alice')
		type($('#p'), 'hunter2')
		for (const kind of ['pointerdown', 'mousedown', 'pointerup']) $('#u').dispatchEvent(new MouseEvent(kind, { bubbles: true, composed: true }))
		$('#u').dispatchEvent(new KeyboardEvent('keydown', { key: 'a', bubbles: true, composed: true }))
		$<HTMLFormElement>('#f').dispatchEvent(trusted(new Event('submit', { bubbles: true, cancelable: true })))
		expect(sent).toEqual([])
	})

	it('reports nothing for a script\'s requestSubmit(), trusted but with no user activation', () => {
		mount('<form id="f"><input id="u" name="username"><input id="p" type="password"><button type="submit">Log in</button></form>')
		type($('#u'), 'alice')
		type($('#p'), 'hunter2')
		$<HTMLFormElement>('#f').requestSubmit()
		// The browser's own submit event for it is trusted.
		$<HTMLFormElement>('#f').dispatchEvent(trusted(new Event('submit', { bubbles: true, cancelable: true })))
		expect(sent).toEqual([])
	})

	it('reports nothing once the user\'s activation has expired', () => {
		vi.useFakeTimers({ toFake: ['Date'] })
		try {
			enter($('#username'))
			vi.setSystemTime(Date.now() + ACTIVATION_MS)
			$<HTMLDivElement>('#login').dispatchEvent(trusted(new MouseEvent('click', { bubbles: true, composed: true })))
			$('#submit').dispatchEvent(trusted(new MouseEvent('click', { bubbles: true, composed: true })))
			expect(sent).toEqual([])
		} finally {
			vi.useRealTimers()
		}
	})

	it('follows navigator.userActivation where the browser has it', () => {
		Object.defineProperty(navigator, 'userActivation', { value: { isActive: false }, configurable: true })
		click($('#submit'))
		expect(sent).toEqual([])
		Object.defineProperty(navigator, 'userActivation', { value: { isActive: true }, configurable: true })
		$('#submit').dispatchEvent(trusted(new MouseEvent('click', { bubbles: true, composed: true })))
		expect(sent).toEqual([LOGIN])
	})

	it('reports at most once per user activation, so a script cannot probe several usernames on one click', () => {
		mount(`${SPA}<form id="f"><input id="u" name="username"><input id="p" type="password"><button type="submit">Log in</button></form>`)
		type($('#username'), 'alice')
		type($('#password'), 'hunter2')
		type($('#u'), 'bob')
		type($('#p'), 'hunter2')
		click($('#submit'))
		// Still within the click's activation: the browser's submit of a script's requestSubmit().
		$<HTMLFormElement>('#f').dispatchEvent(trusted(new Event('submit', { bubbles: true, cancelable: true })))
		expect(sent).toEqual([LOGIN])
		userSubmit($<HTMLFormElement>('#f'))
		expect(sent.map((message) => message.username)).toEqual(['alice', 'bob'])
	})
})

describe('formless groups (test-site/formless-mixed.html)', () => {
	beforeEach(() => {
		mount(MIXED)
		type($('#lu'), 'alice')
		type($('#lp'), 'hunter2')
	})

	it.each(['#header-signup', '#signup-submit', '#subscribe'])('reports nothing for the login panel at a click on %s', (button) => {
		click($(button))
		expect(sent).toEqual([])
	})

	it('reports the login panel at its own "Sign in"', () => {
		click($('#login-submit'))
		expect(sent).toEqual([LOGIN])
	})

	it('reports a filled sign-up panel as a new login, not the login panel', () => {
		type($('#su'), 'bob')
		type($('#sp'), 'n3w-pass')
		type($('#sc'), 'n3w-pass')
		click($('#signup-submit'))
		expect(sent).toEqual([{ kind: 'capture.submitted', username: 'bob', password: 'n3w-pass', changed: false }])
	})
})

describe('forms', () => {
	it('reports a login form with autocomplete="off" once, at the click and its submit', () => {
		mount(`<form id="f" autocomplete="off">
			<label>Username <input id="u" name="username" autocomplete="off"></label>
			<label>Password <input id="p" type="password" autocomplete="off"></label>
			<button id="b" type="submit">Log in</button>
		</form>`)
		type($('#u'), 'alice')
		type($('#p'), 'hunter2')
		click($('#b'))
		$<HTMLFormElement>('#f').dispatchEvent(trusted(new Event('submit', { bubbles: true, cancelable: true })))
		expect(sent).toEqual([LOGIN])
	})

	it('reports a form submitted without its keyword button, as by Enter in the username', () => {
		mount('<form id="f"><input id="u" name="username"><input id="p" type="password"><button type="submit" aria-label="Go"></button></form>')
		type($('#u'), 'alice')
		type($('#p'), 'hunter2')
		userSubmit($<HTMLFormElement>('#f'))
		expect(sent).toEqual([LOGIN])
	})

	it('reports nothing for an empty password', () => {
		mount('<form id="f"><input id="u" name="username"><input id="p" type="password"><button id="b">Log in</button></form>')
		type($('#u'), 'alice')
		click($('#b'))
		enter($('#p'))
		userSubmit($<HTMLFormElement>('#f'))
		expect(sent).toEqual([])
	})

	it('reports a login password mislabelled new-password as a login', () => {
		mount(`<form><label>Username <input id="u" name="username"></label>
			<label>Password <input id="p" type="password" autocomplete="new-password"></label><button id="b">Log in</button></form>`)
		type($('#u'), 'alice')
		type($('#p'), 'hunter2')
		click($('#b'))
		expect(sent).toEqual([LOGIN])
	})

	it('reports a change-password form with a username as a change, with the new password', () => {
		mount(`<form id="f">
			<label>Username <input id="u" name="username" autocomplete="username"></label>
			<label>Current password <input id="c" type="password" autocomplete="current-password"></label>
			<label>New password <input id="n" type="password" autocomplete="new-password"></label>
			<label>Confirm new password <input id="r" type="password" autocomplete="new-password"></label>
			<button id="b" type="submit">Change password</button>
		</form>`)
		type($('#u'), 'alice')
		type($('#c'), 'hunter2')
		type($('#n'), 'n3w-pass')
		type($('#r'), 'n3w-pass')
		userSubmit($<HTMLFormElement>('#f'))
		expect(sent).toEqual([{ kind: 'capture.submitted', username: 'alice', password: 'n3w-pass', changed: true }])
	})

	it('reports a change-password form without a username with no username', () => {
		mount(`<form id="f"><label>Current password <input id="c" type="password"></label>
			<label>New password <input id="n" type="password"></label><label>Confirm password <input id="r" type="password"></label>
			<button type="submit">Save</button></form>`)
		type($('#c'), 'hunter2')
		type($('#n'), 'n3w-pass')
		type($('#r'), 'n3w-pass')
		enter($('#r'))
		expect(sent).toEqual([{ kind: 'capture.submitted', username: null, password: 'n3w-pass', changed: true }])
	})

	it('reports a form inside a shadow root at its submit', () => {
		mount('<div id="host"></div>')
		const shadow = $<HTMLDivElement>('#host').attachShadow({ mode: 'open' })
		shadow.innerHTML = '<form id="f"><input id="u" name="username"><input id="p" type="password"><button type="submit">Sign in</button></form>'
		const form = $<HTMLFormElement>('#f', shadow)
		form.addEventListener('submit', (event) => event.preventDefault())
		type($('#u', shadow), 'alice')
		type($('#p', shadow), 'hunter2')
		userSubmit(form)
		expect(sent).toEqual([LOGIN])
	})
})

describe('beforeunload', () => {
	const unload = () => window.dispatchEvent(trusted(new Event('beforeunload')))

	it('reports a group submitted while its password was empty, if the page set one by the time it navigates', () => {
		mount(SPA)
		type($('#username'), 'alice')
		click($('#submit'))
		expect(sent).toEqual([])
		// A page script sets it, sending no input event.
		$<HTMLInputElement>('#password').value = 'hunter2'
		unload()
		expect(sent).toEqual([LOGIN])
	})

	it('reports nothing for a page left without a submit', () => {
		mount(SPA)
		type($('#username'), 'alice')
		type($('#password'), 'hunter2')
		unload()
		expect(sent).toEqual([])
	})

	it('reports nothing for a submit whose password the user typed only afterwards', () => {
		mount(SPA)
		click($('#submit'))
		type($('#password'), 'hunter2')
		unload()
		expect(sent).toEqual([])
	})

	it('reports nothing twice for a submission reported at its click', () => {
		mount(SPA)
		type($('#password'), 'hunter2')
		click($('#submit'))
		unload()
		expect(sent).toHaveLength(1)
	})
})
