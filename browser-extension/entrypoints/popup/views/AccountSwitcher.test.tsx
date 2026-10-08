// @vitest-environment happy-dom
import { cleanup, render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { account, dispatchReturning, popupState } from '../testing'
import { AccountSwitcher } from './AccountSwitcher'

afterEach(cleanup)

const a = account({ id: 'a', displayName: 'Alice', status: 'unlocked', active: true })
const b = account({ id: 'b', displayName: 'Bob', status: 'locked', active: false })
const c = account({ id: 'c', displayName: 'Carol', status: 'logged_out', active: false })

function renderSwitcher(state = popupState({ accounts: [a, b, c], active: a }), dispatch = dispatchReturning()) {
	const onClose = vi.fn()
	const onAddAccount = vi.fn()
	render(<AccountSwitcher state={state} dispatch={dispatch} onClose={onClose} onAddAccount={onAddAccount} />)
	return { dispatch, onClose, onAddAccount }
}

const row = (name: string) => screen.getByText(name).closest('li')!

describe('AccountSwitcher', () => {
	it('labels each account and offers Lock only when unlocked', () => {
		renderSwitcher()
		expect(within(row('Alice')).getByText('Unlocked')).toBeTruthy()
		expect(within(row('Bob')).getByText('Locked')).toBeTruthy()
		expect(within(row('Carol')).getByText('Logged out')).toBeTruthy()
		expect(within(row('Alice')).queryByRole('button', { name: 'Lock' })).toBeTruthy()
		expect(within(row('Bob')).queryByRole('button', { name: 'Lock' })).toBeNull()
		for (const name of ['Alice', 'Bob', 'Carol']) expect(within(row(name)).getByRole('button', { name: 'Log out' })).toBeTruthy()
	})

	it('marks the active account', () => {
		renderSwitcher()
		expect(within(row('Alice')).getByText('(active)')).toBeTruthy()
		expect(within(row('Bob')).queryByText('(active)')).toBeNull()
	})

	it('switches and closes', async () => {
		const { dispatch, onClose } = renderSwitcher()
		await userEvent.setup().click(screen.getByText('Bob'))
		expect(dispatch).toHaveBeenCalledWith({ kind: 'accounts.switch', accountId: 'b' })
		expect(onClose).toHaveBeenCalled()
	})

	it('disables Add account at the limit', () => {
		renderSwitcher(popupState({ accounts: [a], active: a, canAddAccount: false }))
		expect(screen.getByRole('button', { name: 'Add account' })).toHaveProperty('disabled', true)
		expect(screen.getByText('Maximum of 5 accounts reached')).toBeTruthy()
	})

	it('locks all', async () => {
		const { dispatch } = renderSwitcher()
		await userEvent.setup().click(screen.getByRole('button', { name: 'Lock all' }))
		expect(dispatch).toHaveBeenCalledWith({ kind: 'vault.lockAll' })
	})

	it('asks before logging out of every account', async () => {
		const { dispatch } = renderSwitcher()
		const user = userEvent.setup()
		await user.click(screen.getByRole('button', { name: 'Log out all' }))
		expect(dispatch).not.toHaveBeenCalled()
		await user.click(within(screen.getByRole('group')).getByRole('button', { name: 'Cancel' }))
		await user.click(screen.getByRole('button', { name: 'Log out all' }))
		await user.click(within(screen.getByRole('group')).getByRole('button', { name: 'Log out all' }))
		expect(dispatch).toHaveBeenCalledExactlyOnceWith({ kind: 'accounts.removeAll' })
	})
})
