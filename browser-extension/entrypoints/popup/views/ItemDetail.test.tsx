// @vitest-environment happy-dom
import { cleanup, render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { DecryptedItem, FolderMeta, ItemMeta } from '@/src/messages'
import { DEFAULT_SHELL, ShellContext } from '../shell-context'
import { fakeBackground, itemMeta, sentKinds, types } from '../testing'
import { ItemDetail } from './ItemDetail'

// Shell's footer bar, where the detail portals Edit and Delete.
let footer: HTMLElement
beforeEach(() => {
	footer = document.body.appendChild(document.createElement('div'))
})
afterEach(() => {
	cleanup()
	footer.remove()
})

const folders: FolderMeta[] = [{ id: 'w', name: 'Work', parentId: null }, { id: 'c', name: 'Clients', parentId: 'w' }]

function renderDetail(item: ItemMeta, decrypted?: DecryptedItem, decryptError?: 'locked' | 'use_only', offline = false) {
	const sent = fakeBackground({ decrypted: decrypted && { [item.id]: decrypted }, decryptError })
	const refreshState = vi.fn()
	const launch = vi.fn()
	const items = { add: vi.fn(), edit: vi.fn(), clone: vi.fn(), move: vi.fn(), remove: vi.fn() }
	const view = render(
		<ShellContext.Provider value={{ ...DEFAULT_SHELL, refreshState, launch, footer, items, offline }}>
			<ItemDetail item={item} type={types.find((t) => t.id === item.typeId)} folders={folders} webAppUrl="https://cloud.example.org/index.php/apps/keepiq/" />
		</ShellContext.Provider>,
	)
	return { sent, refreshState, launch, items, view }
}

const section = (title: string) => screen.getByRole('heading', { name: title }).closest('section')!

describe('header', () => {
	it('shows name, type and folder path before anything decrypts', () => {
		renderDetail(itemMeta({ folderId: 'c' }))
		expect(screen.getByRole('heading', { name: 'GitHub' })).toBeTruthy()
		expect(screen.getByText('Login · Work / Clients')).toBeTruthy()
	})

	it('says No folder', () => {
		renderDetail(itemMeta())
		expect(screen.getByText('Login · No folder')).toBeTruthy()
	})
})

describe('login credentials', () => {
	it('shows the username and a masked password that reveals in monospace', async () => {
		const { sent } = renderDetail(itemMeta(), { login: 'alice', key: 'hunter2' })
		await screen.findByRole('heading', { name: 'Login credentials' })
		expect(sent).toHaveBeenCalledWith({ kind: 'item.decrypt', ids: ['i1'], fields: ['login', 'key', 'additionalFields'], passive: false })
		const credentials = section('Login credentials')
		expect(within(credentials).getByText('alice')).toBeTruthy()
		expect(within(credentials).queryByText('hunter2')).toBeNull()
		await userEvent.setup().click(within(credentials).getByRole('button', { name: 'Show password' }))
		expect(within(credentials).getByText('hunter2').className).toContain('field-row__value--mono')
		expect(within(credentials).getByRole('button', { name: 'Hide password' })).toBeTruthy()
	})

	it('names the value after the type when there is no username', async () => {
		renderDetail(itemMeta({ typeId: 't-api_key', hasLogin: false }), { key: 'sk_live' })
		const credentials = await screen.findByRole('heading', { name: 'Login credentials' }).then((h) => h.closest('section')!)
		expect(within(credentials).queryByText('Username')).toBeNull()
		expect(within(credentials).getByText('API key')).toBeTruthy()
		expect(within(credentials).getByRole('button', { name: 'Show API key' })).toBeTruthy()
	})
	it('decrypts again when a sync changed the open item', async () => {
		const { sent, view } = renderDetail(itemMeta(), { login: 'alice', key: 'old' })
		await screen.findByRole('heading', { name: 'Login credentials' })
		const decrypts = () => sentKinds(sent).filter((kind) => kind === 'item.decrypt').length
		expect(decrypts()).toBe(1)
		view.rerender(
			<ShellContext.Provider value={{ ...DEFAULT_SHELL, footer }}>
				<ItemDetail item={itemMeta({ updatedAt: '2026-04-01T00:00:00Z' })} type={types[0]} folders={folders} webAppUrl="https://cloud.example.org/index.php/apps/keepiq/" />
			</ShellContext.Provider>,
		)
		await vi.waitFor(() => expect(decrypts()).toBe(2))
		// Not the user's doing, so it does not reset the vault timeout.
		expect(sent.mock.calls.at(-1)![0]).toMatchObject({ kind: 'item.decrypt', passive: true })
	})
})

describe('TOTP', () => {
	it('shows a grouped code with a countdown', async () => {
		renderDetail(itemMeta({ typeId: 't-totp', hasLogin: false }), { key: 'otpauth://totp/Example:alice?secret=GEZDGNBVGY3TQOJQ&digits=6&period=30' })
		await vi.waitFor(() => expect(document.querySelector('.totp')!.textContent).toMatch(/^\d{3} \d{3}$/))
		expect(screen.getByRole('timer').getAttribute('aria-label')).toMatch(/^Changes in \d+ seconds?$/)
		expect(screen.queryByRole('heading', { name: 'Login credentials' })).toBeNull()
	})

	it('shows no code for an invalid seed', async () => {
		renderDetail(itemMeta({ typeId: 't-totp', hasLogin: false }), { key: 'not-a-seed' })
		expect(await screen.findByText('Invalid authenticator key')).toBeTruthy()
		expect(screen.queryByRole('button', { name: 'Copy verification code' })).toBeNull()
	})
})

describe('additional fields and notes', () => {
	it('masks every field and routes notes to Notes', async () => {
		renderDetail(itemMeta(), { key: 'pw', additionalFields: JSON.stringify({ 'Recovery code': 'r-1', PIN: '1234', Notes: 'line one\nline two' }) })
		const fields = await screen.findByRole('heading', { name: 'Additional fields' }).then((h) => h.closest('section')!)
		expect(within(fields).getByText('Recovery code')).toBeTruthy()
		expect(within(fields).getByText('PIN')).toBeTruthy()
		expect(within(fields).queryByText('r-1')).toBeNull()
		expect(within(fields).getAllByRole('button', { name: /^Show / })).toHaveLength(2)
		expect(within(section('Notes')).getByText(/line one/).textContent).toBe('line one\nline two')
	})

	it('says when the fields do not parse', async () => {
		renderDetail(itemMeta(), { key: 'pw', additionalFields: '[1,2]' })
		expect(await screen.findByText('Could not read additional fields')).toBeTruthy()
	})

	it('shows a note’s text and no credentials', async () => {
		renderDetail(itemMeta({ typeId: 't-note', hasLogin: false, url: null }), { key: 'a\nb\nc' })
		expect((await screen.findByText(/^a/)).textContent).toBe('a\nb\nc')
		expect(screen.queryByRole('heading', { name: 'Login credentials' })).toBeNull()
		expect(screen.queryByRole('heading', { name: 'Website' })).toBeNull()
	})
})

describe('website', () => {
	it('launches the url in a new tab', async () => {
		const { launch } = renderDetail(itemMeta({ url: 'https://example.com' }), { key: 'pw' })
		await userEvent.setup().click(await screen.findByRole('button', { name: 'Launch GitHub' }))
		expect(launch).toHaveBeenCalledWith('https://example.com/')
	})
})

describe('card and identity', () => {
	it('masks the number to its last four with the brand, and CVV and PIN', async () => {
		renderDetail(itemMeta({ typeId: 't-card', hasLogin: false, url: null }), { key: JSON.stringify({ number: '4111 1111 1111 1111', expiry: '08/29', cvv: '123', pin: '0000', cardholder: 'Alice Doe' }) })
		const card = await screen.findByRole('heading', { name: 'Card details' }).then((h) => h.closest('section')!)
		expect(within(card).getByText('•••• •••• •••• 1111')).toBeTruthy()
		expect(within(card).getByText('Number · Visa')).toBeTruthy()
		expect(within(card).getByText('Alice Doe')).toBeTruthy()
		expect(within(card).getByText('08/29')).toBeTruthy()
		expect(within(card).queryByText('123')).toBeNull()
		expect(within(card).queryByText('0000')).toBeNull()
	})

	it('shows identity fields plainly and masks the BSN', async () => {
		renderDetail(itemMeta({ typeId: 't-identity', hasLogin: false, url: null }), { key: JSON.stringify({ firstName: 'Alice', lastName: 'Doe', address: 'Main 1', phone: '0612345678', email: 'a@x.nl', bsn: '123456782' }) })
		const identity = await screen.findByRole('heading', { name: 'Identity' }).then((h) => h.closest('section')!)
		for (const value of ['Alice', 'Doe', 'Main 1', '0612345678', 'a@x.nl']) expect(within(identity).getByText(value)).toBeTruthy()
		expect(within(identity).queryByText('123456782')).toBeNull()
		expect(within(identity).getByRole('button', { name: 'Show BSN' })).toBeTruthy()
	})

	it('says when the payload does not parse', async () => {
		renderDetail(itemMeta({ typeId: 't-card', hasLogin: false, url: null }), { key: 'garbage' })
		expect(await screen.findByText('Could not read this item')).toBeTruthy()
	})
})

describe('passkey', () => {
	it('shows who and where, the not-supported note, and never the private key', async () => {
		const { view } = renderDetail(itemMeta({ typeId: 't-passkey', hasLogin: false, url: null }), {
			key: JSON.stringify({ credentialId: 'c', rpId: 'example.com', rpName: 'Example', userName: 'alice', privateKey: 'PRIVATE-KEY-MATERIAL', createdAt: '2026-01-05T10:00:00Z' }),
		})
		expect(await screen.findByText('Example (example.com)')).toBeTruthy()
		expect(screen.getByText('alice')).toBeTruthy()
		expect(screen.getByText('Signing in with this passkey is not yet supported in the extension')).toBeTruthy()
		expect(view.container.innerHTML).not.toContain('PRIVATE-KEY-MATERIAL')
	})

	it('says when the passkey does not parse', async () => {
		renderDetail(itemMeta({ typeId: 't-passkey', hasLogin: false, url: null }), { key: '{' })
		expect(await screen.findByText('Could not read this passkey')).toBeTruthy()
	})
})

describe('blocked item', () => {
	it('shows the reason and the web app, and never asks to decrypt', () => {
		const { sent } = renderDetail(itemMeta({ blocked: true, hasLogin: false, blockedReason: 'suite_revoked', migrationError: 'Key migration failed' }))
		expect(screen.getByText(/Its encryption key was revoked/)).toBeTruthy()
		expect(screen.getByText(/Key migration failed/)).toBeTruthy()
		expect(screen.getByRole('link', { name: 'Open the Keepiq web app' })).toBeTruthy()
		expect(screen.queryByRole('heading', { name: 'Login credentials' })).toBeNull()
		expect(sentKinds(sent)).not.toContain('item.decrypt')
	})

	it('says it is blocked when the cached row has no reason', () => {
		renderDetail(itemMeta({ blocked: true, hasLogin: false }))
		expect(screen.getByText(/This item is blocked\./)).toBeTruthy()
		expect(screen.getByRole('link', { name: 'Open the Keepiq web app' })).toBeTruthy()
	})
})

describe('use-only copy', () => {
	it('asks for the login only and shows no value, reveal or copy beyond it', async () => {
		const { sent, view } = renderDetail(itemMeta({ useOnly: true }), { login: 'bob', key: 'hunter2', additionalFields: JSON.stringify({ PIN: '1234' }) })
		const credentials = await screen.findByRole('heading', { name: 'Login credentials' }).then((h) => h.closest('section')!)
		expect(sent).toHaveBeenCalledWith({ kind: 'item.decrypt', ids: ['i1'], fields: ['login'], passive: false })
		expect(within(credentials).getByText('bob')).toBeTruthy()
		expect(within(credentials).getByText("Its owner doesn't let you view or copy this.")).toBeTruthy()
		expect(within(credentials).queryByText('Password')).toBeNull()
		expect(screen.queryAllByRole('button', { name: /^Show / })).toEqual([])
		expect(screen.queryAllByRole('button', { name: /^Copy / }).map((b) => b.getAttribute('aria-label'))).toEqual(['Copy username', 'Copy website'])
		expect(screen.queryByRole('heading', { name: 'Additional fields' })).toBeNull()
		expect(view.container.innerHTML).not.toMatch(/hunter2|1234/)
	})

	it('shows no code or payload, whatever its type', async () => {
		for (const typeId of ['t-totp', 't-card', 't-note']) {
			renderDetail(itemMeta({ typeId, hasLogin: false, useOnly: true }), { key: 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ' })
			expect(await screen.findByText("Its owner doesn't let you view or copy this.")).toBeTruthy()
			expect(document.querySelector('.totp')).toBeNull()
			expect(document.body.innerHTML).not.toContain('GEZDGNBVGY3TQOJQ')
			cleanup()
		}
	})

	it('says so when the background refuses a copy that became use-only', async () => {
		renderDetail(itemMeta(), undefined, 'use_only')
		expect((await screen.findByRole('alert')).textContent).toBe("Its owner doesn't let you view or copy this.")
	})
})

describe('metadata and actions', () => {
	it('shows created, updated and expires', () => {
		renderDetail(itemMeta({ expiresAt: '2027-01-01T00:00:00Z' }))
		const history = section('Item history')
		expect(within(history).getByText('Created')).toBeTruthy()
		expect(within(history).getByText(new Date('2026-01-05T10:00:00Z').toLocaleString())).toBeTruthy()
		expect(within(history).getByText(new Date('2026-03-02T10:00:00Z').toLocaleString())).toBeTruthy()
		expect(within(history).getByText('Expires')).toBeTruthy()
	})

	it('has Edit, Delete, Clone and Move in the footer', async () => {
		const item = itemMeta()
		const { items } = renderDetail(item)
		const user = userEvent.setup()
		await user.click(within(footer).getByRole('button', { name: 'Edit' }))
		expect(items.edit).toHaveBeenCalledWith('i1')
		await user.click(within(footer).getByRole('button', { name: 'Delete' }))
		expect(items.remove).toHaveBeenCalledWith(item)
		await user.click(within(footer).getByRole('button', { name: 'More, GitHub' }))
		await user.click(screen.getByRole('menuitem', { name: 'Clone' }))
		expect(items.clone).toHaveBeenCalledWith('i1')
		await user.click(within(footer).getByRole('button', { name: 'More, GitHub' }))
		await user.click(screen.getByRole('menuitem', { name: 'Move to folder' }))
		expect(items.move).toHaveBeenCalledWith(item)
	})

	it('holds back the writes while offline, and says why', async () => {
		const { items } = renderDetail(itemMeta(), undefined, undefined, true)
		const user = userEvent.setup()
		const remove = within(footer).getByRole('button', { name: 'Delete' })
		expect(remove.getAttribute('aria-disabled')).toBe('true')
		expect(remove.title).toBe('You are offline. Changes need a connection to Keepiq.')
		await user.click(remove)
		expect(items.remove).not.toHaveBeenCalled()
		await user.click(within(footer).getByRole('button', { name: 'More, GitHub' }))
		for (const name of ['Clone', 'Move to folder']) expect(screen.getByRole('menuitem', { name }).getAttribute('aria-disabled')).toBe('true')
		// Edit reads the item first, which tells whether the server is back.
		expect(within(footer).getByRole('button', { name: 'Edit' }).getAttribute('aria-disabled')).toBeNull()
	})

	it('offers no change to a use-only copy', async () => {
		const { items } = renderDetail(itemMeta({ useOnly: true }), { login: 'bob' })
		expect(await screen.findByText("Its owner doesn't let you view or copy this.")).toBeTruthy()
		const edit = within(footer).getByRole('button', { name: 'Edit' })
		expect(edit.title).toBe('Shared with you for signing in only')
		const user = userEvent.setup()
		await user.click(edit)
		expect(items.edit).not.toHaveBeenCalled()
		await user.click(within(footer).getByRole('button', { name: 'More, GitHub' }))
		for (const name of ['Clone', 'Move to folder']) expect(screen.getByRole('menuitem', { name }).title).toBe('Shared with you for signing in only')
	})

	it('lets a read-only copy only be moved, and an expiring one not be cloned', async () => {
		renderDetail(itemMeta({ readOnly: true }))
		expect(within(footer).getByRole('button', { name: 'Edit' }).title).toBe('Shared from another organisation, so it can only be moved')
		const user = userEvent.setup()
		await user.click(within(footer).getByRole('button', { name: 'More, GitHub' }))
		expect(screen.getByRole('menuitem', { name: 'Move to folder' }).getAttribute('aria-disabled')).toBeNull()
		cleanup()
		renderDetail(itemMeta({ accessExpiresAt: '2026-12-01T00:00:00Z' }))
		expect(within(footer).getByRole('button', { name: 'Edit' }).getAttribute('aria-disabled')).toBeNull()
		await user.click(within(footer).getByRole('button', { name: 'More, GitHub' }))
		expect(screen.getByRole('menuitem', { name: 'Clone' }).title).toMatch(/^Your access ends .*2026, so it cannot be cloned$/)
	})

	it('cannot clone a passkey', async () => {
		renderDetail(itemMeta({ typeId: 't-passkey' }))
		await userEvent.setup().click(within(footer).getByRole('button', { name: 'More, GitHub' }))
		expect(screen.getByRole('menuitem', { name: 'Clone' }).title).toBe('Passkeys cannot be cloned')
	})

	it('returns to the background state when the vault locked meanwhile', async () => {
		const { refreshState } = renderDetail(itemMeta(), undefined, 'locked')
		await vi.waitFor(() => expect(refreshState).toHaveBeenCalled())
		expect(screen.getByRole('alert').textContent).toBe('Could not decrypt this item')
	})
})
