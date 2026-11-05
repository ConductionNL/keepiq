// @vitest-environment happy-dom
import { act, cleanup, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { SyncStatus, VaultSnapshotReply } from '@/src/messages'
import App from '../../App'
import { LOADING_DELAY_MS } from '../../components/Loading'
import { DEFAULT_SHELL, ShellContext } from '../../shell-context'
import { everythingVisible, fakeBackground, sentOf, snapshotReply, type UnlockedSnapshot } from '../../testing'
import { Vault } from './Vault'

afterEach(cleanup)
beforeEach(everythingVisible)

const earlier = '2026-10-20T08:15:00Z'
const now = '2026-10-23T09:30:00Z'
const shown = (iso: string) => new Date(iso).toLocaleString('en', { dateStyle: 'short', timeStyle: 'short' })
const sync = (overrides: Partial<SyncStatus> = {}): SyncStatus => ({ syncing: false, syncedAt: earlier, offline: false, lastError: null, ...overrides })

/** The real Shell, so Sync now refreshes the snapshot the vault tab shows. `after` is the status a sync leaves. */
async function openVault({ after, ...options }: Parameters<typeof fakeBackground>[0] & { after?: SyncStatus } = {}) {
	let status = sync()
	const sent = fakeBackground({
		snapshot: () => snapshotReply({ sync: status }),
		...options,
		replies: { 'vault.sync': () => (status = after ?? status), ...options.replies },
	})
	const user = userEvent.setup()
	render(<App />)
	await user.click(within(await screen.findByRole('navigation', { name: 'Main' })).getByRole('button', { name: 'Settings' }))
	await user.click(within(screen.getByRole('list')).getByRole('button', { name: 'Vault' }))
	/** What the next snapshot read reports, as a sync elsewhere would leave it. */
	const setStatus = (next: SyncStatus) => (status = next)
	return { sent, user, setStatus }
}

const syncButton = () => screen.findByRole('button', { name: 'Sync now' })
const described = (element: HTMLElement) => (element.getAttribute('aria-describedby') ?? '').split(' ').filter(Boolean).map((id) => document.getElementById(id)!.textContent)
const typeSelect = () => screen.findByRole<HTMLSelectElement>('combobox', { name: 'Default item type in this extension' })

describe('Vault settings', () => {
	it('opens Folders', async () => {
		const { user } = await openVault()
		await user.click(await screen.findByRole('button', { name: 'Folders' }))
		expect((await screen.findByRole('heading', { level: 1 })).textContent).toBe('Folders')
	})

	it('makes the whole Sync now row the button, Last sync line included', async () => {
		await openVault()
		const button = await syncButton()
		expect(button.textContent).toContain(`Last sync: ${shown(earlier)}`)
		expect(button.parentElement!.childElementCount).toBe(1)
	})

	it('syncs now, and the new last sync outlives leaving the section', async () => {
		const { sent, user } = await openVault({ after: sync({ syncedAt: now }) })
		await user.click(await syncButton())
		await waitFor(async () => expect(described(await syncButton())).toEqual([`Last sync: ${shown(now)}`]))
		expect(sentOf(sent, 'vault.sync')).toHaveLength(1)
		await user.click(screen.getByRole('button', { name: 'Back' }))
		await user.click(within(screen.getByRole('list')).getByRole('button', { name: 'Vault' }))
		expect(described(await syncButton())).toEqual([`Last sync: ${shown(now)}`])
	})

	it('marks Sync now busy while the sync runs', async () => {
		let finish!: (status: SyncStatus) => void
		const { user } = await openVault({ replies: { 'vault.sync': () => new Promise((resolve) => (finish = resolve)) } })
		const button = await syncButton()
		expect(button.getAttribute('aria-busy')).toBe('false')
		await user.click(button)
		await waitFor(async () => expect((await syncButton()).getAttribute('aria-busy')).toBe('true'))
		finish(sync({ syncedAt: now }))
		await waitFor(async () => expect((await syncButton()).getAttribute('aria-busy')).toBe('false'))
	})

	it('says the vault did not load instead of waiting for it', async () => {
		vi.spyOn(console, 'error').mockImplementation(() => {})
		await openVault({ unanswered: ['vault.snapshot'] })
		expect(await screen.findByText('Keepiq is not responding. Reload the extension and reopen this popup.')).toBeTruthy()
		expect(screen.getByRole('button', { name: 'Folders' })).toBeTruthy()
		await new Promise((resolve) => setTimeout(resolve, 400))
		expect(screen.queryByText('Loading…')).toBeNull()
	})

	it('reports only the sync it started, not a later one that failed elsewhere', async () => {
		const { user, setStatus } = await openVault({ after: sync({ syncedAt: now }) })
		await user.click(await syncButton())
		await waitFor(async () => expect(described(await syncButton())).toEqual([`Last sync: ${shown(now)}`]))
		setStatus(sync({ syncedAt: now, offline: true, lastError: 'network' }))
		// Choosing a default type re-reads the vault snapshot, which now reports the other sync's failure.
		await user.selectOptions(await typeSelect(), 'Note')
		await waitFor(() => expect(screen.getByRole<HTMLSelectElement>('combobox', { name: 'Default item type in this extension' }).value).toBe('note'))
		await new Promise((resolve) => setTimeout(resolve, 20))
		expect(screen.queryByText('Sync failed, you are offline')).toBeNull()
	})

	it('says Never before the first sync', async () => {
		await openVault({ snapshot: snapshotReply({ sync: sync({ syncedAt: null }) }) })
		expect(described(await syncButton())).toEqual(['Last sync: Never'])
	})

	it.each([
		['offline', sync({ offline: true, lastError: 'network' }), 'Sync failed, you are offline'],
		['the server failed', sync({ lastError: 'server' }), 'Sync failed, try again later'],
	])('keeps the last sync when %s', async (_, after, text) => {
		const { user } = await openVault({ after })
		const button = await syncButton()
		expect(screen.queryByText(text)).toBeNull()
		await user.click(button)
		expect((await screen.findByRole('alert')).textContent).toBe(text)
		expect(described(await syncButton())).toEqual([`Last sync: ${shown(earlier)}`])
	})

	it('links Import and Export to the web app', async () => {
		await openVault()
		expect((await screen.findByRole('link', { name: 'Export vault' })).getAttribute('href')).toBe('https://cloud.example.org/index.php/apps/keepiq/')
		expect(screen.getByRole('link', { name: 'Import items' }).getAttribute('href')).toBe('https://cloud.example.org/index.php/apps/keepiq/')
	})

	it('turns Export off when the organisation did, keeping Import', async () => {
		await openVault({ accountSettings: { exportDisabled: true } })
		const exportRow = await screen.findByRole('button', { name: 'Export vault' })
		expect(exportRow.getAttribute('aria-disabled')).toBe('true')
		expect(described(exportRow)).toEqual(['Turned off by your organisation'])
		expect(exportRow.textContent).toContain('Turned off by your organisation')
		expect(screen.queryByRole('link', { name: 'Export vault' })).toBeNull()
		expect(screen.getByRole('link', { name: 'Import items' })).toBeTruthy()
	})

	it('stores the default item type from the cached types, without passkeys or authenticators', async () => {
		const { sent, user } = await openVault()
		const select = await typeSelect()
		expect(select.value).toBe('login')
		expect([...select.options].map((option) => option.textContent)).toEqual(['Login', 'API key', 'Note', 'Card', 'Identity'])
		await user.selectOptions(select, 'Note')
		await waitFor(() => expect(sentOf(sent, 'account.settings.set').map((message) => message.patch)).toEqual([{ defaultItemType: 'note' }]))
	})

	it('shows Login for a stored type this server lacks', async () => {
		await openVault({ accountSettings: { values: { defaultItemType: 'wifi' } } })
		expect((await typeSelect()).value).toBe('login')
	})

	it('shows Login for a stored Authenticator default from before it was dropped', async () => {
		await openVault({ accountSettings: { values: { defaultItemType: 'totp' } } })
		expect((await typeSelect()).value).toBe('login')
	})

	it('keeps the rows usable when the account settings do not answer', async () => {
		vi.spyOn(console, 'error').mockImplementation(() => {})
		await openVault({ unanswered: ['account.settings.get'] })
		expect(await screen.findByText('Keepiq is not responding. Reload the extension and reopen this popup.')).toBeTruthy()
		expect(screen.getByRole('button', { name: 'Folders' })).toBeTruthy()
		expect(await syncButton()).toBeTruthy()
		expect(screen.getByRole('link', { name: 'Import items' })).toBeTruthy()
		expect(screen.queryByRole('combobox', { name: 'Default item type in this extension' })).toBeNull()
	})
})

describe('Vault settings with a given snapshot', () => {
	interface Page {
		snapshot?: UnlockedSnapshot
		snapshotFailed?: boolean
		syncNow?: () => Promise<VaultSnapshotReply | undefined>
	}

	function openPage({ snapshot, snapshotFailed = false, syncNow = async () => undefined, ...options }: Page & Parameters<typeof fakeBackground>[0] = {}) {
		fakeBackground(options)
		render(
			<ShellContext.Provider value={{ ...DEFAULT_SHELL, syncNow }}>
				<Vault onOpen={vi.fn()} snapshot={snapshot} snapshotFailed={snapshotFailed} reload={vi.fn()} />
			</ShellContext.Provider>,
		)
		return userEvent.setup()
	}

	const unlocked = (status: Partial<SyncStatus> = {}) => snapshotReply({ sync: sync(status) })
	const pastLoadingDelay = () => act(() => new Promise<void>((done) => setTimeout(done, LOADING_DELAY_MS)))
	const notResponding = 'Keepiq is not responding. Reload the extension and reopen this popup.'

	it('shows the spinner while the snapshot loads, with no last sync yet', async () => {
		openPage()
		expect(await screen.findByText('Loading…')).toBeTruthy()
		expect(described(await syncButton())).toEqual([])
	})

	it.each<[string, Page & { unanswered?: string[] }]>([
		['the snapshot failed', { snapshotFailed: true }],
		['the settings failed', { snapshot: unlocked(), unanswered: ['account.settings.get'] }],
		['both failed', { snapshotFailed: true, unanswered: ['account.settings.get'] }],
	])('shows no spinner once %s', async (_, page) => {
		vi.spyOn(console, 'error').mockImplementation(() => {})
		openPage(page)
		expect(await screen.findByText(notResponding)).toBeTruthy()
		await pastLoadingDelay()
		expect(screen.queryByText('Loading…')).toBeNull()
	})

	it('keeps a cached snapshot quiet when its re-read failed', async () => {
		openPage({ snapshot: unlocked(), snapshotFailed: true })
		expect(await screen.findByRole('combobox', { name: 'Default item type in this extension' })).toBeTruthy()
		expect(screen.queryByText(notResponding)).toBeNull()
	})

	it('offers Import and a focusable turned-off Export from the settings before the snapshot loads', async () => {
		openPage({ accountSettings: { exportDisabled: true } })
		expect((await screen.findByRole('link', { name: 'Import items' })).getAttribute('href')).toBe('https://cloud.example.org/index.php/apps/keepiq/')
		const exportRow = screen.getByRole<HTMLButtonElement>('button', { name: 'Export vault' })
		expect(exportRow.disabled).toBe(false)
		exportRow.focus()
		expect(document.activeElement).toBe(exportRow)
		expect(described(exportRow)).toEqual(['Turned off by your organisation'])
	})

	it('ignores Sync now while a sync runs', async () => {
		const syncNow = vi.fn(async () => unlocked())
		const user = openPage({ snapshot: unlocked({ syncing: true }), syncNow })
		await user.click(await syncButton())
		expect(syncNow).not.toHaveBeenCalled()
	})

	it.each([
		['got no answer', undefined],
		['ended locked', { state: 'locked' } as VaultSnapshotReply],
	])('says Keepiq is not responding when the sync %s', async (_, after) => {
		const user = openPage({ snapshot: unlocked(), syncNow: async () => after })
		await user.click(await syncButton())
		expect((await screen.findByRole('alert')).textContent).toBe(notResponding)
	})

	it('reports a sync that ended offline without an error', async () => {
		const user = openPage({ snapshot: unlocked(), syncNow: async () => unlocked({ offline: true }) })
		await user.click(await syncButton())
		expect((await screen.findByRole('alert')).textContent).toBe('Sync failed, you are offline')
	})

	it('says nothing after a sync that went well', async () => {
		const syncNow = vi.fn(async () => unlocked())
		const user = openPage({ snapshot: unlocked(), syncNow })
		await user.click(await syncButton())
		await waitFor(() => expect(syncNow).toHaveBeenCalled())
		await act(async () => {})
		expect(screen.queryByRole('alert')).toBeNull()
	})

	it('clears the last sync\'s error as soon as another starts', async () => {
		const ends: Array<Promise<VaultSnapshotReply | undefined>> = [Promise.resolve(undefined), new Promise(() => {})]
		const user = openPage({ snapshot: unlocked(), syncNow: () => ends.shift()! })
		await user.click(await syncButton())
		expect(await screen.findByRole('alert')).toBeTruthy()
		await user.click(await syncButton())
		expect(screen.queryByRole('alert')).toBeNull()
	})
})
