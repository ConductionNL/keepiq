// @vitest-environment happy-dom
import { cleanup, render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { GUARD_MS } from '@/src/autofill/visibility'
import type { DecryptedItem } from '@/src/messages'
import type { CurrentTab } from '../hooks/useCurrentTab'
import { DEFAULT_SHELL, ShellContext } from '../shell-context'
import { everythingVisible, fakeBackground, fakeClipboard, itemMeta, sentKinds, sentOf, snapshotReply, types, type UnlockedSnapshot } from '../testing'
import { VaultList } from './VaultList'

afterEach(cleanup)
beforeEach(everythingVisible)

const githubTab: CurrentTab = { id: 7, windowId: 1, url: 'https://github.com/login', host: 'github.com' }

interface ListOptions {
	tab?: CurrentTab
	decrypted?: Record<string, DecryptedItem>
	replies?: Record<string, unknown>
	unanswered?: string[]
}

function renderList(snapshot: UnlockedSnapshot, options: ListOptions = {}) {
	const sent = fakeBackground({ snapshot, decrypted: options.decrypted, replies: options.replies, unanswered: options.unanswered })
	const toast = vi.fn()
	const launch = vi.fn()
	const onOpen = vi.fn()
	const syncNow = vi.fn()
	const refreshState = vi.fn()
	const items = { add: vi.fn(), edit: vi.fn(), clone: vi.fn(), move: vi.fn(), remove: vi.fn(), send: vi.fn() }
	render(
		<ShellContext.Provider value={{ ...DEFAULT_SHELL, toast, launch, items, refreshState }}>
			<VaultList snapshot={snapshot} tab={options.tab ?? { id: 1, windowId: 1, url: undefined, host: null }} syncNow={syncNow} onOpen={onOpen} />
		</ShellContext.Provider>,
	)
	return { sent, toast, launch, onOpen, syncNow, items, refreshState }
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

	it('does not list a blocked match, which stays in the main list with its badge', () => {
		renderList(snapshotReply({ items: [itemMeta({ id: '1', blocked: true, hasLogin: false, blockedReason: 'suite_revoked' })], suggestionIds: ['1'] }), { tab: githubTab })
		const section = screen.getByRole('region', { name: 'Autofill suggestions' })
		expect(within(section).queryAllByRole('listitem')).toHaveLength(0)
		expect(within(section).getByText('No items for github.com')).toBeTruthy()
		expect(listed()).toEqual(['GitHub'])
		expect(within(screen.getByRole('region', { name: 'Items' })).getByText('Blocked')).toBeTruthy()
	})

	it('keeps the background’s order, last used first, instead of sorting by name', () => {
		renderList(snapshotReply({ items: [itemMeta({ id: 'a', name: 'Amazon' }), itemMeta({ id: 'z', name: 'Zebra' })], suggestionIds: ['z', 'a'] }), { tab: githubTab })
		const section = screen.getByRole('region', { name: 'Autofill suggestions' })
		expect(within(section).getAllByRole('listitem').map((row) => row.querySelector('.item__name')!.textContent)).toEqual(['Zebra', 'Amazon'])
		expect(listed()).toEqual(['Amazon', 'Zebra'])
	})

	it('says autofill is not available on a page that is not http(s), offering no item', () => {
		renderList(snapshotReply({ suggestionIds: ['i1'] }), { tab: { id: 1, windowId: 1, url: 'chrome://newtab/', host: null } })
		const section = screen.getByRole('region', { name: 'Autofill suggestions' })
		expect(within(section).getByText('Autofill is not available on this page')).toBeTruthy()
		expect(within(section).queryAllByRole('listitem')).toHaveLength(0)
		expect(screen.queryByRole('button', { name: 'Fill GitHub' })).toBeNull()
	})
})

describe('filling from the suggestions', () => {
	const snapshot = () => snapshotReply({ items: [itemMeta({ id: '1', name: 'GitHub' })], suggestionIds: ['1'] })
	const suggestions = () => screen.getByRole('region', { name: 'Autofill suggestions' })
	const closeSpy = () => vi.spyOn(window, 'close').mockImplementation(() => {})

	it('fills username and password on a card click and closes the popup', async () => {
		const close = closeSpy()
		const { sent, onOpen } = renderList(snapshot(), { tab: githubTab, replies: { 'fill.request': { filled: 'both' } } })
		await userEvent.setup().click(within(suggestions()).getByText('GitHub'))
		expect(sentOf(sent, 'fill.request')).toEqual([{ kind: 'fill.request', itemId: '1', tabId: 7 }])
		await vi.waitFor(() => expect(close).toHaveBeenCalled())
		expect(onOpen).not.toHaveBeenCalled()
	})

	it('fills from the card’s Fill button, and still views the item from More', async () => {
		const close = closeSpy()
		const { sent, onOpen } = renderList(snapshot(), { tab: githubTab, replies: { 'fill.request': { filled: 'both' } } })
		const user = userEvent.setup()
		await user.click(within(suggestions()).getByRole('button', { name: 'Fill GitHub' }))
		await vi.waitFor(() => expect(close).toHaveBeenCalled())
		expect(sentOf(sent, 'fill.request')).toHaveLength(1)
		await user.click(within(suggestions()).getByRole('button', { name: 'More, GitHub' }))
		await user.click(screen.getByRole('menuitem', { name: 'View' }))
		expect(onOpen).toHaveBeenCalledWith('1')
	})

	it('offers Fill in the suggestions only, so a main list card still opens the item', async () => {
		const { sent, onOpen } = renderList(snapshot(), { tab: githubTab })
		const main = screen.getByRole('region', { name: 'Items' })
		expect(within(main).queryByRole('button', { name: 'Fill GitHub' })).toBeNull()
		await userEvent.setup().click(within(main).getByText('GitHub'))
		expect(onOpen).toHaveBeenCalledWith('1')
		expect(sentOf(sent, 'fill.request')).toHaveLength(0)
	})

	it('stays open and says so when the page has no login form', async () => {
		const close = closeSpy()
		const { toast } = renderList(snapshot(), { tab: githubTab, replies: { 'fill.request': { filled: 'none' } } })
		await userEvent.setup().click(within(suggestions()).getByText('GitHub'))
		await vi.waitFor(() => expect(toast).toHaveBeenCalledWith('Unable to autofill on this page. Copy and paste instead.'))
		expect(close).not.toHaveBeenCalled()
	})

	it('re-reads the state when the fill gets no answer, so a vault locked meanwhile shows Unlock', async () => {
		vi.spyOn(console, 'error').mockImplementation(() => {})
		const close = closeSpy()
		const { toast, refreshState } = renderList(snapshot(), { tab: githubTab, unanswered: ['fill.request'] })
		await userEvent.setup().click(within(suggestions()).getByText('GitHub'))
		await vi.waitFor(() => expect(refreshState).toHaveBeenCalled())
		expect(toast).toHaveBeenCalledWith('Unable to autofill on this page. Copy and paste instead.')
		expect(close).not.toHaveBeenCalled()
	})

	it('never fills a blocked row even if one arrives', async () => {
		const { sent, onOpen } = renderList(snapshotReply({ items: [itemMeta({ id: '1', blocked: true, hasLogin: false })], suggestionIds: ['1'] }), { tab: githubTab })
		expect(screen.queryByRole('button', { name: 'Fill GitHub' })).toBeNull()
		await userEvent.setup().click(screen.getByText('GitHub'))
		expect(onOpen).toHaveBeenCalledWith('1')
		expect(sentOf(sent, 'fill.request')).toHaveLength(0)
	})

	it('tells a screen reader that the card click fills', () => {
		renderList(snapshot(), { tab: githubTab })
		const fill = within(suggestions()).getByRole('button', { name: 'Fill GitHub' })
		const main = within(suggestions()).getByText('GitHub').closest('button')!
		expect(fill.id).toBeTruthy()
		expect(main.getAttribute('aria-describedby')).toBe(fill.id)
		expect(main.getAttribute('aria-label')).toBeNull()
		const listCard = within(screen.getByRole('region', { name: 'Items' })).getByText('GitHub').closest('button')!
		expect(listCard.getAttribute('aria-describedby')).toBeNull()
	})

	it('sends one fill for two quick clicks', async () => {
		const close = closeSpy()
		let answer: (reply: unknown) => void = () => {}
		const { sent } = renderList(snapshot(), { tab: githubTab, replies: { 'fill.request': () => new Promise((resolve) => (answer = resolve)) } })
		const user = userEvent.setup()
		const fill = within(suggestions()).getByRole('button', { name: 'Fill GitHub' })
		await user.click(fill)
		await user.click(fill)
		expect(sentOf(sent, 'fill.request')).toHaveLength(1)
		answer({ filled: 'both' })
		await vi.waitFor(() => expect(close).toHaveBeenCalled())
	})

	it('closes the popup when only the username was filled, as on a username-first step', async () => {
		const close = closeSpy()
		const { toast } = renderList(snapshot(), { tab: githubTab, replies: { 'fill.request': { filled: 'username' } } })
		await userEvent.setup().click(within(suggestions()).getByText('GitHub'))
		await vi.waitFor(() => expect(close).toHaveBeenCalled())
		expect(toast).not.toHaveBeenCalled()
	})

	describe('on an insecure page', () => {
		const insecure = (message: { confirmedInsecure?: boolean }) => (message.confirmedInsecure ? { filled: 'both' } : { filled: 'none', needsInsecureConfirm: true })

		it('asks first, with focus on Cancel, and declining sends nothing more', async () => {
			const close = closeSpy()
			const { sent, toast } = renderList(snapshot(), { tab: githubTab, replies: { 'fill.request': insecure } })
			const user = userEvent.setup()
			const fill = within(suggestions()).getByRole('button', { name: 'Fill GitHub' })
			await user.click(fill)
			const dialog = await screen.findByRole('dialog', { name: 'Do you still wish to fill this login?' })
			expect(dialog.getAttribute('aria-describedby')).toBeTruthy()
			expect(document.getElementById(dialog.getAttribute('aria-describedby')!)!.textContent).toContain('This is an unsecured HTTP page')
			expect(document.activeElement).toBe(within(dialog).getByRole('button', { name: 'Cancel' }))
			await user.click(within(dialog).getByRole('button', { name: 'Cancel' }))
			expect(screen.queryByRole('dialog')).toBeNull()
			expect(sentOf(sent, 'fill.request')).toEqual([{ kind: 'fill.request', itemId: '1', tabId: 7 }])
			expect(toast).not.toHaveBeenCalled()
			expect(close).not.toHaveBeenCalled()
			expect(document.activeElement).toBe(fill)
		})

		it('cancels on Escape and sends nothing more', async () => {
			const close = closeSpy()
			const { sent } = renderList(snapshot(), { tab: githubTab, replies: { 'fill.request': insecure } })
			const user = userEvent.setup()
			await user.click(within(suggestions()).getByText('GitHub'))
			await screen.findByRole('dialog')
			await user.keyboard('{Escape}')
			expect(screen.queryByRole('dialog')).toBeNull()
			expect(sentOf(sent, 'fill.request')).toHaveLength(1)
			expect(close).not.toHaveBeenCalled()
		})

		it('fills after the user accepts, saying so to the background', async () => {
			const close = closeSpy()
			const { sent } = renderList(snapshot(), { tab: githubTab, replies: { 'fill.request': insecure } })
			const user = userEvent.setup()
			await user.click(within(suggestions()).getByText('GitHub'))
			await user.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Fill' }))
			await vi.waitFor(() => expect(close).toHaveBeenCalled())
			expect(sentOf(sent, 'fill.request')).toEqual([
				{ kind: 'fill.request', itemId: '1', tabId: 7 },
				{ kind: 'fill.request', itemId: '1', tabId: 7, confirmedInsecure: true },
			])
		})
	})
})

describe('filling a card or identity', () => {
	const shopTab: CurrentTab = { id: 7, windowId: 1, url: 'http://shop.test/checkout', host: 'shop.test' }
	const ORIGIN = 'http://shop.test'
	const snapshot = () => snapshotReply({
		items: [itemMeta({ id: 'l', name: 'Shop' }), itemMeta({ id: 'c', name: 'Visa', typeId: 't-card', hasLogin: false, url: null }), itemMeta({ id: 'p', name: 'Me', typeId: 't-identity', hasLogin: false, url: null })],
		suggestionIds: ['l', 'c', 'p'],
		formIds: ['c', 'p'],
	})
	const suggestions = () => screen.getByRole('region', { name: 'Autofill suggestions' })
	const closeSpy = () => vi.spyOn(window, 'close').mockImplementation(() => {})
	const question = (insecure: boolean, frameHosts: string[]) => ({ insecure, host: 'shop.test', origin: ORIGIN, frameHosts })
	// Asks until the request carries an answer for every question, as the background does.
	const asking = (insecure: boolean, frameHosts: string[]) => (message: { confirmed?: { insecure?: true; frames: string; frameHosts: string[] } }) => {
		const answer = message.confirmed
		const open = (insecure && !answer?.insecure) || (frameHosts.length > 0 && !answer)
		return open ? { filled: 'none', code: false, needsFormConfirm: question(insecure, frameHosts) } : { filled: 'form', code: false }
	}
	const requests = (sent: ReturnType<typeof renderList>['sent']) => sentOf(sent, 'fill.request')
	// The cross-site question ignores Fill and "Only {host}" for `GUARD_MS` after it appears.
	beforeEach(() => vi.useFakeTimers({ toFake: ['Date'] }))
	afterEach(() => vi.useRealTimers())
	const settle = () => vi.setSystemTime(Date.now() + GUARD_MS)
	const crossSite = async (name: string) => {
		const dialog = await screen.findByRole('dialog', { name })
		settle()
		return dialog
	}

	it('lists the logins, then the cards, then the identities, in the background\'s order', () => {
		renderList(snapshot(), { tab: shopTab })
		expect(within(suggestions()).getAllByRole('listitem').map((row) => row.querySelector('.item__name')!.textContent)).toEqual(['Shop', 'Visa', 'Me'])
	})

	it('fills without a question and closes the popup', async () => {
		const close = closeSpy()
		const { sent } = renderList(snapshot(), { tab: shopTab, replies: { 'fill.request': { filled: 'form', code: false } } })
		await userEvent.setup().click(within(suggestions()).getByRole('button', { name: 'Fill Visa' }))
		await vi.waitFor(() => expect(close).toHaveBeenCalled())
		expect(requests(sent)).toEqual([{ kind: 'fill.request', itemId: 'c', tabId: 7 }])
	})

	it('says so when no frame has a field for it', async () => {
		const close = closeSpy()
		const { toast } = renderList(snapshot(), { tab: shopTab, replies: { 'fill.request': { filled: 'none', code: false } } })
		await userEvent.setup().click(within(suggestions()).getByRole('button', { name: 'Fill Me' }))
		await vi.waitFor(() => expect(toast).toHaveBeenCalledWith('Unable to autofill on this page. Copy and paste instead.'))
		expect(close).not.toHaveBeenCalled()
	})

	it('asks the insecure question, then the cross-site one, and sends one merged answer echoing the origin', async () => {
		const close = closeSpy()
		const { sent } = renderList(snapshot(), { tab: shopTab, replies: { 'fill.request': asking(true, ['js.stripe.test', 'pay.test']) } })
		const user = userEvent.setup()
		await user.click(within(suggestions()).getByRole('button', { name: 'Fill Visa' }))
		const insecure = await screen.findByRole('dialog', { name: 'Fill this card on an insecure site?' })
		expect(document.getElementById(insecure.getAttribute('aria-describedby')!)!.textContent).toBe('This site is not secure. Anyone on the network could read the card details that are filled in.')
		expect(document.activeElement).toBe(within(insecure).getByRole('button', { name: 'Cancel' }))
		await user.click(within(insecure).getByRole('button', { name: 'Fill' }))
		const hosts = await crossSite('Also fill on js.stripe.test, pay.test?')
		expect(document.getElementById(hosts.getAttribute('aria-describedby')!)!.textContent)
			.toBe('Part of this form comes from js.stripe.test, pay.test, not from shop.test. Keepiq fills it there only if you agree.')
		expect(within(hosts).getAllByRole('button').map((button) => button.textContent)).toEqual(['Fill', 'Only shop.test', 'Cancel'])
		expect(document.activeElement).toBe(within(hosts).getByRole('button', { name: 'Cancel' }))
		expect(requests(sent)).toHaveLength(1)
		await user.click(within(hosts).getByRole('button', { name: 'Fill' }))
		await vi.waitFor(() => expect(close).toHaveBeenCalled())
		expect(requests(sent)).toEqual([
			{ kind: 'fill.request', itemId: 'c', tabId: 7 },
			{ kind: 'fill.request', itemId: 'c', tabId: 7, confirmed: { insecure: true, frames: 'all', frameHosts: ['js.stripe.test', 'pay.test'], origin: ORIGIN } },
		])
	})

	it('fills only the page\'s own site on "Only {host}"', async () => {
		closeSpy()
		const { sent } = renderList(snapshot(), { tab: shopTab, replies: { 'fill.request': asking(false, ['js.stripe.test']) } })
		const user = userEvent.setup()
		await user.click(within(suggestions()).getByRole('button', { name: 'Fill Visa' }))
		await user.click(within(await crossSite('Also fill on js.stripe.test?')).getByRole('button', { name: 'Only shop.test' }))
		await vi.waitFor(() => expect(requests(sent)).toHaveLength(2))
		expect(requests(sent)[1]).toEqual({ kind: 'fill.request', itemId: 'c', tabId: 7, confirmed: { frames: 'sameSite', frameHosts: ['js.stripe.test'], origin: ORIGIN } })
	})

	it.each(['insecure', 'cross-site'])('sends nothing more after Cancel at the %s question', async (at) => {
		const close = closeSpy()
		const { sent, toast } = renderList(snapshot(), { tab: shopTab, replies: { 'fill.request': asking(true, ['pay.test']) } })
		const user = userEvent.setup()
		await user.click(within(suggestions()).getByRole('button', { name: 'Fill Visa' }))
		if (at === 'cross-site') await user.click(within(await screen.findByRole('dialog', { name: 'Fill this card on an insecure site?' })).getByRole('button', { name: 'Fill' }))
		await user.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Cancel' }))
		expect(screen.queryByRole('dialog')).toBeNull()
		expect(requests(sent)).toHaveLength(1)
		expect(toast).not.toHaveBeenCalled()
		expect(close).not.toHaveBeenCalled()
	})

	it('sends an insecure-only answer straight away', async () => {
		closeSpy()
		const { sent } = renderList(snapshot(), { tab: shopTab, replies: { 'fill.request': asking(true, []) } })
		const user = userEvent.setup()
		await user.click(within(suggestions()).getByRole('button', { name: 'Fill Visa' }))
		await user.click(within(await screen.findByRole('dialog', { name: 'Fill this card on an insecure site?' })).getByRole('button', { name: 'Fill' }))
		await vi.waitFor(() => expect(requests(sent)).toHaveLength(2))
		expect(requests(sent)[1]!.confirmed).toEqual({ insecure: true, frames: 'all', frameHosts: [], origin: ORIGIN })
	})

	it('ignores Fill and "Only {host}" on the cross-site question for its first moments, so one double click does not answer both', async () => {
		closeSpy()
		const { sent } = renderList(snapshot(), { tab: shopTab, replies: { 'fill.request': asking(true, ['pay.test']) } })
		const user = userEvent.setup()
		await user.click(within(suggestions()).getByRole('button', { name: 'Fill Visa' }))
		await user.click(within(await screen.findByRole('dialog', { name: 'Fill this card on an insecure site?' })).getByRole('button', { name: 'Fill' }))
		const hosts = await screen.findByRole('dialog', { name: 'Also fill on pay.test?' })
		vi.setSystemTime(Date.now() + GUARD_MS - 1)
		await user.click(within(hosts).getByRole('button', { name: 'Fill' }))
		await user.click(within(hosts).getByRole('button', { name: 'Only shop.test' }))
		expect(requests(sent)).toHaveLength(1)
		expect(screen.getByRole('dialog', { name: 'Also fill on pay.test?' })).toBe(hosts)
		vi.setSystemTime(Date.now() + 1)
		await user.click(within(hosts).getByRole('button', { name: 'Fill' }))
		await vi.waitFor(() => expect(requests(sent)).toHaveLength(2))
		expect(requests(sent)[1]!.confirmed).toEqual({ insecure: true, frames: 'all', frameHosts: ['pay.test'], origin: ORIGIN })
	})

	it('counts the cross-site question\'s first moments from when it appears, not from the first question', async () => {
		closeSpy()
		const { sent } = renderList(snapshot(), { tab: shopTab, replies: { 'fill.request': asking(true, ['pay.test']) } })
		const user = userEvent.setup()
		await user.click(within(suggestions()).getByRole('button', { name: 'Fill Visa' }))
		const insecure = await screen.findByRole('dialog', { name: 'Fill this card on an insecure site?' })
		vi.setSystemTime(Date.now() + GUARD_MS * 4)
		await user.click(within(insecure).getByRole('button', { name: 'Fill' }))
		const hosts = await screen.findByRole('dialog', { name: 'Also fill on pay.test?' })
		vi.setSystemTime(Date.now() + GUARD_MS / 5)
		await user.click(within(hosts).getByRole('button', { name: 'Fill' }))
		expect(requests(sent)).toHaveLength(1)
	})

	it('cancels the cross-site question at once, by Cancel or Escape', async () => {
		const { sent } = renderList(snapshot(), { tab: shopTab, replies: { 'fill.request': asking(false, ['pay.test']) } })
		const user = userEvent.setup()
		await user.click(within(suggestions()).getByRole('button', { name: 'Fill Visa' }))
		await user.click(within(await screen.findByRole('dialog', { name: 'Also fill on pay.test?' })).getByRole('button', { name: 'Cancel' }))
		expect(screen.queryByRole('dialog')).toBeNull()
		await user.click(within(suggestions()).getByRole('button', { name: 'Fill Visa' }))
		await screen.findByRole('dialog', { name: 'Also fill on pay.test?' })
		await user.keyboard('{Escape}')
		expect(screen.queryByRole('dialog')).toBeNull()
		expect(requests(sent)).toHaveLength(2)
	})

	it('asks again about a host added after the answer, carrying the earlier answers', async () => {
		closeSpy()
		const replies = [
			{ filled: 'none', code: false, needsFormConfirm: question(false, ['js.stripe.test']) },
			{ filled: 'none', code: false, needsFormConfirm: question(false, ['ads.test']) },
			{ filled: 'form', code: false },
		]
		const { sent } = renderList(snapshot(), { tab: shopTab, replies: { 'fill.request': () => replies.shift() } })
		const user = userEvent.setup()
		await user.click(within(suggestions()).getByRole('button', { name: 'Fill Visa' }))
		await user.click(within(await crossSite('Also fill on js.stripe.test?')).getByRole('button', { name: 'Fill' }))
		await user.click(within(await crossSite('Also fill on ads.test?')).getByRole('button', { name: 'Fill' }))
		await vi.waitFor(() => expect(requests(sent)).toHaveLength(3))
		expect(requests(sent)[2]!.confirmed).toEqual({ frames: 'all', frameHosts: ['js.stripe.test', 'ads.test'], origin: ORIGIN })
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

	it('offers a use-only copy’s username and never its password or code', async () => {
		const user = userEvent.setup()
		renderList(snapshotReply({ items: [
			itemMeta({ id: '1', name: 'Portal', useOnly: true }),
			itemMeta({ id: '2', name: 'Codes', typeId: 't-totp', hasLogin: false, useOnly: true }),
		] }))
		await user.click(screen.getByRole('button', { name: 'Copy, Portal' }))
		expect(screen.getAllByRole('menuitem').map((m) => m.textContent)).toEqual(['Copy username'])
		expect((screen.getByRole('button', { name: 'Copy, Codes' }) as HTMLButtonElement).disabled).toBe(true)
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

	it('offers View and the edit actions', async () => {
		const { onOpen, items } = renderList(snapshotReply())
		const user = userEvent.setup()
		const more = () => user.click(screen.getByRole('button', { name: 'More, GitHub' }))
		await more()
		for (const label of ['Edit', 'Clone', 'Move to folder', 'Delete']) expect(screen.getByRole('menuitem', { name: label }).getAttribute('aria-disabled')).toBeNull()
		await user.click(screen.getByRole('menuitem', { name: 'Edit' }))
		expect(items.edit).toHaveBeenCalledWith('i1')
		await more()
		await user.click(screen.getByRole('menuitem', { name: 'Delete' }))
		expect(items.remove).toHaveBeenCalledWith(expect.objectContaining({ id: 'i1' }))
		await more()
		await user.click(screen.getByRole('menuitem', { name: 'View' }))
		expect(onOpen).toHaveBeenCalledWith('i1')
	})

	it('offers Send on a read-only share', async () => {
		renderList(snapshotReply({ items: [itemMeta({ readOnly: true })] }))
		await userEvent.setup().click(screen.getByRole('button', { name: 'More, GitHub' }))
		expect(screen.getByRole('menuitem', { name: 'Send' }).getAttribute('aria-disabled')).toBeNull()
	})

	it('offers Send on a login only, before Delete', async () => {
		const { items } = renderList(snapshotReply({ items: [itemMeta(), itemMeta({ id: 'i2', name: 'Licence', typeId: 't-api_key' })] }))
		const user = userEvent.setup()
		await user.click(screen.getByRole('button', { name: 'More, GitHub' }))
		expect(screen.getAllByRole('menuitem').map((m) => m.textContent)).toEqual(['View', 'Edit', 'Clone', 'Move to folder', 'Send', 'Delete'])
		await user.click(screen.getByRole('menuitem', { name: 'Send' }))
		expect(items.send).toHaveBeenCalledWith(expect.objectContaining({ id: 'i1' }))
		await user.click(screen.getByRole('button', { name: 'More, Licence' }))
		expect(screen.queryByRole('menuitem', { name: 'Send' })).toBeNull()
	})

	it.each([
		[{ blocked: true, hasLogin: false }, 'This item is blocked.'],
		[{ useOnly: true }, 'Shared with you for signing in only'],
		[{ accessExpiresAt: '2026-12-01T12:00:00Z' }, `Your access ends ${new Date('2026-12-01T12:00:00Z').toLocaleDateString('en')}, so it cannot be sent`],
	])('disables Send with the reason for %o', async (overrides, reason) => {
		const { items } = renderList(snapshotReply({ items: [itemMeta(overrides)] }))
		const user = userEvent.setup()
		await user.click(screen.getByRole('button', { name: 'More, GitHub' }))
		const send = screen.getByRole('menuitem', { name: 'Send' })
		expect(send.getAttribute('aria-disabled')).toBe('true')
		expect(send.title).toBe(reason)
		await user.click(send)
		expect(items.send).not.toHaveBeenCalled()
	})

	it('does not edit or clone a blocked item', async () => {
		renderList(snapshotReply({ items: [itemMeta({ blocked: true, hasLogin: false })] }))
		await userEvent.setup().click(screen.getByRole('button', { name: 'More, GitHub' }))
		for (const label of ['Edit', 'Clone']) expect(screen.getByRole('menuitem', { name: label }).title).toBe('This item is blocked.')
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
