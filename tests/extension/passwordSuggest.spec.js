/**
 * The in-page offer of a strong password in a sign-up field.
 *
 * @spec openspec/changes/clients-extension-generator-vault-send/specs/extension-generator/spec.md#requirement-suggest-a-strong-password-in-a-sign-up-field
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
	fieldsToFill,
	isNewPasswordField,
	showSuggestion,
} from '../../browser-extension/src/content/password-suggest.js'

/**
 * Put a form in the document.
 *
 * @param {string} html The form's inner HTML.
 * @return {HTMLFormElement}
 */
function form(html) {
	document.body.innerHTML = `<form>${html}</form>`
	return document.querySelector('form')
}

describe('which fields get the offer', () => {
	afterEach(() => {
		document.body.innerHTML = ''
	})

	it('a field marked new-password does, a sign-in field does not', () => {
		form('<input type="password" autocomplete="new-password">')
		expect(isNewPasswordField(document.querySelector('input'))).toBe(true)
		form(
			'<input type="text" name="user"><input type="password" autocomplete="current-password">',
		)
		expect(
			isNewPasswordField(document.querySelector('input[type=password]')),
		).toBe(false)
	})

	it('a password with a confirmation field does', () => {
		form('<input type="password" name="pw"><input type="password" name="pw2">')
		expect(isNewPasswordField(document.querySelector('input'))).toBe(true)
	})

	it('a single unmarked password field only with a sign-up hint', () => {
		form('<input type="password" name="password">')
		expect(isNewPasswordField(document.querySelector('input'))).toBe(false)
		form('<input type="password" name="new_password">')
		expect(isNewPasswordField(document.querySelector('input'))).toBe(true)
	})

	it('fills the new password and its confirmation, never the current one', () => {
		form(
			'<input type="password" autocomplete="current-password" id="old"><input type="password" id="a"><input type="password" id="b">',
		)
		expect(fieldsToFill(document.getElementById('a')).map((f) => f.id)).toEqual([
			'a',
			'b',
		])
	})
})

describe('the offer', () => {
	afterEach(() => {
		document.body.innerHTML = ''
	})

	it("fills both fields on the user's click and closes", async () => {
		form(
			'<input type="password" id="a" autocomplete="new-password"><input type="password" id="b">',
		)
		const input = document.getElementById('a')
		const request = vi.fn(async () => ({ value: 'Gen3rated!Value' }))
		const shown = showSuggestion(input, request, {
			mode: 'open',
			isUserEvent: () => true,
		})
		const root = document.getElementById('keepiq-password-suggest').shadowRoot
		expect(root.textContent).toContain('Use a strong password')

		root.querySelector('button').click()
		await expect(shown).resolves.toBe(true)
		expect(document.getElementById('a').value).toBe('Gen3rated!Value')
		expect(document.getElementById('b').value).toBe('Gen3rated!Value')
		expect(document.getElementById('keepiq-password-suggest')).toBeNull()
	})

	it('ignores a click the page script made', async () => {
		form('<input type="password" id="a" autocomplete="new-password">')
		const request = vi.fn(async () => ({ value: 'x' }))
		showSuggestion(document.getElementById('a'), request, {
			mode: 'open',
			isUserEvent: () => false,
		})
		document
			.getElementById('keepiq-password-suggest')
			.shadowRoot.querySelector('button')
			.click()
		await Promise.resolve()
		expect(request).not.toHaveBeenCalled()
		expect(document.getElementById('a').value).toBe('')
	})

	it('fills nothing when the worker has no password', async () => {
		form('<input type="password" id="a" autocomplete="new-password">')
		const shown = showSuggestion(
			document.getElementById('a'),
			async () => ({ error: 'no' }),
			{ mode: 'open', isUserEvent: () => true },
		)
		document
			.getElementById('keepiq-password-suggest')
			.shadowRoot.querySelector('button')
			.click()
		await expect(shown).resolves.toBe(false)
		expect(document.getElementById('a').value).toBe('')
	})
})
