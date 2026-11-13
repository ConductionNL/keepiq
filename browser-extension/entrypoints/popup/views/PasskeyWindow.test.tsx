// @vitest-environment happy-dom
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { act, cleanup, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { GUARD_MS } from '@/src/autofill/visibility'
import type { AccountSummary, PasskeyRow, PasskeySaveTarget, PasskeyView } from '@/src/messages'
import { setLocale } from '@/src/testing/i18n'
import App from '../App'
import { account, fakeBackground, popupState, sentOf } from '../testing'
import { PasskeyWindow } from './PasskeyWindow'

afterEach(() => {
	cleanup()
	vi.useRealTimers()
})

function passkeyView(overrides: Partial<PasskeyView> = {}): PasskeyView {
	return {
		kind: 'get', conditional: false, rpId: 'example.test', host: 'example.test', rpName: 'Example Corp', userName: 'alice', locked: false, verify: false,
		rows: [], targets: [], folders: [], alreadySaved: false, failure: null, pending: null, ...overrides,
	}
}

const row = (overrides: Partial<PasskeyRow> = {}): PasskeyRow => ({ itemId: 'i1', credentialId: 'cred-1', name: 'Example', userName: 'alice', ...overrides })
const target = (overrides: Partial<PasskeySaveTarget> = {}): PasskeySaveTarget => ({ itemId: 'i1', name: 'Example', username: 'alice@example.test', replace: null, ...overrides })

const wait = (ms = GUARD_MS) => vi.setSystemTime(Date.now() + ms)

/** The window ignores activations for `GUARD_MS` after a step appears; `user` waits that out before each action, `hasty` does not. */
function settled(user: ReturnType<typeof userEvent.setup>): typeof user {
	return new Proxy(user, {
		get(target, property, receiver) {
			const value: unknown = Reflect.get(target, property, receiver)
			if (typeof value !== 'function') return value
			return (...args: unknown[]) => {
				wait()
				return (value as (...a: unknown[]) => unknown).apply(target, args)
			}
		},
	})
}

interface Setup {
	replies?: Record<string, unknown>
	unanswered?: string[]
	account?: Partial<AccountSummary>
}

function renderWindow(view: PasskeyView, { replies = {}, unanswered, account: accountOverrides }: Setup = {}) {
	let current = view
	const listeners: Array<(message: unknown) => void> = []
	vi.spyOn(browser.runtime.onMessage, 'addListener').mockImplementation((listener) => void listeners.push(listener as never))
	const active = account({ status: view.locked ? 'locked' : 'unlocked', ...accountOverrides })
	const sent = fakeBackground({
		state: popupState({ screen: view.locked ? 'unlock' : 'unlocked', active }),
		unanswered,
		replies: { 'passkey.state': () => ({ ok: true, view: current }), 'passkey.cancel': { ok: true }, 'passkey.fallback': { ok: true }, ...replies },
	})
	const close = vi.spyOn(window, 'close').mockImplementation(() => {})
	vi.useFakeTimers({ toFake: ['Date'] })
	const hasty = userEvent.setup()
	render(<PasskeyWindow ceremonyId="c1" />)
	return {
		sent, close, hasty, user: settled(hasty),
		setView: (next: PasskeyView) => (current = next),
		broadcast: (message: unknown) => act(() => listeners.forEach((listener) => listener(message))),
	}
}

const heading = (name: string) => screen.findByRole('heading', { level: 1, name })
const alertText = async () => (await screen.findByRole('alert')).textContent

describe('sign in', () => {
	it('lists each passkey by login and user name under the request, focuses the first, and picks it', async () => {
		const { sent, close, user } = renderWindow(passkeyView({ rows: [row(), row({ itemId: 'i2', credentialId: 'cred-2', name: 'Work', userName: 'alice.w' })] }), { replies: { 'passkey.pick': { ok: true } } })
		await heading('Sign in with a passkey')
		const list = screen.getByRole('list', { name: 'Use a passkey saved in Keepiq for example.test' })
		expect(within(list).getAllByRole('button').map((button) => button.getAttribute('aria-label'))).toEqual(['Example, alice', 'Work, alice.w'])
		expect(document.activeElement).toBe(screen.getByRole('button', { name: 'Example, alice' }))
		await user.click(screen.getByRole('button', { name: 'Work, alice.w' }))
		expect(sentOf(sent, 'passkey.pick')).toEqual([{ kind: 'passkey.pick', ceremonyId: 'c1', itemId: 'i2', credentialId: 'cred-2' }])
		expect(close).toHaveBeenCalled()
	})

	it('picks the second passkey with Tab and Enter', async () => {
		const { sent, user } = renderWindow(passkeyView({ rows: [row(), row({ itemId: 'i2', credentialId: 'cred-2', name: 'Work' })] }), { replies: { 'passkey.pick': { ok: true } } })
		await heading('Sign in with a passkey')
		await user.tab()
		await user.keyboard('{Enter}')
		expect(sentOf(sent, 'passkey.pick')[0]).toMatchObject({ itemId: 'i2', credentialId: 'cred-2' })
	})

	it('reads "No Keepiq passkey" with the two ways out, and sends nothing until the user acts', async () => {
		const { sent, close } = renderWindow(passkeyView({ rpId: 'nothing.test' }))
		await heading('Sign in with a passkey')
		expect(screen.getByText('No Keepiq passkey for nothing.test')).toBeTruthy()
		expect(screen.getByRole('button', { name: 'Use your device or hardware key' })).toBeTruthy()
		expect(screen.getByRole('button', { name: 'Cancel' })).toBeTruthy()
		await new Promise((resolve) => setTimeout(resolve, 50))
		expect(sent.mock.calls.map(([message]) => message.kind).filter((kind) => kind !== 'passkey.state' && kind !== 'vault.status' && kind !== 'settings.get')).toEqual([])
		expect(close).not.toHaveBeenCalled()
	})

	it('picks at once without asking when verification is not required', async () => {
		const { sent, user } = renderWindow(passkeyView({ rows: [row()] }), { replies: { 'passkey.pick': { ok: true } } })
		await user.click(await screen.findByRole('button', { name: 'Example, alice' }))
		expect(sentOf(sent, 'passkey.verify')).toEqual([])
		expect(sentOf(sent, 'passkey.pick')).toHaveLength(1)
	})

	it('asks "Confirm it\'s you" before a pick when required, then signs', async () => {
		const verified = passkeyView({ rows: [row()], verify: false })
		const { sent, close, user } = renderWindow(passkeyView({ rows: [row()], verify: true }), { replies: { 'passkey.pick': { ok: true }, 'passkey.verify': { ok: true, view: verified } } })
		await user.click(await screen.findByRole('button', { name: 'Example, alice' }))
		await heading('Confirm it\'s you')
		expect(sentOf(sent, 'passkey.pick')).toEqual([])
		expect(screen.getByText('Enter your master password to continue.')).toBeTruthy()
		const field = screen.getByLabelText('Master password')
		expect(document.activeElement).toBe(field)
		await user.type(field, 'hunter2{Enter}')
		expect(sentOf(sent, 'passkey.verify')).toEqual([{ kind: 'passkey.verify', ceremonyId: 'c1', method: { type: 'masterPassword', masterPassword: 'hunter2' } }])
		await waitFor(() => expect(sentOf(sent, 'passkey.pick')).toEqual([{ kind: 'passkey.pick', ceremonyId: 'c1', itemId: 'i1', credentialId: 'cred-1' }]))
		expect(close).toHaveBeenCalled()
		expect(sentOf(sent, 'vault.unlock')).toEqual([])
	})

	it('asks to confirm when the background answers verify_required, and keeps asking on a wrong password', async () => {
		const { sent, user } = renderWindow(passkeyView({ rows: [row()] }), {
			replies: { 'passkey.pick': { ok: false, code: 'verify_required' }, 'passkey.verify': { ok: false, code: 'invalid_master_password' } },
		})
		await user.click(await screen.findByRole('button', { name: 'Example, alice' }))
		await heading('Confirm it\'s you')
		await user.type(screen.getByLabelText('Master password'), 'nope{Enter}')
		expect(await alertText()).toBe('Invalid master password')
		expect(document.activeElement).toBe(screen.getByLabelText('Master password'))
		expect(sentOf(sent, 'passkey.pick')).toHaveLength(1)
	})

	it('shows a failed counter save with Retry, focused, and keeps the window open', async () => {
		const { sent, close, user } = renderWindow(passkeyView({ rows: [row()] }), {
			replies: { 'passkey.pick': { ok: false, code: 'write_failed', error: { code: 'offline' } }, 'passkey.retry': { ok: true } },
		})
		await user.click(await screen.findByRole('button', { name: 'Example, alice' }))
		expect(await alertText()).toContain('Could not reach the server')
		const retry = screen.getByRole('button', { name: 'Retry' })
		expect(document.activeElement).toBe(retry)
		expect(close).not.toHaveBeenCalled()
		await user.click(retry)
		expect(sentOf(sent, 'passkey.retry')).toEqual([{ kind: 'passkey.retry', ceremonyId: 'c1' }])
		expect(close).toHaveBeenCalled()
	})

	it('keeps focus on Retry while it runs and when it fails again', async () => {
		let answer: (reply: unknown) => void = () => {}
		const { sent, user } = renderWindow(passkeyView({ rows: [row()], failure: { code: 'offline' } }), {
			replies: { 'passkey.retry': () => new Promise((resolve) => (answer = resolve)) },
		})
		const retry = await screen.findByRole('button', { name: 'Retry' })
		await user.click(retry)
		expect(retry.hasAttribute('disabled')).toBe(false)
		expect(retry.getAttribute('aria-disabled')).toBe('true')
		expect(document.activeElement).toBe(retry)
		await user.click(retry)
		expect(sentOf(sent, 'passkey.retry')).toHaveLength(1)
		await act(async () => answer({ ok: false, code: 'write_failed', error: { code: 'offline' } }))
		expect(document.activeElement).toBe(retry)
	})

	it('opens on the failed step with Retry focused rather than the first row', async () => {
		renderWindow(passkeyView({ rows: [row()], failure: { code: 'write_locked' } }))
		expect(await alertText()).toContain('Vault is temporarily locked for a key migration, try again later')
		expect(document.activeElement).toBe(screen.getByRole('button', { name: 'Retry' }))
	})

	it('shows a counter save that waits for the share scan without Retry, with the first row focused', async () => {
		const { user } = renderWindow(passkeyView({ rows: [row()] }), {
			replies: { 'passkey.pick': { ok: false, code: 'write_failed', error: { code: 'share_scan_pending' } } },
		})
		await user.click(await screen.findByRole('button', { name: 'Example, alice' }))
		expect(await alertText()).toBe('This login is waiting for a check of its shares that has not finished. Nothing was saved; try again later.')
		expect(screen.queryByRole('button', { name: 'Retry' })).toBeNull()
		expect(document.activeElement).toBe(screen.getByRole('button', { name: 'Example, alice' }))
	})

	it('goes back to the list from "Confirm it\'s you" with Escape or Back, and cancels with Escape from the list', async () => {
		const { sent, user } = renderWindow(passkeyView({ rows: [row()], verify: true }))
		await user.click(await screen.findByRole('button', { name: 'Example, alice' }))
		await heading('Confirm it\'s you')
		await user.keyboard('{Escape}')
		await heading('Sign in with a passkey')
		expect(document.activeElement).toBe(screen.getByRole('button', { name: 'Example, alice' }))
		await user.click(screen.getByRole('button', { name: 'Example, alice' }))
		await user.click(screen.getByRole('button', { name: 'Back' }))
		await heading('Sign in with a passkey')
		expect(sentOf(sent, 'passkey.cancel')).toEqual([])
		expect(sentOf(sent, 'passkey.pick')).toEqual([])
		await user.keyboard('{Escape}')
		expect(sentOf(sent, 'passkey.cancel')).toHaveLength(1)
	})

	it('keeps the rows focusable while a pick runs, ignores another, and keeps focus after a stale answer', async () => {
		let answer: (reply: unknown) => void = () => {}
		const { sent, user } = renderWindow(passkeyView({ rows: [row(), row({ itemId: 'i2', credentialId: 'cred-2', name: 'Work' })] }), {
			replies: { 'passkey.pick': () => new Promise((resolve) => (answer = resolve)) },
		})
		await user.click(await screen.findByRole('button', { name: 'Example, alice' }))
		const other = screen.getByRole('button', { name: 'Work, alice' })
		expect(other.hasAttribute('disabled')).toBe(false)
		expect(other.getAttribute('aria-disabled')).toBe('true')
		await user.click(other)
		expect(sentOf(sent, 'passkey.pick')).toHaveLength(1)
		await act(async () => answer({ ok: false, code: 'stale' }))
		expect(await alertText()).toBe('This passkey is no longer in your vault. Choose another one.')
		expect(document.activeElement).toBe(other)
		expect(other.getAttribute('aria-disabled')).toBe('false')
	})

	it('says when the picked passkey is gone and shows the list again', async () => {
		const { sent, user, setView } = renderWindow(passkeyView({ rows: [row()] }), { replies: { 'passkey.pick': { ok: false, code: 'stale' } } })
		const picked = await screen.findByRole('button', { name: 'Example, alice' })
		setView(passkeyView())
		await user.click(picked)
		expect(await alertText()).toBe('This passkey is no longer in your vault. Choose another one.')
		await screen.findByText('No Keepiq passkey for example.test')
		expect(sentOf(sent, 'passkey.state').length).toBeGreaterThan(1)
	})
})

describe('a pick from the inline list that waits on "Confirm it\'s you"', () => {
	const work = row({ itemId: 'i2', credentialId: 'cred-2', name: 'Work', userName: 'alice.w' })
	const heldView = () => passkeyView({ rows: [row(), work], verify: true, pending: work })

	it('opens on that step for the picked passkey, and closes once the background signed it', async () => {
		const { sent, close, user } = renderWindow(heldView(), { replies: { 'passkey.verify': { ok: true } } })
		await heading('Confirm it\'s you')
		expect(screen.getByText('Work')).toBeTruthy()
		expect(screen.getByText('alice.w')).toBeTruthy()
		expect(screen.queryByText('Example')).toBeNull()
		expect(screen.queryByRole('list')).toBeNull()
		const field = screen.getByLabelText('Master password')
		expect(document.activeElement).toBe(field)
		await user.type(field, 'hunter2{Enter}')
		await waitFor(() => expect(close).toHaveBeenCalled())
		expect(sentOf(sent, 'passkey.verify')).toEqual([{ kind: 'passkey.verify', ceremonyId: 'c1', method: { type: 'masterPassword', masterPassword: 'hunter2' } }])
		expect(sentOf(sent, 'passkey.pick')).toEqual([])
	})

	it('shows the list and says so when the passkey is gone by then', async () => {
		const { sent, user, setView } = renderWindow(heldView(), { replies: { 'passkey.verify': { ok: false, code: 'stale' } } })
		const field = await screen.findByLabelText('Master password')
		setView(passkeyView({ rows: [row()] }))
		await user.type(field, 'hunter2{Enter}')
		expect(await alertText()).toBe('This passkey is no longer in your vault. Choose another one.')
		await heading('Sign in with a passkey')
		expect(screen.getByRole('button', { name: 'Example, alice' })).toBeTruthy()
		expect(sentOf(sent, 'passkey.pick')).toEqual([])
	})

	it('shows a failed counter save with Retry', async () => {
		const { close, user, setView } = renderWindow(heldView(), { replies: { 'passkey.verify': { ok: false, code: 'write_failed', error: { code: 'offline' } } } })
		const field = await screen.findByLabelText('Master password')
		setView(passkeyView({ rows: [row(), work], failure: { code: 'offline' } }))
		await user.type(field, 'hunter2{Enter}')
		expect(await alertText()).toContain('Could not reach the server')
		expect(screen.getByRole('button', { name: 'Retry' })).toBeTruthy()
		expect(close).not.toHaveBeenCalled()
	})

	it('goes back to the list with Escape, and a pick there replaces the held one in the background first', async () => {
		let picks = 0
		const { sent, close, user } = renderWindow(heldView(), {
			replies: {
				'passkey.pick': () => (++picks === 1 ? { ok: false, code: 'verify_required' } : { ok: true }),
				'passkey.verify': { ok: true, view: passkeyView({ rows: [row(), work] }) },
			},
		})
		await heading('Confirm it\'s you')
		await user.keyboard('{Escape}')
		await heading('Sign in with a passkey')
		expect(sentOf(sent, 'passkey.cancel')).toEqual([])
		await user.click(screen.getByRole('button', { name: 'Example, alice' }))
		await heading('Confirm it\'s you')
		expect(screen.getByText('Example')).toBeTruthy()
		expect(sentOf(sent, 'passkey.pick')).toEqual([{ kind: 'passkey.pick', ceremonyId: 'c1', itemId: 'i1', credentialId: 'cred-1' }])
		await user.type(screen.getByLabelText('Master password'), 'hunter2{Enter}')
		await waitFor(() => expect(close).toHaveBeenCalled())
		expect(sentOf(sent, 'passkey.pick')).toHaveLength(2)
		expect(sentOf(sent, 'passkey.pick')[1]).toMatchObject({ itemId: 'i1', credentialId: 'cred-1' })
	})

	it('offers no "Use your device or hardware key", since the browser\'s own request already runs', async () => {
		renderWindow({ ...heldView(), conditional: true })
		await heading('Confirm it\'s you')
		expect(screen.queryByRole('button', { name: 'Use your device or hardware key' })).toBeNull()
		expect(screen.getByRole('button', { name: 'Cancel' })).toBeTruthy()
	})

	it('shows no passkey name while locked', async () => {
		renderWindow(passkeyView({ locked: true, rows: [], pending: null }))
		await heading('Unlock')
		expect(document.body.textContent).not.toMatch(/Work|alice/)
	})
})

describe('a locked vault', () => {
	it('opens on the unlock view, shows nothing of the vault, and lists the passkeys after unlocking through passkey.verify', async () => {
		const { sent, user } = renderWindow(passkeyView({ locked: true }), { replies: { 'passkey.verify': { ok: true, view: passkeyView({ rows: [row()] }) } } })
		await heading('Unlock')
		expect(screen.getByText('Unlock Keepiq to use a passkey for example.test')).toBeTruthy()
		expect(screen.queryByRole('list')).toBeNull()
		expect(document.body.textContent).not.toContain('Example Corp')
		expect(screen.getByRole('button', { name: 'Use your device or hardware key' })).toBeTruthy()
		expect(screen.getByRole('button', { name: 'Cancel' })).toBeTruthy()
		const field = screen.getByLabelText('Master password')
		expect(document.activeElement).toBe(field)
		await user.type(field, 'hunter2{Enter}')
		await heading('Sign in with a passkey')
		expect(screen.getByRole('button', { name: 'Example, alice' })).toBeTruthy()
		expect(sentOf(sent, 'passkey.verify')[0]).toEqual({ kind: 'passkey.verify', ceremonyId: 'c1', method: { type: 'masterPassword', masterPassword: 'hunter2' } })
		expect(sentOf(sent, 'vault.unlock')).toEqual([])
	})

	it('does not ask again after unlocking for a request that requires verification', async () => {
		const { sent, user } = renderWindow(passkeyView({ locked: true, verify: true }), {
			replies: { 'passkey.verify': { ok: true, view: passkeyView({ rows: [row()], verify: false }) }, 'passkey.pick': { ok: true } },
		})
		await user.type(await screen.findByLabelText('Master password'), 'hunter2{Enter}')
		await user.click(await screen.findByRole('button', { name: 'Example, alice' }))
		expect(sentOf(sent, 'passkey.verify')).toHaveLength(1)
		expect(sentOf(sent, 'passkey.pick')).toHaveLength(1)
	})

	it('asks for the PIN where set, counts down its tries, and falls back to the master password', async () => {
		let tries = 0
		const { sent, user } = renderWindow(passkeyView({ locked: true, kind: 'create' }), {
			account: { pin: true },
			replies: { 'passkey.verify': () => (++tries === 1 ? { ok: false, code: 'invalid_pin', attemptsLeft: 4 } : { ok: false, code: 'pin_forgotten' }) },
		})
		await heading('Unlock')
		expect(screen.getByText('Unlock Keepiq to save a passkey for example.test')).toBeTruthy()
		await user.type(screen.getByLabelText('PIN'), '123456{Enter}')
		expect(await alertText()).toBe('Wrong PIN, 4 attempts left')
		expect(sentOf(sent, 'passkey.verify')[0]).toMatchObject({ method: { type: 'pin', pin: '123456' } })
		await user.type(screen.getByLabelText('PIN'), '654321{Enter}')
		await waitFor(() => expect(screen.getByLabelText('Master password')).toBeTruthy())
		expect(screen.getByRole('alert').textContent).toBe('PIN forgotten after too many attempts')
	})

	it('falls back to the browser from the unlock view', async () => {
		const { sent, close, user } = renderWindow(passkeyView({ locked: true }))
		await user.click(await screen.findByRole('button', { name: 'Use your device or hardware key' }))
		expect(sentOf(sent, 'passkey.fallback')).toEqual([{ kind: 'passkey.fallback', ceremonyId: 'c1' }])
		expect(close).toHaveBeenCalled()
	})

	it('returns to the unlock view when the vault locks during the ceremony, leaving nothing of the vault on screen', async () => {
		const { user, setView, broadcast } = renderWindow(passkeyView({ rows: [row(), row({ itemId: 'i2', credentialId: 'cred-2', name: 'Work' })] }), {
			replies: { 'passkey.pick': { ok: false, code: 'stale' } },
		})
		await user.click(await screen.findByRole('button', { name: 'Example, alice' }))
		await alertText()
		setView(passkeyView({ locked: true }))
		broadcast({ kind: 'vault.locked' })
		await heading('Unlock')
		expect(screen.queryByRole('alert')).toBeNull()
		expect(screen.queryByRole('list')).toBeNull()
		expect(document.body.textContent).not.toMatch(/alice|Work|no longer/)
	})
})

describe('save', () => {
	const createView = (overrides: Partial<PasskeyView> = {}) => passkeyView({ kind: 'create', folders: [{ id: 'f1', name: 'Work' }, { id: 'f2', name: 'Work/Sites' }], ...overrides })

	it('shows the site, the page\'s user name, the matching logins, and saves to one', async () => {
		const { sent, close, user } = renderWindow(createView({ targets: [target()] }), { replies: { 'passkey.save': { ok: true } } })
		await heading('Save passkey')
		expect(screen.getByText('example.test')).toBeTruthy()
		expect(screen.getByText('alice')).toBeTruthy()
		const list = screen.getByRole('list', { name: 'Save to a login' })
		const login = within(list).getByRole('button', { name: 'Example, alice@example.test' })
		expect(document.activeElement).toBe(login)
		expect(screen.queryByLabelText('Name')).toBeNull()
		expect(screen.queryByRole('button', { name: 'Save as new login' })).toBeNull()
		expect(screen.queryByRole('button', { name: 'Back' })).toBeNull()
		await user.click(login)
		expect(sentOf(sent, 'passkey.save')).toEqual([{ kind: 'passkey.save', ceremonyId: 'c1', target: { itemId: 'i1' } }])
		expect(close).toHaveBeenCalled()
	})

	it('offers New login as a named icon button in the header, after the title', async () => {
		renderWindow(createView({ targets: [target()] }))
		await heading('Save passkey')
		const header = screen.getByRole('banner')
		const button = within(header).getByRole('button', { name: 'New login' })
		expect(button.getAttribute('title')).toBe('New login')
		expect(button.className).toContain('icon-btn')
		expect(button.textContent).toBe('')
		expect(within(header).getAllByRole('button')).toEqual([button])
	})

	it('opens New login with Shift+Tab and Enter, focuses the Name, and goes back with Escape or Back to the button', async () => {
		const { sent, user } = renderWindow(createView({ targets: [target()] }))
		await screen.findByRole('button', { name: 'Example, alice@example.test' })
		await user.tab({ shift: true })
		const open = screen.getByRole('button', { name: 'New login' })
		expect(document.activeElement).toBe(open)
		await user.keyboard('{Enter}')
		expect(screen.getByRole('heading', { level: 2, name: 'New login' })).toBeTruthy()
		expect(screen.getByRole('form', { name: 'New login' })).toBeTruthy()
		expect(document.activeElement).toBe(screen.getByLabelText('Name'))
		expect(screen.queryByRole('list', { name: 'Save to a login' })).toBeNull()
		expect(screen.queryByRole('button', { name: 'New login' })).toBeNull()
		expect(screen.getByText('example.test')).toBeTruthy()
		expect(screen.getByRole('button', { name: 'Use your device or hardware key' })).toBeTruthy()
		expect(screen.getByRole('button', { name: 'Cancel' })).toBeTruthy()

		await user.keyboard('{Escape}')
		expect(screen.getByRole('list', { name: 'Save to a login' })).toBeTruthy()
		expect(document.activeElement).toBe(screen.getByRole('button', { name: 'New login' }))
		await user.keyboard('{Enter}')
		await user.click(screen.getByRole('button', { name: 'Back' }))
		expect(screen.queryByLabelText('Name')).toBeNull()
		expect(document.activeElement).toBe(screen.getByRole('button', { name: 'New login' }))
		expect(sentOf(sent, 'passkey.cancel')).toEqual([])
		expect(sentOf(sent, 'passkey.save')).toEqual([])

		await user.keyboard('{Escape}')
		expect(sentOf(sent, 'passkey.cancel')).toHaveLength(1)
	})

	it('keeps the typed name and folder when going back and opening New login again, and saves them', async () => {
		const { sent, user } = renderWindow(createView({ targets: [target()] }), { replies: { 'passkey.save': { ok: true } } })
		await user.click(await screen.findByRole('button', { name: 'New login' }))
		await user.clear(screen.getByLabelText('Name'))
		await user.type(screen.getByLabelText('Name'), 'Example')
		await user.selectOptions(screen.getByLabelText('Folder'), 'f2')
		await user.click(screen.getByRole('button', { name: 'Back' }))
		await user.click(screen.getByRole('button', { name: 'New login' }))
		expect(screen.getByLabelText<HTMLInputElement>('Name').value).toBe('Example')
		await user.click(screen.getByRole('button', { name: 'Save as new login' }))
		expect(sentOf(sent, 'passkey.save')).toEqual([{ kind: 'passkey.save', ceremonyId: 'c1', target: { name: 'Example', folderId: 'f2' } }])
	})

	it('shows New login at once when no login matches, with no Back or New login button, and Escape cancels', async () => {
		const { sent, user } = renderWindow(createView())
		await heading('Save passkey')
		expect(screen.getByRole('heading', { level: 2, name: 'New login' })).toBeTruthy()
		expect(screen.queryByRole('list', { name: 'Save to a login' })).toBeNull()
		expect(screen.queryByRole('button', { name: 'Back' })).toBeNull()
		expect(screen.queryByRole('button', { name: 'New login' })).toBeNull()
		await user.keyboard('{Escape}')
		expect(sentOf(sent, 'passkey.cancel')).toHaveLength(1)
	})

	it('keeps New login, its focus and its keystrokes when a matching login arrives while typing, adding only Back', async () => {
		const { sent, user, setView, broadcast } = renderWindow(createView(), { replies: { 'passkey.save': { ok: true } } })
		const name = await screen.findByLabelText<HTMLInputElement>('Name')
		await user.type(name, ' Bank')
		setView(createView({ targets: [target()] }))
		broadcast({ kind: 'vault.changed' })
		await screen.findByRole('button', { name: 'Back' })
		expect(screen.queryByRole('list', { name: 'Save to a login' })).toBeNull()
		expect(screen.queryByRole('button', { name: 'New login' })).toBeNull()
		expect(document.activeElement).toBe(name)
		await user.keyboard(' Account{Enter}')
		expect(sentOf(sent, 'passkey.save')).toEqual([{ kind: 'passkey.save', ceremonyId: 'c1', target: { name: 'Example Corp Bank Account', folderId: null } }])
	})

	it('shows a failed save to a login, and its Retry, on the list only, and focuses New login on the way back', async () => {
		const { sent, user } = renderWindow(createView({ targets: [target()] }), {
			replies: { 'passkey.save': { ok: false, code: 'write_failed', error: { code: 'offline' } }, 'passkey.retry': { ok: true } },
		})
		await user.click(await screen.findByRole('button', { name: 'Example, alice@example.test' }))
		await screen.findByRole('button', { name: 'Retry' })
		await user.click(screen.getByRole('button', { name: 'New login' }))
		expect(screen.queryByRole('button', { name: 'Retry' })).toBeNull()
		expect(document.activeElement).toBe(screen.getByLabelText('Name'))
		await user.keyboard('{Escape}')
		expect(document.activeElement).toBe(screen.getByRole('button', { name: 'New login' }))
		await user.click(screen.getByRole('button', { name: 'Retry' }))
		expect(sentOf(sent, 'passkey.retry')).toHaveLength(1)
	})

	it('shows a save to a login that waits for the share scan without Retry, with that login focused', async () => {
		const { user } = renderWindow(createView({ targets: [target()] }), {
			replies: { 'passkey.save': { ok: false, code: 'write_failed', error: { code: 'share_scan_pending' } } },
		})
		await user.click(await screen.findByRole('button', { name: 'Example, alice@example.test' }))
		expect(await alertText()).toBe('This login is waiting for a check of its shares that has not finished. Nothing was saved; try again later.')
		expect(screen.queryByRole('button', { name: 'Retry' })).toBeNull()
		expect(document.activeElement).toBe(screen.getByRole('button', { name: 'Example, alice@example.test' }))
	})

	it('shows a failed save as a new login, and its Retry, on New login only', async () => {
		const { user } = renderWindow(createView({ targets: [target()] }), { replies: { 'passkey.save': { ok: false, code: 'write_failed', error: { code: 'offline' } } } })
		await user.click(await screen.findByRole('button', { name: 'New login' }))
		await user.click(screen.getByRole('button', { name: 'Save as new login' }))
		await screen.findByRole('button', { name: 'Retry' })
		await user.click(screen.getByRole('button', { name: 'Back' }))
		expect(screen.getByRole('list', { name: 'Save to a login' })).toBeTruthy()
		expect(screen.queryByRole('button', { name: 'Retry' })).toBeNull()
	})

	it('keeps a failed save as a new login on New login when a later save to a login is refused as locked before writing', async () => {
		const offline = { code: 'offline' } as const
		const { sent, user, setView } = renderWindow(createView({ targets: [target()] }), {
			replies: {
				'passkey.save': (message: { target: object }) => ('name' in message.target ? { ok: false, code: 'write_failed', error: offline } : { ok: false, code: 'locked' }),
				'passkey.verify': { ok: true, view: createView({ targets: [target()], failure: offline }) },
			},
		})
		await user.click(await screen.findByRole('button', { name: 'New login' }))
		await user.click(screen.getByRole('button', { name: 'Save as new login' }))
		await screen.findByRole('button', { name: 'Retry' })
		await user.click(screen.getByRole('button', { name: 'Back' }))
		setView(createView({ locked: true }))
		await user.click(screen.getByRole('button', { name: 'Example, alice@example.test' }))
		await heading('Unlock')
		await user.type(screen.getByLabelText('Master password'), 'hunter2{Enter}')
		await heading('Save passkey')
		expect(screen.getByRole('list', { name: 'Save to a login' })).toBeTruthy()
		expect(screen.queryByRole('button', { name: 'Retry' })).toBeNull()
		expect(screen.queryByRole('alert')).toBeNull()
		await user.click(screen.getByRole('button', { name: 'New login' }))
		expect(screen.getByRole('button', { name: 'Retry' })).toBeTruthy()
		expect(sentOf(sent, 'passkey.retry')).toEqual([])
		expect(sentOf(sent, 'passkey.save').map((message) => message.target)).toEqual([{ name: 'Example Corp', folderId: null }, { itemId: 'i1' }])
	})

	it('stays on New login while its save runs, with Back disabled and Escape doing nothing', async () => {
		let answer: (reply: unknown) => void = () => {}
		const { sent, user } = renderWindow(createView({ targets: [target()] }), { replies: { 'passkey.save': () => new Promise((resolve) => (answer = resolve)) } })
		await user.click(await screen.findByRole('button', { name: 'New login' }))
		await user.click(screen.getByRole('button', { name: 'Save as new login' }))
		const back = screen.getByRole('button', { name: 'Back' })
		expect(back.hasAttribute('disabled')).toBe(true)
		await user.keyboard('{Escape}')
		expect(screen.getByLabelText('Name')).toBeTruthy()
		expect(sentOf(sent, 'passkey.cancel')).toEqual([])
		await act(async () => answer({ ok: false, code: 'write_failed', error: { code: 'offline' } }))
		expect(await screen.findByRole('button', { name: 'Retry' })).toBeTruthy()
		expect(screen.getByLabelText('Name')).toBeTruthy()
	})

	it('goes back from "Confirm it\'s you" to New login, keeping the typed name', async () => {
		const { sent, user } = renderWindow(createView({ targets: [target()], verify: true }))
		await user.click(await screen.findByRole('button', { name: 'New login' }))
		await user.type(screen.getByLabelText('Name'), ' 2{Enter}')
		await heading('Confirm it\'s you')
		await user.click(screen.getByRole('button', { name: 'Back' }))
		expect(screen.getByLabelText<HTMLInputElement>('Name').value).toBe('Example Corp 2')
		expect(screen.getByRole('button', { name: 'Back' })).toBeTruthy()
		expect(sentOf(sent, 'passkey.save')).toEqual([])
	})

	it('focuses the first login again after "Confirm it\'s you" for a login picked after a New login and back', async () => {
		const { user } = renderWindow(createView({ targets: [target()], verify: true }))
		await user.click(await screen.findByRole('button', { name: 'New login' }))
		await user.keyboard('{Escape}')
		await user.click(screen.getByRole('button', { name: 'Example, alice@example.test' }))
		await heading('Confirm it\'s you')
		await user.keyboard('{Escape}')
		await heading('Save passkey')
		expect(document.activeElement).toBe(screen.getByRole('button', { name: 'Example, alice@example.test' }))
	})

	it('keeps New login from opening while a save to a login runs', async () => {
		const { sent, user } = renderWindow(createView({ targets: [target()] }), { replies: { 'passkey.save': () => new Promise(() => {}) } })
		await user.click(await screen.findByRole('button', { name: 'Example, alice@example.test' }))
		const open = screen.getByRole('button', { name: 'New login' })
		expect(open.getAttribute('aria-disabled')).toBe('true')
		await user.click(open)
		expect(screen.queryByLabelText('Name')).toBeNull()
		expect(sentOf(sent, 'passkey.save')).toHaveLength(1)
	})

	it('saves as a new login with the typed name and the chosen folder', async () => {
		const { sent, user } = renderWindow(createView(), { replies: { 'passkey.save': { ok: true } } })
		const name = await screen.findByLabelText<HTMLInputElement>('Name')
		expect(name.value).toBe('Example Corp')
		// No logins to save to, so the name takes focus.
		expect(document.activeElement).toBe(name)
		await user.clear(name)
		await user.type(name, 'Example')
		const folder = screen.getByLabelText<HTMLSelectElement>('Folder')
		expect([...folder.options].map((option) => option.textContent)).toEqual(['No folder', 'Work', 'Work/Sites'])
		await user.selectOptions(folder, 'f1')
		await user.click(screen.getByRole('button', { name: 'Save as new login' }))
		expect(sentOf(sent, 'passkey.save')).toEqual([{ kind: 'passkey.save', ceremonyId: 'c1', target: { name: 'Example', folderId: 'f1' } }])
	})

	it('prefills the RP ID without an rp.name, saves to no folder by default, and submits with Enter', async () => {
		const { sent, user } = renderWindow(createView({ rpName: '' }), { replies: { 'passkey.save': { ok: true } } })
		const name = await screen.findByLabelText<HTMLInputElement>('Name')
		expect(name.value).toBe('example.test')
		await user.type(name, '{Enter}')
		expect(sentOf(sent, 'passkey.save')[0]?.target).toEqual({ name: 'example.test', folderId: null })
	})

	it('cannot save a new login without a name', async () => {
		const { sent, user } = renderWindow(createView())
		await user.clear(await screen.findByLabelText('Name'))
		const saveNew = screen.getByRole('button', { name: 'Save as new login' })
		expect(saveNew.getAttribute('aria-disabled')).toBe('true')
		await user.click(saveNew)
		expect(sentOf(sent, 'passkey.save')).toEqual([])
	})

	it('keeps focus on "Save as new login" while it runs, ignoring another click', async () => {
		let answer: (reply: unknown) => void = () => {}
		const { sent, user } = renderWindow(createView(), { replies: { 'passkey.save': () => new Promise((resolve) => (answer = resolve)) } })
		const saveNew = await screen.findByRole('button', { name: 'Save as new login' })
		await user.click(saveNew)
		expect(saveNew.hasAttribute('disabled')).toBe(false)
		expect(saveNew.getAttribute('aria-disabled')).toBe('true')
		expect(document.activeElement).toBe(saveNew)
		await user.click(saveNew)
		expect(sentOf(sent, 'passkey.save')).toHaveLength(1)
		await act(async () => answer({ ok: false, code: 'stale' }))
		expect(await alertText()).toBeTruthy()
		expect(document.activeElement).toBe(saveNew)
	})

	it('asks the Replace question for a login holding this user\'s passkey, and replaces on Replace', async () => {
		const { sent, user } = renderWindow(createView({ targets: [target({ replace: { userName: 'alice' } })] }), { replies: { 'passkey.save': { ok: true } } })
		const login = await screen.findByRole('button', { name: 'Example, alice@example.test', description: 'Replaces the passkey for alice' })
		await user.click(login)
		const dialog = screen.getByRole('dialog', { name: 'Replace the passkey for alice on Example?' })
		expect(sentOf(sent, 'passkey.save')).toEqual([])
		await user.click(within(dialog).getByRole('button', { name: 'Replace' }))
		expect(sentOf(sent, 'passkey.save')).toEqual([{ kind: 'passkey.save', ceremonyId: 'c1', target: { itemId: 'i1', replace: true } }])
	})

	it('closes the Replace question on its Cancel or Escape, keeping the ceremony and returning focus', async () => {
		const { sent, user } = renderWindow(createView({ targets: [target({ replace: { userName: 'alice' } })] }))
		const login = await screen.findByRole('button', { name: 'Example, alice@example.test' })
		await user.click(login)
		await user.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Cancel' }))
		expect(screen.queryByRole('dialog')).toBeNull()
		expect(document.activeElement).toBe(login)
		await user.click(login)
		await user.keyboard('{Escape}')
		expect(screen.queryByRole('dialog')).toBeNull()
		expect(sentOf(sent, 'passkey.cancel')).toEqual([])
		expect(sentOf(sent, 'passkey.save')).toEqual([])
	})

	it('asks the Replace question when the background answers replace_required', async () => {
		const { user, setView } = renderWindow(createView({ targets: [target()] }), { replies: { 'passkey.save': { ok: false, code: 'replace_required' } } })
		setView(createView({ targets: [target({ replace: { userName: 'alice' } })] }))
		await user.click(await screen.findByRole('button', { name: 'Example, alice@example.test' }))
		expect(await screen.findByRole('dialog', { name: 'Replace the passkey for alice on Example?' })).toBeTruthy()
	})

	it('says the login can no longer take the passkey when replace_required finds no Replace to ask', async () => {
		const { user } = renderWindow(createView({ targets: [target()] }), { replies: { 'passkey.save': { ok: false, code: 'replace_required' } } })
		await user.click(await screen.findByRole('button', { name: 'Example, alice@example.test' }))
		expect(await alertText()).toBe('This login can no longer take the passkey. Choose another, or save it as a new login.')
		expect(screen.queryByRole('dialog')).toBeNull()
	})

	it('shows a refused save with the reason and Retry, and keeps the window open', async () => {
		const { close, user } = renderWindow(createView({ targets: [target()] }), { replies: { 'passkey.save': { ok: false, code: 'write_failed', error: { code: 'write_locked' } } } })
		await user.click(await screen.findByRole('button', { name: 'Example, alice@example.test' }))
		expect(await alertText()).toContain('Vault is temporarily locked for a key migration, try again later')
		expect(screen.getByRole('button', { name: 'Retry' })).toBeTruthy()
		expect(screen.getByRole('button', { name: 'Example, alice@example.test' })).toBeTruthy()
		expect(close).not.toHaveBeenCalled()
	})

	it('words a partner that cannot store passkeys, naming the partners and the version they need', async () => {
		const error = { code: 'partner_upgrade_required', partners: [{ shareId: 's1', cloudId: 'bob@partner.example' }], requiredVersion: '0.3.12' }
		renderWindow(createView({ failure: error as PasskeyView['failure'] }))
		const text = await alertText()
		expect(text).toContain('Nothing was saved: the Keepiq of bob@partner.example is too old to store passkeys.')
		expect(text).toContain('They need Keepiq 0.3.12 or later.')
		expect(text).not.toMatch(/authenticator key|Remove the key/)
	})

	it('words a partner that cannot store passkeys without names or a version', async () => {
		renderWindow(createView({ failure: { code: 'partner_upgrade_required' } }))
		const text = await alertText()
		expect(text).toContain('a partner organisation this login is shared with runs a Keepiq too old to store passkeys')
		expect(text).not.toMatch(/They need|authenticator key/)
	})

	it('words a conflict so it fits a fetch the vault does not hold and a second stale alike', async () => {
		renderWindow(createView({ failure: { code: 'conflict' } }))
		expect(await alertText()).toContain('This login changed while saving. Nothing was saved; try again.')
	})

	it('says so when a passkey for this account is already saved, and answers Close with passkey.cancel', async () => {
		const { sent, close, user } = renderWindow(createView({ alreadySaved: true }))
		await heading('Passkey already saved')
		expect(screen.getByText('A passkey for this account is already saved in Keepiq')).toBeTruthy()
		expect(screen.queryByRole('button', { name: 'Use your device or hardware key' })).toBeNull()
		const closeButton = screen.getByRole('button', { name: 'Close' })
		expect(document.activeElement).toBe(closeButton)
		await user.click(closeButton)
		expect(sentOf(sent, 'passkey.cancel')).toEqual([{ kind: 'passkey.cancel', ceremonyId: 'c1' }])
		expect(close).toHaveBeenCalled()
	})

	it('renders the page\'s rp.name and user.name as text only', async () => {
		const rpName = '<img src=x onerror="alert(1)">'
		renderWindow(createView({ rpName, userName: '<b onmouseover="alert(2)">bob</b>' }))
		expect((await screen.findByLabelText<HTMLInputElement>('Name')).value).toBe(rpName)
		expect(screen.getByText('<b onmouseover="alert(2)">bob</b>')).toBeTruthy()
		expect(document.querySelector('img, b, a, [onerror], [onmouseover]')).toBeNull()
	})
})

describe('the ways out', () => {
	it('cancels with Cancel and closes', async () => {
		const { sent, close, user } = renderWindow(passkeyView({ rows: [row()] }))
		await user.click(await screen.findByRole('button', { name: 'Cancel' }))
		expect(sentOf(sent, 'passkey.cancel')).toEqual([{ kind: 'passkey.cancel', ceremonyId: 'c1' }])
		expect(close).toHaveBeenCalled()
	})

	it('cancels with Escape', async () => {
		const { sent, close, user } = renderWindow(passkeyView({ rows: [row()] }))
		await screen.findByRole('button', { name: 'Example, alice' })
		await user.keyboard('{Escape}')
		expect(sentOf(sent, 'passkey.cancel')).toHaveLength(1)
		expect(close).toHaveBeenCalled()
	})

	it('closes on Cancel even without a reply, which cancels the ceremony too', async () => {
		const { close, user } = renderWindow(passkeyView(), { unanswered: ['passkey.cancel'] })
		vi.spyOn(console, 'error').mockImplementation(() => {})
		await user.click(await screen.findByRole('button', { name: 'Cancel' }))
		expect(close).toHaveBeenCalled()
	})

	it('reaches both with Tab after the rows and falls back with Enter', async () => {
		const { sent, close, user } = renderWindow(passkeyView({ rows: [row()] }))
		await screen.findByRole('button', { name: 'Example, alice' })
		await user.tab()
		expect(document.activeElement).toBe(screen.getByRole('button', { name: 'Use your device or hardware key' }))
		await user.tab()
		expect(document.activeElement).toBe(screen.getByRole('button', { name: 'Cancel' }))
		await user.tab({ shift: true })
		await user.keyboard('{Enter}')
		expect(sentOf(sent, 'passkey.fallback')).toHaveLength(1)
		expect(close).toHaveBeenCalled()
	})

	it('stays open and says so when the fallback gets no reply', async () => {
		vi.spyOn(console, 'error').mockImplementation(() => {})
		const { close, user } = renderWindow(passkeyView(), { unanswered: ['passkey.fallback'] })
		await user.click(await screen.findByRole('button', { name: 'Use your device or hardware key' }))
		expect(await alertText()).toMatch(/not responding/)
		expect(close).not.toHaveBeenCalled()
	})

	it('closes when the ceremony has ended', async () => {
		const { close } = renderWindow(passkeyView(), { replies: { 'passkey.state': { ok: false, code: 'ended' } } })
		await waitFor(() => expect(close).toHaveBeenCalled())
	})

	it('says the background is not responding when the state gets no reply', async () => {
		vi.spyOn(console, 'error').mockImplementation(() => {})
		renderWindow(passkeyView(), { unanswered: ['passkey.state'] })
		expect(await alertText()).toMatch(/not responding/)
	})
})

describe('activations right after a step appears', () => {
	const createView = (overrides: Partial<PasskeyView> = {}) => passkeyView({ kind: 'create', ...overrides })

	it('ignores a click or Enter on a passkey until the window has shown for the guard time', async () => {
		const { sent, hasty } = renderWindow(passkeyView({ rows: [row()] }), { replies: { 'passkey.pick': { ok: true } } })
		const first = await screen.findByRole('button', { name: 'Example, alice' })
		await hasty.keyboard('{Enter}')
		await hasty.click(first)
		wait(GUARD_MS - 1)
		await hasty.keyboard(' ')
		expect(sentOf(sent, 'passkey.pick')).toEqual([])
		wait(1)
		await hasty.keyboard('{Enter}')
		expect(sentOf(sent, 'passkey.pick')).toHaveLength(1)
	})

	it('ignores Enter on a login and a click on New login right after the save view appears', async () => {
		const { sent, hasty } = renderWindow(createView({ targets: [target()] }), { replies: { 'passkey.save': { ok: true } } })
		await screen.findByRole('button', { name: 'Example, alice@example.test' })
		await hasty.keyboard('{Enter}')
		await hasty.click(screen.getByRole('button', { name: 'New login' }))
		expect(screen.queryByLabelText('Name')).toBeNull()
		expect(sentOf(sent, 'passkey.save')).toEqual([])
		wait()
		await hasty.click(screen.getByRole('button', { name: 'New login' }))
		expect(screen.getByLabelText('Name')).toBeTruthy()
	})

	it('applies again when New login opens and when it goes back to the list', async () => {
		const { sent, user, hasty } = renderWindow(createView({ targets: [target()] }), { replies: { 'passkey.save': { ok: true } } })
		await user.click(await screen.findByRole('button', { name: 'New login' }))
		await hasty.keyboard('{Enter}')
		expect(sentOf(sent, 'passkey.save')).toEqual([])
		await hasty.click(screen.getByRole('button', { name: 'Back' }))
		const login = screen.getByRole('button', { name: 'Example, alice@example.test' })
		await hasty.click(login)
		await hasty.keyboard('{Enter}')
		expect(sentOf(sent, 'passkey.save')).toEqual([])
		expect(screen.queryByLabelText('Name')).toBeNull()
		wait()
		await hasty.click(login)
		expect(sentOf(sent, 'passkey.save')).toEqual([{ kind: 'passkey.save', ceremonyId: 'c1', target: { itemId: 'i1' } }])
	})

	it('ignores Enter in the Name field right after New login appears at once', async () => {
		const { sent, hasty } = renderWindow(createView(), { replies: { 'passkey.save': { ok: true } } })
		await hasty.type(await screen.findByLabelText('Name'), '{Enter}')
		expect(sentOf(sent, 'passkey.save')).toEqual([])
		wait()
		await hasty.type(screen.getByLabelText('Name'), '{Enter}')
		expect(sentOf(sent, 'passkey.save')).toHaveLength(1)
	})

	it('applies again after each view change, as a sign-in that turns into a save', async () => {
		const { sent, hasty, setView, broadcast } = renderWindow(passkeyView({ rows: [row()] }), { replies: { 'passkey.save': { ok: true }, 'passkey.pick': { ok: true } } })
		await screen.findByRole('button', { name: 'Example, alice' })
		wait()
		setView(createView({ targets: [target()] }))
		broadcast({ kind: 'vault.changed' })
		const login = await screen.findByRole('button', { name: 'Example, alice@example.test' })
		await hasty.click(login)
		expect(sentOf(sent, 'passkey.save')).toEqual([])
		wait()
		await hasty.click(login)
		expect(sentOf(sent, 'passkey.save')).toHaveLength(1)
		expect(sentOf(sent, 'passkey.pick')).toEqual([])
	})

	it('applies again when the window gets focus', async () => {
		const { sent, hasty } = renderWindow(passkeyView({ rows: [row()] }), { replies: { 'passkey.pick': { ok: true } } })
		const first = await screen.findByRole('button', { name: 'Example, alice' })
		wait()
		act(() => void window.dispatchEvent(new Event('focus')))
		await hasty.click(first)
		expect(sentOf(sent, 'passkey.pick')).toEqual([])
	})

	it('ignores Replace right after the question appears', async () => {
		const { sent, user, hasty } = renderWindow(createView({ targets: [target({ replace: { userName: 'alice' } })] }), { replies: { 'passkey.save': { ok: true } } })
		await user.click(await screen.findByRole('button', { name: 'Example, alice@example.test' }))
		const replace = within(screen.getByRole('dialog')).getByRole('button', { name: 'Replace' })
		await hasty.click(replace)
		expect(sentOf(sent, 'passkey.save')).toEqual([])
		wait()
		await hasty.click(replace)
		expect(sentOf(sent, 'passkey.save')).toEqual([{ kind: 'passkey.save', ceremonyId: 'c1', target: { itemId: 'i1', replace: true } }])
	})

	it('ignores Retry right after the failure appears', async () => {
		const { sent, hasty } = renderWindow(passkeyView({ rows: [row()], failure: { code: 'offline' } }), { replies: { 'passkey.retry': { ok: true } } })
		await hasty.click(await screen.findByRole('button', { name: 'Retry' }))
		expect(sentOf(sent, 'passkey.retry')).toEqual([])
	})

	it('ignores Retry right after a reply shows the failure', async () => {
		const { sent, user, hasty } = renderWindow(passkeyView({ rows: [row()] }), {
			replies: { 'passkey.pick': { ok: false, code: 'write_failed', error: { code: 'offline' } }, 'passkey.retry': { ok: true } },
		})
		await user.click(await screen.findByRole('button', { name: 'Example, alice' }))
		const retry = await screen.findByRole('button', { name: 'Retry' })
		await hasty.click(retry)
		expect(sentOf(sent, 'passkey.retry')).toEqual([])
		wait()
		await hasty.click(retry)
		expect(sentOf(sent, 'passkey.retry')).toHaveLength(1)
	})

	describe('a held Enter', () => {
		/** Each repeat of a held key comes past the guard time, as when holding it for a second. */
		function repeatsPastGuard() {
			const advance = (event: KeyboardEvent) => {
				if (event.repeat) wait()
			}
			document.addEventListener('keydown', advance, true)
			return () => document.removeEventListener('keydown', advance, true)
		}
		let release = () => {}
		afterEach(() => release())

		it('does not pick a passkey, and the next press does', async () => {
			const { sent, hasty } = renderWindow(passkeyView({ rows: [row()] }), { replies: { 'passkey.pick': { ok: true } } })
			await screen.findByRole('button', { name: 'Example, alice' })
			release = repeatsPastGuard()
			await hasty.keyboard('{Enter>4}')
			expect(sentOf(sent, 'passkey.pick')).toEqual([])
			await hasty.keyboard('{Enter}')
			expect(sentOf(sent, 'passkey.pick')).toHaveLength(1)
		})

		it('does not submit the Name form, and the next press does', async () => {
			const { sent, hasty } = renderWindow(createView(), { replies: { 'passkey.save': { ok: true } } })
			await hasty.click(await screen.findByLabelText('Name'))
			release = repeatsPastGuard()
			await hasty.keyboard('{Enter>4}')
			expect(sentOf(sent, 'passkey.save')).toEqual([])
			await hasty.keyboard('{Enter}')
			expect(sentOf(sent, 'passkey.save')).toHaveLength(1)
		})

		it('meets Cancel first in the Replace question', async () => {
			const { sent, user, hasty } = renderWindow(createView({ targets: [target({ replace: { userName: 'alice' } })] }), { replies: { 'passkey.save': { ok: true } } })
			await user.click(await screen.findByRole('button', { name: 'Example, alice@example.test' }))
			expect(document.activeElement).toBe(within(screen.getByRole('dialog')).getByRole('button', { name: 'Cancel' }))
			release = repeatsPastGuard()
			await hasty.keyboard('{Enter>4}')
			expect(sentOf(sent, 'passkey.save')).toEqual([])
		})
	})

	it('does not submit "Confirm it\'s you" or the unlock form from typing meant for the page', async () => {
		const held = row()
		const { sent, hasty } = renderWindow(passkeyView({ rows: [held], verify: true, pending: held }), { account: { pin: true } })
		await hasty.type(await screen.findByLabelText('PIN'), '1234{Enter}')
		expect(sentOf(sent, 'passkey.verify')).toEqual([])
		wait()
		await hasty.keyboard('{Enter}')
		expect(sentOf(sent, 'passkey.verify')).toHaveLength(1)
	})

	it('cancels at once with Escape or Cancel', async () => {
		const { sent, hasty } = renderWindow(passkeyView({ rows: [row()] }))
		await screen.findByRole('button', { name: 'Example, alice' })
		await hasty.keyboard('{Escape}')
		expect(sentOf(sent, 'passkey.cancel')).toHaveLength(1)
	})
})

describe('the passkey window', () => {
	it('opens from the popup page at ?passkey=<ceremonyId>', async () => {
		history.replaceState(null, '', '?passkey=c9')
		try {
			const sent = fakeBackground({ replies: { 'passkey.state': { ok: true, view: passkeyView() } } })
			render(<App />)
			await heading('Sign in with a passkey')
			expect(sentOf(sent, 'passkey.state')[0]).toEqual({ kind: 'passkey.state', ceremonyId: 'c9' })
			expect(document.title).toBe('Sign in with a passkey')
		} finally {
			history.replaceState(null, '', location.pathname)
		}
	})

	it('reads the lock state passively, so a window the page opened does not hold off the vault timeout', async () => {
		const { sent } = renderWindow(passkeyView())
		await heading('Sign in with a passkey')
		expect(sentOf(sent, 'vault.status').every((message) => message.passive === true)).toBe(true)
	})

	it('is in Dutch in a Dutch browser', async () => {
		setLocale('nl')
		renderWindow(passkeyView({ rows: [row()] }))
		await heading('Inloggen met een passkey')
		expect(screen.getByRole('list', { name: 'Gebruik een passkey uit Keepiq voor example.test' })).toBeTruthy()
		expect(screen.getByRole('button', { name: 'Je apparaat of hardwaresleutel gebruiken' })).toBeTruthy()
		expect(screen.getByRole('button', { name: 'Annuleren' })).toBeTruthy()
	})

	it('names New login in Dutch in a Dutch browser', async () => {
		setLocale('nl')
		renderWindow(passkeyView({ kind: 'create', targets: [target()] }))
		await heading('Passkey opslaan')
		expect(screen.getByRole('list', { name: 'Opslaan bij een login' })).toBeTruthy()
		expect(screen.getByRole('button', { name: 'Nieuwe login' }).getAttribute('title')).toBe('Nieuwe login')
	})

	it('imports nothing from src/api or src/crypto', () => {
		const source = readFileSync(resolve('entrypoints/popup/views/PasskeyWindow.tsx'), 'utf8')
		expect(source).not.toMatch(/from '@\/src\/(api|crypto)/)
	})
})
