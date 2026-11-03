// @vitest-environment happy-dom
import { StrictMode } from 'react'
import { act, cleanup, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import App from '../../App'
import { account, fakeBackground, fakeClipboard, itemMeta, ok, popupState, sendRow, sentOf, snapshotReply } from '../../testing'

afterEach(cleanup)

const title = () => screen.getByRole('heading', { level: 1 }).textContent
const footer = () => document.querySelector<HTMLElement>('.popup__footer')!
const create = () => within(footer()).getByRole('button', { name: 'Create' }) as HTMLButtonElement
const input = (label: string) => screen.getByLabelText(label) as HTMLInputElement

async function openForm(options: Parameters<typeof fakeBackground>[0] = {}) {
	const sent = fakeBackground({ lastTab: 'send', ...options })
	render(<App />)
	const user = userEvent.setup()
	await user.click(await screen.findByRole('button', { name: 'New' }))
	return { sent, user }
}

describe('the new send form', () => {
	it('opens with the defaults and Create disabled', async () => {
		await openForm()
		expect(title()).toBe('New send')
		expect(screen.getByRole('tab', { name: 'Text' }).getAttribute('aria-selected')).toBe('true')
		expect(input('Max views').value).toBe('1')
		expect((screen.getByLabelText('Expiry') as HTMLSelectElement).value).toBe('7d')
		expect(input('Access password').value).toBe('')
		expect(create().disabled).toBe(true)
		expect(screen.queryByRole('navigation', { name: 'Main' })).toBeNull()
	})

	it('creates a text send, shows its link once, and lists it with Copy link', async () => {
		const { sent, user } = await openForm()
		await user.type(screen.getByLabelText('Text to share'), 'the secret')
		await user.selectOptions(screen.getByLabelText('Expiry'), '3d')
		await user.click(create())
		expect(sentOf(sent, 'send.create')).toEqual([{
			kind: 'send.create', accountId: 'a1', input: { payloadType: 'text', content: 'the secret', maxViews: 1, ttlSeconds: 259200 },
		}])
		expect(await screen.findByRole('heading', { name: 'Your send is ready' })).toBe(document.activeElement)
		expect(title()).toBe('Send created')
		const link = 'https://cloud.example.org/index.php/apps/keepiq/public/send/' + '1'.padStart(64, '0') + '#k=key1'
		expect(input('Link').value).toBe(link)
		expect(screen.getByText(/burns after 1 view\./)).toBeTruthy()

		const clipboard = fakeClipboard()
		await user.click(within(footer()).getByRole('button', { name: 'Copy link' }))
		expect(clipboard).toHaveBeenCalledWith(link)
		await user.click(within(footer()).getByRole('button', { name: 'Done' }))
		expect(title()).toBe('Send')
		// Focus goes to the new send's row, which the created page held.
		const copy = await screen.findByRole('button', { name: /^Copy link, Text send/ })
		await waitFor(() => expect(document.activeElement).toBe(copy))
		await user.click(copy)
		expect(clipboard).toHaveBeenLastCalledWith(link)
	})

	it('finishes a create under StrictMode, as the popup renders', async () => {
		fakeBackground({ lastTab: 'send' })
		render(<StrictMode><App /></StrictMode>)
		const user = userEvent.setup()
		await user.click(await screen.findByRole('button', { name: 'New' }))
		await user.type(screen.getByLabelText('Text to share'), 'the secret')
		await user.type(input('Access password'), '123')
		await user.click(create())
		expect(await screen.findByRole('heading', { name: 'Your send is ready' })).toBeTruthy()
	})

	it('focuses the first field when it opens', async () => {
		await openForm()
		expect(document.activeElement).toBe(screen.getByLabelText('Text to share'))
	})

	it('keeps the user on the form while a create runs, and says a password is being applied', async () => {
		let answer: (reply: unknown) => void = () => {}
		const { user } = await openForm({ replies: { 'send.create': () => new Promise((resolve) => (answer = resolve)) } })
		const status = document.querySelector('form [role="status"]')!
		await user.type(screen.getByLabelText('Text to share'), 'x')
		await user.type(input('Access password'), 'pw')
		await user.click(create())
		expect(status.textContent).toBe('Protecting with password…')
		expect((screen.getByRole('button', { name: 'Back' }) as HTMLButtonElement).disabled).toBe(true)
		expect((screen.getByRole('button', { name: 'Pop out' }) as HTMLButtonElement).disabled).toBe(true)
		expect((screen.getByRole('button', { name: 'Accounts, signed in as Alice Doe' }) as HTMLButtonElement).disabled).toBe(true)
		expect((within(footer()).getByRole('button', { name: 'Cancel' }) as HTMLButtonElement).disabled).toBe(true)
		answer({ ok: true, value: { row: sendRow({ hasPassword: true }), link: 'https://cloud.example.org/l' } })
		expect(await screen.findByRole('heading', { name: 'Your send is ready' })).toBeTruthy()
		expect((screen.getByRole('button', { name: 'Back' }) as HTMLButtonElement).disabled).toBe(false)
	})

	it('goes to the unlock screen when the vault locked before Create', async () => {
		let state = popupState({ screen: 'unlocked', active: account({ status: 'unlocked' }), lastTab: 'send' })
		const { user } = await openForm({
			replies: {
				'vault.status': () => ok(state),
				'send.create': () => {
					state = popupState({ screen: 'unlock', active: account({ status: 'locked' }), lastTab: 'send' })
					return { ok: false, error: { code: 'locked' } }
				},
			},
		})
		await user.type(screen.getByLabelText('Text to share'), 'x')
		await user.click(create())
		expect(await screen.findByRole('heading', { name: 'Unlock' })).toBeTruthy()
		expect(screen.queryByRole('alert')).toBeNull()
	})

	it('re-reads the popup state when the session was revoked', async () => {
		const { sent, user } = await openForm({ replies: { 'send.create': { ok: false, error: { code: 'session_revoked' } } } })
		const before = sentOf(sent, 'vault.status').length
		await user.type(screen.getByLabelText('Text to share'), 'x')
		await user.click(create())
		await waitFor(() => expect(sentOf(sent, 'vault.status')).toHaveLength(before + 1))
		expect(screen.queryByRole('alert')).toBeNull()
	})

	it('sends a credential as the two English lines', async () => {
		const { sent, user } = await openForm()
		await user.click(screen.getByRole('tab', { name: 'Credential' }))
		await user.type(input('Username'), 'alice')
		expect(create().disabled).toBe(true)
		await user.type(input('Password'), '  ')
		expect(create().disabled).toBe(true)
		await user.clear(input('Password'))
		await user.type(input('Password'), 'pw')
		await user.click(create())
		await screen.findByRole('heading', { name: 'Your send is ready' })
		expect(sentOf(sent, 'send.create')[0]!.input).toMatchObject({ payloadType: 'credential', content: 'Username: alice\nPassword: pw' })
	})

	it('sends the access password, and the link of a password send stays copyable', async () => {
		const { sent, user } = await openForm()
		await user.type(screen.getByLabelText('Text to share'), 'x')
		await user.type(input('Access password'), 'open sesame')
		await user.click(create())
		await screen.findByRole('heading', { name: 'Your send is ready' })
		expect(sentOf(sent, 'send.create')[0]!.input).toMatchObject({ password: 'open sesame' })
		expect(screen.getByText(/After 5 wrong attempts/)).toBeTruthy()
		await user.click(within(footer()).getByRole('button', { name: 'Done' }))
		const row = await screen.findByRole('listitem')
		expect(within(row).getByText('Password')).toBeTruthy()
		expect(within(row).getByRole('button', { name: /^Copy link/ })).toBeTruthy()
	})

	it('checks max views and custom hours', async () => {
		const { user } = await openForm()
		await user.type(screen.getByLabelText('Text to share'), 'x')
		expect(create().disabled).toBe(false)
		await user.clear(input('Max views'))
		await user.type(input('Max views'), '101')
		expect(screen.getByText('Between 1 and 100')).toBeTruthy()
		expect(create().disabled).toBe(true)
		await user.clear(input('Max views'))
		await user.type(input('Max views'), '0')
		expect(screen.getByText('Between 1 and 100')).toBeTruthy()
		expect(create().disabled).toBe(true)
		await user.clear(input('Max views'))
		await user.type(input('Max views'), '100')
		expect(create().disabled).toBe(false)
		await user.selectOptions(screen.getByLabelText('Expiry'), 'custom')
		await user.clear(input('Hours'))
		await user.type(input('Hours'), '721')
		expect(screen.getByText('At most 720 hours (30 days)')).toBeTruthy()
		expect(create().disabled).toBe(true)
		await user.clear(input('Hours'))
		await user.type(input('Hours'), '0')
		expect(screen.getByText('At least 1 hour')).toBeTruthy()
	})

	it('refuses an access password where this browser cannot protect one', async () => {
		const { user } = await openForm({ send: { passwordProtection: false } })
		await user.type(screen.getByLabelText('Text to share'), 'x')
		await user.type(input('Access password'), 'pw')
		expect(screen.getByText('Password protection is not available in this browser')).toBeTruthy()
		expect(create().disabled).toBe(true)
		await user.clear(input('Access password'))
		expect(create().disabled).toBe(false)
	})

	it('keeps the values and says nothing was sent when the server is not reached', async () => {
		const { user } = await openForm({ replies: { 'send.create': { ok: false, error: { code: 'offline' } } } })
		await user.type(screen.getByLabelText('Text to share'), 'keep me')
		await user.click(create())
		expect((await screen.findByRole('alert')).textContent).toBe('Could not reach the server, nothing was sent')
		expect((screen.getByLabelText('Text to share') as HTMLTextAreaElement).value).toBe('keep me')
	})

	it('shows its own text, not the server\'s, for a refused send, and keeps the values', async () => {
		const { user } = await openForm({ replies: { 'send.create': { ok: false, error: { code: 'invalid' } } } })
		await user.type(screen.getByLabelText('Text to share'), 'keep me')
		await user.clear(input('Max views'))
		await user.type(input('Max views'), '5')
		await user.selectOptions(screen.getByLabelText('Expiry'), '1d')
		await user.click(create())
		expect((await screen.findByRole('alert')).textContent).toContain('Keepiq did not accept this send')
		expect((screen.getByLabelText('Text to share') as HTMLTextAreaElement).value).toBe('keep me')
		expect(input('Max views').value).toBe('5')
		expect((screen.getByLabelText('Expiry') as HTMLSelectElement).value).toBe('1d')
	})

	it('disables Create while offline, and says why', async () => {
		const offline = snapshotReply({ sync: { syncing: false, syncedAt: null, offline: true, lastError: 'network' } })
		const { sent, user } = await openForm({ snapshot: offline })
		await user.type(screen.getByLabelText('Text to share'), 'x')
		expect(screen.getByText('Sends need a connection to your Keepiq server')).toBeTruthy()
		expect(create().disabled).toBe(true)
		await user.click(screen.getByRole('button', { name: 'Sync now' }))
		expect(sentOf(sent, 'vault.sync').length).toBeGreaterThan(0)
	})

	it('asks before Back drops typed content', async () => {
		const { user } = await openForm()
		await user.type(screen.getByLabelText('Text to share'), 'draft')
		await user.click(screen.getByRole('button', { name: 'Back' }))
		await user.click(within(screen.getByRole('dialog', { name: 'Unsaved changes' })).getByRole('button', { name: 'Discard' }))
		expect(title()).toBe('Send')
	})

	it('asks before Back drops a changed option', async () => {
		const { user } = await openForm()
		await user.clear(input('Max views'))
		await user.type(input('Max views'), '3')
		await user.click(screen.getByRole('button', { name: 'Back' }))
		expect(screen.getByRole('dialog', { name: 'Unsaved changes' })).toBeTruthy()
	})

	it('leaves an untouched form without asking', async () => {
		const { user } = await openForm()
		await user.click(screen.getByRole('button', { name: 'Back' }))
		expect(screen.queryByRole('dialog')).toBeNull()
		expect(title()).toBe('Send')
	})

	it('focuses the new row after Done, also when the list refresh answers late', async () => {
		const list = (sends: ReturnType<typeof sendRow>[]) => ({ ok: true, value: { sends, passwordProtection: true } })
		const waiting: (() => void)[] = []
		let calls = 0
		const { user } = await openForm({
			replies: {
				'send.list': () => ++calls === 1
					? list([sendRow({ id: 'old' })])
					: new Promise((resolve) => waiting.push(() => resolve(list([sendRow({ id: 's-new-1' }), sendRow({ id: 'old' })])))),
			},
		})
		await user.type(screen.getByLabelText('Text to share'), 'x')
		await user.click(create())
		await user.click(await within(footer()).findByRole('button', { name: 'Done' }))
		const old = await screen.findByRole('listitem')
		expect(old.contains(document.activeElement)).toBe(false)
		await act(async () => waiting.forEach((release) => release()))
		const copy = await screen.findByRole('button', { name: /^Copy link/ })
		await waitFor(() => expect(document.activeElement).toBe(copy))
	})

	it('focuses Retry after Done when the list refresh fails', async () => {
		let calls = 0
		const { user } = await openForm({
			replies: {
				'send.list': () => ++calls === 1
					? { ok: true, value: { sends: [sendRow({ id: 'old' })], passwordProtection: true } }
					: { ok: false, error: { code: 'server_error' } },
			},
		})
		await user.type(screen.getByLabelText('Text to share'), 'x')
		await user.click(create())
		await user.click(await within(footer()).findByRole('button', { name: 'Done' }))
		const retry = await screen.findByRole('button', { name: 'Retry' })
		await waitFor(() => expect(document.activeElement).toBe(retry))
	})

	it('keeps a link without a password for this popup only', async () => {
		const { user } = await openForm({ send: { sends: [sendRow({ id: 'old' })] } })
		await user.type(screen.getByLabelText('Text to share'), 'x')
		await user.click(create())
		await user.click(await within(footer()).findByRole('button', { name: 'Done' }))
		await waitFor(() => expect(screen.getAllByRole('button', { name: /^Copy link/ })).toHaveLength(1))
		cleanup()
		fakeBackground({ lastTab: 'send', send: { sends: [sendRow({ id: 's-new-1' })] } })
		render(<App />)
		expect(await screen.findByText('Link no longer available')).toBeTruthy()
	})
})

describe('a link without a password', () => {
	const unlocked = (id = 'a1') => popupState({ screen: 'unlocked', active: account({ id, status: 'unlocked' }), lastTab: 'send' })

	/** A popup whose state the test changes, re-read on each lock broadcast as the background would send. */
	function popup(replies: Record<string, unknown> = {}) {
		let state = unlocked()
		const listeners: Array<(message: unknown) => void> = []
		vi.spyOn(browser.runtime.onMessage, 'addListener').mockImplementation((listener) => void listeners.push(listener as never))
		const sent = fakeBackground({ lastTab: 'send', replies: { 'vault.status': () => ok(state), ...replies } })
		render(<App />)
		return {
			sent,
			become: (next: ReturnType<typeof unlocked>) => {
				state = next
				act(() => listeners.forEach((listener) => listener({ kind: 'vault.locked' })))
			},
		}
	}

	async function createOne(user: ReturnType<typeof userEvent.setup>) {
		await user.click(await screen.findByRole('button', { name: 'New' }))
		await user.type(screen.getByLabelText('Text to share'), 'x')
		await user.click(create())
		await user.click(await within(footer()).findByRole('button', { name: 'Done' }))
		await screen.findByRole('button', { name: /^Copy link/ })
	}

	it('is gone after a lock and unlock', async () => {
		const { become } = popup()
		const user = userEvent.setup()
		await createOne(user)
		become(popupState({ screen: 'unlock', active: account({ status: 'locked' }), lastTab: 'send' }))
		await screen.findByRole('heading', { name: 'Unlock' })
		become(unlocked())
		expect(await screen.findByText('Link no longer available')).toBeTruthy()
		expect(screen.queryByRole('button', { name: /^Copy link/ })).toBeNull()
	})

	it('never opens its page when the create answers after a lock', async () => {
		let answer: (reply: unknown) => void = () => {}
		const { become } = popup({ 'send.create': () => new Promise((resolve) => (answer = resolve)) })
		const user = userEvent.setup()
		await user.click(await screen.findByRole('button', { name: 'New' }))
		await user.type(screen.getByLabelText('Text to share'), 'x')
		await user.click(create())
		become(popupState({ screen: 'unlock', active: account({ status: 'locked' }), lastTab: 'send' }))
		await screen.findByRole('heading', { name: 'Unlock' })
		await act(async () => answer({ ok: true, value: { row: sendRow({ id: 'late' }), link: 'https://cloud.example.org/l#k=secret' } }))
		become(unlocked())
		expect(await screen.findByRole('heading', { name: 'No sends yet' })).toBeTruthy()
		expect(screen.queryByRole('heading', { name: 'Your send is ready' })).toBeNull()
		expect(document.body.innerHTML).not.toContain('#k=secret')
	})

	it('is gone after switching to another account and back', async () => {
		const { become } = popup()
		const user = userEvent.setup()
		await createOne(user)
		become(unlocked('a2'))
		await waitFor(() => expect(screen.queryByRole('button', { name: /^Copy link/ })).toBeNull())
		become(unlocked('a1'))
		expect(await screen.findByText('Link no longer available')).toBeTruthy()
	})
})

describe('Send from an item', () => {
	it('opens the form on the Send tab with the login filled in', async () => {
		const sent = fakeBackground({ decrypted: { i1: { login: 'alice', key: 'hunter2' } } })
		render(<App />)
		const user = userEvent.setup()
		await user.click(await screen.findByRole('button', { name: 'More, GitHub' }))
		await user.click(screen.getByRole('menuitem', { name: 'Send' }))
		await waitFor(() => expect(title()).toBe('New send'))
		expect(sentOf(sent, 'item.decrypt').at(-1)).toMatchObject({ ids: ['i1'], fields: ['login', 'key'] })
		expect(screen.getByRole('tab', { name: 'Credential' }).getAttribute('aria-selected')).toBe('true')
		expect(input('Username').value).toBe('alice')
		expect(input('Password').value).toBe('hunter2')
		expect(input('Max views').value).toBe('1')
		await user.click(screen.getByRole('button', { name: 'Back' }))
		expect(title()).toBe('Send')
		expect(screen.queryByRole('dialog')).toBeNull()
	})

	it('opens with an empty password for a login that has none', async () => {
		fakeBackground({ decrypted: { i1: { login: 'alice' } } })
		render(<App />)
		const user = userEvent.setup()
		await user.click(await screen.findByRole('button', { name: 'More, GitHub' }))
		await user.click(screen.getByRole('menuitem', { name: 'Send' }))
		await waitFor(() => expect(title()).toBe('New send'))
		expect(input('Username').value).toBe('alice')
		expect(input('Password').value).toBe('')
	})

	it('says so when the login cannot be read', async () => {
		fakeBackground({ snapshot: snapshotReply({ items: [itemMeta()] }), decryptError: 'decrypt_failed' })
		render(<App />)
		const user = userEvent.setup()
		await user.click(await screen.findByRole('button', { name: 'More, GitHub' }))
		await user.click(screen.getByRole('menuitem', { name: 'Send' }))
		expect(await screen.findByText('Could not read this login')).toBeTruthy()
		expect(title()).toBe('Vault')
	})
})
