// @vitest-environment happy-dom
import { cleanup, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it } from 'vitest'
import type { ErrorCode } from '@/src/messages'
import { errorText } from '../errors'
import { Avatar, initials } from './Avatar'
import { Button } from './Button'

afterEach(cleanup)

describe('initials', () => {
	it.each([
		['Alice Doe', 'AD'],
		['alice', 'AL'],
		['Jan van der Berg', 'JB'],
		['  ', '?'],
	])('%j → %s', (name, expected) => {
		expect(initials(name)).toBe(expected)
	})
})

describe('Avatar', () => {
	it('shows the picture when there is one, else initials', () => {
		const { container, rerender } = render(<Avatar name="Alice Doe" dataUrl="data:image/png;base64,AA==" />)
		expect(container.querySelector('img')?.getAttribute('src')).toBe('data:image/png;base64,AA==')
		rerender(<Avatar name="Alice Doe" dataUrl={null} />)
		expect(container.textContent).toBe('AD')
	})
})

describe('Button', () => {
	it('is disabled and marked busy while busy', () => {
		render(<Button busy>Unlock</Button>)
		const button = screen.getByRole('button', { name: 'Unlock' })
		expect(button).toHaveProperty('disabled', true)
		expect(button.getAttribute('aria-busy')).toBe('true')
		expect(button.getAttribute('type')).toBe('button')
	})
})

describe('errorText', () => {
	it('has copy for every code, falling back to the server message', () => {
		const codes: ErrorCode[] = [
			'insecure_url', 'invalid_url', 'permission_denied', 'unreachable', 'not_nextcloud', 'unauthorized', 'keepiq_missing',
			'no_active_suite', 'unlock_blocked', 'duplicate', 'limit_reached', 'invalid_master_password', 'offline_no_cache', 'session_revoked',
		]
		for (const code of codes) expect(errorText(code, 'h', 'fallback')).not.toBe('fallback')
		expect(errorText('write_locked', 'h', 'Vault is migrating')).toBe('Vault is migrating')
		expect(errorText('unreachable', 'cloud.example.org', '')).toBe('Could not reach cloud.example.org')
	})
})
