// @vitest-environment happy-dom
import { cleanup, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fakeBrowser } from 'wxt/testing/fake-browser'
import { InvalidMasterPassword } from '@/src/crypto'
import type { PasskeySupport } from '@/src/vault/passkey-support'
import { enrolPasskey, unlockWithPasskey } from '@/src/vault/passkey-unlock'
import { account, popupState } from '../testing'
import { VaultPasskeyWindow } from './VaultPasskeyWindow'

let support: PasskeySupport = 'supported'
vi.mock('@/src/vault/passkey-support', () => ({ passkeySupport: async () => support }))
vi.mock('@/src/vault/passkey-unlock', async (original) => ({ ...await original<object>(), enrolPasskey: vi.fn(), unlockWithPasskey: vi.fn() }))

let sent: Array<{ kind: string }> = []
const unlockReply: unknown = { ok: true, state: popupState({ screen: 'unlocked', accounts: [account()] }) }

beforeEach(() => {
	fakeBrowser.reset()
	sent = []
	support = 'supported'
	vi.spyOn(browser.runtime, 'sendMessage').mockImplementation((async (message: { kind: string }) => {
		sent.push(message)
		return message.kind === 'vault.unlock' ? unlockReply : { ok: true, state: popupState({ screen: 'unlock', accounts: [account()] }) }
	}) as never)
	vi.spyOn(window, 'close').mockImplementation(() => {})
})
afterEach(() => {
	cleanup()
	vi.mocked(enrolPasskey).mockReset()
	vi.mocked(unlockWithPasskey).mockReset()
})

describe('unlock', () => {
	it('hands the released key to the worker and closes', async () => {
		const raw = new Uint8Array(32).fill(5)
		vi.mocked(unlockWithPasskey).mockResolvedValue(raw)
		render(<VaultPasskeyWindow accountId="a1" mode="unlock" />)
		await userEvent.setup().click(await screen.findByRole('button', { name: 'Unlock with fingerprint or face' }))
		await waitFor(() => expect(window.close).toHaveBeenCalled())
		expect(sent.find((m) => m.kind === 'vault.unlock')).toEqual({ kind: 'vault.unlock', accountId: 'a1', method: { type: 'unlockKey', unlockKey: btoa(String.fromCharCode(...new Uint8Array(32).fill(5))) } })
		expect(raw.every((byte) => byte === 0)).toBe(true)
	})

	it('offers the master password when no passkey is offered or the ceremony fails', async () => {
		vi.mocked(unlockWithPasskey).mockResolvedValueOnce(null).mockRejectedValueOnce(new DOMException('cancelled', 'NotAllowedError'))
		render(<VaultPasskeyWindow accountId="a1" mode="unlock" />)
		const user = userEvent.setup()
		for (let i = 0; i < 2; i++) {
			await user.click(await screen.findByRole('button', { name: 'Unlock with fingerprint or face' }))
			expect((await screen.findByRole('alert')).textContent).toBe('The passkey could not unlock your vault. Use your master password.')
		}
		expect(sent.some((m) => m.kind === 'vault.unlock')).toBe(false)
		expect(screen.getByRole('button', { name: 'Use master password' })).toBeTruthy()
	})

	it('runs nothing in a browser that cannot', async () => {
		support = 'browser_too_old'
		render(<VaultPasskeyWindow accountId="a1" mode="unlock" />)
		expect(await screen.findByText('Fingerprint or face unlock needs Chrome or Edge 122, or Firefox 150, or later. Use your master password.')).toBeTruthy()
		expect(screen.queryByRole('button', { name: 'Unlock with fingerprint or face' })).toBeNull()
	})
})

describe('enrolment', () => {
	it('enrols with the master password typed here, then says so', async () => {
		vi.mocked(enrolPasskey).mockResolvedValue()
		render(<VaultPasskeyWindow accountId="a1" mode="enrol" />)
		const user = userEvent.setup()
		await user.type(await screen.findByLabelText('Master password'), 'secret')
		await user.click(screen.getByRole('button', { name: 'Set up' }))
		expect(await screen.findByText('Fingerprint or face unlock is set up for this browser.')).toBeTruthy()
		expect(vi.mocked(enrolPasskey).mock.calls[0]!.slice(1)).toEqual(['secret', 'Keepiq browser extension'])
	})

	it('names a wrong master password', async () => {
		vi.mocked(enrolPasskey).mockRejectedValue(new InvalidMasterPassword())
		render(<VaultPasskeyWindow accountId="a1" mode="enrol" />)
		const user = userEvent.setup()
		await user.type(await screen.findByLabelText('Master password'), 'wrong')
		await user.click(screen.getByRole('button', { name: 'Set up' }))
		expect((await screen.findByRole('alert')).textContent).toBe('Invalid master password')
	})
})
