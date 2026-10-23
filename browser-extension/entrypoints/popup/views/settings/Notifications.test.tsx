// @vitest-environment happy-dom
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { DEFAULT_SHELL, ShellContext } from '../../shell-context'
import { accountSettingsSnapshot, fakeBackground, sentKinds, sentOf } from '../../testing'
import { ExcludedDomains } from './ExcludedDomains'
import { Notifications } from './Notifications'

afterEach(cleanup)

const delivered = { autofillFeatures: ['savePrompts' as const] }
const checkbox = (name: string) => screen.getByRole<HTMLInputElement>('checkbox', { name })
const described = (element: HTMLElement) => (element.getAttribute('aria-describedby') ?? '').split(' ').filter(Boolean).map((id) => document.getElementById(id)!.textContent)
const patches = (sent: ReturnType<typeof fakeBackground>) => sentOf(sent, 'account.settings.set').map((message) => message.patch)

function renderNotifications(options: Parameters<typeof fakeBackground>[0] = {}) {
	const sent = fakeBackground(options)
	const onOpen = vi.fn()
	render(<ShellContext.Provider value={DEFAULT_SHELL}><Notifications onOpen={onOpen} /></ShellContext.Provider>)
	return { sent, onOpen, user: userEvent.setup() }
}

describe('Notifications', () => {
	it('shows the prompts as coming later until ext-autofill delivers them', async () => {
		const { sent, onOpen, user } = renderNotifications({ accountSettings: { values: { askToUpdate: false } } })
		await screen.findByRole('checkbox', { name: 'Ask to add login' })
		for (const name of ['Ask to add login', 'Ask to update existing login']) {
			expect(checkbox(name).getAttribute('aria-disabled')).toBe('true')
			expect(described(checkbox(name))).toEqual(['Coming later'])
		}
		expect(checkbox('Ask to add login').checked).toBe(true)
		expect(checkbox('Ask to update existing login').checked).toBe(false)
		const excluded = screen.getByRole('button', { name: 'Excluded domains' })
		expect(excluded.getAttribute('aria-disabled')).toBe('true')
		expect(described(excluded)).toEqual(['Coming later'])
		expect(excluded.textContent).toContain('Coming later')
		await user.click(excluded)
		await user.click(checkbox('Ask to add login'))
		expect(onOpen).not.toHaveBeenCalled()
		expect(patches(sent)).toEqual([])
	})

	it('stores the prompts and opens Excluded domains once delivered', async () => {
		const { sent, onOpen, user } = renderNotifications({ accountSettings: delivered })
		await user.click(await screen.findByRole('checkbox', { name: 'Ask to update existing login' }))
		await waitFor(() => expect(patches(sent)).toEqual([{ askToUpdate: false }]))
		await user.click(screen.getByRole('button', { name: 'Excluded domains' }))
		expect(onOpen).toHaveBeenCalledWith('excludedDomains')
	})

	it('ends with Keepiq on the web, linking to the web app and reading none of its preferences', async () => {
		const { sent } = renderNotifications({ accountSettings: { webAppUrl: 'https://example.org/cloud/index.php/apps/keepiq/' } })
		const group = await screen.findByRole('region', { name: 'Keepiq on the web' })
		expect(group.textContent).toContain('Keepiq\'s own notifications, import and export, and your master password are managed in Keepiq on Nextcloud, under Personal settings.')
		expect(within(group).getByRole('link', { name: 'Open Keepiq' }).getAttribute('href')).toBe('https://example.org/cloud/index.php/apps/keepiq/')
		expect(new Set(sentKinds(sent))).toEqual(new Set(['account.settings.get']))
	})
})

function renderExcluded(options: Parameters<typeof fakeBackground>[0] = {}) {
	const sent = fakeBackground({ ...options, accountSettings: { ...delivered, ...options.accountSettings } })
	render(<ShellContext.Provider value={DEFAULT_SHELL}><ExcludedDomains /></ShellContext.Provider>)
	return { sent, user: userEvent.setup() }
}

describe('ExcludedDomains', () => {
	it('adds the current site\'s base domain', async () => {
		const { sent, user } = renderExcluded({ tabUrl: 'https://login.example.co.uk/signin' })
		const add = await screen.findByRole<HTMLButtonElement>('button', { name: 'Add current site' })
		await waitFor(() => expect(add.disabled).toBe(false))
		await user.click(add)
		await waitFor(() => expect(patches(sent)).toEqual([{ excludedDomains: ['example.co.uk'] }]))
		expect(screen.getByRole('list').textContent).toContain('example.co.uk')
	})

	it('refuses the current site by its button, leaving the field alone', async () => {
		const { sent, user } = renderExcluded({ tabUrl: 'https://www.example.test/', accountSettings: { values: { excludedDomains: ['example.test'] } } })
		const add = await screen.findByRole<HTMLButtonElement>('button', { name: 'Add current site' })
		await waitFor(() => expect(add.disabled).toBe(false))
		await user.click(add)
		expect((await screen.findByRole('alert')).textContent).toBe('Already excluded')
		expect(screen.getByLabelText('Domain').getAttribute('aria-invalid')).toBeNull()
		expect(patches(sent)).toEqual([])
	})

	it('waits for a write before taking another', async () => {
		let answer!: (reply: unknown) => void
		const { sent, user } = renderExcluded({
			accountSettings: { values: { excludedDomains: ['example.org'] } },
			replies: { 'account.settings.set': () => new Promise((resolve) => (answer = resolve)) },
		})
		await user.type(await screen.findByLabelText('Domain'), 'example.test')
		const add = screen.getByRole('button', { name: 'Add' })
		await user.click(add)
		await waitFor(() => expect(patches(sent)).toHaveLength(1))
		// Refused rather than disabled, so the pressed button keeps focus.
		await user.click(add)
		await user.click(screen.getByRole('button', { name: 'Remove example.org' }))
		await user.click(add)
		expect(document.activeElement).toBe(add)
		expect(patches(sent)).toHaveLength(1)
		answer(null)
	})

	it('refuses a press before the last write has rendered, so it never writes from an old list', async () => {
		let answer!: () => void
		const { sent, user } = renderExcluded({
			tabUrl: 'https://login.example.co.uk/',
			replies: {
				'account.settings.set': (message: { patch: { excludedDomains: string[] } }) => new Promise((resolve) => (answer = () => resolve(
					accountSettingsSnapshot({ ...delivered, values: { excludedDomains: message.patch.excludedDomains } }),
				))),
			},
		})
		const site = await screen.findByRole<HTMLButtonElement>('button', { name: 'Add current site' })
		await waitFor(() => expect(site.disabled).toBe(false))
		await user.type(screen.getByLabelText('Domain'), 'example.test{Enter}')
		await waitFor(() => expect(patches(sent)).toHaveLength(1))
		// Inside one act, the reply lands but its render waits, as between two quick presses.
		await act(async () => {
			answer()
			await new Promise((resolve) => setTimeout(resolve, 0))
			fireEvent.click(site)
			await new Promise((resolve) => setTimeout(resolve, 0))
		})
		await new Promise((resolve) => setTimeout(resolve, 20))
		expect(patches(sent)).toEqual([{ excludedDomains: ['example.test'] }])
	})

	it('reduces free text to a base domain', async () => {
		const { sent, user } = renderExcluded()
		await user.type(await screen.findByLabelText('Domain'), 'HTTPS://Accounts.Example.test/path{Enter}')
		await waitFor(() => expect(patches(sent)).toEqual([{ excludedDomains: ['example.test'] }]))
		expect((screen.getByLabelText('Domain') as HTMLInputElement).value).toBe('')
	})

	it.each([
		['www.example.test', 'Already excluded'],
		['exa mple', 'Enter a domain such as example.org'],
	])('refuses %j with %j', async (input, error) => {
		const { sent, user } = renderExcluded({ accountSettings: { values: { excludedDomains: ['example.test'] } } })
		await user.type(await screen.findByLabelText('Domain'), input)
		const field = screen.getByLabelText('Domain')
		let describedOnFocus: string | null = null
		field.addEventListener('focus', () => (describedOnFocus = field.getAttribute('aria-describedby')))
		await user.click(screen.getByRole('button', { name: 'Add' }))
		const alert = await screen.findByRole('alert')
		expect(alert.textContent).toBe(error)
		expect(document.activeElement).toBe(field)
		expect(describedOnFocus).toContain(alert.id)
		expect(patches(sent)).toEqual([])
	})

	it('reads a refusal out when Enter left focus in the field', async () => {
		const { user } = renderExcluded()
		await user.type(await screen.findByLabelText('Domain'), 'exa mple{Enter}')
		expect((await screen.findByRole('alert')).textContent).toBe('Enter a domain such as example.org')
	})

	it('refuses an empty entry', async () => {
		const { sent, user } = renderExcluded()
		await screen.findByLabelText('Domain')
		await user.click(screen.getByRole('button', { name: 'Add' }))
		expect(await screen.findByText('Enter a domain')).toBeTruthy()
		expect(patches(sent)).toEqual([])
	})

	it('removes an entry', async () => {
		const { sent, user } = renderExcluded({ accountSettings: { values: { excludedDomains: ['example.test', 'example.org'] } } })
		await user.click(await screen.findByRole('button', { name: 'Remove example.test' }))
		await waitFor(() => expect(patches(sent)).toEqual([{ excludedDomains: ['example.org'] }]))
		expect(screen.queryByText('example.test')).toBeNull()
		await waitFor(() => expect(document.activeElement).toBe(screen.getByRole('button', { name: 'Remove example.org' })))
	})

	it('focuses the previous row after removing the last, and the field once the list empties', async () => {
		const { user } = renderExcluded({ accountSettings: { values: { excludedDomains: ['example.test', 'example.org'] } } })
		await user.click(await screen.findByRole('button', { name: 'Remove example.org' }))
		await waitFor(() => expect(document.activeElement).toBe(screen.getByRole('button', { name: 'Remove example.test' })))
		await user.click(screen.getByRole('button', { name: 'Remove example.test' }))
		await waitFor(() => expect(document.activeElement).toBe(screen.getByLabelText('Domain')))
	})

	it('cannot add the current site on a browser page', async () => {
		renderExcluded({ tabUrl: 'chrome://newtab/' })
		await screen.findByLabelText('Domain')
		// Wait for the tab read, then check it stays disabled.
		await waitFor(() => expect(browser.tabs.query).toHaveBeenCalled())
		expect(screen.getByRole<HTMLButtonElement>('button', { name: 'Add current site' }).disabled).toBe(true)
	})
})
