// @vitest-environment happy-dom
// @vitest-environment-options {"settings":{"navigation":{"disableChildFrameNavigation":true}}}
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { mockLayout } from '@/src/testing/layout'
import { createFrameHost, type FrameHost } from './frame-host'
import { classifyField, codeGroup, fillField, fillSelect, findCodeGroups, findGroups, findLoginFields, formRolesIn, groupOf, isQualifying } from './fields'

const $ = (selector: string, root: Document | ShadowRoot = document) => root.querySelector(selector) as HTMLInputElement
const mount = (html: string) => {
	document.body.innerHTML = html
}

beforeEach(() => {
	mockLayout()
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
		// Outside a login a phone field is the person's phone (spec `card-identity-fill`, Identity field roles).
		expect(classifyField($('#t'))).toBe('phone')
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

	it('keeps six tel boxes, the first with the token, one code group and no phone number', () => {
		const box = (i: number) => `<input id="t${i}" type="tel" inputmode="numeric" maxlength="1"${i === 0 ? ' autocomplete="one-time-code"' : ''}>`
		mount(`<form>${[0, 1, 2, 3, 4, 5].map(box).join('')}<button>Verify</button></form>`)
		const ids = ['#t0', '#t1', '#t2', '#t3', '#t4', '#t5']
		expect(roles(...ids)).toEqual(['oneTimeCode', 'other', 'other', 'other', 'other', 'other'])
		expect(ids.map((id) => isQualifying($(id)))).toEqual(Array(6).fill('code'))
		expect(formRolesIn(groupOf($('#t0'))!, 'identity').size).toBe(0)
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

	it('fills a textarea through its own native setter', () => {
		mount('<textarea id="a"></textarea>')
		const area = document.querySelector('textarea')!
		const events: string[] = []
		for (const type of ['input', 'change']) area.addEventListener(type, () => events.push(type))
		fillField(area, 'Kerkstraat 1\n1234 AB Utrecht')
		expect(area.value).toBe('Kerkstraat 1\n1234 AB Utrecht')
		expect(events).toEqual(['input', 'change'])
	})
})

const roles = (...ids: string[]) => ids.map((id) => classifyField($(id)))

describe('card fields', () => {
	it('reads the roles of a checkout form from its tokens', () => {
		mount(`<form>
			<label>Name <input id="h" autocomplete="cc-name"></label>
			<label>Number <input id="n" autocomplete="cc-number"></label>
			<label>Expires <input id="e" autocomplete="cc-exp"></label>
			<label>Code <input id="c" autocomplete="cc-csc"></label>
			<button>Pay</button>
		</form>`)
		expect(roles('#h', '#n', '#e', '#c')).toEqual(['cardholder', 'number', 'expiry', 'securityCode'])
		expect(['#h', '#n', '#e', '#c'].map((id) => isQualifying($(id)))).toEqual(['card', 'card', 'card', 'card'])
	})

	it('reads Dutch labels without tokens', () => {
		mount(`<form>
			<label>Naam op de kaart <input id="h"></label>
			<label>Kaartnummer <input id="n"></label>
			<label>Vervaldatum <input id="e" placeholder="MMJJ"></label>
			<label>Beveiligingscode <input id="c"></label>
			<button>Betalen</button>
		</form>`)
		expect(roles('#h', '#n', '#e', '#c')).toEqual(['cardholder', 'number', 'expiry', 'securityCode'])
	})

	it('never takes a password-typed CVV beside a card number as a login password', () => {
		mount(`<form>
			<label>Card number <input id="n" inputmode="numeric"></label>
			<label>CVV <input id="c" type="password" maxlength="4"></label>
			<button type="submit">Pay</button>
		</form>`)
		expect(classifyField($('#c'))).toBe('securityCode')
		expect(groupOf($('#c'))?.kind).toBe('other')
		expect(groupOf($('#c'))?.currentPassword).toBeNull()
		expect(findLoginFields()).toEqual([])
		expect(isQualifying($('#c'))).toBe('card')
	})

	it('never reads a payment form whose only password field is its CVC as a username-only step', () => {
		mount(`<form>
			<label>Email <input id="e" type="email" autocomplete="username"></label>
			<label>CVC <input id="c" type="password"></label>
			<button>Continue</button>
		</form>`)
		expect(groupOf($('#e'))?.kind).toBe('other')
		expect(isQualifying($('#e'))).toBeNull()
	})

	it('keeps the login form of a page that also has a payment form', () => {
		mount(`<form id="login">
			<label>Username <input id="u"></label>
			<label>Password <input id="p" type="password"></label>
			<button>Sign in</button>
		</form>
		<form id="pay">
			<label>Card number <input id="n"></label>
			<label>CVC <input id="c" type="password"></label>
			<button>Pay</button>
		</form>`)
		expect(findLoginFields().map((group) => group.anchor)).toEqual([document.getElementById('login')])
		expect(roles('#u', '#p', '#n', '#c')).toEqual(['username', 'currentPassword', 'number', 'securityCode'])
	})

	it('gives a field named only "PIN" no role', () => {
		mount(`<form>
			<label>Card number <input id="n"></label>
			<label>PIN <input id="pin" inputmode="numeric" maxlength="4"></label>
			<button>Pay</button>
		</form>`)
		expect(classifyField($('#pin'))).toBe('other')
		expect(isQualifying($('#pin'))).toBeNull()
	})

	it('reads four inputs of four after "Card number" as one split number', () => {
		mount(`<form>
			<label>Card number <input id="n1" maxlength="4"></label>
			<input id="n2" maxlength="4"><input id="n3" maxlength="4"><input id="n4" maxlength="4">
			<label>CVC <input id="c" maxlength="3"></label>
			<button>Pay</button>
		</form>`)
		expect(roles('#n1', '#n2', '#n3', '#n4', '#c')).toEqual(['number', 'number', 'number', 'number', 'securityCode'])
		expect(isQualifying($('#n3'))).toBe('card')
	})

	it('reads an Amex number over 4, 6 and 5 inputs', () => {
		mount(`<form>
			<label>Card number <input id="n1" maxlength="4"></label><input id="n2" maxlength="6"><input id="n3" maxlength="5">
			<label>Postcode <input id="z" maxlength="4"></label>
		</form>`)
		expect(roles('#n1', '#n2', '#n3', '#z')).toEqual(['number', 'number', 'number', 'other'])
	})

	it('reads month and year selects beside a card number, and opens no list on them', () => {
		mount(`<form>
			<label>Card number <input id="n"></label>
			<label>Month <select id="m"><option>01</option><option>02</option></select></label>
			<label>Year <select id="y"><option>2026</option><option>2027</option></select></label>
			<label>Card type <select id="b"><option>VISA</option></select></label>
		</form>`)
		const select = (id: string) => document.getElementById(id)!
		expect(['m', 'y', 'b'].map((id) => classifyField(select(id)))).toEqual(['expMonth', 'expYear', 'brand'])
		expect(['m', 'y', 'b'].map((id) => isQualifying(select(id)))).toEqual([null, null, null])
	})

	it('gives a birth date month and year no card role without card evidence', () => {
		mount(`<form>
			<label>First name <input id="f"></label>
			<label>Month <select id="m"><option>01</option></select></label>
			<label>Year <input id="y" maxlength="4"></label>
		</form>`)
		expect([classifyField(document.getElementById('m')!), classifyField($('#y'))]).toEqual(['other', 'other'])
	})

	it('reads a combined field and the month and year of an expiry', () => {
		mount(`<form>
			<label>Card number <input id="n"></label>
			<label>Expiry month <input id="m"></label><label>Expiry year <input id="y"></label>
			<input id="e" placeholder="MM / YYYY">
		</form>`)
		expect(roles('#m', '#y', '#e')).toEqual(['expMonth', 'expYear', 'expiry'])
	})

	it.each([
		['<input id="d" placeholder="DD/MM/YYYY">'],
		['<label>Geboortedatum <input id="d" placeholder="dd-mm-jjjj"></label>'],
		['<label>Date of birth (MM/YY) <input id="d"></label>'],
		['<label>Start date <input id="d" placeholder="MM/YY"></label>'],
		['<label>Valid from <input id="d" placeholder="MM/YY"></label>'],
	])('gives a birth or start date beside a card number no expiry role: %s', (field) => {
		mount(`<form><label>Card number <input id="n"></label><label>CVC <input id="c"></label>${field}</form>`)
		expect(roles('#n', '#d')).toEqual(['number', 'other'])
		expect(isQualifying($('#d'))).toBeNull()
	})

	it('gives month and year selects no card role beside a day field, and keeps cc-exp tokens', () => {
		mount(`<form>
			<label>Card number <input id="n"></label>
			<label>Day <select id="dd"><option>01</option></select></label>
			<label>Month <select id="m"><option>01</option></select></label>
			<label>Year <select id="y"><option>2027</option></select></label>
			<select id="tm" autocomplete="cc-exp-month"><option>01</option></select>
		</form>`)
		const select = (id: string) => classifyField(document.getElementById(id)!)
		expect(['n', 'dd', 'm', 'y', 'tm'].map(select)).toEqual(['number', 'other', 'other', 'other', 'expMonth'])
	})

	it('reads "Customer ID" in a login as the username, never a CID', () => {
		mount(`<form>
			<label>Customer ID <input id="u" name="cid"></label>
			<label>Password <input id="p" type="password"></label>
			<button>Log in</button>
		</form>`)
		expect(groupOf($('#u'))?.username).toBe($('#u'))
		expect(roles('#u', '#p')).toEqual(['username', 'currentPassword'])
		expect(isQualifying($('#u'))).toBe('login')
	})

	it('reads a library card number beside a PIN password as a login username', () => {
		mount(`<form>
			<label>Library card number <input id="u"></label>
			<label>PIN <input id="p" type="password"></label>
			<button>Sign in</button>
		</form>`)
		const group = groupOf($('#u'))!
		expect([group.kind, group.username]).toEqual(['login', $('#u')])
		expect(roles('#u', '#p')).toEqual(['username', 'currentPassword'])
	})

	it('reads labels beside cc-* tokens in a group with a password that is no card field', () => {
		mount(`<form>
			<label>Card number <input id="n" autocomplete="cc-number"></label>
			<label>Expires <input id="e" placeholder="MM/YY"></label>
			<label>CVC <input id="c" autocomplete="cc-csc"></label>
			<label>Choose a password <input id="p" type="password" autocomplete="new-password"></label>
		</form>`)
		expect(roles('#n', '#e', '#c')).toEqual(['number', 'expiry', 'securityCode'])
	})

	it('reads "Name on card" alone, as in a top frame whose card number is in a payment frame', () => {
		mount('<form><label>Name on card <input id="h"></label><button>Pay</button></form>')
		expect(classifyField($('#h'))).toBe('cardholder')
		expect(isQualifying($('#h'))).toBe('card')
	})

	it.each([['3 digits on the back'], ['Kaartcode'], ['CVN']])('reads a password-typed "%s" beside a card number and expiry as its security code', (label) => {
		mount(`<form>
			<label>Card number <input id="n"></label>
			<label>Expiry <input id="e" placeholder="MM/YY"></label>
			<label>${label} <input id="c" type="password" maxlength="4"></label>
			<button>Pay</button>
		</form>`)
		const group = groupOf($('#c'))!
		expect(roles('#n', '#e', '#c')).toEqual(['number', 'expiry', 'securityCode'])
		expect([group.kind, group.username, group.currentPassword]).toEqual(['other', null, null])
	})

	it.each([['Security code', 3], ['Beveiligingscode', 3], ['Controlegetal', 4], ['CVV', 3]])('reads a saved card\'s password-typed "%s" of %i alone as its CVC, never a login password', (label, length) => {
		mount(`<form><p>Visa ending 1111</p><label>${label} <input id="c" type="password" maxlength="${length}"></label><button>Pay now</button></form>`)
		const group = groupOf($('#c'))!
		expect(classifyField($('#c'))).toBe('securityCode')
		expect([group.kind, group.currentPassword]).toEqual(['other', null])
		expect(findLoginFields()).toEqual([])
		expect(isQualifying($('#c'))).toBe('card')
	})

	it.each(['CVC', 'CVV'])('reads a saved card\'s text-typed "%s" alone as its CVC', (label) => {
		mount(`<form><label>${label} <input id="c" inputmode="numeric" maxlength="3"></label><button>Pay</button></form>`)
		expect(classifyField($('#c'))).toBe('securityCode')
		expect(isQualifying($('#c'))).toBe('card')
	})

	it('never reads a password-typed PIN or password beside a card number and expiry as a security code', () => {
		mount(`<form>
			<label>Card number <input id="n"></label>
			<label>Expiry <input id="e" placeholder="MM/YY"></label>
			<label>PIN <input id="pin" type="password" maxlength="4"></label>
		</form>`)
		expect(classifyField($('#pin'))).not.toBe('securityCode')
	})

	it('keeps the expiry month and year beside a birth date asked elsewhere in the form', () => {
		mount(`<form>
			<label>Card number <input id="n"></label>
			<label>Month <select id="em"><option>01</option></select></label>
			<label>Year <select id="ey"><option>2027</option></select></label>
			<label>Day <select id="bd"><option>01</option></select></label>
			<label>Month <select id="bm"><option>01</option></select></label>
			<label>Year <select id="by"><option>1990</option></select></label>
		</form>`)
		const select = (id: string) => classifyField(document.getElementById(id)!)
		expect(['n', 'em', 'ey', 'bd', 'bm', 'by'].map(select)).toEqual(['number', 'expMonth', 'expYear', 'other', 'other', 'other'])
	})
})

describe('card wording on fields that are no card fields', () => {
	it.each([
		['Security code', 'autocomplete="one-time-code" inputmode="numeric" maxlength="6"'],
		['Enter the security code we sent you', 'name="otp" inputmode="numeric" maxlength="6"'],
		['Beveiligingscode', 'autocomplete="one-time-code" inputmode="numeric" maxlength="6"'],
		['Security code', 'type="password" maxlength="6"'],
	])('reads a one-time code labelled "%s" as one', (label, attributes) => {
		mount(`<form><label>${label} <input id="o" ${attributes}></label><button>Verify</button></form>`)
		expect(classifyField($('#o'))).toBe('oneTimeCode')
		expect(isQualifying($('#o'))).toBe('code')
		expect(findCodeGroups()).toEqual([[$('#o')]])
	})

	it('reads six boxes labelled "Security code digit N" as a split one-time code', () => {
		mount(`<form>${[1, 2, 3, 4, 5, 6].map((n) => `<input id="d${n}" aria-label="Security code digit ${n}" inputmode="numeric" maxlength="1">`).join('')}<button>Verify</button></form>`)
		expect(roles('#d1', '#d6')).not.toContain('securityCode')
		expect(isQualifying($('#d1'))).toBe('code')
		expect(codeGroup($('#d3'))).toHaveLength(6)
	})

	it.each([
		['Library card number', 'name="userid"'],
		['Customer ID', 'name="cid" autocomplete="username"'],
		['Customer ID', 'name="cid" placeholder="Your account"'],
		['Card number', 'name="username"'],
		['ID card number', 'name="login"'],
		['ID card number', 'autocomplete="username"'],
	])('keeps a username-first step whose identifier is labelled "%s" %s a login', (label, attributes) => {
		mount(`<form><label>${label} <input id="u" ${attributes}></label><button>Next</button></form>`)
		const group = groupOf($('#u'))!
		expect([group.kind, group.username, classifyField($('#u'))]).toEqual(['login', $('#u'), 'username'])
		expect(isQualifying($('#u'))).toBe('login')
	})

	const DATE = '<label>Day <select id="dd"><option>1</option></select></label><label>Month <select id="mm"><option>1</option></select></label><label>Year <select id="yy"><option>1990</option></select></label>'

	it.each([
		['"Cardholder ID"', '<label>Cardholder ID <input id="u"></label><label>Password <input id="p" type="password"></label><button>Log in</button>'],
		['"Card holder number"', '<label>Card holder number <input id="u"></label><label>Password <input id="p" type="password"></label><button>Sign in</button>'],
		['"Student card number" beside a "Year" select', '<label>Student card number <input id="u"></label><label>Year <select id="y"><option>1</option></select></label><label>Password <input id="p" type="password"></label><button>Log in</button>'],
		['"Card number" beside a date of birth', `<label>Card number <input id="u"></label>${DATE}<label>Memorable word <input id="p" type="password"></label><button>Log in</button>`],
	])('keeps a login whose username is labelled %s a login', (_, fields) => {
		mount(`<form>${fields}</form>`)
		const group = groupOf($('#u'))!
		expect([group.kind, group.username, group.currentPassword]).toEqual(['login', $('#u'), $('#p')])
		expect([...formRolesIn(group, 'card').values()]).toEqual([])
	})

	it.each([[8, 6], [6, 8], [8, 8]])('reads a card number of %i beside a PIN of %i as a login, never a split number', (number, pin) => {
		mount(`<form>
			<label>Card number <input id="u" maxlength="${number}"></label>
			<label>PIN <input id="p" type="password" maxlength="${pin}"></label>
			<button>Log in</button>
		</form>`)
		const group = groupOf($('#u'))!
		expect([group.kind, group.username, group.currentPassword]).toEqual(['login', $('#u'), $('#p')])
		expect(roles('#u', '#p')).toEqual(['username', 'currentPassword'])
	})

	it('keeps the password of a formless login above a card number and expiry a login password', () => {
		mount(`<input id="u" name="user" placeholder="Benutzer"><input id="p" type="password" name="kw">
			<label>Card number <input id="n"></label><label>Expiry <input id="x" placeholder="MM/YY"></label>
			<button>Anmelden</button>`)
		expect(classifyField($('#p'))).toBe('currentPassword')
		expect(isQualifying($('#p'))).toBe('login')
		expect(roles('#n', '#x')).toEqual(['number', 'expiry'])
	})

	it('reads a guest checkout without tokens as a sign-up beside its card fields', () => {
		mount(`<form>
			<label>Name on card <input id="h"></label>
			<label>Card number <input id="n"></label>
			<label>Expiry (MM/YY) <input id="e"></label>
			<label>CVV <input id="c" type="password" maxlength="4"></label>
			<label>Email <input id="m" type="email"></label>
			<label>Create a password <input id="p" type="password"></label>
			<button>Create account and pay</button>
		</form>`)
		const group = groupOf($('#n'))!
		expect(roles('#h', '#n', '#e', '#c')).toEqual(['cardholder', 'number', 'expiry', 'securityCode'])
		expect([group.kind, group.username, group.newPasswords]).toEqual(['signUp', $('#m'), [$('#p')]])
	})

	it('gives a tel-typed card number its card role, not a phone, in a sign-up with a checkout', () => {
		mount(`<form>
			<label>Email <input id="m" type="email"></label>
			<label>Create a password <input id="p" type="password"></label>
			<label>Card number <input id="n" type="tel"></label>
			<label>Expiry <input id="e" placeholder="MM/YY"></label>
			<label>CVV <input id="c" type="password" maxlength="4"></label>
			<button>Create account and pay</button>
		</form>`)
		const group = groupOf($('#n'))!
		expect(roles('#n', '#e', '#c')).toEqual(['number', 'expiry', 'securityCode'])
		expect(group.newPasswords).toEqual([$('#p')])
		expect([...formRolesIn(group, 'card').keys()]).toEqual([$('#n'), $('#e'), $('#c')])
	})
})

describe('card and identity fields the user cannot see', () => {
	// A page large enough to scroll to every field past the viewport.
	beforeEach(() => {
		document.documentElement.setAttribute('data-scroll-height', '4000')
		document.documentElement.setAttribute('data-scroll-width', '3000')
	})
	afterEach(() => {
		document.documentElement.removeAttribute('data-scroll-height')
		document.documentElement.removeAttribute('data-scroll-width')
		document.documentElement.removeAttribute('style')
		document.body.removeAttribute('style')
	})

	// Each trick on the card number; the name on card beside it stays visible.
	it.each([
		['opacity 0', '<input id="n" autocomplete="cc-number" style="opacity: 0">'],
		['an ancestor faded out', '<div style="opacity: 0.05"><input id="n" autocomplete="cc-number"></div>'],
		['an ancestor with aria-hidden', '<div aria-hidden="true"><input id="n" autocomplete="cc-number"></div>'],
		['an inert ancestor', '<div inert><input id="n" autocomplete="cc-number"></div>'],
		['off-screen to the left', '<input id="n" autocomplete="cc-number" style="position: absolute; left: -9999px">'],
		['off-screen above', '<input id="n" autocomplete="cc-number" style="position: absolute; top: -9999px">'],
		['one pixel', '<input id="n" autocomplete="cc-number" style="width: 1px; height: 1px">'],
		['clip-path', '<input id="n" autocomplete="cc-number" style="clip-path: inset(50%)">'],
		['covered by another element', '<input id="n" autocomplete="cc-number"><div data-covers="n"></div>'],
	])('gives no card role to a field hidden by %s, nor any login role', (_, field) => {
		mount(`<form><label>Name on card <input id="h" autocomplete="cc-name"></label>${field}<button>Pay</button></form>`)
		expect(roles('#h', '#n')).toEqual(['cardholder', 'other'])
		expect(isQualifying($('#n'))).toBeNull()
		expect([...formRolesIn(groupOf($('#h'))!, 'card').values()]).toEqual(['cardholder'])
	})

	it('keeps a hidden password-typed CVC out of the login roles', () => {
		mount(`<form>
			<label>Email <input id="u" type="email"></label>
			<input id="c" type="password" autocomplete="cc-csc" style="opacity: 0; position: absolute; left: -9999px">
			<button>Continue</button>
		</form>`)
		expect(classifyField($('#c'))).toBe('other')
		expect(groupOf($('#c'))?.currentPassword).toBeNull()
		expect(findLoginFields()).toEqual([])
	})

	it('gives no identity role to hidden phone and address fields beside a visible name and email', () => {
		mount(`<form>
			<label>Your name <input id="n"></label><label>Email <input id="e" type="email"></label>
			<input id="t" type="tel" style="opacity: 0"><textarea id="a" autocomplete="street-address" style="position: absolute; left: -9999px"></textarea>
			<button>Subscribe</button>
		</form>`)
		expect([...formRolesIn(groupOf($('#n'))!, 'identity').values()]).toEqual(['fullName', 'email'])
	})

	it('gives no role to a field covered inside a shadow root by covering its host', () => {
		mount('<form><label>Name on card <input id="h" autocomplete="cc-name"></label><div id="host"></div><div data-covers="host"></div></form>')
		const shadow = document.getElementById('host')!.attachShadow({ mode: 'open' })
		shadow.innerHTML = '<input id="n" autocomplete="cc-number">'
		expect(classifyField($('#n', shadow))).toBe('other')
		document.querySelector('[data-covers]')!.remove()
		expect(classifyField($('#n', shadow))).toBe('number')
	})

	it('looks through the inline list or save bar lying over a field', () => {
		mount('<form><label>Name on card <input id="h" autocomplete="cc-name"></label><input id="n" autocomplete="cc-number"></form>')
		const host = createFrameHost('/inline-list.html', 'Keepiq')
		host.style({ position: 'fixed' })
		host.element.setAttribute('data-covers', 'n')
		document.body.append(host.element)
		expect(roles('#h', '#n')).toEqual(['cardholder', 'number'])
	})

	it.each([
		['restyled by the page', (host: FrameHost) => host.element.setAttribute('style', `${host.written} opacity: 1 !important;`)],
		['kept by the page after we removed it', (host: FrameHost) => {
			host.remove()
			document.body.append(host.element)
		}],
	])('looks through no list host %s', (_, tamper) => {
		mount('<form><label>Name on card <input id="h" autocomplete="cc-name"></label><input id="n" autocomplete="cc-number"></form>')
		const host = createFrameHost('/inline-list.html', 'Keepiq')
		host.style({ position: 'fixed' })
		host.element.setAttribute('data-covers', 'n')
		document.body.append(host.element)
		tamper(host)
		expect(roles('#h', '#n')).toEqual(['cardholder', 'other'])
	})

	it('takes a field under its own floating label as seen, but not under another field\'s label', () => {
		mount(`<form>
			<label for="n" data-covers="n">Card number</label><input id="n">
			<label for="e" data-covers="c">Expiry</label><input id="e" placeholder="MM/YY">
			<label>CVC <input id="c"></label>
		</form>`)
		expect(roles('#n', '#e', '#c')).toEqual(['number', 'expiry', 'other'])
	})

	it('judges a field below the viewport without a hit test', () => {
		mount('<form><label>Name on card <input id="h" autocomplete="cc-name"></label><input id="n" autocomplete="cc-number" style="top: 2000px"><div data-covers="n"></div></form>')
		expect(roles('#h', '#n')).toEqual(['cardholder', 'number'])
	})

	it('gives no identity role to fields below the viewport that an ancestor clips away', () => {
		mount(`<form>
			<label>Your name <input id="n"></label><label>Email <input id="e" type="email"></label>
			<div style="height: 0; overflow: hidden"><input id="t" type="tel" style="top: 3000px"><textarea id="a" autocomplete="street-address" style="top: 3100px"></textarea></div>
			<div style="clip-path: inset(50%)"><label>Last name <input id="l" style="top: 3200px"></label></div>
			<button>Subscribe</button>
		</form>`)
		expect([...formRolesIn(groupOf($('#n'))!, 'identity').values()]).toEqual(['fullName', 'email'])
	})

	it('keeps a field below the viewport that shows through an ancestor clipping its overflow', () => {
		mount('<form><label>Name on card <input id="h" autocomplete="cc-name"></label><div style="overflow: hidden; top: 2900px; height: 400px"><input id="n" autocomplete="cc-number" style="top: 3000px"></div></form>')
		expect(roles('#h', '#n')).toEqual(['cardholder', 'number'])
	})

	describe('below the fold of a page that scrolls', () => {
		const CARD = '<form><label>Name on card <input id="h" autocomplete="cc-name"></label><input id="n" autocomplete="cc-number" style="top: 2000px"></form>'

		it.each([
			['the body, which passes its overflow to the viewport', 'height: 100%', 'height: 100%; overflow-x: hidden; overflow-y: auto'],
			['the root', 'height: 100%; overflow-x: auto; overflow-y: scroll', ''],
			['the body, which hides only its sideways overflow', 'height: 100%', 'height: 100%; overflow-x: hidden'],
			['the root, which hides only its sideways overflow', 'overflow-x: hidden', ''],
		])('keeps a field the page scrolls to through %s', (_, root, body) => {
			document.documentElement.setAttribute('style', root)
			document.body.setAttribute('style', body)
			mount(CARD)
			expect(roles('#h', '#n')).toEqual(['cardholder', 'number'])
		})

		it.each([
			['an app root of the viewport\'s height', 'height: 768px; overflow: auto', 3000],
			['a modal\'s scrolling body', 'top: 100px; height: 500px; overflow-x: auto; overflow-y: auto', 2100],
		])('keeps a field scrolled to within %s, on a page no longer than the viewport', (_, style, extent) => {
			document.documentElement.removeAttribute('data-scroll-height')
			mount(`<div style="${style}" data-scroll-height="${extent}">${CARD}</div>`)
			expect(roles('#h', '#n')).toEqual(['cardholder', 'number'])
		})

		it('gives no role to a field past the end of what its scroller scrolls through', () => {
			mount(`<div style="height: 768px; overflow: auto" data-scroll-height="1500">${CARD}</div>`)
			expect(roles('#h', '#n')).toEqual(['cardholder', 'other'])
		})

		it.each([
			['a collapsed accordion', `<div style="top: 2000px; height: 0; overflow: hidden"><input id="n" autocomplete="cc-number" style="top: 2000px"></div>`],
			['a wizard step off to the side', `<div style="overflow: hidden; width: 600px; height: 2100px"><input id="n" autocomplete="cc-number" style="left: 700px; top: 2000px"></div>`],
		])('gives no role to a field below the fold in %s', (_, field) => {
			document.body.setAttribute('style', 'overflow: auto')
			mount(`<form><label>Name on card <input id="h" autocomplete="cc-name"></label>${field}</form>`)
			expect(roles('#h', '#n')).toEqual(['cardholder', 'other'])
		})
	})

	describe('where no scrolling reaches', () => {
		const card = (field: string) => `<form><label>Name on card <input id="h" autocomplete="cc-name"></label>${field}</form>`

		it.each([
			['fixed below the viewport', '<input id="n" autocomplete="cc-number" style="position: fixed; top: 900px">'],
			['fixed beside the viewport', '<input id="n" autocomplete="cc-number" style="position: fixed; left: 1100px; top: 300px">'],
			['below the viewport in a fixed panel', '<div style="position: fixed"><input id="n" autocomplete="cc-number" style="top: 900px"></div>'],
		])('gives no role to a field %s', (_, field) => {
			mount(card(field))
			expect(roles('#h', '#n')).toEqual(['cardholder', 'other'])
		})

		it.each([
			['the root hides its overflow', 'overflow: hidden', ''],
			['the root clips its overflow', 'overflow: clip', ''],
			['the root hides its vertical overflow', 'overflow-y: hidden', ''],
			['the body hides its overflow, which the root passes on', '', 'overflow: hidden'],
			['the body hides its vertical overflow, which the root passes on', '', 'overflow-y: hidden'],
		])('gives no role to a field below the fold of a page that cannot scroll, as %s', (_, root, body) => {
			document.documentElement.setAttribute('style', root)
			document.body.setAttribute('style', body)
			mount(card('<input id="n" autocomplete="cc-number" style="top: 2000px">'))
			expect(roles('#h', '#n')).toEqual(['cardholder', 'other'])
		})

		it.each([
			['no height', 'height: 0', 'other'],
			['under ten pixels of height', 'height: 6px', 'other'],
			['under ten pixels of width', 'width: 6px; height: 300px', 'other'],
			['room to show it', 'height: 300px', 'number'],
		])('judges a field in a scrolling box with %s by what that box can show', (_, size, role) => {
			mount(card(`<div style="top: 1500px; overflow: auto; ${size}" data-scroll-height="3000" data-scroll-width="3000"><input id="n" autocomplete="cc-number" style="top: 2000px"></div>`))
			expect(roles('#h', '#n')).toEqual(['cardholder', role])
		})

		it('keeps a sticky field below the fold, which scrolling brings in like any other', () => {
			mount(card('<input id="n" autocomplete="cc-number" style="position: sticky; top: 2000px">'))
			expect(roles('#h', '#n')).toEqual(['cardholder', 'number'])
		})
	})

	describe('scrolled out of its scrolling box while in the viewport', () => {
		// The page draws something else where the field sits outside its box; `#filler` is what the box shows nearest to the field.
		const page = (overflow: string, covers: string) => `<form><label>Name on card <input id="h" autocomplete="cc-name"></label>
			<div style="left: 0; top: 0; width: 300px; height: 100px; overflow: ${overflow}" data-scroll-height="1000">
				<p id="filler" style="left: 0; top: 89px; width: 120px; height: 20px"></p>
				<input id="n" autocomplete="cc-number" style="left: 0; top: 150px; width: 120px; height: 20px">
			</div>
			<div data-covers="${covers}"></div>
		</form>`

		// The layout mock hit-tests only what was measured.
		const mountMeasured = (html: string) => {
			mount(html)
			$('#filler').getBoundingClientRect()
		}

		it('keeps a field the box scrolls to', () => {
			mountMeasured(page('auto', 'n'))
			expect(roles('#h', '#n')).toEqual(['cardholder', 'number'])
		})

		it.each([
			['a box the page covers nearest to it', page('auto', 'n filler')],
			['a box that hides its overflow without scrolling', page('hidden', 'n')],
		])('gives no role to a field scrolled out of %s', (_, html) => {
			mountMeasured(html)
			expect(roles('#h', '#n')).toEqual(['cardholder', 'other'])
		})
	})

	it('keeps the login check for login roles', () => {
		mount(`<form>
			<label>Username <input id="u" style="opacity: 0"></label>
			<label>Password <input id="p" type="password"></label>
			<button>Log in</button>
		</form>`)
		expect(roles('#u', '#p')).toEqual(['username', 'currentPassword'])
	})
})

describe('identity fields', () => {
	it('reads a Dutch address form', () => {
		mount(`<form>
			<label>Voornaam <input id="f"></label>
			<label>Achternaam <input id="l"></label>
			<label>E-mailadres <input id="e" type="email"></label>
			<label>Telefoon <input id="t"></label>
			<label>Adres <textarea id="a"></textarea></label>
			<button>Opslaan</button>
		</form>`)
		expect([...roles('#f', '#l', '#e', '#t'), classifyField(document.getElementById('a')!)])
			.toEqual(['firstName', 'lastName', 'email', 'phone', 'streetAddress'])
		expect(isQualifying($('#f'))).toBe('identity')
		expect(isQualifying(document.getElementById('a')!)).toBe('identity')
	})

	it('reads the tokens of an address form, and no role for a second address line', () => {
		mount(`<form>
			<input id="n" autocomplete="name"><input id="s" autocomplete="street-address">
			<input id="a1" autocomplete="address-line1"><label>Address line 2 <input id="a2"></label>
			<input id="z" autocomplete="postal-code">
		</form>`)
		expect(roles('#n', '#s', '#a1', '#a2', '#z')).toEqual(['fullName', 'streetAddress', 'addressLine1', 'other', 'other'])
	})

	it('gives no identity role in a login form with an email username', () => {
		mount(`<form>
			<label>Email <input id="u" type="email"></label>
			<label>Password <input id="p" type="password"></label>
			<label>Phone <input id="t" type="tel"></label>
			<button>Log in</button>
		</form>`)
		expect(groupOf($('#u'))?.kind).toBe('login')
		expect(groupOf($('#u'))!.controls.map((control) => groupOf($('#u'))!.roles.get(control))).not.toContain('email')
		expect(roles('#u', '#t')).toEqual(['username', 'other'])
		expect(isQualifying($('#u'))).toBe('login')
	})

	it('gives the email username of a sign-up the email role and its password fields none', () => {
		mount(`<form>
			<label>First name <input id="f"></label>
			<label>Email <input id="u" type="email" autocomplete="username"></label>
			<label>Password <input id="p" type="password" autocomplete="new-password"></label>
			<button>Create account</button>
		</form>`)
		const group = groupOf($('#u'))!
		expect(group.kind).toBe('signUp')
		expect(group.username).toBe($('#u'))
		expect(roles('#f', '#u', '#p')).toEqual(['firstName', 'email', 'newPassword'])
		expect([...formRolesIn(group, 'identity').values()]).toEqual(['firstName', 'email'])
		expect(isQualifying($('#u'))).toBe('identity')
	})

	it('opens no list on a newsletter email, its only identity field', () => {
		mount('<form><label>Email <input id="e" type="email"></label><button>Subscribe</button></form>')
		expect(classifyField($('#e'))).toBe('email')
		expect(isQualifying($('#e'))).toBeNull()
	})

	it('gives no phone role to the boxes of a code sent to a mobile', () => {
		mount(`<form>
			<label>Voornaam <input id="f"></label><label>Mobiel <input id="t" type="tel"></label>
			${[1, 2, 3, 4, 5, 6].map((n) => `<input id="d${n}" type="tel" maxlength="1" aria-label="Code sent to your mobile, digit ${n}">`).join('')}
			<button>Opslaan</button>
		</form>`)
		expect(roles('#f', '#t', '#d1', '#d6')).toEqual(['firstName', 'phone', 'other', 'other'])
		expect(isQualifying($('#d2'))).toBe('code')
	})

	it('gives a Dutch second address line and a voicemail number no identity role', () => {
		mount(`<form>
			<label>Voornaam <input id="f"></label><label>Adres <input id="a"></label>
			<label>Adresregel 2 <input id="a2"></label><label>Voicemail <input id="v"></label>
		</form>`)
		expect(roles('#f', '#a', '#a2', '#v')).toEqual(['firstName', 'streetAddress', 'other', 'other'])
	})

	it('leaves a card field a card field in an address form', () => {
		mount(`<form>
			<label>Naam <input id="n"></label><label>Email <input id="e"></label>
			<label>Name on card <input id="h"></label><label>Card number <input id="c"></label>
		</form>`)
		expect(roles('#n', '#e', '#h', '#c')).toEqual(['fullName', 'email', 'cardholder', 'number'])
	})
})

describe('fillSelect', () => {
	it('selects the first option matching a candidate by value or text, and dispatches input and change', () => {
		mount('<select id="s"><option value="">Choose</option><option value="1">VISA</option><option value="2">MC</option></select>')
		const select = document.getElementById('s') as HTMLSelectElement
		const events: string[] = []
		for (const type of ['input', 'change']) select.addEventListener(type, () => events.push(type))
		expect(fillSelect(select, ['mastercard', 'mc'])).toBe(true)
		expect(select.value).toBe('2')
		expect(events).toEqual(['input', 'change'])
	})

	it('leaves a select without a matching option as it was', () => {
		mount('<select id="s"><option>2026</option><option>2027</option></select>')
		const select = document.getElementById('s') as HTMLSelectElement
		expect(fillSelect(select, ['2031', '31', ''])).toBe(false)
		expect(select.value).toBe('2026')
	})
})
