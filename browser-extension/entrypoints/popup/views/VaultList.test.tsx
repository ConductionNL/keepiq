// @vitest-environment happy-dom
import { cleanup, render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { DecryptedItem } from '@/src/messages'
import type { CurrentTab } from '../hooks/useCurrentTab'
import { ShellContext } from '../shell-context'
import { everythingVisible, fakeBackground, fakeClipboard, itemMeta, sentKinds, snapshotReply, types, type UnlockedSnapshot } from '../testing'
import { VaultList } from './VaultList'

afterEach(cleanup)
beforeEach(everythingVisible)

const githubTab: CurrentTab = { id: 7, windowId: 1, url: 'https://github.com/login', host: 'github.com' }

function renderList(snapshot: UnlockedSnapshot, options: { tab?: CurrentTab; decrypted?: Record<string, DecryptedItem> } = {}) {
	const sent = fakeBackground({ snapshot, decrypted: options.decrypted })
	const toast = vi.fn()
	const launch = vi.fn()
	const onOpen = vi.fn()
	const syncNow = vi.fn()
	render(
		<ShellContext.Provider value={{ refreshState: vi.fn(), toast, launch }}>
			<VaultList snapshot={snapshot} tab={options.tab ?? { id: 1, windowId: 1, url: undefined, host: null }} syncNow={syncNow} onOpen={onOpen} />
		</ShellContext.Provider>,
	)
	return { sent, toast, launch, onOpen, syncNow }
}

/** Names of the item cards in the main list, in order. */
function listed(): string[] {
	const section = screen.queryByRole('region', { name: 'Items' })
	if (!section) return []
	return within(section).queryAllByRole('listitem').map((row) => row.querySelector('.item__name')!.textContent!)
}

describe('layout and sorting', () => {
	it('renders search, folder, type chips, then the list sorted by name', () => {
		renderList(snapshotReply({ items: [itemMeta({ id: '1', name: 'bank' }), itemMeta({ id: '2', name: 'Amazon' }), itemMeta({ id: '3', name: 'apple' })] }))
		const order = [screen.getByRole('searchbox'), screen.getByRole('combobox', { name: 'Folder' }), screen.getByRole('group', { name: 'Type' }), screen.getByRole('region', { name: 'Items' })]
		for (let i = 1; i < order.length; i++) expect(order[i - 1]!.compareDocumentPosition(order[i]!) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
		expect(listed()).toEqual(['Amazon', 'apple', 'bank'])
	})

	it('breaks name ties by id', async () => {
		const { launch } = renderList(snapshotReply({ items: [itemMeta({ id: 'b', name: 'Mail', url: 'b.test' }), itemMeta({ id: 'a', name: 'mail', url: 'a.test' })] }))
		await userEvent.setup().click(screen.getAllByRole('button', { name: /^Launch/ })[0]!)
		expect(launch).toHaveBeenCalledWith('https://a.test/')
	})
})

describe('search', () => {
	it('matches the url as well as the name', async () => {
		renderList(snapshotReply({ items: [itemMeta({ id: '1', name: 'Work mail', url: 'https://mail.example.com' }), itemMeta({ id: '2', name: 'Other', url: 'other.test' })] }))
		await userEvent.setup().type(screen.getByRole('searchbox'), 'EXAMPLE')
		expect(listed()).toEqual(['Work mail'])
	})

	it('does not search usernames and says what it covers', async () => {
		renderList(snapshotReply({ items: [itemMeta({ id: '1', name: 'Mail', url: 'mail.test' })] }), { decrypted: { 1: { login: 'alice' } } })
		await userEvent.setup().type(screen.getByRole('searchbox'), 'alice')
		expect(listed()).toEqual([])
		expect(screen.getByText('No items match')).toBeTruthy()
		expect(screen.getByText('Search covers names and websites.')).toBeTruthy()
	})
})

describe('folders', () => {
	const folders = [{ id: 'w', name: 'Work', parentId: null }, { id: 'c', name: 'Clients', parentId: 'w' }, { id: 'a', name: 'Archive', parentId: null }]

	it('lists All items, the tree depth-first, then No folder', () => {
		renderList(snapshotReply({ folders }))
		const options = within(screen.getByRole('combobox', { name: 'Folder' })).getAllByRole('option').map((o) => o.textContent)
		expect(options).toEqual(['All items', 'Archive', 'Work', '   Clients', 'No folder'])
	})

	it('shows a folder’s own items, not its descendants’', async () => {
		renderList(snapshotReply({
			folders,
			items: [itemMeta({ id: '1', name: 'In work', folderId: 'w' }), itemMeta({ id: '2', name: 'In clients', folderId: 'c' }), itemMeta({ id: '3', name: 'Loose', folderId: null })],
		}))
		const user = userEvent.setup()
		await user.selectOptions(screen.getByRole('combobox', { name: 'Folder' }), 'w')
		expect(listed()).toEqual(['In work'])
		await user.selectOptions(screen.getByRole('combobox', { name: 'Folder' }), 'none')
		expect(listed()).toEqual(['Loose'])
	})
})

describe('type chips', () => {
	it('filters to one type', async () => {
		renderList(snapshotReply({ items: [itemMeta({ id: '1', name: 'Code', typeId: 't-totp' }), itemMeta({ id: '2', name: 'Site' })] }))
		const user = userEvent.setup()
		await user.click(screen.getByRole('button', { name: 'TOTP' }))
		expect(screen.getByRole('button', { name: 'TOTP' }).getAttribute('aria-pressed')).toBe('true')
		expect(listed()).toEqual(['Code'])
	})

	it('reaches other types through More and shows the chosen label', async () => {
		renderList(snapshotReply({
			types: [...types, { id: 't-lic', name: 'licence', label: 'Licence key' }],
			items: [itemMeta({ id: '1', name: 'Office', typeId: 't-lic' }), itemMeta({ id: '2', name: 'Site' })],
		}))
		const user = userEvent.setup()
		await user.click(screen.getByRole('button', { name: 'More' }))
		await user.click(screen.getByRole('menuitem', { name: 'Licence key' }))
		expect(screen.getByRole('button', { name: 'Licence key' }).getAttribute('aria-pressed')).toBe('true')
		expect(listed()).toEqual(['Office'])
	})

	it('clears every filter at once', async () => {
		renderList(snapshotReply({ items: [itemMeta({ id: '1', name: 'Site' })] }))
		const user = userEvent.setup()
		await user.click(screen.getByRole('button', { name: 'Card' }))
		await user.type(screen.getByRole('searchbox'), 'x')
		await user.click(screen.getByRole('button', { name: 'Clear filters' }))
		expect((screen.getByRole('searchbox') as HTMLInputElement).value).toBe('')
		expect(screen.getByRole('button', { name: 'All' }).getAttribute('aria-pressed')).toBe('true')
		expect((screen.getByRole('combobox', { name: 'Folder' }) as HTMLSelectElement).value).toBe('all')
		expect(listed()).toEqual(['Site'])
	})
})

describe('autofill suggestions', () => {
	it('lists the matching items above the list and ignores the filters', async () => {
		renderList(snapshotReply({ items: [itemMeta({ id: '1', name: 'GitHub' }), itemMeta({ id: '2', name: 'Bank', url: 'bank.test' })], suggestionIds: ['1'] }), { tab: githubTab })
		const section = screen.getByRole('region', { name: 'Autofill suggestions' })
		expect(within(section).getByText('GitHub')).toBeTruthy()
		await userEvent.setup().type(screen.getByRole('searchbox'), 'bank')
		expect(within(section).getByText('GitHub')).toBeTruthy()
	})

	it('says when nothing matches the tab', () => {
		renderList(snapshotReply({ suggestionIds: [] }), { tab: { id: 1, windowId: 1, url: 'https://nothing.test', host: 'nothing.test' } })
		expect(screen.getByText('No items for nothing.test')).toBeTruthy()
	})

	it('shows a blocked match with its badge and no copy', () => {
		renderList(snapshotReply({ items: [itemMeta({ id: '1', blocked: true, hasLogin: false, blockedReason: 'suite_revoked' })], suggestionIds: ['1'] }), { tab: githubTab })
		const section = screen.getByRole('region', { name: 'Autofill suggestions' })
		expect(within(section).getByText('Blocked')).toBeTruthy()
		expect((within(section).getByRole('button', { name: 'Copy, GitHub' }) as HTMLButtonElement).disabled).toBe(true)
	})
})

describe('item cards', () => {
	it('shows the decrypted login, decrypting only rows that have one', async () => {
		const { sent } = renderList(snapshotReply({ items: [itemMeta({ id: '1', name: 'GitHub' }), itemMeta({ id: '2', name: 'Diary', typeId: 't-note', hasLogin: false, url: null })] }), { decrypted: { 1: { login: 'alice@example.com' } } })
		expect(await screen.findByText('alice@example.com')).toBeTruthy()
		expect(within(screen.getByRole('region', { name: 'Items' })).getByText('Note')).toBeTruthy()
		expect(sent).toHaveBeenCalledWith({ kind: 'item.decrypt', ids: ['1'], fields: ['login'], passive: true })
	})

	it('launches with https added and hides Launch without a url', async () => {
		const { launch } = renderList(snapshotReply({ items: [itemMeta({ id: '1', name: 'Hub', url: 'github.com' }), itemMeta({ id: '2', name: 'Plain', url: null })] }))
		await userEvent.setup().click(screen.getByRole('button', { name: 'Launch Hub' }))
		expect(launch).toHaveBeenCalledWith('https://github.com/')
		expect(screen.queryByRole('button', { name: 'Launch Plain' })).toBeNull()
	})

	it('never launches a non-web url', () => {
		renderList(snapshotReply({ items: [itemMeta({ id: '1', name: 'Evil', url: 'javascript:alert(1)' })] }))
		expect(screen.queryByRole('button', { name: 'Launch Evil' })).toBeNull()
	})

	it('copies the password, toasts and tells the background', async () => {
		// After setup, which installs its own clipboard.
		const user = userEvent.setup()
		const writeText = fakeClipboard()
		const { sent, toast } = renderList(snapshotReply(), { decrypted: { i1: { key: 'hunter2' } } })
		await user.click(screen.getByRole('button', { name: 'Copy, GitHub' }))
		expect(screen.getAllByRole('menuitem').map((m) => m.textContent)).toEqual(['Copy username', 'Copy password'])
		await user.click(screen.getByRole('menuitem', { name: 'Copy password' }))
		await vi.waitFor(() => expect(toast).toHaveBeenCalledWith('Password copied'))
		expect(writeText).toHaveBeenCalledWith('hunter2')
		expect(sentKinds(sent)).toContain('clipboard.copied')
	})

	it('copies the current code of a TOTP item', async () => {
		const user = userEvent.setup()
		const writeText = fakeClipboard()
		renderList(snapshotReply({ items: [itemMeta({ typeId: 't-totp', hasLogin: false })] }), { decrypted: { i1: { key: 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ' } } })
		await user.click(screen.getByRole('button', { name: 'Copy, GitHub' }))
		expect(screen.getAllByRole('menuitem').map((m) => m.textContent)).toEqual(['Copy verification code'])
		await user.click(screen.getByRole('menuitem', { name: 'Copy verification code' }))
		await vi.waitFor(() => expect(writeText).toHaveBeenCalledWith(expect.stringMatching(/^\d{6}$/)))
	})

	it('says so when the clipboard refuses', async () => {
		const user = userEvent.setup()
		fakeClipboard(true)
		const { sent, toast } = renderList(snapshotReply(), { decrypted: { i1: { key: 'hunter2' } } })
		await user.click(screen.getByRole('button', { name: 'Copy, GitHub' }))
		await user.click(screen.getByRole('menuitem', { name: 'Copy password' }))
		await vi.waitFor(() => expect(toast).toHaveBeenCalledWith('Could not copy'))
		expect(sentKinds(sent)).not.toContain('clipboard.copied')
	})

	it('offers View and the edit actions disabled until a later update', async () => {
		const { onOpen } = renderList(snapshotReply())
		const user = userEvent.setup()
		await user.click(screen.getByRole('button', { name: 'More, GitHub' }))
		for (const label of ['Edit', 'Clone', 'Move to folder', 'Delete']) {
			const entry = screen.getByRole('menuitem', { name: label })
			expect(entry.getAttribute('aria-disabled')).toBe('true')
			expect(entry.getAttribute('title')).toBe('Available in a later update')
		}
		await user.click(screen.getByRole('menuitem', { name: 'View' }))
		expect(onOpen).toHaveBeenCalledWith('i1')
	})

	it('closes a menu on Escape and returns focus to its button', async () => {
		renderList(snapshotReply())
		const user = userEvent.setup()
		await user.click(screen.getByRole('button', { name: 'More, GitHub' }))
		expect(document.activeElement?.textContent).toBe('View')
		await user.keyboard('{ArrowDown}')
		expect(document.activeElement?.textContent).toBe('Edit')
		await user.keyboard('{Escape}')
		expect(screen.queryByRole('menu')).toBeNull()
		expect(document.activeElement).toBe(screen.getByRole('button', { name: 'More, GitHub' }))
	})
})

describe('states', () => {
	it('shows the first sync with the filters inert', () => {
		renderList(snapshotReply({ items: null, sync: { syncing: true, syncedAt: null, offline: false, lastError: null } }))
		expect(screen.getByRole('status').textContent).toBe('Syncing your vault')
		expect((screen.getByRole('searchbox') as HTMLInputElement).disabled).toBe(true)
		expect((screen.getByRole('button', { name: 'All' }) as HTMLButtonElement).disabled).toBe(true)
	})

	it('offers a retry when the first sync failed', async () => {
		const { syncNow } = renderList(snapshotReply({ items: null, sync: { syncing: false, syncedAt: null, offline: true, lastError: 'network' } }))
		expect(screen.getByText('Could not load your vault')).toBeTruthy()
		await userEvent.setup().click(screen.getByRole('button', { name: 'Try again' }))
		expect(syncNow).toHaveBeenCalled()
	})

	it('links to the web app from an empty vault', () => {
		renderList(snapshotReply({ items: [] }))
		expect(screen.getByText('No items in your vault')).toBeTruthy()
		expect(screen.getByRole('link', { name: 'Open the Keepiq web app' }).getAttribute('href')).toBe('https://cloud.example.org/index.php/apps/keepiq/')
	})

	it('explains a vault where everything is blocked, above the blocked cards', () => {
		renderList(snapshotReply({ items: [itemMeta({ id: '1', blocked: true, hasLogin: false, blockedReason: 'suite_revoked' })] }))
		expect(screen.getByText(/All items are blocked\. Its encryption key was revoked\./)).toBeTruthy()
		expect(listed()).toEqual(['GitHub'])
	})

	it('shows the offline banner with the last sync and Sync now', async () => {
		const syncedAt = new Date(Date.now() - 2 * 3_600_000).toISOString()
		const { syncNow } = renderList(snapshotReply({ sync: { syncing: false, syncedAt, offline: true, lastError: 'network' } }))
		expect(screen.getByText('Offline. Last synced 2 hours ago')).toBeTruthy()
		await userEvent.setup().click(screen.getByRole('button', { name: 'Sync now' }))
		expect(syncNow).toHaveBeenCalled()
	})

	it('says the server is busy on a write lock', () => {
		renderList(snapshotReply({ sync: { syncing: false, syncedAt: new Date().toISOString(), offline: false, lastError: 'busy' } }))
		expect(screen.getByText('Server busy, retrying later')).toBeTruthy()
	})
})
