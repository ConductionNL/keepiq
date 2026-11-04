// @vitest-environment happy-dom
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { classifyField, codeGroup, fillField, findCodeGroups, findGroups, findLoginFields, groupOf, isQualifying } from './fields'

const $ = (selector: string, root: Document | ShadowRoot = document) => root.querySelector(selector) as HTMLInputElement
const mount = (html: string) => {
	document.body.innerHTML = html
}

beforeEach(() => {
	// happy-dom has no layout; give every element a size unless its style zeroes it.
	vi.spyOn(Element.prototype, 'getBoundingClientRect').mockImplementation(function (this: Element) {
		const style = (this as HTMLElement).style
		const zero = (value: string | undefined) => value === '0px' || value === '0'
		const width = zero(style?.width) ? 0 : 120
		const height = zero(style?.height) ? 0 : 20
		return { x: 0, y: 0, top: 0, left: 0, right: width, bottom: height, width, height, toJSON: () => ({}) } as DOMRect
	})
	document.body.innerHTML = ''
})

describe('login forms', () => {
	it('finds the username and password of a basic login form', () => {
		mount(`<form>
			<label>Username <input id="u" name="username" type="text"></label>
			<label>Password <input id="p" name="password" type="password"></label>
			<button type="submit">Sign in</button>
		</form>`)
		const group = groupOf($('#u'))
		expect(group?.kind).toBe('login')
		expect(group?.username).toBe($('#u'))
		expect(group?.currentPassword).toBe($('#p'))
		expect(group?.submit?.textContent).toBe('Sign in')
		expect(isQualifying($('#u'))).toBe('login')
		expect(isQualifying($('#p'))).toBe('login')
	})

	it('ignores autocomplete="off" on the form and its fields', () => {
		mount(`<form autocomplete="off">
			<label>Username <input id="u" name="username" autocomplete="off"></label>
			<label>Password <input id="p" name="password" type="password" autocomplete="off"></label>
			<button type="submit">Log in</button>
		</form>`)
		expect(groupOf($('#u'))?.kind).toBe('login')
		expect(classifyField($('#u'))).toBe('username')
		expect(classifyField($('#p'))).toBe('currentPassword')
		expect(isQualifying($('#u'))).toBe('login')
	})

	it.each(['false', 'nonsense-token'])('ignores autocomplete="%s"', (value) => {
		mount(`<form>
			<label>Username <input id="u" name="username" autocomplete="${value}"></label>
			<label>Password <input id="p" type="password" autocomplete="${value}"></label>
			<button>Log in</button>
		</form>`)
		expect(groupOf($('#u'))?.kind).toBe('login')
		expect(isQualifying($('#p'))).toBe('login')
	})

	it('treats a login password mislabelled new-password as the current password', () => {
		mount(`<form>
			<label>Username <input id="u" name="username" autocomplete="username"></label>
			<label>Password <input id="p" name="password" type="password" autocomplete="new-password"></label>
			<button type="submit">Log in</button>
		</form>`)
		expect(classifyField($('#p'))).toBe('currentPassword')
		expect(groupOf($('#p'))?.kind).toBe('login')
		expect(isQualifying($('#p'))).toBe('login')
	})

	it('reads the email login of test-site/email-login.html and leaves the checkbox alone', () => {
		mount(`<form>
			<label>Email address <input id="u" name="email" type="email" autocomplete="username"></label>
			<label>Password <input id="p" name="pass" type="password" autocomplete="current-password"></label>
			<label><input id="r" name="remember" type="checkbox"> Remember me</label>
			<button type="submit">Log in</button>
		</form>`)
		const group = groupOf($('#u'))
		expect(group?.kind).toBe('login')
		expect(group?.fields).not.toContain($('#r'))
	})

	it('supports a password-only step with the username in a hidden field', () => {
		mount(`<form>
			<input id="h" name="username" type="hidden" autocomplete="username" value="alice">
			<label>Password <input id="p" name="password" type="password" autocomplete="current-password"></label>
			<button type="submit">Sign in</button>
		</form>`)
		const group = groupOf($('#p'))
		expect(group?.kind).toBe('login')
		expect(group?.username).toBeNull()
		expect(isQualifying($('#p'))).toBe('login')
	})

	it('supports a username-only step', () => {
		mount(`<form>
			<label>Username <input id="u" name="username" type="text" autocomplete="username"></label>
			<button type="submit">Next</button>
		</form>`)
		const group = groupOf($('#u'))
		expect(group?.kind).toBe('login')
		expect(group?.username).toBe($('#u'))
		expect(group?.currentPassword).toBeNull()
		expect(isQualifying($('#u'))).toBe('login')
	})

	it('prefers the default submit button over a keyword button before it', () => {
		mount(`<form>
			<label>Gebruikersnaam <input id="u" name="user"></label>
			<label>Wachtwoord <input id="p" type="password"></label>
			<button type="button">Registreren</button>
			<button type="submit" id="s">Inloggen</button>
		</form>`)
		const group = groupOf($('#u'))
		expect(group?.kind).toBe('login')
		expect(group?.submit).toBe(document.getElementById('s'))
	})

	it('reads the alt text of an image submit button', () => {
		mount(`<form>
			<label>Username <input id="u" name="username"></label>
			<label>Password <input id="p" type="password" autocomplete="new-password"></label>
			<input type="image" src="go.png" alt="Log in">
		</form>`)
		expect(groupOf($('#p'))?.kind).toBe('login')
	})

	it('takes a phone number with username evidence as the username of a login', () => {
		mount(`<form>
			<label>Phone number <input id="t" type="tel" autocomplete="tel"></label>
			<label>Password <input id="p" type="password"></label>
			<button>Log in</button>
		</form>`)
		const group = groupOf($('#p'))
		expect(group?.kind).toBe('login')
		expect(group?.username).toBe($('#t'))
		expect(isQualifying($('#t'))).toBe('login')
	})

	it('takes a phone number as the username of a username-only step with "Volgende"', () => {
		mount(`<form>
			<label>Mobiel nummer <input id="t" type="tel"></label>
			<button>Volgende</button>
		</form>`)
		expect(groupOf($('#t'))?.kind).toBe('login')
		expect(groupOf($('#t'))?.username).toBe($('#t'))
	})

	it('leaves a phone field outside a login as no username', () => {
		mount(`<form>
			<label>Username <input id="u" name="username"></label>
			<label>Telefoon <input id="t" type="tel" autocomplete="tel"></label>
			<label>Password <input id="p" type="password"></label>
			<button>Create account</button>
		</form>`)
		const group = groupOf($('#p'))
		expect(group?.kind).toBe('signUp')
		expect(group?.username).toBe($('#u'))
		expect(classifyField($('#t'))).toBe('other')
	})

	it('groups a field with a form attribute into that form', () => {
		mount(`<label>Username <input id="u" name="username" form="f"></label>
		<form id="f"><label>Password <input id="p" type="password"></label><button>Log in</button></form>`)
		expect(groupOf($('#p'))?.username).toBe($('#u'))
	})

	it('takes the username from before the password field, within the group', () => {
		mount(`<form><label>Username <input id="other" name="username"></label></form>
		<form>
			<label>Password <input id="p" type="password"></label>
			<button>Log in</button>
		</form>`)
		expect(groupOf($('#p'))?.username).toBeNull()
	})

	it('keeps the username of an identifier-first form with "Continue" in its row', () => {
		mount(`<form>
			<div><input id="u" type="email" autocomplete="username"><button type="button">Continue</button></div>
			<div><input id="p" type="password"></div>
			<button type="submit">Log in</button>
		</form>`)
		expect(groupOf($('#p'))?.username).toBe($('#u'))
	})

	it.each(['form', 'div'])('keeps a username with "Sign in with Google" in its row, in a <%s>', (tag) => {
		mount(`<${tag}>
			<div><label>Username <input id="u" name="username"></label><button type="button">Sign in with Google</button></div>
			<div><label>Password <input id="p" type="password"></label></div>
			<button type="${tag === 'form' ? 'submit' : 'button'}">Sign in</button>
		</${tag}>`)
		expect(groupOf($('#p'))?.username).toBe($('#u'))
	})
})

describe('submit texts', () => {
	it.each([
		['Log in', 'login'],
		['Inloggen', 'login'],
		['Aanmelden', 'login'],
		['Create account', 'signUp'],
		['Registreren', 'signUp'],
		['Account aanmaken', 'signUp'],
	])('a username and one password with "%s" is a %s', (text, kind) => {
		mount(`<form>
			<label>Gebruikersnaam <input id="u" name="user"></label>
			<label>Wachtwoord <input id="p" type="password"></label>
			<button type="submit">${text}</button>
		</form>`)
		expect(groupOf($('#u'))?.kind).toBe(kind)
	})

	it.each([
		['Log in', 'login'],
		['Inloggen', 'login'],
		['Next', 'login'],
		['Volgende', 'login'],
		['Doorgaan', 'login'],
		['Create account', 'other'],
		['Registreren', 'other'],
		['Aanmelden', 'other'],
	])('an email field alone with "%s" is %s', (text, kind) => {
		mount(`<form>
			<label>E-mailadres <input id="u" name="email" type="email"></label>
			<button type="submit">${text}</button>
		</form>`)
		expect(groupOf($('#u'))?.kind).toBe(kind)
		expect(isQualifying($('#u'))).toBe(kind === 'login' ? 'login' : null)
	})
})

describe('sign-up and password change', () => {
	it('classifies the sign-up form of test-site/signup.html, its username field too', () => {
		mount(`<form>
			<label>Email address <input id="e" name="email" type="email" autocomplete="email"></label>
			<label>Username <input id="u" name="username" type="text" autocomplete="username"></label>
			<label>Password <input id="p" name="password" type="password" autocomplete="new-password"></label>
			<label>Confirm password <input id="c" name="password_confirm" type="password" autocomplete="new-password"></label>
			<button type="submit">Create account</button>
		</form>`)
		const group = groupOf($('#u'))
		expect(group?.kind).toBe('signUp')
		expect(classifyField($('#u'))).toBe('username')
		expect(classifyField($('#p'))).toBe('newPassword')
		expect(classifyField($('#c'))).toBe('newPassword')
		for (const id of ['#e', '#u', '#p', '#c']) expect(isQualifying($(id))).toBeNull()
	})

	it('classifies a sign-up with one password and no tokens by its submit text', () => {
		mount(`<form>
			<label>Username <input id="u" name="username"></label>
			<label>Password <input id="p" type="password"></label>
			<button>Sign up</button>
		</form>`)
		expect(groupOf($('#u'))?.kind).toBe('signUp')
		expect(classifyField($('#p'))).toBe('newPassword')
	})

	it('classifies the password change of test-site/change-password.html, with a username', () => {
		mount(`<form>
			<label>Username <input id="u" name="username" type="text" autocomplete="username"></label>
			<label>Current password <input id="cur" name="current" type="password" autocomplete="current-password"></label>
			<label>New password <input id="new" name="new" type="password" autocomplete="new-password"></label>
			<label>Confirm new password <input id="c" name="confirm" type="password" autocomplete="new-password"></label>
			<button type="submit">Change password</button>
		</form>`)
		const group = groupOf($('#u'))
		expect(group?.kind).toBe('passwordChange')
		expect(group?.username).toBe($('#u'))
		expect(group?.currentPassword).toBe($('#cur'))
		expect(group?.newPasswords).toEqual([$('#new'), $('#c')])
		for (const id of ['#u', '#cur', '#new', '#c']) expect(isQualifying($(id))).toBeNull()
	})

	it('reads three unlabelled password fields as current, new and confirm by their shape', () => {
		mount(`<form>
			<input id="a" type="password"><input id="b" type="password"><input id="c" type="password">
			<button>Save</button>
		</form>`)
		const group = groupOf($('#a'))
		expect(group?.kind).toBe('passwordChange')
		expect(group?.currentPassword).toBe($('#a'))
		expect(group?.newPasswords).toEqual([$('#b'), $('#c')])
	})

	it('classifies a current and a new password without a username as a password change', () => {
		mount(`<form>
			<label>Current password <input id="cur" type="password"></label>
			<label>New password <input id="new" type="password"></label>
			<button>Save</button>
		</form>`)
		expect(groupOf($('#cur'))?.kind).toBe('passwordChange')
		expect(classifyField($('#cur'))).toBe('currentPassword')
		expect(classifyField($('#new'))).toBe('newPassword')
	})

	it('classifies a new password and its confirmation without a username as a password change', () => {
		mount(`<form>
			<label>Password <input id="new" type="password" autocomplete="new-password"></label>
			<label>Repeat password <input id="c" type="password" autocomplete="new-password"></label>
			<button>Save</button>
		</form>`)
		expect(groupOf($('#new'))?.kind).toBe('passwordChange')
		expect(isQualifying($('#new'))).toBeNull()
	})
})

describe('one-time codes', () => {
	it('classifies the code field of test-site/otp.html as a one-time code', () => {
		mount(`<form>
			<label>Authentication code <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6"></label>
			<button type="submit">Verify</button>
		</form>`)
		expect(classifyField($('#code'))).toBe('oneTimeCode')
		expect(groupOf($('#code'))?.kind).not.toBe('login')
		expect(isQualifying($('#code'))).toBe('code')
	})

	it('qualifies every input of six one-character inputs in code mode, and finds them as one group', () => {
		mount(`<form>${[0, 1, 2, 3, 4, 5].map((i) => `<input id="d${i}" type="text" inputmode="numeric" maxlength="1" aria-label="Digit ${i + 1}">`).join('')}<button>Verify</button></form>`)
		expect(isQualifying($('#d0'))).toBe('code')
		expect(isQualifying($('#d5'))).toBe('code')
		expect(codeGroup($('#d3'))?.map((input) => input.id)).toEqual(['d0', 'd1', 'd2', 'd3', 'd4', 'd5'])
		expect(findCodeGroups(document)).toHaveLength(1)
	})

	it('groups two halves of three with a dash between them', () => {
		mount(`<form>
			<input id="a" type="text" inputmode="numeric" maxlength="3" autocomplete="one-time-code" aria-label="Code, first half">
			<span>-</span>
			<input id="b" type="text" inputmode="numeric" maxlength="3" aria-label="Code, second half">
			<button>Verify</button></form>`)
		expect(codeGroup($('#b'))?.map((input) => input.id)).toEqual(['a', 'b'])
		expect(isQualifying($('#b'))).toBe('code')
	})

	it('leaves a birth date in three inputs and three one-character inputs out', () => {
		mount(`<form>
			<input id="day" maxlength="2" inputmode="numeric" name="day"><input id="month" maxlength="2" inputmode="numeric" name="month"><input id="year" maxlength="4" inputmode="numeric" name="year">
			<button>Next</button></form>
			<form><input id="x" maxlength="1"><input maxlength="1"><input maxlength="1"><button>Go</button></form>`)
		expect(codeGroup($('#month'))).toBeNull()
		expect(codeGroup($('#x'))).toBeNull()
		expect(isQualifying($('#month'))).toBeNull()
	})

	it.each(['Recovery code', 'Backup code', 'Herstelcode', 'Back-upcode'])('never treats a field labelled "%s" as a one-time code', (label) => {
		mount(`<form><label>${label} <input id="r" type="text" autocomplete="one-time-code" inputmode="numeric" maxlength="8"></label><button>Verify</button></form>`)
		expect(classifyField($('#r'))).not.toBe('oneTimeCode')
		expect(isQualifying($('#r'))).toBeNull()
	})

	it('never takes a one-time code field as the username', () => {
		mount(`<form>
			<label>Inlogcode <input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6"></label>
			<label>Password <input id="p" type="password"></label>
			<button>Log in</button>
		</form>`)
		const group = groupOf($('#p'))
		expect(group?.username).toBeNull()
		expect(classifyField($('#code'))).toBe('oneTimeCode')
	})

	it('recognises a code field without the token by its other evidence', () => {
		mount(`<form>
			<label>Verification code <input id="code" name="code" inputmode="numeric"></label>
			<button>Verify</button>
		</form>`)
		expect(classifyField($('#code'))).toBe('oneTimeCode')
	})

	it('keeps a password field with a lone one-time-code token beside a username and "Log in" a password', () => {
		mount(`<form>
			<label>Username <input id="u" name="username"></label>
			<label>Password <input id="p" type="password" autocomplete="one-time-code"></label>
			<button>Log in</button>
		</form>`)
		expect(classifyField($('#p'))).toBe('currentPassword')
		expect(groupOf($('#p'))?.kind).toBe('login')
	})

	it('classifies a password-type code field with the token and other evidence as a one-time code', () => {
		mount(`<form>
			<label>Code <input id="otp" name="otp" type="password" maxlength="6" autocomplete="one-time-code"></label>
			<button>Verify</button>
		</form>`)
		expect(classifyField($('#otp'))).toBe('oneTimeCode')
		expect(groupOf($('#otp'))?.currentPassword).toBeNull()
	})

	it('lets strong username evidence outweigh a lone one-time-code token', () => {
		mount(`<form>
			<label>Username <input id="u" name="username" autocomplete="one-time-code"></label>
			<label>Password <input id="p" type="password"></label>
			<button>Log in</button>
		</form>`)
		expect(classifyField($('#u'))).toBe('username')
	})
})

describe('newsletters', () => {
	it('does not treat an "Email" + "Subscribe" form as a login', () => {
		mount(`<form>
			<label>Email <input id="e" name="email" type="email"></label>
			<button type="submit">Subscribe</button>
		</form>`)
		expect(groupOf($('#e'))?.kind).toBe('other')
		expect(findLoginFields(document)).toEqual([])
		expect(isQualifying($('#e'))).toBeNull()
	})

	it('does not treat "Aanmelden voor nieuwsbrief" as a login', () => {
		mount(`<form>
			<label>E-mailadres <input id="e" name="email" type="email"></label>
			<button type="submit">Aanmelden voor nieuwsbrief</button>
		</form>`)
		expect(groupOf($('#e'))?.kind).toBe('other')
		expect(isQualifying($('#e'))).toBeNull()
	})

	it('does not treat "Aanmelden" beside a nieuwsbrief label as a login', () => {
		mount(`<form>
			<label>E-mail voor de nieuwsbrief <input id="e" name="email" type="email"></label>
			<button type="submit">Aanmelden</button>
		</form>`)
		expect(groupOf($('#e'))?.kind).toBe('other')
	})
})

describe('formless groups', () => {
	it('groups the formless login of test-site/spa.html around its "Sign in" button', () => {
		mount(`<main>
			<div class="card" id="login">
				<label>Username <input id="username" type="text"></label>
				<label>Password <input id="password" type="password"></label>
				<button type="button" id="submit">Sign in</button>
			</div>
		</main>`)
		const group = groupOf($('#username'))
		expect(group?.kind).toBe('login')
		expect(group?.anchor).toBe(document.getElementById('login'))
		expect(group?.username).toBe($('#username'))
		expect(group?.currentPassword).toBe($('#password'))
		expect(group?.submit).toBe(document.getElementById('submit'))
		expect(isQualifying($('#username'))).toBe('login')
	})

	it('anchors a password field without a keyword button on the document', () => {
		mount(`<div><input id="u" name="username"><input id="p" type="password"><button aria-label="Go">→</button></div>`)
		const group = groupOf($('#p'))
		expect(group?.anchor).toBe(document)
		expect(group?.kind).toBe('login')
	})

	it.each(['Subscribe', 'Sign up'])('does not take a header field with "%s" as the username of a password-only step', (text) => {
		mount(`<header><label>Email <input id="e" type="email"></label><button type="button">${text}</button></header>
		<main><label>Password <input id="p" type="password"></label><button type="button" aria-label="Go">→</button></main>`)
		const group = groupOf($('#p'))
		expect(group?.kind).toBe('login')
		expect(group?.username).toBeNull()
		expect(isQualifying($('#e'))).toBeNull()
	})

	it('does not treat a lone formless email with a "Join" button as a login', () => {
		mount(`<div><label>Email <input id="e" type="email"></label><button type="button">Join</button></div>`)
		expect(groupOf($('#e'))?.kind).toBe('other')
	})

	it('treats a lone formless email with a username token as a username-only step', () => {
		mount(`<div><label>Email <input id="e" type="email" autocomplete="username"></label><button type="button">Join</button></div>`)
		expect(groupOf($('#e'))?.kind).toBe('login')
	})

	it('anchors a formless username-only step with "Next" on the field', () => {
		mount(`<header><input id="q" type="search"></header>
		<div id="step">
			<label>Email address <input id="e" type="email"></label>
			<button type="button">Next</button>
		</div>`)
		const group = groupOf($('#e'))
		expect(group?.kind).toBe('login')
		expect(group?.anchor).toBe(document.getElementById('step'))
		expect(group?.username).toBe($('#e'))
		expect(isQualifying($('#e'))).toBe('login')
	})

	describe('three formless groups as on test-site/formless-mixed.html', () => {
		beforeEach(() => {
			mount(`<header><span>Shop</span><button type="button" id="header-signup">Sign up</button></header>
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
			<footer><label>Email <input id="ne" type="email"></label><button type="button">Subscribe</button></footer>`)
		})

		it('classifies the login panel as a login with its own submit button', () => {
			const group = groupOf($('#lu'))
			expect(group?.kind).toBe('login')
			expect(group?.anchor).toBe(document.getElementById('login-panel'))
			expect(group?.submit).toBe(document.getElementById('login-submit'))
		})

		it('classifies the sign-up panel, with a new password and its confirmation, as a sign-up', () => {
			const group = groupOf($('#su'))
			expect(group?.kind).toBe('signUp')
			expect(group?.username).toBe($('#su'))
			expect(group?.newPasswords).toEqual([$('#sp'), $('#sc')])
			expect(group?.submit).toBe(document.getElementById('signup-submit'))
		})

		it('does not treat the footer newsletter as a login', () => {
			expect(groupOf($('#ne'))?.kind).toBe('other')
		})

		it('never picks the header "Sign up" as a group submit button', () => {
			const submits = findGroups(document).map((group) => group.submit)
			expect(submits).not.toContain(document.getElementById('header-signup'))
		})

		it('qualifies the login panel fields only', () => {
			const qualifying = ['#lu', '#lp', '#su', '#sp', '#sc', '#ne'].filter((id) => isQualifying($(id)))
			expect(qualifying).toEqual(['#lu', '#lp'])
		})

		it('finds the login panel as the only login', () => {
			expect(findLoginFields(document).map((group) => group.anchor)).toEqual([document.getElementById('login-panel')])
		})
	})
})

describe('hard exclusions', () => {
	it('fills only the visible password field of test-site/honeypot.html', () => {
		mount(`<form>
			<label>Username <input id="u" name="username" type="text"></label>
			<input id="trap" name="trap" type="password" tabindex="-1" aria-hidden="true">
			<input id="trap2" name="trap2" type="password" hidden>
			<div style="display: none"><input id="trap3" type="password"></div>
			<label>Password <input id="p" name="password" type="password"></label>
			<button type="submit">Sign in</button>
		</form>`)
		const group = groupOf($('#p'))
		expect(group?.kind).toBe('login')
		expect(group?.currentPassword).toBe($('#p'))
		expect(group?.newPasswords).toEqual([])
		expect(group?.fields).toEqual([$('#u'), $('#p')])
	})

	it.each([
		['disabled', 'disabled'],
		['read-only', 'readonly'],
		['hidden', 'hidden'],
		['not rendered', 'style="display: none"'],
		['zero-width', 'style="width: 0"'],
		['zero-height', 'style="height: 0"'],
		['aria-hidden', 'aria-hidden="true"'],
	])('skips a %s username field', (_name, attribute) => {
		mount(`<form>
			<label>Username <input id="u" name="username" ${attribute}></label>
			<label>Password <input id="p" type="password"></label>
			<button>Sign in</button>
		</form>`)
		expect(groupOf($('#p'))?.username).toBeNull()
		expect(classifyField($('#u'))).toBe('other')
		expect(isQualifying($('#u'))).toBeNull()
	})

	it('skips fields inside a disabled fieldset', () => {
		mount(`<form><fieldset disabled>
			<label>Username <input id="u" name="username"></label>
			<label>Password <input id="p" type="password"></label>
		</fieldset><button>Sign in</button></form>`)
		expect(isQualifying($('#p'))).toBeNull()
	})
})

describe('shadow roots', () => {
	const shadow = (html: string) => {
		mount('<test-login></test-login>')
		const root = document.querySelector('test-login')!.attachShadow({ mode: 'open' })
		root.innerHTML = html
		return root
	}

	it('finds a login form inside an open shadow root', () => {
		const root = shadow(`<form>
			<label>Username <input id="u" name="username" type="text"></label>
			<label>Password <input id="p" name="password" type="password"></label>
			<button type="submit">Sign in</button>
		</form>`)
		expect(findLoginFields(document).map((group) => group.username)).toEqual([$('#u', root)])
		expect(isQualifying($('#p', root))).toBe('login')
	})

	it('anchors a formless login on the shadow root holding its keyword button', () => {
		const root = shadow(`<input id="u" name="username"><input id="p" type="password"><button>Sign in</button>`)
		expect(groupOf($('#p', root))?.anchor).toBe(root)
		expect(isQualifying($('#u', root))).toBe('login')
	})

	it('anchors a formless shadow-root login without a keyword button on the document', () => {
		const root = shadow(`<input id="u" name="username"><input id="p" type="password"><button>→</button>`)
		expect(groupOf($('#p', root))?.anchor).toBe(document)
	})

	describe('a design-system form with each input in its own open shadow root', () => {
		const attach = (selector: string, html: string) => {
			const root = document.querySelector(selector)!.attachShadow({ mode: 'open' })
			root.innerHTML = html
			return root
		}
		let user: HTMLInputElement
		let pass: HTMLInputElement
		let submit: HTMLElement

		beforeEach(() => {
			mount(`<form id="f"><x-input id="hu"></x-input><x-input id="hp"></x-input><x-button id="hb"></x-button></form>`)
			user = $('input', attach('#hu', '<label>Username <input name="username"></label>'))
			pass = $('input', attach('#hp', '<label>Password <input type="password"></label>'))
			submit = $('button', attach('#hb', '<button type="submit">Log in</button>'))
		})

		it('makes the form one login group', () => {
			expect(findGroups(document)).toHaveLength(1)
			const group = groupOf(user)
			expect(group?.anchor).toBe(document.getElementById('f'))
			expect(group?.kind).toBe('login')
			expect(group?.username).toBe(user)
			expect(group?.currentPassword).toBe(pass)
			expect(group?.submit).toBe(submit)
		})

		it('qualifies both fields', () => {
			expect(isQualifying(user)).toBe('login')
			expect(isQualifying(pass)).toBe('login')
		})
	})

	describe('slotted fields, in rendered order', () => {
		const render = (host: string, html: string) => {
			document.querySelector(host)!.attachShadow({ mode: 'open' }).innerHTML = html
		}

		it('finds the submit button in the shadow root after slotted fields', () => {
			mount(`<login-card>
				<label slot="user">Username <input id="u" name="username"></label>
				<label slot="pass">Password <input id="p" type="password"></label>
			</login-card>`)
			render('login-card', '<div><slot name="user"></slot><slot name="pass"></slot><button type="button">Sign in</button></div>')
			const group = groupOf($('#p'))
			expect(group?.kind).toBe('login')
			expect(group?.username).toBe($('#u'))
			expect(group?.submit?.textContent).toBe('Sign in')
		})

		it('finds a button slotted into a footer though it comes first in light DOM', () => {
			mount(`<x-dialog>
				<button slot="actions" type="button" id="s">Sign in</button>
				<label>Username <input id="u" name="username"></label>
				<label>Password <input id="p" type="password"></label>
			</x-dialog>`)
			render('x-dialog', '<div><slot></slot></div><footer><slot name="actions"></slot></footer>')
			expect(groupOf($('#p'))?.submit).toBe(document.getElementById('s'))
		})

		it('reads slot fallback content when nothing is assigned', () => {
			mount('<x-login></x-login>')
			render('x-login', '<slot><input id="u" name="username"><input id="p" type="password"><button>Sign in</button></slot>')
			const root = document.querySelector('x-login')!.shadowRoot!
			expect(groupOf($('#p', root))?.username).toBe($('#u', root))
		})

		it('skips fallback content a slot does not render because only whitespace is assigned', () => {
			mount('<x-login> </x-login>')
			render('x-login', '<slot><input id="u" name="username"><input id="p" type="password"><button>Sign in</button></slot>')
			const root = document.querySelector('x-login')!.shadowRoot!
			expect(groupOf($('#p', root))).toBeNull()
			expect(findLoginFields()).toEqual([])
		})
	})
})

describe('pages without a login', () => {
	it('finds nothing on a search page like test-site/no-form.html', () => {
		mount(`<form method="get">
			<label>Search <input id="q" name="q" type="search"></label>
			<button type="submit">Search</button>
		</form>`)
		expect(findGroups(document)).toEqual([])
		expect(groupOf($('#q'))).toBeNull()
		expect(isQualifying($('#q'))).toBeNull()
	})
})

describe('fillField', () => {
	it('sets the value through the native setter and dispatches key, input and change events', () => {
		mount('<input id="u">')
		const input = $('#u')
		const native = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')!
		// A framework's value tracker on the instance, as React installs it.
		let tracked = ''
		Object.defineProperty(input, 'value', {
			configurable: true,
			get: () => native.get!.call(input),
			set: (value: string) => {
				tracked = value
				native.set!.call(input, value)
			},
		})
		const events: string[] = []
		for (const type of ['keydown', 'keypress', 'keyup', 'input', 'change']) {
			input.addEventListener(type, (event) => events.push(`${event.type}:${event.bubbles}`))
		}

		fillField(input, 'alice')

		expect(input.value).toBe('alice')
		expect(tracked).toBe('')
		expect(events).toEqual(['keydown:true', 'keypress:true', 'keyup:true', 'input:true', 'change:true'])
	})
})
