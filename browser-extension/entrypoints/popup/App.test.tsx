// @vitest-environment happy-dom
import { cleanup, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { PopupState, Result } from '@/src/messages'
import App from './App'
import { account, fakeBackground, popupState } from './testing'

afterEach(cleanup)

function background(state: PopupState) {
	return fakeBackground({ state })
}

const heading = () => screen.findByRole('heading', { level: 1 })

describe('App', () => {
	it('asks the background for the state on open', async () => {
		const sendMessage = background(popupState())
		render(<App />)
		await heading()
		expect(sendMessage).toHaveBeenCalledWith({ kind: 'vault.status' })
	})

	it.each([
		[popupState({ screen: 'add_account', active: null }), 'Add account'],
		[popupState({ screen: 'reauthenticate', active: account({ status: 'logged_out' }) }), 'Log in again'],
		[popupState({ screen: 'unlock' }), 'Unlock'],
		[popupState({ screen: 'unlocked', active: account({ status: 'unlocked' }) }), 'Vault'],
	])('renders the screen the background picked: %#', async (state, title) => {
		background(state)
		render(<App />)
		expect(await screen.findByRole('heading', { level: 1, name: title })).toBeTruthy()
	})

	it('opens the switcher from the avatar and adds an account from it', async () => {
		background(popupState())
		render(<App />)
		await heading()
		const user = userEvent.setup()
		await user.click(screen.getByRole('button', { name: 'Accounts, signed in as Alice Doe' }))
		expect((await heading()).textContent).toBe('Accounts')
		await user.click(screen.getByRole('button', { name: 'Add account' }))
		expect((await heading()).textContent).toBe('Add account')
		await user.click(screen.getByRole('button', { name: 'Back' }))
		expect((await heading()).textContent).toBe('Accounts')
		await user.click(screen.getByRole('button', { name: 'Back' }))
		expect((await heading()).textContent).toBe('Unlock')
	})

	it('shows Loading until the background answers', async () => {
		let answer!: (result: Result) => void
		vi.spyOn(browser.runtime, 'sendMessage').mockReturnValue(new Promise((resolve) => (answer = resolve)) as never)
		render(<App />)
		expect(screen.queryByRole('status')).toBeNull()
		expect(screen.queryByRole('heading', { level: 1 })).toBeNull()
		expect((await screen.findByRole('status', {}, { timeout: 1000 })).textContent).toBe('Loading…')
		answer({ ok: true, state: popupState() })
		expect((await screen.findByRole('heading', { name: 'Unlock' }))).toBeTruthy()
	})

	it.each([
		['rejects', () => vi.spyOn(browser.runtime, 'sendMessage').mockRejectedValue(new Error('gone'))],
		['answers undefined', () => vi.spyOn(browser.runtime, 'sendMessage').mockResolvedValue(undefined as never)],
		['is a stale scaffold', () => vi.spyOn(browser.runtime, 'sendMessage').mockResolvedValue({ enabled: true, activeTabs: 0 } as never)],
	])('never renders an empty popup when the background %s', async (_, arrange) => {
		vi.spyOn(console, 'error').mockImplementation(() => {})
		arrange()
		render(<App />)
		expect((await screen.findByRole('alert')).textContent).toMatch(/not responding/)
		expect(screen.getByRole('heading', { level: 1 }).textContent).toBe('Keepiq')
	})
})
