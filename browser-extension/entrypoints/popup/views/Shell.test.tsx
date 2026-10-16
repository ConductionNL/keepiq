// @vitest-environment happy-dom
import { act, cleanup, render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import App from '../App'
import { everythingVisible, fakeBackground, itemMeta, popupState, sentKinds, snapshotReply } from '../testing'

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
		await userEvent.setup().click(screen.getByRole('button', { name: 'Settings' }))
		expect((await title()).textContent).toBe('Settings')
		expect(screen.getByText('This arrives in a later update.')).toBeTruthy()
		expect(screen.queryByRole('searchbox')).toBeNull()
		expect(sent).toHaveBeenCalledWith({ kind: 'popup.lastTab.set', tab: 'settings' })
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
		expect(screen.queryByRole('navigation', { name: 'Main' })).toBeTruthy()
		await user.click(screen.getByRole('button', { name: 'Back' }))
		expect((await title()).textContent).toBe('Vault')
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
