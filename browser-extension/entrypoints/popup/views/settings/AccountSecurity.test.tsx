// @vitest-environment happy-dom
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { DEFAULT_SHELL, ShellContext } from '../../shell-context'
import { account, accountSettingsSnapshot, fakeBackground, sentOf } from '../../testing'
import { AccountSecurity } from './AccountSecurity'

afterEach(cleanup)

function renderView(options: Parameters<typeof fakeBackground>[0] = {}) {
	const sent = fakeBackground(options)
	const refreshState = vi.fn()
	render(
		<ShellContext.Provider value={{ ...DEFAULT_SHELL, refreshState }}>
			<AccountSecurity account={account({ status: 'unlocked' })} />
		</ShellContext.Provider>,
	)
	return { sent, refreshState, user: userEvent.setup() }
}

const timeout = () => screen.findByRole<HTMLSelectElement>('combobox', { name: 'Vault timeout' })
const optionLabels = (select: HTMLSelectElement) => [...select.options].map((option) => option.textContent)
const described = (element: HTMLElement) => {
	const id = element.getAttribute('aria-describedby')
	return id ? id.split(' ').map((part) => document.getElementById(part)!.textContent) : []
}
const patches = (sent: ReturnType<typeof fakeBackground>) => sentOf(sent, 'account.settings.set').map((message) => message.patch)

describe('AccountSecurity', () => {
	it('shows the stored timeout and action, and the unlock entries', async () => {
		renderView()
		expect((await timeout()).value).toBe('15')
		expect(described(await timeout())).toEqual([])
		expect(screen.getByRole<HTMLSelectElement>('combobox', { name: 'Timeout action' }).value).toBe('lock')
		const pin = screen.getByRole<HTMLInputElement>('checkbox', { name: 'Unlock with PIN' })
		expect(pin.checked).toBe(false)
		expect(described(pin)).toEqual(['After a browser restart you enter your master password once; then your PIN works again.'])
		const biometrics = screen.getByRole<HTMLInputElement>('checkbox', { name: 'Unlock with biometrics' })
		expect(biometrics.checked).toBe(false)
		expect(biometrics.getAttribute('aria-disabled')).toBe('true')
		expect(described(biometrics)).toEqual(['Not available yet'])
	})

	it('links Change master password to the account\'s Keepiq web app', async () => {
		renderView({ accountSettings: { webAppUrl: 'https://example.org/cloud/index.php/apps/keepiq/' } })
		const link = await screen.findByRole('link', { name: 'Change master password' })
		expect(link.getAttribute('href')).toBe('https://example.org/cloud/index.php/apps/keepiq/')
		expect(link.getAttribute('target')).toBe('_blank')
	})

	it('stores Never only after the warning is confirmed', async () => {
		const { sent, user } = renderView()
		await user.selectOptions(await timeout(), 'never')
		const dialog = screen.getByRole('dialog', { name: 'Keep the vault unlocked?' })
		expect(dialog.textContent).toContain('Your vault stays unlocked until you lock it by hand. The key that opens it is written to this browser profile\'s disk, where anyone who can read the profile can read it.')
		expect(patches(sent)).toEqual([])
		await user.click(within(dialog).getByRole('button', { name: 'Keep unlocked' }))
		await waitFor(() => expect(patches(sent)).toEqual([{ vaultTimeout: 'never' }]))
		expect(screen.queryByRole('dialog')).toBeNull()
		expect((await timeout()).value).toBe('never')
	})

	it('keeps the timeout when the Never warning is cancelled', async () => {
		const { sent, user } = renderView()
		await user.selectOptions(await timeout(), 'never')
		await user.click(screen.getByRole('button', { name: 'Cancel' }))
		expect(screen.queryByRole('dialog')).toBeNull()
		expect((await timeout()).value).toBe('15')
		expect(patches(sent)).toEqual([])
	})

	it('shows a stored Never without a note while no maximum was read', async () => {
		renderView({ accountSettings: { values: { vaultTimeout: 'never' } } })
		const select = await timeout()
		expect(select.value).toBe('never')
		expect(described(select)).toEqual([])
	})

	it('clamps to the organisation maximum and says so', async () => {
		renderView({ accountSettings: { maxIdleMinutes: 30, values: { vaultTimeout: 240 } } })
		const select = await timeout()
		expect(optionLabels(select)).not.toContain('1 hour')
		expect(optionLabels(select)).not.toContain('4 hours')
		expect(optionLabels(select)).not.toContain('On browser restart')
		expect(optionLabels(select)).not.toContain('Never')
		expect(select.value).toBe('30')
		expect(described(select)).toEqual(['Set by your organisation'])
	})

	it('words On system lock with the maximum', async () => {
		renderView({ accountSettings: { maxIdleMinutes: 60, values: { vaultTimeout: 'onSystemLock' } } })
		const select = await timeout()
		expect(select.selectedOptions[0]!.textContent).toBe('On system lock, or after 1 h idle (set by your organisation)')
	})

	it('stores a custom timeout in minutes and names it', async () => {
		const { sent, user } = renderView({ accountSettings: { maxIdleMinutes: 240 } })
		await user.selectOptions(await timeout(), 'custom')
		expect(screen.getByLabelText<HTMLInputElement>('Minutes').value).toBe('15')
		await user.clear(screen.getByLabelText('Minutes'))
		await user.type(screen.getByLabelText('Hours'), '{Backspace}2')
		await user.type(screen.getByLabelText('Minutes'), '30')
		await user.click(screen.getByRole('button', { name: 'Save' }))
		await waitFor(() => expect(patches(sent)).toEqual([{ vaultTimeout: 150 }]))
		expect((await timeout()).selectedOptions[0]!.textContent).toBe('2 h 30 min')
		expect(screen.queryByLabelText('Hours')).toBeNull()
	})

	it('edits a stored custom timeout from Custom, prefilled', async () => {
		const { sent, user } = renderView({ accountSettings: { values: { vaultTimeout: 150 } } })
		const select = await timeout()
		expect(select.value).toBe('150')
		await user.selectOptions(select, 'custom')
		expect(screen.getByLabelText<HTMLInputElement>('Hours').value).toBe('2')
		const minutes = screen.getByLabelText<HTMLInputElement>('Minutes')
		expect(minutes.value).toBe('30')
		await user.clear(minutes)
		await user.type(minutes, '45{Enter}')
		await waitFor(() => expect(patches(sent)).toEqual([{ vaultTimeout: 165 }]))
		expect((await timeout()).selectedOptions[0]!.textContent).toBe('2 h 45 min')
	})

	it('shows a clamped value that is no preset as its own duration', async () => {
		renderView({ accountSettings: { maxIdleMinutes: 120, values: { vaultTimeout: 240 } } })
		const select = await timeout()
		expect(select.value).toBe('120')
		expect(select.selectedOptions[0]!.textContent).toBe('2 h')
	})

	it('restores the stored choice once the maximum rises', async () => {
		let maxIdleMinutes = 30
		fakeBackground({ accountSettings: { values: { vaultTimeout: 240 } }, replies: { 'account.settings.get': () => accountSettingsSnapshot({ maxIdleMinutes, values: { vaultTimeout: 240 } }) } })
		const shell = { ...DEFAULT_SHELL, refreshState: vi.fn() }
		const view = <ShellContext.Provider value={shell}><AccountSecurity account={account({ status: 'unlocked' })} /></ShellContext.Provider>
		const { unmount } = render(view)
		expect((await timeout()).value).toBe('30')
		unmount()
		maxIdleMinutes = 240
		render(view)
		const select = await timeout()
		await waitFor(() => expect(select.value).toBe('240'))
		expect(described(select)).toEqual([])
	})

	it.each([
		['0', '0', 'Enter at least one minute'],
		['5', '0', 'Your organisation allows at most 4 h'],
		['1.5', '0', 'Enter whole hours and minutes'],
	])('refuses a custom %s h %s min', async (hours, minutes, error) => {
		const { sent, user } = renderView({ accountSettings: { maxIdleMinutes: 240 } })
		await user.selectOptions(await timeout(), 'custom')
		const hoursField = screen.getByLabelText('Hours')
		// Set at once: typing a decimal point into a number field is not reliable in happy-dom.
		fireEvent.change(hoursField, { target: { value: hours } })
		await user.clear(screen.getByLabelText('Minutes'))
		await user.type(screen.getByLabelText('Minutes'), `${minutes}{Enter}`)
		expect(screen.getByRole('alert').textContent).toBe(error)
		for (const field of [hoursField, screen.getByLabelText('Minutes')]) {
			expect(field.getAttribute('aria-invalid')).toBe('true')
			expect(described(field)).toEqual([error])
		}
		expect(patches(sent)).toEqual([])
	})

	it('focuses Cancel first in the Never warning', async () => {
		const { user } = renderView()
		await user.selectOptions(await timeout(), 'never')
		expect(document.activeElement).toBe(screen.getByRole('button', { name: 'Cancel' }))
	})

	it.each([
		['Lock now', 'vault.lock'],
		['Log out', 'accounts.remove'],
	])('says why %s failed', async (name, kind) => {
		const { user } = renderView({ replies: { [kind]: { ok: false, code: 'not_responding' } } })
		await user.click(await screen.findByRole('button', { name }))
		if (name === 'Log out') await user.click(within(screen.getByRole('group')).getByRole('button', { name: 'Log out' }))
		expect((await screen.findByRole('alert')).textContent).toBe('Keepiq is not responding. Reload the extension and reopen this popup.')
	})

	it('stores the Log out action and explains it', async () => {
		const { sent, user } = renderView()
		const action = await screen.findByRole('combobox', { name: 'Timeout action' })
		expect(described(action)).toEqual([])
		await user.selectOptions(action, 'logout')
		await waitFor(() => expect(patches(sent)).toEqual([{ vaultTimeoutAction: 'logout' }]))
		expect(described(action)).toEqual(['When the timeout ends you are logged out and need the app password again'])
	})

	it('locks now', async () => {
		const { sent, refreshState, user } = renderView()
		await user.click(await screen.findByRole('button', { name: 'Lock now' }))
		expect(sentOf(sent, 'vault.lock')).toEqual([{ kind: 'vault.lock', accountId: 'a1' }])
		await waitFor(() => expect(refreshState).toHaveBeenCalled())
	})

	it('logs out after confirmation, and a cancel changes nothing', async () => {
		const { sent, refreshState, user } = renderView()
		await user.click(await screen.findByRole('button', { name: 'Log out' }))
		await user.click(screen.getByRole('button', { name: 'Cancel' }))
		expect(sentOf(sent, 'accounts.remove')).toEqual([])
		expect(document.activeElement).toBe(screen.getByRole('button', { name: 'Log out' }))
		await user.click(screen.getByRole('button', { name: 'Log out' }))
		const confirm = screen.getByRole('group', { name: 'Log out of Alice Doe on cloud.example.org?' })
		await user.click(within(confirm).getByRole('button', { name: 'Log out' }))
		expect(sentOf(sent, 'accounts.remove')).toEqual([{ kind: 'accounts.remove', accountId: 'a1' }])
		await waitFor(() => expect(refreshState).toHaveBeenCalled())
	})

	it('turns the PIN on through its dialog and off at once', async () => {
		const { sent, user } = renderView()
		const pin = await screen.findByRole<HTMLInputElement>('checkbox', { name: 'Unlock with PIN' })
		await user.click(pin)
		expect(pin.checked).toBe(false)
		const dialog = screen.getByRole('dialog', { name: 'Unlock with PIN' })
		await user.type(within(dialog).getByLabelText('Master password'), 'hunter2')
		await user.type(within(dialog).getByLabelText('PIN'), '246801')
		await user.type(within(dialog).getByLabelText('Confirm PIN'), '246801')
		await user.click(within(dialog).getByRole('button', { name: 'Turn on' }))
		await waitFor(() => expect(pin.checked).toBe(true))
		expect(screen.queryByRole('dialog')).toBeNull()
		await user.click(pin)
		await waitFor(() => expect(pin.checked).toBe(false))
		expect(sentOf(sent, 'pin.remove')).toEqual([{ kind: 'pin.remove', accountId: 'a1' }])
	})
})
