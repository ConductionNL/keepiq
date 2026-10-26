// @vitest-environment happy-dom
import { act, cleanup, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { generate } from '@/src/generator/generate'
import type { PopupState, Result } from '@/src/messages'
import App from './App'
import { account, fakeBackground, fakeClipboard, popupState, sentOf } from './testing'

vi.mock('@/src/generator/generate', async (original) => ({ ...await original<typeof import('@/src/generator/generate')>(), generate: vi.fn() }))

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

	it('generates and copies from the lock screen without unlocking, then goes back', async () => {
		vi.mocked(generate).mockResolvedValue({ ok: true, value: 'Gen3rated' })
		const sent = background(popupState({ screen: 'unlock' }))
		render(<App />)
		await heading()
		const user = userEvent.setup()
		// After `userEvent.setup`, which installs a clipboard of its own.
		const writeText = fakeClipboard()
		await user.click(screen.getByRole('button', { name: 'Generate a password' }))
		expect((await heading()).textContent).toBe('Generator')
		expect(screen.queryByRole('navigation', { name: 'Main' })).toBeNull()
		const copy = screen.getByRole('button', { name: 'Copy password' })
		await waitFor(() => expect((copy as HTMLButtonElement).disabled).toBe(false))
		await user.click(copy)
		expect(writeText).toHaveBeenCalledWith('Gen3rated')
		expect(screen.getByRole('status').textContent).toBe('Password copied')
		expect(sentOf(sent, 'generator.context')[0]?.accountId).toBe('a1')
		expect(sentOf(sent, 'vault.unlock')).toHaveLength(0)
		await user.click(screen.getByRole('button', { name: 'Back' }))
		expect((await heading()).textContent).toBe('Unlock')
	})

	it('moves focus into the lock screen generator, and back to its link on Back', async () => {
		vi.mocked(generate).mockResolvedValue({ ok: true, value: 'Gen3rated' })
		background(popupState({ screen: 'unlock' }))
		render(<App />)
		await heading()
		expect(document.activeElement).toBe(screen.getByLabelText('Master password'))
		const user = userEvent.setup()
		await user.click(screen.getByRole('button', { name: 'Generate a password' }))
		await waitFor(() => expect(document.activeElement?.textContent).toBe('Gen3rated'))
		await user.click(screen.getByRole('button', { name: 'Back' }))
		expect((await heading()).textContent).toBe('Unlock')
		expect(document.activeElement).toBe(screen.getByRole('button', { name: 'Generate a password' }))
	})

	it('offers no history from the lock screen generator and never lists it, while still recording a copy', async () => {
		vi.mocked(generate).mockResolvedValue({ ok: true, value: 'Gen3rated' })
		const sent = background(popupState({ screen: 'unlock' }))
		render(<App />)
		await heading()
		const user = userEvent.setup()
		fakeClipboard()
		await user.click(screen.getByRole('button', { name: 'Generate a password' }))
		const copy = screen.getByRole('button', { name: 'Copy password' })
		await waitFor(() => expect((copy as HTMLButtonElement).disabled).toBe(false))
		await user.click(copy)
		await waitFor(() => expect(sentOf(sent, 'generator.history.add')).toHaveLength(1))
		expect(screen.queryByRole('button', { name: 'Generator history' })).toBeNull()
		expect(sentOf(sent, 'generator.history.list')).toEqual([])
	})

	it('leaves no history on screen when the vault locks while it is open', async () => {
		const listeners: Array<(message: unknown) => void> = []
		vi.spyOn(browser.runtime.onMessage, 'addListener').mockImplementation((listener) => void listeners.push(listener as never))
		fakeBackground({ lastTab: 'generator', generator: { history: [{ value: 'S3cretValue', kind: 'password', createdAt: new Date().toISOString() }] } })
		render(<App />)
		await userEvent.setup().click(await screen.findByRole('button', { name: 'Generator history' }))
		// Rendered a span per character, so it is matched on the page's text.
		await waitFor(() => expect(document.body.textContent).toContain('S3cretValue'))
		background(popupState({ screen: 'unlock' }))
		act(() => listeners.forEach((listener) => listener({ kind: 'vault.locked' })))
		await waitFor(async () => expect((await heading()).textContent).toBe('Unlock'))
		expect(document.body.textContent).not.toContain('S3cretValue')
		expect(screen.queryByRole('button', { name: 'Generator history' })).toBeNull()
	})

	it('closes the lock screen generator when another window unlocks', async () => {
		vi.mocked(generate).mockResolvedValue({ ok: true, value: 'Gen3rated' })
		const listeners: Array<(message: unknown) => void> = []
		vi.spyOn(browser.runtime.onMessage, 'addListener').mockImplementation((listener) => void listeners.push(listener as never))
		background(popupState({ screen: 'unlock' }))
		render(<App />)
		await heading()
		await userEvent.setup().click(screen.getByRole('button', { name: 'Generate a password' }))
		expect((await heading()).textContent).toBe('Generator')
		background(popupState({ screen: 'unlocked', active: account({ status: 'unlocked' }) }))
		act(() => listeners.forEach((listener) => listener({ kind: 'vault.locked' })))
		await waitFor(async () => expect((await heading()).textContent).toBe('Vault'))
		expect(screen.queryByRole('button', { name: 'Use this password' })).toBeNull()
	})

	it('offers no generator link on the screens other than unlock', async () => {
		background(popupState({ screen: 'reauthenticate', active: account({ status: 'logged_out' }) }))
		render(<App />)
		await heading()
		expect(screen.queryByRole('button', { name: 'Generate a password' })).toBeNull()
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
