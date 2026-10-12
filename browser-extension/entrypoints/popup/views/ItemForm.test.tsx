// @vitest-environment happy-dom
import { cleanup, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { EditableItem, FolderMeta } from '@/src/messages'
import { DEFAULT_SHELL, ShellContext } from '../shell-context'
import { editableItem, fakeBackground, sentOf, snapshotReply, type UnlockedSnapshot } from '../testing'
import { ItemForm, type FormMode } from './ItemForm'

let footer: HTMLElement
beforeEach(() => {
	footer = document.body.appendChild(document.createElement('div'))
})
afterEach(() => {
	cleanup()
	footer.remove()
})

const folders: FolderMeta[] = [{ id: 'w', name: 'Work', parentId: null }, { id: 'c', name: 'Clients', parentId: 'w' }]

interface Options {
	mode?: FormMode
	id?: string
	snapshot?: UnlockedSnapshot
	tabUrl?: string
	offline?: boolean
	items?: Record<string, EditableItem>
	replies?: Record<string, unknown>
}

function renderForm({ mode = 'add', id, snapshot = snapshotReply({ folders }), tabUrl, offline = false, items, replies }: Options = {}) {
	const sent = fakeBackground({ snapshot, items, replies })
	const shell = { ...DEFAULT_SHELL, footer, offline, toast: vi.fn(), refreshState: vi.fn(), syncNow: vi.fn(), setLeaveGuard: vi.fn() }
	const onSaved = vi.fn()
	const onCancel = vi.fn()
	const onGone = vi.fn()
	render(
		<ShellContext.Provider value={shell}>
			<ItemForm mode={mode} id={id} snapshot={snapshot} tabUrl={tabUrl} onSaved={onSaved} onCancel={onCancel} onGone={onGone} />
		</ShellContext.Provider>,
	)
	return { sent, shell, onSaved, onCancel, onGone, user: userEvent.setup() }
}

const save = () => within(footer).getByRole('button', { name: 'Save' })
const field = (label: string) => screen.getByLabelText(label) as HTMLInputElement
const created = (sent: ReturnType<typeof fakeBackground>) => sentOf(sent, 'item.create').map((message) => message.draft)

describe('adding', () => {
	it('opens on a tab with its URL, No folder and the login type', () => {
		renderForm({ tabUrl: 'https://example.com/login' })
		expect(field('Website URL').value).toBe('https://example.com/login')
		expect(screen.getByRole('button', { name: 'Choose a folder, now No folder' })).toBeTruthy()
		expect((screen.getByLabelText('Type') as HTMLSelectElement).value).toBe('t-login')
		for (const label of ['Name', 'Username', 'Password', 'Notes']) expect(field(label).value).toBe('')
	})

	it('preselects the user\'s default type and leaves passkeys out', () => {
		renderForm({ snapshot: snapshotReply({ defaultSecretType: 'note' }) })
		const type = screen.getByLabelText('Type') as HTMLSelectElement
		expect(type.value).toBe('t-note')
		expect([...type.options].map((option) => option.textContent)).not.toContain('Passkey')
		expect(screen.getByRole('textbox', { name: 'Note' })).toBeTruthy()
		expect(screen.queryByLabelText('Username')).toBeNull()
	})

	it('refuses a missing name without a request', async () => {
		const { sent, user } = renderForm()
		await user.click(save())
		expect(screen.getByText('Name is required')).toBeTruthy()
		expect(created(sent)).toEqual([])
	})

	it('creates a login with its notes and fields, then hands over the new id', async () => {
		const { sent, onSaved, user } = renderForm({ tabUrl: 'https://github.com/login' })
		await user.type(field('Name'), 'GitHub')
		await user.type(field('Username'), 'alice')
		await user.type(field('Password'), 'hunter2')
		await user.type(field('Notes'), 'Recovery codes in the safe')
		await user.click(screen.getByRole('button', { name: 'Add field' }))
		await user.type(field('Field name'), 'pin')
		await user.type(field('pin'), '1234')
		await user.click(save())
		await waitFor(() => expect(onSaved).toHaveBeenCalledWith('new'))
		expect(created(sent)).toEqual([{
			typeId: 't-login', name: 'GitHub', url: 'https://github.com/login', folderId: null, key: 'hunter2', login: 'alice',
			additionalFields: { pin: '1234', notes: 'Recovery codes in the safe' },
		}])
	})

	it('refuses a reserved or duplicate field name while typing', async () => {
		const { user } = renderForm()
		await user.click(screen.getByRole('button', { name: 'Add field' }))
		await user.type(screen.getAllByLabelText('Field name')[0]!, 'Login')
		expect(screen.getByText('This name is reserved')).toBeTruthy()
		expect((save() as HTMLButtonElement).disabled).toBe(true)
		await user.clear(screen.getAllByLabelText('Field name')[0]!)
		await user.type(screen.getAllByLabelText('Field name')[0]!, 'pin')
		await user.click(screen.getByRole('button', { name: 'Add field' }))
		await user.type(screen.getAllByLabelText('Field name')[1]!, 'pin')
		expect(screen.getByText('A field with this name already exists')).toBeTruthy()
		expect((save() as HTMLButtonElement).disabled).toBe(true)
	})

	it('refuses an invalid authenticator secret', async () => {
		const { sent, user } = renderForm()
		await user.selectOptions(screen.getByLabelText('Type'), 't-totp')
		await user.type(field('Name'), 'GitHub 2FA')
		await user.type(field('Authenticator key'), 'not a secret!')
		await user.click(save())
		expect(screen.getByText('Not a valid authenticator secret')).toBeTruthy()
		expect(created(sent)).toEqual([])
	})

	it('writes changed TOTP options into the seed', async () => {
		const { sent, onSaved, user } = renderForm()
		await user.selectOptions(screen.getByLabelText('Type'), 't-totp')
		await user.type(field('Name'), 'GitHub')
		await user.type(field('Authenticator key'), 'JBSWY3DPEHPK3PXP')
		await user.click(screen.getByText('Advanced'))
		await user.selectOptions(screen.getByLabelText('Digits'), '8')
		await user.click(save())
		await waitFor(() => expect(onSaved).toHaveBeenCalled())
		expect(created(sent)[0]).toMatchObject({ key: 'otpauth://totp/GitHub?secret=JBSWY3DPEHPK3PXP&algorithm=SHA1&digits=8&period=30', login: null })
	})

	it('writes a card with all five members', async () => {
		const { sent, onSaved, user } = renderForm()
		await user.selectOptions(screen.getByLabelText('Type'), 't-card')
		await user.type(field('Name'), 'Visa')
		await user.type(field('Number'), '4111111111111111')
		await user.type(field('Expiry'), '12/29')
		await user.type(field('Cardholder'), 'Alice Doe')
		await user.click(save())
		await waitFor(() => expect(onSaved).toHaveBeenCalled())
		expect(JSON.parse((created(sent)[0] as { key: string }).key)).toEqual({ number: '4111111111111111', expiry: '12/29', cvv: '', pin: '', cardholder: 'Alice Doe' })
	})

	it('reports itself dirty to the leave guard once something is typed', async () => {
		const { shell, user } = renderForm({ tabUrl: 'https://github.com/' })
		const isDirty = shell.setLeaveGuard.mock.calls[0]![0] as () => boolean
		expect(isDirty()).toBe(false)
		await user.type(field('Name'), 'x')
		expect(isDirty()).toBe(true)
	})
})

describe('folders', () => {
	it('picks a nested folder without a request', async () => {
		const { sent, onSaved, user } = renderForm()
		await user.click(screen.getByRole('button', { name: 'Choose a folder, now No folder' }))
		const dialog = screen.getByRole('dialog', { name: 'Choose folder' })
		expect(within(dialog).getAllByRole('button').map((button) => button.textContent)).toEqual(['No folderCurrent folder', 'Work', 'Clients', 'New folder', 'Cancel'])
		await user.click(within(dialog).getByRole('button', { name: 'Clients' }))
		expect(screen.getByRole('button', { name: 'Choose a folder, now Work / Clients' })).toBeTruthy()
		expect(sentOf(sent, 'folder.create')).toEqual([])
		await user.type(field('Name'), 'GitHub')
		await user.click(save())
		await waitFor(() => expect(onSaved).toHaveBeenCalled())
		expect(created(sent)[0]).toMatchObject({ folderId: 'c' })
	})

	it('creates a folder from the picker and keeps the other fields', async () => {
		const { sent, onSaved, user } = renderForm()
		await user.type(field('Name'), 'Bank')
		await user.click(screen.getByRole('button', { name: 'Choose a folder, now No folder' }))
		await user.click(screen.getByRole('button', { name: 'New folder' }))
		await user.type(field('Folder name'), 'Banking')
		await user.click(within(screen.getByRole('dialog', { name: 'New folder' })).getByRole('button', { name: 'Save' }))
		await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull())
		expect(sentOf(sent, 'folder.create')).toEqual([{ kind: 'folder.create', accountId: 'a1', name: 'Banking', parentId: null }])
		expect(field('Name').value).toBe('Bank')
		await user.click(save())
		await waitFor(() => expect(onSaved).toHaveBeenCalled())
		expect(created(sent)[0]).toMatchObject({ folderId: 'f-new' })
	})

	it('refuses a slash in a new folder name', async () => {
		const { sent, user } = renderForm()
		await user.click(screen.getByRole('button', { name: 'Choose a folder, now No folder' }))
		await user.click(screen.getByRole('button', { name: 'New folder' }))
		await user.type(field('Folder name'), 'Work/Clients')
		await user.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Save' }))
		expect(screen.getByText('Folder names cannot contain slashes')).toBeTruthy()
		expect(sentOf(sent, 'folder.create')).toEqual([])
	})
})

describe('editing', () => {
	it('fills from a fresh fetch and sends only the rename with the base version', async () => {
		const { sent, onSaved, user } = renderForm({ mode: 'edit', id: 'i1', items: { i1: editableItem({ additionalFields: { notes: 'n' } }) } })
		expect((await screen.findByLabelText('Username') as HTMLInputElement).value).toBe('alice')
		expect(field('Notes').value).toBe('n')
		expect((screen.getByLabelText('Type') as HTMLSelectElement).disabled).toBe(true)
		await user.type(field('Name'), ' work')
		await user.click(save())
		await waitFor(() => expect(onSaved).toHaveBeenCalledWith('i1'))
		expect(sentOf(sent, 'item.update')).toEqual([{ kind: 'item.update', accountId: 'a1', id: 'i1', patch: { name: 'GitHub work' }, baseUpdatedAt: '2026-03-02T10:00:00Z' }])
	})

	it('clears an emptied username with null', async () => {
		const { sent, onSaved, user } = renderForm({ mode: 'edit', id: 'i1', items: { i1: editableItem() } })
		await user.clear(await screen.findByLabelText('Username'))
		await user.click(save())
		await waitFor(() => expect(onSaved).toHaveBeenCalled())
		expect(sentOf(sent, 'item.update')[0]).toMatchObject({ patch: { login: null } })
	})

	it('closes without a request when nothing changed', async () => {
		const { sent, onSaved, user } = renderForm({ mode: 'edit', id: 'i1', items: { i1: editableItem() } })
		await screen.findByLabelText('Username')
		await user.click(save())
		await waitFor(() => expect(onSaved).toHaveBeenCalledWith('i1'))
		expect(sentOf(sent, 'item.update')).toEqual([])
	})

	it('keeps the typed values when the item changed elsewhere', async () => {
		const { onSaved, user } = renderForm({ mode: 'edit', id: 'i1', items: { i1: editableItem() }, replies: { 'item.update': { ok: false, error: { code: 'conflict' } } } })
		await user.type(await screen.findByLabelText('Username'), '2')
		await user.click(save())
		expect(await screen.findByText('This item was changed elsewhere. Go back and edit it again to see the latest version.')).toBeTruthy()
		expect(field('Username').value).toBe('alice2')
		expect(onSaved).not.toHaveBeenCalled()
	})

	it('keeps the values on a write lock', async () => {
		const { user } = renderForm({ replies: { 'item.create': { ok: false, error: { code: 'write_locked' } } } })
		await user.type(field('Name'), 'GitHub')
		await user.click(save())
		expect(await screen.findByText('Vault is temporarily locked for a key migration, try again later')).toBeTruthy()
		expect(field('Name').value).toBe('GitHub')
	})

	it('offers Retry when the server could not be reached', async () => {
		let attempts = 0
		const { onSaved, user } = renderForm({ replies: { 'item.create': () => (++attempts === 1 ? { ok: false, error: { code: 'offline' } } : { ok: true, value: { id: 'new' } }) } })
		await user.type(field('Name'), 'GitHub')
		await user.click(save())
		await user.click(await screen.findByRole('button', { name: 'Try again' }))
		await waitFor(() => expect(onSaved).toHaveBeenCalledWith('new'))
	})

	it('edits only name, folder and URL of a passkey', async () => {
		const { sent, onSaved, user } = renderForm({ mode: 'edit', id: 'p', items: { p: editableItem({ id: 'p', typeId: 't-passkey', key: '', keyWithheld: true, login: null }) } })
		expect(await screen.findByText('Passkey credentials can only be changed in the Keepiq web app')).toBeTruthy()
		for (const label of ['Username', 'Password', 'Notes', 'Field name']) expect(screen.queryByLabelText(label)).toBeNull()
		expect(screen.queryByRole('button', { name: 'Add field' })).toBeNull()
		await user.type(field('Name'), '!')
		await user.click(save())
		await waitFor(() => expect(onSaved).toHaveBeenCalled())
		expect(sentOf(sent, 'item.update')[0]).toMatchObject({ patch: { name: 'GitHub!' } })
	})

	it('leaves when the item was deleted elsewhere', async () => {
		const { shell, onGone } = renderForm({ mode: 'edit', id: 'gone' })
		await waitFor(() => expect(onGone).toHaveBeenCalled())
		expect(shell.toast).toHaveBeenCalledWith('This item no longer exists')
	})

	it('refuses to edit a blocked item and says why', async () => {
		renderForm({ mode: 'edit', id: 'b', replies: { 'item.fetch': { ok: false, error: { code: 'blocked', reason: 'suite_revoked' } } } })
		const alert = await screen.findByRole('alert')
		expect(alert.textContent).toContain('Its encryption key was revoked.')
		expect(within(alert).getByRole('link', { name: 'Open the Keepiq web app' }).getAttribute('href')).toBe('https://cloud.example.org/index.php/apps/keepiq/')
		expect(screen.queryByLabelText('Name')).toBeNull()
	})
})

describe('cloning', () => {
	it('prefills every field with the name suffixed and creates a new item', async () => {
		const { sent, onSaved, user } = renderForm({ mode: 'clone', id: 'i1', items: { i1: editableItem({ folderId: 'w', additionalFields: { pin: '1' } }) } })
		expect((await screen.findByLabelText('Name') as HTMLInputElement).value).toBe('GitHub - Clone')
		await user.click(save())
		await waitFor(() => expect(onSaved).toHaveBeenCalledWith('new'))
		expect(created(sent)).toEqual([{
			typeId: 't-login', name: 'GitHub - Clone', url: 'https://github.com', folderId: 'w', key: 'hunter2', login: 'alice', additionalFields: { pin: '1' },
		}])
		expect(sentOf(sent, 'item.update')).toEqual([])
	})
})

describe('offline', () => {
	it('disables Save and explains why, with a way to retry', async () => {
		const { shell, user } = renderForm({ offline: true })
		expect(screen.getByText('You are offline. Changes need a connection to Keepiq.')).toBeTruthy()
		expect((save() as HTMLButtonElement).disabled).toBe(true)
		await user.click(screen.getByRole('button', { name: 'Sync now' }))
		expect(shell.syncNow).toHaveBeenCalled()
	})
})
