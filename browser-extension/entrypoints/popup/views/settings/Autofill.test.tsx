// @vitest-environment happy-dom
import { cleanup, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { AutofillFeature } from '@/src/messages'
import { DEFAULT_SHELL, ShellContext } from '../../shell-context'
import { fakeBackground, fakeClipboard, sentOf } from '../../testing'
import { Autofill } from './Autofill'

afterEach(() => {
	cleanup()
	vi.unstubAllEnvs()
})

const ALL: AutofillFeature[] = ['pageLoad', 'uriMatch', 'inlineSuggestions', 'contextMenu', 'savePrompts']

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
	it('shows every entry as coming later, with its stored value, until ext-autofill delivers it', async () => {
		const { sent, user } = renderView({ accountSettings: { values: { contextMenu: false } } })
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

	it('warns under Autofill on page load once on, and enables the default setting', async () => {
		const { sent, user } = renderView({ accountSettings: { autofillFeatures: ALL } })
		await loaded()
		expect(select('Default autofill setting for login items').disabled).toBe(true)
		await user.click(checkbox('Autofill on page load'))
		await waitFor(() => expect(checkbox('Autofill on page load').checked).toBe(true))
		expect(patches(sent)).toEqual([{ autofillOnPageLoad: true }])
		expect(described(checkbox('Autofill on page load'))).toEqual(['Compromised or untrusted websites can exploit autofill on page load'])
		expect(select('Default autofill setting for login items').disabled).toBe(false)
	})

	it('notes that a regular expression can break matching', async () => {
		const { sent, user } = renderView({ accountSettings: { autofillFeatures: ALL } })
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
