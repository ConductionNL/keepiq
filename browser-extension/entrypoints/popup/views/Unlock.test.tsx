// @vitest-environment happy-dom
import { cleanup, render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it } from 'vitest'
import { account, dispatchReturning } from '../testing'
import { LogInAgain } from './LogInAgain'
import { Unlock } from './Unlock'

afterEach(cleanup)

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
		render(<Unlock account={account()} dispatch={dispatchReturning({ ok: false, code: 'invalid_master_password', message: 'Invalid master password' })} />)
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

describe('LogInAgain', () => {
	it('shows the revoked notice and re-authenticates with one app password field', async () => {
		const dispatch = dispatchReturning()
		render(<LogInAgain account={account({ status: 'logged_out' })} notice="Session revoked, please log in again" dispatch={dispatch} />)
		expect(screen.getByRole('status').textContent).toBe('Session revoked, please log in again')
		expect(screen.queryByLabelText('Server URL')).toBeNull()
		await userEvent.setup().type(screen.getByLabelText('App password'), 'new{Enter}')
		expect(dispatch).toHaveBeenCalledWith({ kind: 'accounts.reauthenticate', accountId: 'a1', appPassword: 'new' })
	})
})
