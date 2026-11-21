// @vitest-environment happy-dom
import { render } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { setLocale } from '@/src/testing/i18n'
import { fieldAction, language, rich, SLOT } from './i18n'

describe('language', () => {
	it('is the catalog in use', () => {
		expect(language()).toBe('en')
		setLocale('nl')
		expect(language()).toBe('nl')
	})
})

describe('rich', () => {
	it('puts the element where the sentence has its slot', () => {
		const { container } = render(<p>{rich(`Before ${SLOT}, after.`, <a href="https://example.org">link</a>)}</p>)
		expect(container.innerHTML).toBe('<p>Before <a href="https://example.org">link</a>, after.</p>')
	})
})

describe('fieldAction', () => {
	it('lower-cases the label mid-sentence, but not an acronym', () => {
		expect(fieldAction('field.show', 'Password')).toBe('Show password')
		expect(fieldAction('field.copy', 'BSN')).toBe('Copy BSN')
		expect(fieldAction('field.hide', 'API key')).toBe('Hide API key')
	})

	it('keeps the capital where the label starts the sentence', () => {
		setLocale('nl')
		expect(fieldAction('field.show', 'Wachtwoord')).toBe('Wachtwoord tonen')
	})
})
