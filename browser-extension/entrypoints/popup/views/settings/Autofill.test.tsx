// @vitest-environment happy-dom
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { setLocale } from '@/src/testing/i18n'
import { DEFAULT_SHELL, ShellContext } from '../../shell-context'
import { fakeBackground, fakeClipboard, mounts, sentOf } from '../../testing'
import { Autofill } from './Autofill'

afterEach(() => {
	cleanup()
	vi.unstubAllEnvs()
})

function renderView(options: Parameters<typeof fakeBackground>[0] = {}) {
	const sent = fakeBackground(options)
	const shell = { ...DEFAULT_SHELL, launch: vi.fn(), toast: vi.fn() }
	render(<ShellContext.Provider value={shell}><Autofill /></ShellContext.Provider>)
	return { sent, shell, user: userEvent.setup() }
}

const checkbox = (name: string) => screen.getByRole<HTMLInputElement>('checkbox', { name })
const select = (name: string) => screen.getByRole<HTMLSelectElement>('combobox', { name })
const described = (element: HTMLElement) => (element.getAttribute('aria-describedby') ?? '').split(' ').filter(Boolean).map((id) => document.getElementById(id)!.textContent)
const patches = (sent: ReturnType<typeof fakeBackground>) => sentOf(sent, 'account.settings.set').map((message) => message.patch)
const loaded = () => screen.findByRole('checkbox', { name: 'Autofill on page load' })

describe('Autofill', () => {
	it('shows an entry whose behaviour is not delivered as coming later, with its stored value', async () => {
		const { sent, user } = renderView({ accountSettings: { autofillFeatures: [], values: { contextMenu: false } } })
		await loaded()
		for (const name of ['Autofill on page load', 'Show autofill suggestions on form fields', 'Enable context menu options']) {
			expect(checkbox(name).getAttribute('aria-disabled')).toBe('true')
			expect(described(checkbox(name))).toEqual(['Coming later'])
		}
		for (const name of ['Default autofill setting for login items', 'Default URI match detection']) {
			expect(select(name).disabled).toBe(true)
			expect(described(select(name))).toEqual(['Coming later'])
		}
		expect(checkbox('Enable context menu options').checked).toBe(false)
		await user.click(checkbox('Enable context menu options'))
		expect(patches(sent)).toEqual([])
		expect(screen.queryByText('Show identities as suggestions')).toBeNull()
	})

	it('enables every entry and stores its change, now that ext-autofill delivered them', async () => {
		const { sent, user } = renderView({ accountSettings: { values: { contextMenu: false } } })
		await loaded()
		expect(screen.queryByText('Coming later')).toBeNull()
		for (const name of ['Autofill on page load', 'Show autofill suggestions on form fields', 'Enable context menu options']) {
			expect(checkbox(name).getAttribute('aria-disabled')).toBeNull()
		}
		expect(select('Default URI match detection').disabled).toBe(false)
		await user.click(checkbox('Enable context menu options'))
		await waitFor(() => expect(patches(sent)).toEqual([{ contextMenu: true }]))
	})

	it('warns under Autofill on page load once on, and enables the default setting', async () => {
		const { sent, user } = renderView()
		await loaded()
		expect(select('Default autofill setting for login items').disabled).toBe(true)
		await user.click(checkbox('Autofill on page load'))
		await waitFor(() => expect(checkbox('Autofill on page load').checked).toBe(true))
		expect(patches(sent)).toEqual([{ autofillOnPageLoad: true }])
		expect(described(checkbox('Autofill on page load'))).toEqual(['Compromised or untrusted websites can exploit autofill on page load'])
		expect(select('Default autofill setting for login items').disabled).toBe(false)
	})

	it('notes that a regular expression can break matching', async () => {
		const { sent, user } = renderView()
		await loaded()
		expect([...select('Default URI match detection').options].map((option) => option.textContent)).toEqual(['Base domain', 'Host', 'Starts with', 'Exact', 'Regular expression', 'Never'])
		await user.selectOptions(select('Default URI match detection'), 'Regular expression')
		await waitFor(() => expect(patches(sent)).toEqual([{ uriMatch: 'regex' }]))
		expect(described(select('Default URI match detection'))).toEqual(['Regular expressions are an advanced option and can break matching'])
	})

	it('stores a clear clipboard delay', async () => {
		const { sent, user } = renderView()
		await loaded()
		const delay = select('Clear clipboard')
		expect(delay.value).toBe('30000')
		expect([...delay.options].map((option) => option.textContent)).toEqual(['Never', '10 seconds', '20 seconds', '30 seconds', '1 minute', '2 minutes', '5 minutes'])
		await user.selectOptions(delay, '20 seconds')
		await waitFor(() => expect(patches(sent)).toEqual([{ clearClipboardMs: 20_000 }]))
	})

	it('has Copy TOTP automatically on by default, below Clear clipboard, and stores turning it off', async () => {
		const { sent, user } = renderView()
		await loaded()
		const entry = checkbox('Copy TOTP automatically')
		expect(entry.checked).toBe(true)
		expect(select('Clear clipboard').compareDocumentPosition(entry) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
		expect(described(entry)).toEqual(['If a login has a verification code, copy it to your clipboard when you autofill the login.'])
		await user.click(entry)
		await waitFor(() => expect(patches(sent)).toEqual([{ autoCopyTotp: false }]))
	})

	it('shows the shortcut and opens the browser\'s shortcut page on Chromium', async () => {
		const { shell, user } = renderView({ accountSettings: { shortcut: 'Ctrl+Shift+L' } })
		await loaded()
		expect(screen.getByText('Ctrl+Shift+L')).toBeTruthy()
		await user.click(screen.getByRole('button', { name: 'Change' }))
		expect(shell.launch).toHaveBeenCalledWith('chrome://extensions/shortcuts')
	})

	it('shows the steps on Firefox, with a Copy control for about:addons', async () => {
		vi.stubEnv('BROWSER', 'firefox')
		const { shell, user } = renderView({ accountSettings: { shortcut: 'Ctrl+Shift+L' } })
		// After user-event's setup, which installs a clipboard of its own.
		const writeText = fakeClipboard()
		await loaded()
		await user.click(screen.getByRole('button', { name: 'Change' }))
		expect(shell.launch).not.toHaveBeenCalled()
		expect(screen.getByText('Open about:addons, choose the gear menu, then Manage Extension Shortcuts')).toBeTruthy()
		await user.click(screen.getByRole('button', { name: 'Copy about:addons' }))
		expect(writeText).toHaveBeenCalledWith('about:addons')
	})

	it('says Not set without a shortcut', async () => {
		renderView()
		await loaded()
		expect(screen.getByText('Not set')).toBeTruthy()
	})
})

describe('Make Keepiq your default password manager', () => {
	const NAME = 'Make Keepiq your default password manager'
	const HINT = 'Turns off the browser\'s own password saving and address autofill, so its suggestions don\'t cover Keepiq\'s.'
	const MANAGED = 'Your organisation or another extension manages this browser setting.'
	const entry = () => screen.findByRole<HTMLInputElement>('checkbox', { name: NAME })
	const grant = (granted: boolean) => vi.spyOn(browser.permissions, 'request').mockImplementation(async () => granted)

	it('shows off with its description on a fresh install', async () => {
		const { sent } = renderView()
		const toggle = await entry()
		expect(toggle.checked).toBe(false)
		expect(toggle.getAttribute('aria-disabled')).toBeNull()
		expect(described(toggle)).toEqual([HINT])
		expect(sentOf(sent, 'defaultManager.get')).toHaveLength(mounts())
	})

	it('asks for privacy inside the click, then turns on once the background took both settings', async () => {
		const request = grant(true)
		const { sent } = renderView()
		const toggle = await entry()
		// Synchronous, so nothing was awaited before the request.
		fireEvent.click(toggle)
		expect(request).toHaveBeenCalledWith({ permissions: ['privacy'] })
		await waitFor(() => expect(toggle.checked).toBe(true))
		expect(sentOf(sent, 'defaultManager.enable')).toHaveLength(1)
	})

	it('shows on when reopened after Chrome closed the popup at the permission prompt', async () => {
		// The closed popup never sees the grant resolve; the background's grant listener turns it on instead.
		vi.spyOn(browser.permissions, 'request').mockImplementation(() => new Promise(() => {}))
		const closed = renderView()
		await closed.user.click(await entry())
		cleanup()
		const { sent } = renderView({ defaultManager: 'on' })
		expect((await entry()).checked).toBe(true)
		expect([...sentOf(closed.sent, 'defaultManager.enable'), ...sentOf(sent, 'defaultManager.enable')]).toEqual([])
	})

	it('stays off and sets nothing when the permission is declined', async () => {
		grant(false)
		const { sent, user } = renderView()
		await user.click(await entry())
		await waitFor(() => expect(browser.permissions.request).toHaveBeenCalled())
		expect((await entry()).checked).toBe(false)
		expect(sentOf(sent, 'defaultManager.enable')).toEqual([])
	})

	it('stays off when the background found the settings managed after the grant', async () => {
		grant(true)
		const { user } = renderView({ replies: { 'defaultManager.enable': 'managed' } })
		await user.click(await entry())
		await screen.findByText(MANAGED)
		expect((await entry()).checked).toBe(false)
	})

	it('turns off without asking for anything', async () => {
		const request = grant(true)
		const { sent, user } = renderView({ defaultManager: 'on' })
		const toggle = await entry()
		expect(toggle.checked).toBe(true)
		await user.click(toggle)
		await waitFor(() => expect(toggle.checked).toBe(false))
		expect(sentOf(sent, 'defaultManager.disable')).toHaveLength(1)
		expect(request).not.toHaveBeenCalled()
	})

	it('is disabled with a note when the browser setting is managed elsewhere', async () => {
		const request = grant(true)
		const { sent, user } = renderView({ defaultManager: 'managed' })
		const toggle = await entry()
		expect(toggle.getAttribute('aria-disabled')).toBe('true')
		expect(described(toggle)).toEqual([MANAGED, HINT])
		await user.click(toggle)
		expect(request).not.toHaveBeenCalled()
		expect(sentOf(sent, 'defaultManager.enable')).toEqual([])
	})

	it('shows on and can still be turned off, with the note, once the other setting is managed elsewhere', async () => {
		const request = grant(true)
		const { sent, user } = renderView({ defaultManager: 'managedOn' })
		const toggle = await entry()
		expect(toggle.checked).toBe(true)
		expect(toggle.getAttribute('aria-disabled')).toBeNull()
		expect(described(toggle)).toEqual([MANAGED, HINT])
		await user.click(toggle)
		await waitFor(() => expect(toggle.checked).toBe(false))
		expect(sentOf(sent, 'defaultManager.disable')).toHaveLength(1)
		expect(request).not.toHaveBeenCalled()
		expect(screen.queryByText(MANAGED)).toBeNull()
	})

	it('says so when the background does not answer', async () => {
		renderView({ unanswered: ['defaultManager.get'] })
		expect((await entry()).checked).toBe(false)
		expect(screen.getByText('Keepiq is not responding. Reload the extension and reopen this popup.')).toBeTruthy()
	})

	it('is not shown on Firefox', async () => {
		vi.stubEnv('BROWSER', 'firefox')
		const { sent } = renderView()
		await loaded()
		expect(screen.queryByRole('checkbox', { name: NAME })).toBeNull()
		expect(sentOf(sent, 'defaultManager.get')).toEqual([])
	})

	it('is not shown on a browser without address autofill', async () => {
		renderView({ defaultManager: 'unsupported' })
		await loaded()
		await waitFor(() => expect(screen.queryByText(HINT)).toBeNull())
		expect(screen.queryByRole('checkbox', { name: NAME })).toBeNull()
	})

	it('words the entry in Dutch', async () => {
		setLocale('nl')
		renderView({ defaultManager: 'managed' })
		const toggle = await screen.findByRole('checkbox', { name: 'Maak Keepiq je standaard wachtwoordbeheerder' })
		expect(described(toggle)).toEqual([
			'Je organisatie of een andere extensie beheert deze browserinstelling.',
			'Zet het opslaan van wachtwoorden en het invullen van adressen in de browser uit, zodat de suggesties ervan die van Keepiq niet bedekken.',
		])
	})
})
