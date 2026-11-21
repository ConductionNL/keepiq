// @vitest-environment happy-dom
import { act, cleanup, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import App from '../../App'
import type { FolderMeta } from '@/src/messages'
import { everythingVisible, fakeBackground, itemMeta, mounts, popupState, sentOf, snapshotReply } from '../../testing'
import { applyAppearance, applyTheme, THEME_MIRROR_KEY } from '../../theme'

afterEach(() => {
	cleanup()
	localStorage.clear()
	applyTheme('system')
	applyAppearance({ compactMode: false, showAnimations: true })
})
beforeEach(everythingVisible)

const title = () => screen.findByRole('heading', { level: 1 })
const theme = () => document.documentElement.dataset.theme

async function openSettings() {
	const user = userEvent.setup()
	render(<App />)
	await title()
	await user.click(within(screen.getByRole('navigation', { name: 'Main' })).getByRole('button', { name: 'Settings' }))
	return user
}

describe('Settings', () => {
	it('lists the sections in order and opens each with a way back', async () => {
		fakeBackground()
		const user = await openSettings()
		const rows = within(screen.getByRole('list')).getAllByRole('button')
		const names = ['Account security', 'Autofill', 'Notifications', 'Vault', 'Appearance', 'About']
		expect(rows.map((row) => row.textContent)).toEqual(names)
		expect(rows.some((row) => row.getAttribute('aria-disabled'))).toBe(false)
		for (const name of names) {
			await user.click(within(screen.getByRole('list')).getByRole('button', { name }))
			expect((await title()).textContent).toBe(name)
			expect(screen.queryByText('Available in a later update')).toBeNull()
			await user.click(screen.getByRole('button', { name: 'Back' }))
			expect((await title()).textContent).toBe('Settings')
		}
	})

	it('opens Autofill with every entry\'s stored value', async () => {
		fakeBackground({ accountSettings: { values: { contextMenu: false, clearClipboardMs: 60_000 } } })
		const user = await openSettings()
		await user.click(screen.getByRole('button', { name: 'Autofill' }))
		expect((await screen.findByRole<HTMLInputElement>('checkbox', { name: 'Enable context menu options' })).checked).toBe(false)
		expect(screen.getByRole<HTMLInputElement>('checkbox', { name: 'Show autofill suggestions on form fields' }).checked).toBe(true)
		expect(screen.getByRole<HTMLSelectElement>('combobox', { name: 'Clear clipboard' }).value).toBe('60000')
	})

	it('returns from Excluded domains to Notifications', async () => {
		fakeBackground()
		const user = await openSettings()
		await user.click(screen.getByRole('button', { name: 'Notifications' }))
		await user.click(await screen.findByRole('button', { name: 'Excluded domains' }))
		expect((await title()).textContent).toBe('Excluded domains')
		await user.click(screen.getByRole('button', { name: 'Back' }))
		expect((await title()).textContent).toBe('Notifications')
	})

	it('starts New with the default item type chosen here', async () => {
		fakeBackground()
		const user = await openSettings()
		await user.click(within(screen.getByRole('list')).getByRole('button', { name: 'Vault' }))
		await user.selectOptions(await screen.findByRole('combobox', { name: 'Default item type in this extension' }), 'Note')
		await user.click(screen.getByRole('button', { name: 'Back' }))
		await user.click(within(screen.getByRole('navigation', { name: 'Main' })).getByRole('button', { name: 'Vault' }))
		await user.click(await screen.findByRole('button', { name: 'New' }))
		expect((await screen.findByRole<HTMLSelectElement>('combobox', { name: 'Type' })).value).toBe('t-note')
	})

	it('drops the cards\' copy actions when quick copy is off, keeping the detail\'s', async () => {
		fakeBackground({ decrypted: { i1: { login: 'alice', key: 'hunter2' } } })
		const user = await openSettings()
		await user.click(screen.getByRole('button', { name: 'Appearance' }))
		await user.click(await screen.findByRole('checkbox', { name: 'Show quick copy actions on vault' }))
		await user.click(screen.getByRole('button', { name: 'Back' }))
		await user.click(within(screen.getByRole('navigation', { name: 'Main' })).getByRole('button', { name: 'Vault' }))
		await screen.findByRole('button', { name: 'More, GitHub' })
		expect(screen.queryByRole('button', { name: 'Copy, GitHub' })).toBeNull()
		await user.click(screen.getAllByRole('button', { name: /^GitHub/ })[0]!)
		expect(await screen.findByRole('button', { name: 'Copy username' })).toBeTruthy()
	})

	it('opens Appearance with the stored theme and goes back to the list', async () => {
		fakeBackground({ settings: { theme: 'light' } })
		const user = await openSettings()
		await user.click(screen.getByRole('button', { name: 'Appearance' }))
		expect((await title()).textContent).toBe('Appearance')
		expect((await screen.findByRole<HTMLSelectElement>('combobox', { name: 'Theme' })).value).toBe('light')
		await user.click(screen.getByRole('button', { name: 'Back' }))
		expect((await title()).textContent).toBe('Settings')
		expect(screen.getByRole('button', { name: 'Appearance' })).toBeTruthy()
	})

	it('repaints and mirrors a new theme at once', async () => {
		const sent = fakeBackground()
		const user = await openSettings()
		await user.click(screen.getByRole('button', { name: 'Appearance' }))
		await user.selectOptions(await screen.findByRole('combobox', { name: 'Theme' }), 'Dark')
		expect(sent).toHaveBeenCalledWith({ kind: 'settings.set', patch: { theme: 'dark' } })
		await waitFor(() => expect(theme()).toBe('dark'))
		expect(localStorage.getItem(THEME_MIRROR_KEY)).toBe('dark')
	})

	it('keeps the stored theme when the background does not answer', async () => {
		fakeBackground({ unanswered: ['settings.set'] })
		const user = await openSettings()
		await user.click(screen.getByRole('button', { name: 'Appearance' }))
		const select = await screen.findByRole<HTMLSelectElement>('combobox', { name: 'Theme' })
		await user.selectOptions(select, 'Dark')
		await screen.findByText('Keepiq is not responding. Reload the extension and reopen this popup.')
		expect(select.value).toBe('system')
		expect(theme()).toBeUndefined()
	})

	it('covers the tab bar while a section is open, with no footer of its own', async () => {
		fakeBackground()
		const user = await openSettings()
		await user.click(screen.getByRole('button', { name: 'Appearance' }))
		await screen.findByRole('combobox', { name: 'Theme' })
		expect(screen.queryByRole('navigation', { name: 'Main' })).toBeNull()
		expect(document.querySelector('.popup__footer')!.childElementCount).toBe(0)
		await user.click(screen.getByRole('button', { name: 'Back' }))
		expect(screen.getByRole('navigation', { name: 'Main' })).toBeTruthy()
	})
})

describe('the stored theme', () => {
	it('applies to the unlock screen and replaces a stale mirror', async () => {
		localStorage.setItem(THEME_MIRROR_KEY, 'light')
		fakeBackground({ state: popupState({ screen: 'unlock' }), settings: { theme: 'dark' } })
		render(<App />)
		expect((await title()).textContent).toBe('Unlock')
		expect(screen.queryByRole('navigation', { name: 'Main' })).toBeNull()
		await waitFor(() => expect(theme()).toBe('dark'))
		expect(localStorage.getItem(THEME_MIRROR_KEY)).toBe('dark')
	})

	it('applies compact mode and stopped animations to the unlock screen', async () => {
		fakeBackground({ state: popupState({ screen: 'unlock' }), settings: { compactMode: true, showAnimations: false } })
		render(<App />)
		expect((await title()).textContent).toBe('Unlock')
		await waitFor(() => expect(document.documentElement.classList.contains('compact')).toBe(true))
		expect(document.documentElement.classList.contains('no-animations')).toBe(true)
	})

	it('follows a change made in another window', async () => {
		fakeBackground()
		render(<App />)
		await title()
		window.dispatchEvent(new StorageEvent('storage', { key: THEME_MIRROR_KEY, newValue: 'light' }))
		expect(theme()).toBe('light')
	})
})

describe('Folders', () => {
	const work: FolderMeta = { id: 'w', name: 'Work', parentId: null }
	const clients: FolderMeta = { id: 'c', name: 'Clients', parentId: 'w' }
	const old: FolderMeta = { id: 'o', name: 'Old', parentId: null }

	async function openFolders(options: Parameters<typeof fakeBackground>[0] = {}) {
		const sent = fakeBackground(options)
		const user = await openSettings()
		await user.click(within(screen.getByRole('list')).getByRole('button', { name: 'Vault' }))
		expect((await title()).textContent).toBe('Vault')
		await user.click(screen.getByRole('button', { name: 'Folders' }))
		expect((await title()).textContent).toBe('Folders')
		return { sent, user }
	}

	const row = (name: string) => screen.getByText(name, { selector: '.folder-row__name' }).closest('li')!
	async function choose(user: ReturnType<typeof userEvent.setup>, folder: string, action: string) {
		await user.click(screen.getByRole('button', { name: `More, ${folder}` }))
		await user.click(screen.getByRole('menuitem', { name: action }))
	}
	const dialog = () => screen.getByRole('dialog')

	it('renders the tree indented and goes back to Vault', async () => {
		const { user } = await openFolders({ snapshot: snapshotReply({ folders: [clients, work] }) })
		expect(row('Clients').style.getPropertyValue('--depth')).toBe('1')
		expect(row('Work').style.getPropertyValue('--depth')).toBe('0')
		await user.click(screen.getByRole('button', { name: 'Back' }))
		expect((await title()).textContent).toBe('Vault')
	})

	it('says when there are no folders, and dismisses the plaintext notice', async () => {
		const { sent, user } = await openFolders()
		expect(screen.getByText('No folders yet')).toBeTruthy()
		expect(screen.getByRole('button', { name: 'Add folder' })).toBeTruthy()
		await user.click(screen.getByRole('button', { name: 'Dismiss' }))
		expect(screen.queryByText('Folder names are not encrypted')).toBeNull()
		expect(sentOf(sent, 'folder.dismissNotice')).toHaveLength(1)
	})

	it('hides a dismissed notice', async () => {
		await openFolders({ snapshot: snapshotReply({ folderNoticeDismissed: true }) })
		expect(screen.queryByText('Folder names are not encrypted')).toBeNull()
	})

	it('creates a subfolder under its row', async () => {
		const { sent, user } = await openFolders({ snapshot: snapshotReply({ folders: [work] }) })
		await choose(user, 'Work', 'Add folder')
		await user.type(screen.getByLabelText('Folder name'), 'Clients')
		await user.click(within(dialog()).getByRole('button', { name: 'Save' }))
		await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull())
		expect(sentOf(sent, 'folder.create')).toEqual([{ kind: 'folder.create', accountId: 'a1', name: 'Clients', parentId: 'w' }])
	})

	it('keeps the typed name when a sibling has it', async () => {
		const { user } = await openFolders({ snapshot: snapshotReply({ folders: [work] }), replies: { 'folder.create': { ok: false, error: { code: 'conflict' } } } })
		await user.click(screen.getByRole('button', { name: 'Add folder' }))
		await user.type(screen.getByLabelText('Folder name'), 'Work')
		await user.click(within(dialog()).getByRole('button', { name: 'Save' }))
		expect(await within(dialog()).findByText('A folder with this name already exists here')).toBeTruthy()
		expect((screen.getByLabelText('Folder name') as HTMLInputElement).value).toBe('Work')
	})

	it('renames with the new name only', async () => {
		const { sent, user } = await openFolders({ snapshot: snapshotReply({ folders: [work] }) })
		await choose(user, 'Work', 'Rename')
		const name = screen.getByLabelText('Folder name')
		await user.clear(name)
		await user.type(name, 'Office')
		await user.click(within(dialog()).getByRole('button', { name: 'Save' }))
		await waitFor(() => expect(sentOf(sent, 'folder.rename')).toEqual([{ kind: 'folder.rename', accountId: 'a1', id: 'w', name: 'Office' }]))
	})

	it('keeps the rename dialog open on a write lock', async () => {
		const { user } = await openFolders({ snapshot: snapshotReply({ folders: [work] }), replies: { 'folder.rename': { ok: false, error: { code: 'write_locked' } } } })
		await choose(user, 'Work', 'Rename')
		await user.type(screen.getByLabelText('Folder name'), '2')
		await user.click(within(dialog()).getByRole('button', { name: 'Save' }))
		expect(await within(dialog()).findByText('Vault is temporarily locked for a key migration, try again later')).toBeTruthy()
		expect((screen.getByLabelText('Folder name') as HTMLInputElement).value).toBe('Work2')
	})

	it('deletes an empty folder with a plain delete', async () => {
		const { sent, user } = await openFolders({ snapshot: snapshotReply({ folders: [old], items: [] }) })
		await choose(user, 'Old', 'Delete')
		expect(await within(dialog()).findByText('Delete Old? This cannot be undone.')).toBeTruthy()
		await user.click(within(dialog()).getByRole('button', { name: 'Delete' }))
		await waitFor(() => expect(sentOf(sent, 'folder.delete')).toEqual([{ kind: 'folder.delete', accountId: 'a1', id: 'o', plan: { mode: 'plain' } }]))
	})

	it('shows the children read it kept, and drops an error once a read succeeds', async () => {
		let reads = 0
		const { user } = await openFolders({
			snapshot: snapshotReply({ folders: [old], items: [] }),
			replies: { 'folder.children': () => (++reads === 1 ? { ok: false, error: { code: 'server_error' } } : { ok: true, value: { directSecretCount: 0, subfolders: [] } }) },
		})
		await choose(user, 'Old', 'Delete')
		// Under StrictMode the failing read belongs to the first mount, whose reply is dropped.
		if (mounts() === 1) await user.click(within(await within(dialog()).findByRole('alert')).getByRole('button', { name: 'Try again' }))
		expect(await within(dialog()).findByText('Delete Old? This cannot be undone.')).toBeTruthy()
		expect(within(dialog()).queryByRole('alert')).toBeNull()
	})

	it('ignores a children reply that arrives after its read was dropped', async () => {
		let answerFirst!: (reply: unknown) => void
		let reads = 0
		const { user } = await openFolders({
			snapshot: snapshotReply({ folders: [old], items: [] }),
			replies: {
				'folder.children': () => (++reads === 1
					? new Promise((resolve) => (answerFirst = resolve))
					: { ok: true, value: { directSecretCount: 0, subfolders: [] } }),
			},
		})
		await choose(user, 'Old', 'Delete')
		const cascade = 'Delete Old and its 3 items, or move them to No folder?'
		if (mounts() === 2) await within(dialog()).findByText('Delete Old? This cannot be undone.')
		await act(async () => answerFirst({ ok: true, value: { directSecretCount: 3, subfolders: [] } }))
		if (mounts() === 2) {
			expect(within(dialog()).getByText('Delete Old? This cannot be undone.')).toBeTruthy()
			expect(within(dialog()).queryByText(cascade)).toBeNull()
		} else {
			expect(await within(dialog()).findByText(cascade)).toBeTruthy()
		}
	})

	it('counts a leaf folder\'s items on the server, archived and trashed ones included', async () => {
		// The cache holds 1 live item; the server counts 3.
		const { sent, user } = await openFolders({
			snapshot: snapshotReply({ folders: [old], items: [itemMeta({ folderId: 'o' })] }),
			replies: { 'folder.children': { ok: true, value: { directSecretCount: 3, subfolders: [] } } },
		})
		await choose(user, 'Old', 'Delete')
		expect(await within(dialog()).findByText('Delete Old and its 3 items, or move them to No folder?')).toBeTruthy()
		expect(dialog().textContent).toContain('Archived and trashed items count too.')
		expect(sentOf(sent, 'folder.children')).toEqual(Array(mounts()).fill({ kind: 'folder.children', accountId: 'a1', id: 'o' }))
		await user.click(within(dialog()).getByRole('button', { name: 'Move items to parent' }))
		await waitFor(() => expect(sentOf(sent, 'folder.delete')).toEqual([{ kind: 'folder.delete', accountId: 'a1', id: 'o', plan: { mode: 'cascade', cascade: 'move' } }]))
	})

	it('re-reads the children when an item landed after they were read', async () => {
		let landed = 0
		const { sent, user } = await openFolders({
			snapshot: snapshotReply({ folders: [old], items: [] }),
			replies: {
				'folder.delete': (message: { plan: { mode: string } }) => {
					if (message.plan.mode !== 'plain') return { ok: true, value: null }
					landed = 1
					return { ok: false, error: { code: 'conflict' } }
				},
				'folder.children': () => ({ ok: true, value: { directSecretCount: landed, subfolders: [] } }),
			},
		})
		await choose(user, 'Old', 'Delete')
		await user.click(await within(dialog()).findByRole('button', { name: 'Delete' }))
		expect(await within(dialog()).findByText('Delete Old and its 1 item, or move it to No folder?')).toBeTruthy()
		await user.click(within(dialog()).getByRole('button', { name: 'Delete items too' }))
		await waitFor(() => expect(sentOf(sent, 'folder.delete').at(-1)).toMatchObject({ plan: { mode: 'cascade', cascade: 'delete' } }))
	})

	it('resolves a folder with subfolders, keeping them by default', async () => {
		const children = { directSecretCount: 2, subfolders: [{ id: 'c', name: 'Clients', secretCount: 5, subfolderCount: 1 }] }
		const { sent, user } = await openFolders({ snapshot: snapshotReply({ folders: [work, clients] }), replies: { 'folder.children': { ok: true, value: children } } })
		await choose(user, 'Work', 'Delete')
		const direct = await within(dialog()).findByLabelText('Items in Work, 2 items') as HTMLSelectElement
		expect(direct.value).toBe('move')
		const sub = within(dialog()).getByLabelText('Clients, 5 items, 1 subfolder') as HTMLSelectElement
		expect([...sub.options].map((option) => option.textContent)).toEqual(['Keep', 'Move items to parent', 'Delete'])
		await user.click(within(dialog()).getByRole('button', { name: 'Delete' }))
		await waitFor(() => expect(sentOf(sent, 'folder.delete')).toEqual([{ kind: 'folder.delete', accountId: 'a1', id: 'w', plan: { mode: 'resolution', directSecrets: 'move', subfolders: { c: 'keep' } } }]))
	})

	it('refuses to keep a subfolder whose name is taken one level up, before anything is sent', async () => {
		const children = { directSecretCount: 1, subfolders: [{ id: 'c', name: 'Clients', secretCount: 0, subfolderCount: 0 }] }
		const rootClients: FolderMeta = { id: 'rc', name: 'clients', parentId: null }
		const { sent, user } = await openFolders({ snapshot: snapshotReply({ folders: [work, clients, rootClients] }), replies: { 'folder.children': { ok: true, value: children } } })
		await choose(user, 'Work', 'Delete')
		expect(await within(dialog()).findByText(/^A folder named Clients already exists one level up/)).toBeTruthy()
		const remove = within(dialog()).getByRole('button', { name: 'Delete' }) as HTMLButtonElement
		expect(remove.disabled).toBe(true)
		await user.selectOptions(within(dialog()).getByLabelText('Clients, 0 items, 0 subfolders'), 'move')
		expect(remove.disabled).toBe(false)
		expect(sentOf(sent, 'folder.delete')).toEqual([])
	})

	it('says nothing was deleted, syncs and stops when the server finds the name clash anyway', async () => {
		const children = { directSecretCount: 1, subfolders: [{ id: 'c', name: 'Clients', secretCount: 0, subfolderCount: 0 }] }
		const { sent, user } = await openFolders({
			snapshot: snapshotReply({ folders: [work, clients] }),
			replies: { 'folder.children': { ok: true, value: children }, 'folder.delete': { ok: false, error: { code: 'conflict' } } },
		})
		await choose(user, 'Work', 'Delete')
		await user.click(await within(dialog()).findByRole('button', { name: 'Delete' }))
		expect(await within(dialog()).findByText(/^A folder you chose to keep has the same name.*, so nothing was deleted\./)).toBeTruthy()
		expect(sentOf(sent, 'folder.delete')).toHaveLength(1)
		expect(sentOf(sent, 'folder.children')).toHaveLength(mounts())
		await waitFor(() => expect(sentOf(sent, 'vault.sync')).toHaveLength(1))
	})

	it('asks again when a subfolder appeared since the children were read', async () => {
		let appeared = 0
		const { sent, user } = await openFolders({
			snapshot: snapshotReply({ folders: [work, clients] }),
			replies: {
				'folder.children': () => ({ ok: true, value: { directSecretCount: 0, subfolders: [{ id: 'c', name: 'Clients', secretCount: 0, subfolderCount: appeared }] } }),
				'folder.delete': () => {
					appeared = 1
					return { ok: false, error: { code: 'invalid' } }
				},
			},
		})
		await choose(user, 'Work', 'Delete')
		await within(dialog()).findByLabelText('Clients, 0 items, 0 subfolders')
		await user.click(within(dialog()).getByRole('button', { name: 'Delete' }))
		expect(await within(dialog()).findByText('This folder changed. Check the choices again.')).toBeTruthy()
		await user.click(within(dialog()).getByRole('button', { name: 'Try again' }))
		expect(await within(dialog()).findByLabelText('Clients, 0 items, 1 subfolder')).toBeTruthy()
		expect(sentOf(sent, 'folder.children')).toHaveLength(mounts() + 1)
	})

	it('disables every change while offline but still shows the tree', async () => {
		const sync = { syncing: false, syncedAt: new Date().toISOString(), offline: true, lastError: 'network' as const }
		await openFolders({ snapshot: snapshotReply({ folders: [work], sync }) })
		expect(screen.getByText('You are offline. Changes need a connection to Keepiq.')).toBeTruthy()
		expect((screen.getByRole('button', { name: 'Add folder' }) as HTMLButtonElement).disabled).toBe(true)
		expect((screen.getByRole('button', { name: 'More, Work' }) as HTMLButtonElement).disabled).toBe(true)
		expect(row('Work')).toBeTruthy()
	})
})
