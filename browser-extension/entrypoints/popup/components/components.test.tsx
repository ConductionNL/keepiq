// @vitest-environment happy-dom
import { cleanup, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { ErrorCode } from '@/src/messages'
import { errorText } from '../errors'
import { Avatar, initials } from './Avatar'
import { Button } from './Button'
import { Header } from './Header'
import { MaskedField } from './MaskedField'
import { TabBar } from './TabBar'
import { Toast } from './Toast'
import { ShellContext } from '../shell-context'
import { account, fakeClipboard } from '../testing'

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

describe('Header', () => {
	it('shows the pop-out button when asked', async () => {
		const onPopout = vi.fn()
		render(<Header title="Vault" active={account()} onAvatarClick={() => {}} onPopout={onPopout} />)
		await userEvent.setup().click(screen.getByRole('button', { name: 'Pop out' }))
		expect(onPopout).toHaveBeenCalled()
	})

	it('leaves out the pop-out button when not given', () => {
		render(<Header title="Vault" active={account()} />)
		expect(screen.queryByRole('button', { name: 'Pop out' })).toBeNull()
	})
})

describe('TabBar', () => {
	it('marks the active tab and reports a choice', async () => {
		const onSelect = vi.fn()
		render(<TabBar active="send" onSelect={onSelect} />)
		expect(screen.getByRole('button', { name: 'Send' }).getAttribute('aria-current')).toBe('page')
		expect(screen.getByRole('button', { name: 'Vault' }).getAttribute('aria-current')).toBeNull()
		await userEvent.setup().click(screen.getByRole('button', { name: 'Generator' }))
		expect(onSelect).toHaveBeenCalledWith('generator')
	})
})

describe('Toast', () => {
	it('keeps its live region mounted while empty', () => {
		const { rerender } = render(<Toast message={null} />)
		expect(screen.getByRole('status').textContent).toBe('')
		rerender(<Toast message="Password copied" />)
		expect(screen.getByRole('status').textContent).toBe('Password copied')
	})
})

describe('MaskedField', () => {
	it('keeps a masked value out of the DOM until revealed, and copies it', async () => {
		const user = userEvent.setup()
		const writeText = fakeClipboard()
		const toast = vi.fn()
		vi.spyOn(browser.runtime, 'sendMessage').mockResolvedValue(null as never)
		render(<ShellContext.Provider value={{ refreshState: () => {}, toast, launch: () => {} }}><MaskedField label="CVV" value="123" masked /></ShellContext.Provider>)
		expect(screen.queryByText('123')).toBeNull()
		await user.click(screen.getByRole('button', { name: 'Show CVV' }))
		expect(screen.getByText('123')).toBeTruthy()
		expect(screen.getByRole('button', { name: 'Hide CVV' }).getAttribute('aria-pressed')).toBe('true')
		await user.click(screen.getByRole('button', { name: 'Copy CVV' }))
		expect(writeText).toHaveBeenCalledWith('123')
		expect(toast).toHaveBeenCalledWith('CVV copied')
	})

	it('shows a plain value with only copy', () => {
		render(<MaskedField label="Username" value="alice" />)
		expect(screen.getByText('alice')).toBeTruthy()
		expect(screen.queryByRole('button', { name: /^Show/ })).toBeNull()
		expect(screen.getByRole('button', { name: 'Copy username' })).toBeTruthy()
	})
})
