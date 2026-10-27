// @vitest-environment happy-dom
import { cleanup, render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { setLocale } from '@/src/testing/i18n'
import { account, dispatchReturning } from '../testing'
import { LogInAgain } from './LogInAgain'
import { Unlock } from './Unlock'

afterEach(cleanup)

describe('Unlock in a Dutch browser', () => {
	it('is in Dutch, notice and error included', async () => {
		setLocale('nl')
		render(<Unlock account={account()} notice="key_changed" dispatch={dispatchReturning({ ok: false, code: 'invalid_master_password' })} />)
		expect(screen.getByRole('status').textContent).toBe('Je kluissleutel is gewijzigd in Keepiq. Ontgrendel met je huidige hoofdwachtwoord.')
		expect(screen.getByRole('button', { name: 'Hoofdwachtwoord tonen' })).toBeTruthy()
		expect(screen.getByRole('button', { name: 'Uitloggen' })).toBeTruthy()
		await userEvent.setup().type(screen.getByLabelText('Hoofdwachtwoord'), 'nope{Enter}')
		expect(screen.getByRole('alert').textContent).toBe('Ongeldig hoofdwachtwoord')
	})
})

describe('the lock screen generator link', () => {
	it('opens the generator without unlocking', async () => {
		const dispatch = dispatchReturning()
		const onGenerate = vi.fn()
		render(<Unlock account={account()} dispatch={dispatch} onGenerate={onGenerate} />)
		await userEvent.setup().click(screen.getByRole('button', { name: 'Generate a password' }))
		expect(onGenerate).toHaveBeenCalledOnce()
		expect(dispatch).not.toHaveBeenCalled()
	})

	it('is not shown without a place to open it', () => {
		render(<Unlock account={account()} dispatch={dispatchReturning()} />)
		expect(screen.queryByRole('button', { name: 'Generate a password' })).toBeNull()
	})
})

describe('Unlock', () => {
	it('shows who, a focused masked field, Unlock and Log out, and no Lock', () => {
		render(<Unlock account={account()} dispatch={dispatchReturning()} />)
		expect(screen.getByText('Alice Doe')).toBeTruthy()
		expect(screen.getByText('cloud.example.org')).toBeTruthy()
		const field = screen.getByLabelText<HTMLInputElement>('Master password')
		expect(field.type).toBe('password')
		expect(document.activeElement).toBe(field)
		expect(screen.getByRole('button', { name: 'Unlock' })).toBeTruthy()
		expect(screen.getByRole('button', { name: 'Log out' })).toBeTruthy()
		expect(screen.queryByRole('button', { name: 'Lock' })).toBeNull()
	})

	it('toggles visibility without clearing the value', async () => {
		render(<Unlock account={account()} dispatch={dispatchReturning()} />)
		const user = userEvent.setup()
		const field = screen.getByLabelText<HTMLInputElement>('Master password')
		await user.type(field, 'hunter2')
		await user.click(screen.getByRole('button', { name: 'Show master password' }))
		expect(field.type).toBe('text')
		expect(field.value).toBe('hunter2')
		await user.click(screen.getByRole('button', { name: 'Hide master password' }))
		expect(field.type).toBe('password')
	})

	it('sends the master password as the unlock method', async () => {
		const dispatch = dispatchReturning()
		render(<Unlock account={account()} dispatch={dispatch} />)
		const user = userEvent.setup()
		await user.type(screen.getByLabelText('Master password'), 'hunter2{Enter}')
		expect(dispatch).toHaveBeenCalledWith({ kind: 'vault.unlock', accountId: 'a1', method: { type: 'masterPassword', masterPassword: 'hunter2' } })
	})

	it('clears and refocuses the field on a wrong password', async () => {
		render(<Unlock account={account()} dispatch={dispatchReturning({ ok: false, code: 'invalid_master_password' })} />)
		const user = userEvent.setup()
		const field = screen.getByLabelText<HTMLInputElement>('Master password')
		await user.type(field, 'wrong{Enter}')
		expect(screen.getByRole('alert').textContent).toBe('Invalid master password')
		expect(field.value).toBe('')
		expect(document.activeElement).toBe(field)
	})

	it('Log out asks first, then removes the account', async () => {
		const dispatch = dispatchReturning()
		render(<Unlock account={account()} dispatch={dispatch} />)
		const user = userEvent.setup()
		await user.click(screen.getByRole('button', { name: 'Log out' }))
		expect(dispatch).not.toHaveBeenCalled()
		const confirm = screen.getByRole('group', { name: 'Log out of Alice Doe on cloud.example.org?' })
		await user.click(within(confirm).getByRole('button', { name: 'Log out' }))
		expect(dispatch).toHaveBeenCalledExactlyOnceWith({ kind: 'accounts.remove', accountId: 'a1' })
	})

	it('Cancel keeps the account and returns focus to Log out', async () => {
		const dispatch = dispatchReturning()
		render(<Unlock account={account()} dispatch={dispatch} />)
		const user = userEvent.setup()
		await user.click(screen.getByRole('button', { name: 'Log out' }))
		expect(document.activeElement).toBe(screen.getByRole('button', { name: 'Cancel' }))
		await user.click(screen.getByRole('button', { name: 'Cancel' }))
		expect(screen.queryByRole('group')).toBeNull()
		expect(document.activeElement).toBe(screen.getByRole('button', { name: 'Log out' }))
		expect(dispatch).not.toHaveBeenCalled()
	})
})

describe('Unlock with a PIN', () => {
	const withPin = () => account({ pin: true })

	it('offers the PIN first and sends it as the unlock method', async () => {
		const dispatch = dispatchReturning()
		render(<Unlock account={withPin()} dispatch={dispatch} />)
		const field = screen.getByLabelText<HTMLInputElement>('PIN')
		expect(field.type).toBe('password')
		expect(document.activeElement).toBe(field)
		expect(screen.queryByLabelText('Master password')).toBeNull()
		await userEvent.setup().type(field, '2468{Enter}')
		expect(dispatch).toHaveBeenCalledWith({ kind: 'vault.unlock', accountId: 'a1', method: { type: 'pin', pin: '2468' } })
	})

	it('switches to the master password on request', async () => {
		render(<Unlock account={withPin()} dispatch={dispatchReturning()} />)
		await userEvent.setup().click(screen.getByRole('button', { name: 'Use master password' }))
		expect(document.activeElement).toBe(screen.getByLabelText('Master password'))
		expect(screen.queryByLabelText('PIN')).toBeNull()
	})

	it('drops a wrong PIN\'s error when switching to the master password', async () => {
		render(<Unlock account={withPin()} dispatch={dispatchReturning({ ok: false, code: 'invalid_pin', attemptsLeft: 4 })} />)
		const user = userEvent.setup()
		await user.type(screen.getByLabelText('PIN'), '0000{Enter}')
		await user.click(screen.getByRole('button', { name: 'Use master password' }))
		expect(screen.queryByRole('alert')).toBeNull()
	})

	it('says how many attempts are left after a wrong PIN', async () => {
		render(<Unlock account={withPin()} dispatch={dispatchReturning({ ok: false, code: 'invalid_pin', attemptsLeft: 4 })} />)
		const field = screen.getByLabelText<HTMLInputElement>('PIN')
		await userEvent.setup().type(field, '0000{Enter}')
		expect(screen.getByRole('alert').textContent).toBe('Wrong PIN, 4 attempts left')
		expect(field.value).toBe('')
		expect(document.activeElement).toBe(field)
	})

	it.each([
		['pin_forgotten', 'PIN forgotten after too many attempts'],
		['pin_key_changed', 'Your vault key changed. Unlock with your master password.'],
	] as const)('falls back to the master password after %s', async (code, text) => {
		render(<Unlock account={withPin()} dispatch={dispatchReturning({ ok: false, code })} />)
		await userEvent.setup().type(screen.getByLabelText('PIN'), '0000{Enter}')
		expect(screen.getByRole('alert').textContent).toBe(text)
		expect(document.activeElement).toBe(screen.getByLabelText('Master password'))
		expect(screen.queryByRole('button', { name: 'Use master password' })).toBeNull()
	})

	it('asks for the master password once after a restart, saying why', () => {
		render(<Unlock account={account({ pinAfterRestart: true })} dispatch={dispatchReturning()} />)
		const field = screen.getByLabelText('Master password')
		expect(document.activeElement).toBe(field)
		expect(document.getElementById(field.getAttribute('aria-describedby')!)!.textContent).toBe('After a browser restart you enter your master password once; then your PIN works again.')
		expect(screen.queryByLabelText('PIN')).toBeNull()
		expect(screen.queryByRole('button', { name: 'Use master password' })).toBeNull()
	})

	it('offers no PIN without one', () => {
		render(<Unlock account={account()} dispatch={dispatchReturning()} />)
		expect(screen.queryByLabelText('PIN')).toBeNull()
		expect(screen.queryByRole('button', { name: 'Use master password' })).toBeNull()
		expect(screen.getByLabelText('Master password').getAttribute('aria-describedby')).toBeNull()
	})
})

describe('LogInAgain', () => {
	it('shows the revoked notice and re-authenticates with one app password field', async () => {
		const dispatch = dispatchReturning()
		render(<LogInAgain account={account({ status: 'logged_out' })} notice="session_revoked" dispatch={dispatch} />)
		expect(screen.getByRole('status').textContent).toBe('Session revoked, please log in again')
		expect(screen.queryByLabelText('Server URL')).toBeNull()
		await userEvent.setup().type(screen.getByLabelText('App password'), 'new{Enter}')
		expect(dispatch).toHaveBeenCalledWith({ kind: 'accounts.reauthenticate', accountId: 'a1', appPassword: 'new' })
	})
})
