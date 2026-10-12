// @vitest-environment happy-dom
import { act, cleanup, render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import App from '../App'
import { editableItem, everythingVisible, fakeBackground, itemMeta, popupState, sentKinds, sentOf, snapshotReply } from '../testing'

afterEach(cleanup)
beforeEach(everythingVisible)

const title = () => screen.findByRole('heading', { level: 1 })

describe('Shell', () => {
	it('opens unlocked on the Vault tab with pop out and avatar, and no tab host', async () => {
		fakeBackground()
		render(<App />)
		expect((await title()).textContent).toBe('Vault')
		await screen.findByRole('searchbox')
		expect(screen.queryByText('github.com')).toBeNull()
		expect(screen.getByRole('button', { name: 'Pop out' })).toBeTruthy()
		expect(screen.getByRole('button', { name: 'Accounts, signed in as Alice Doe' })).toBeTruthy()
		const tabs = within(screen.getByRole('navigation', { name: 'Main' })).getAllByRole('button')
		expect(tabs.map((tab) => tab.textContent)).toEqual(['Vault', 'Generator', 'Send', 'Settings'])
		expect(tabs[0]!.getAttribute('aria-current')).toBe('page')
	})

	it('switches tabs, shows the placeholder and remembers the tab', async () => {
		const sent = fakeBackground()
		render(<App />)
		await title()
		await userEvent.setup().click(screen.getByRole('button', { name: 'Generator' }))
		expect((await title()).textContent).toBe('Generator')
		expect(screen.getByText('This arrives in a later update.')).toBeTruthy()
		expect(screen.queryByRole('searchbox')).toBeNull()
		expect(sent).toHaveBeenCalledWith({ kind: 'popup.lastTab.set', tab: 'generator' })
	})

	it('reopens on the last tab from its first frame', async () => {
		fakeBackground({ lastTab: 'send' })
		render(<App />)
		expect((await title()).textContent).toBe('Send')
	})

	it('shows no suggestions on a browser page', async () => {
		fakeBackground({ tabUrl: 'chrome://newtab/', snapshot: snapshotReply({ suggestionIds: ['i1'] }) })
		render(<App />)
		await screen.findByRole('searchbox')
		expect(screen.queryByRole('heading', { name: 'Autofill suggestions' })).toBeNull()
	})

	it('has no tab bar while locked or logged out', async () => {
		fakeBackground({ state: popupState({ screen: 'unlock' }) })
		render(<App />)
		expect((await title()).textContent).toBe('Unlock')
		expect(screen.queryByRole('navigation', { name: 'Main' })).toBeNull()
	})

	it('pops out with the tab it came from and closes itself', async () => {
		const sent = fakeBackground()
		const close = vi.spyOn(window, 'close').mockImplementation(() => {})
		render(<App />)
		await title()
		await userEvent.setup().click(screen.getByRole('button', { name: 'Pop out' }))
		expect(sent).toHaveBeenCalledWith({ kind: 'popup.popout', tabId: 7 })
		expect(close).toHaveBeenCalled()
	})

	it('stays open and says so when the window could not be opened', async () => {
		vi.spyOn(console, 'error').mockImplementation(() => {})
		fakeBackground({ unanswered: ['popup.popout'] })
		const close = vi.spyOn(window, 'close').mockImplementation(() => {})
		render(<App />)
		await title()
		await userEvent.setup().click(screen.getByRole('button', { name: 'Pop out' }))
		expect(await screen.findByText('Could not open a new window')).toBeTruthy()
		expect(close).not.toHaveBeenCalled()
	})

	it('goes back to the background state when the snapshot says locked', async () => {
		const sent = fakeBackground({ snapshot: { state: 'locked' } })
		render(<App />)
		await title()
		await vi.waitFor(() => expect(sentKinds(sent).filter((kind) => kind === 'vault.status')).toHaveLength(2))
	})

	it('re-reads the state when the background broadcasts a lock', async () => {
		const sent = fakeBackground()
		const listeners: Array<(message: unknown) => void> = []
		vi.spyOn(browser.runtime.onMessage, 'addListener').mockImplementation((listener) => void listeners.push(listener as never))
		render(<App />)
		await title()
		const before = sentKinds(sent).filter((kind) => kind === 'vault.status').length
		act(() => listeners.forEach((listener) => listener({ kind: 'vault.locked' })))
		await vi.waitFor(() => expect(sentKinds(sent).filter((kind) => kind === 'vault.status').length).toBe(before + 1))
	})

	it('opens an item and comes back to the same filters', async () => {
		fakeBackground({
			snapshot: snapshotReply({ items: [itemMeta({ id: 'a', name: 'Authy', typeId: 't-totp', hasLogin: false }), itemMeta({ id: 'b', name: 'Bank' })] }),
			decrypted: { a: { key: 'GEZDGNBVGY3TQOJQ' } },
		})
		render(<App />)
		const user = userEvent.setup()
		await user.click(await screen.findByRole('button', { name: 'TOTP' }))
		await user.click(screen.getByRole('button', { name: /^Authy/ }))
		expect((await title()).textContent).toBe('View item')
		// The detail covers the tab bar and puts its own actions there.
		expect(screen.queryByRole('navigation', { name: 'Main' })).toBeNull()
		expect(screen.getByRole('button', { name: 'Edit' }).closest('.popup__footer')).toBeTruthy()
		await user.click(screen.getByRole('button', { name: 'Back' }))
		expect((await title()).textContent).toBe('Vault')
		expect(screen.getByRole('navigation', { name: 'Main' })).toBeTruthy()
		expect(screen.getByRole('button', { name: 'TOTP' }).getAttribute('aria-pressed')).toBe('true')
		expect(screen.queryByRole('button', { name: /^Bank/ })).toBeNull()
	})

	it('comes back from the account switcher as it was left', async () => {
		const sent = fakeBackground()
		render(<App />)
		const user = userEvent.setup()
		await user.click(await screen.findByRole('button', { name: 'Send' }))
		await user.click(screen.getByRole('button', { name: 'Accounts, signed in as Alice Doe' }))
		expect((await title()).textContent).toBe('Accounts')
		await user.click(screen.getByRole('button', { name: 'Back' }))
		expect((await title()).textContent).toBe('Send')
		const opens = sent.mock.calls.filter(([m]) => m.kind === 'vault.snapshot' && (m as { opened?: boolean }).opened)
		expect(opens).toHaveLength(1)
	})

	it('keeps no decrypted item in the DOM while the account switcher is open', async () => {
		const sent = fakeBackground({ decrypted: { i1: { login: 'alice', key: 'pw' } } })
		render(<App />)
		const user = userEvent.setup()
		await user.click(await screen.findByRole('button', { name: /^GitHub/ }))
		await screen.findByText('Login credentials')
		await user.click(screen.getByRole('button', { name: 'Accounts, signed in as Alice Doe' }))
		expect((await title()).textContent).toBe('Accounts')
		expect(screen.queryByText('Login credentials')).toBeNull()
		await user.click(screen.getByRole('button', { name: 'Back' }))
		await screen.findByText('Login credentials')
		expect(sent.mock.calls.filter(([m]) => m.kind === 'item.decrypt' && m.fields?.includes('key'))).toHaveLength(2)
	})

	it('decrypts again when an item is reopened', async () => {
		const sent = fakeBackground({ decrypted: { i1: { login: 'alice', key: 'pw' } } })
		render(<App />)
		const user = userEvent.setup()
		const open = async () => user.click(await screen.findByRole('button', { name: /^GitHub/ }))
		const detailDecrypts = () => sent.mock.calls.filter(([m]) => m.kind === 'item.decrypt' && m.fields?.includes('key')).length
		await open()
		await screen.findByText('Login credentials')
		await user.click(screen.getByRole('button', { name: 'Back' }))
		await open()
		await screen.findByText('Login credentials')
		expect(detailDecrypts()).toBe(2)
	})

	it('launches a website in the window of the tab the popup belongs to', async () => {
		fakeBackground()
		const create = vi.spyOn(browser.tabs, 'create').mockResolvedValue({} as never)
		render(<App />)
		await userEvent.setup().click(await screen.findByRole('button', { name: 'Launch GitHub' }))
		expect(create).toHaveBeenCalledWith({ url: 'https://github.com/', windowId: 3 })
	})
})

describe('item writes', () => {
	const footer = () => document.querySelector<HTMLElement>('.popup__footer')!

	it('adds an item over the tab bar and opens it once saved', async () => {
		let items = [itemMeta()]
		const sent = fakeBackground({
			snapshot: () => snapshotReply({ items }),
			replies: {
				'item.create': () => {
					items = [...items, itemMeta({ id: 'new', name: 'Bank', url: null })]
					return { ok: true, value: { id: 'new' } }
				},
			},
		})
		render(<App />)
		const user = userEvent.setup()
		await user.click(await screen.findByRole('button', { name: 'New' }))
		expect((await title()).textContent).toBe('New item')
		expect(screen.queryByRole('navigation', { name: 'Main' })).toBeNull()
		// Opened on github.com/login, so the form starts with that page.
		expect((screen.getByLabelText('Website URL') as HTMLInputElement).value).toBe('https://github.com/login')
		await user.type(screen.getByLabelText('Name'), 'Bank')
		await user.click(within(footer()).getByRole('button', { name: 'Save' }))
		expect((await screen.findByRole('heading', { name: 'Bank' })).textContent).toBe('Bank')
		expect((await title()).textContent).toBe('View item')
		expect(screen.getByRole('status').textContent).toBe('Item saved')
		expect(sentKinds(sent)).toContain('item.create')
	})

	it('prefills only the origin and path of the tab, never its query or fragment', async () => {
		fakeBackground({ tabUrl: 'https://user:pw@app.example.com/callback?token=abc#access_token=xyz' })
		render(<App />)
		await userEvent.setup().click(await screen.findByRole('button', { name: 'New' }))
		expect((screen.getByLabelText('Website URL') as HTMLInputElement).value).toBe('https://app.example.com/callback')
	})

	it('offers New item in the header on the Vault list only', async () => {
		fakeBackground({ decrypted: { i1: { login: 'alice', key: 'pw' } } })
		render(<App />)
		const user = userEvent.setup()
		await screen.findByRole('button', { name: 'New' })
		await user.click(screen.getByRole('button', { name: /^GitHub/ }))
		await screen.findByText('Login credentials')
		expect(screen.queryByRole('button', { name: 'New' })).toBeNull()
		await user.click(screen.getByRole('button', { name: 'Back' }))
		await user.click(screen.getByRole('button', { name: 'Settings' }))
		expect(screen.queryByRole('button', { name: 'New' })).toBeNull()
	})

	it('asks before Back drops typed changes, and not when nothing changed', async () => {
		fakeBackground()
		render(<App />)
		const user = userEvent.setup()
		await user.click(await screen.findByRole('button', { name: 'New' }))
		await user.click(screen.getByRole('button', { name: 'Back' }))
		expect((await title()).textContent).toBe('Vault')
		expect(screen.queryByRole('dialog')).toBeNull()

		await user.click(screen.getByRole('button', { name: 'New' }))
		await user.type(screen.getByLabelText('Name'), 'Draft')
		await user.click(screen.getByRole('button', { name: 'Back' }))
		const dialog = screen.getByRole('dialog', { name: 'Unsaved changes' })
		expect(dialog.textContent).toContain('You have unsaved changes. Discard them?')
		await user.click(within(dialog).getByRole('button', { name: 'Keep editing' }))
		expect((screen.getByLabelText('Name') as HTMLInputElement).value).toBe('Draft')
		await user.click(within(footer()).getByRole('button', { name: 'Cancel' }))
		await user.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Discard' }))
		expect((await title()).textContent).toBe('Vault')
		expect(screen.queryByLabelText('Name')).toBeNull()
	})

	it('edits from detail and returns to it', async () => {
		const sent = fakeBackground({ decrypted: { i1: { login: 'alice', key: 'pw' } }, items: { i1: editableItem() } })
		render(<App />)
		const user = userEvent.setup()
		await user.click(await screen.findByRole('button', { name: /^GitHub/ }))
		await user.click(within(footer()).getByRole('button', { name: 'Edit' }))
		expect((await title()).textContent).toBe('Edit item')
		await user.type(await screen.findByLabelText('Name'), ' work')
		await user.click(within(footer()).getByRole('button', { name: 'Save' }))
		await vi.waitFor(async () => expect((await title()).textContent).toBe('View item'))
		expect(sentKinds(sent)).toContain('item.update')
	})

	it('deletes after the confirm dialog and returns to the list', async () => {
		let items = [itemMeta()]
		const sent = fakeBackground({
			snapshot: () => snapshotReply({ items }),
			replies: { 'item.delete': () => ((items = []), { ok: true, value: null }) },
		})
		render(<App />)
		const user = userEvent.setup()
		await user.click(await screen.findByRole('button', { name: /^GitHub/ }))
		await user.click(within(footer()).getByRole('button', { name: 'Delete' }))
		const dialog = screen.getByRole('dialog', { name: 'Delete item' })
		expect(dialog.textContent).toContain('Move GitHub to the trash?')
		await user.click(within(dialog).getByRole('button', { name: 'Cancel' }))
		expect(sentKinds(sent)).not.toContain('item.delete')
		expect((await title()).textContent).toBe('View item')

		await user.click(within(footer()).getByRole('button', { name: 'Delete' }))
		await user.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Delete' }))
		await vi.waitFor(async () => expect((await title()).textContent).toBe('Vault'))
		expect(screen.getByRole('status').textContent).toBe('Item moved to the trash')
		expect(sentOf(sent, 'item.delete')).toEqual([{ kind: 'item.delete', accountId: 'a1', id: 'i1' }])
	})

	it('keeps the item when the delete does not reach the server', async () => {
		fakeBackground({ replies: { 'item.delete': { ok: false, error: { code: 'offline' } } } })
		render(<App />)
		const user = userEvent.setup()
		await user.click(await screen.findByRole('button', { name: /^GitHub/ }))
		await user.click(within(footer()).getByRole('button', { name: 'Delete' }))
		await user.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Delete' }))
		expect((await within(screen.getByRole('dialog')).findByRole('alert')).textContent).toContain('Could not reach the server')
		expect((await title()).textContent).toBe('View item')
	})

	it('moves into a folder with a folderId-only patch', async () => {
		const sent = fakeBackground({ snapshot: snapshotReply({ folders: [{ id: 'w', name: 'Work', parentId: null }] }) })
		render(<App />)
		const user = userEvent.setup()
		await user.click(await screen.findByRole('button', { name: 'More, GitHub' }))
		await user.click(screen.getByRole('menuitem', { name: 'Move to folder' }))
		await user.click(within(screen.getByRole('dialog', { name: 'Move to folder' })).getByRole('button', { name: 'Work' }))
		await vi.waitFor(() => expect(screen.queryByRole('dialog')).toBeNull())
		expect(sentOf(sent, 'item.update')).toEqual([{ kind: 'item.update', accountId: 'a1', id: 'i1', patch: { folderId: 'w' } }])
		expect(screen.getByRole('status').textContent).toBe('Moved to Work')
	})

	it('names a folder made in the move picker in its toast', async () => {
		let folders: { id: string; name: string; parentId: string | null }[] = []
		fakeBackground({
			snapshot: () => snapshotReply({ folders }),
			replies: { 'folder.create': () => ((folders = [{ id: 'f-new', name: 'Banking', parentId: null }]), { ok: true, value: { id: 'f-new' } }) },
		})
		render(<App />)
		const user = userEvent.setup()
		await user.click(await screen.findByRole('button', { name: 'More, GitHub' }))
		await user.click(screen.getByRole('menuitem', { name: 'Move to folder' }))
		await user.click(screen.getByRole('button', { name: 'New folder' }))
		await user.type(screen.getByLabelText('Folder name'), 'Banking')
		await user.click(within(screen.getByRole('dialog', { name: 'New folder' })).getByRole('button', { name: 'Save' }))
		await vi.waitFor(() => expect(screen.getByRole('status').textContent).toBe('Moved to Banking'))
	})

	it('shows a failed move after New folder in the picker, so the folder is not created twice', async () => {
		const sent = fakeBackground({ replies: { 'item.update': { ok: false, error: { code: 'server_error' } } } })
		render(<App />)
		const user = userEvent.setup()
		await user.click(await screen.findByRole('button', { name: 'More, GitHub' }))
		await user.click(screen.getByRole('menuitem', { name: 'Move to folder' }))
		await user.click(screen.getByRole('button', { name: 'New folder' }))
		await user.type(screen.getByLabelText('Folder name'), 'Banking')
		await user.click(within(screen.getByRole('dialog', { name: 'New folder' })).getByRole('button', { name: 'Save' }))
		const picker = await screen.findByRole('dialog', { name: 'Move to folder' })
		expect(await within(picker).findByRole('alert')).toBeTruthy()
		expect(screen.queryByRole('dialog', { name: 'New folder' })).toBeNull()
		expect(sentOf(sent, 'folder.create')).toHaveLength(1)
	})

	it('asks before the account switcher drops typed changes, and closes the form for it', async () => {
		fakeBackground()
		render(<App />)
		const user = userEvent.setup()
		const avatar = () => screen.getByRole('button', { name: 'Accounts, signed in as Alice Doe' })
		await user.click(await screen.findByRole('button', { name: 'New' }))
		await user.type(screen.getByLabelText('Name'), 'Draft')
		await user.click(avatar())
		await user.click(within(screen.getByRole('dialog', { name: 'Unsaved changes' })).getByRole('button', { name: 'Keep editing' }))
		expect((await title()).textContent).toBe('New item')
		await user.click(avatar())
		await user.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Discard' }))
		expect((await title()).textContent).toBe('Accounts')
		expect(screen.queryByLabelText('Name')).toBeNull()
		await user.click(screen.getByRole('button', { name: 'Back' }))
		expect((await title()).textContent).toBe('Vault')
	})
})
