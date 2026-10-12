// @vitest-environment happy-dom
import { cleanup, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import App from '../../App'
import { everythingVisible, fakeBackground, popupState } from '../../testing'
import { applyTheme, THEME_MIRROR_KEY } from '../../theme'

afterEach(() => {
	cleanup()
	localStorage.clear()
	applyTheme('system')
})
beforeEach(everythingVisible)

const title = () => screen.findByRole('heading', { level: 1 })
const theme = () => document.documentElement.dataset.theme

async function openSettings() {
	const user = userEvent.setup()
	render(<App />)
	await title()
	await user.click(within(screen.getByRole('navigation', { name: 'Main' })).getByRole('button', { name: 'Settings' }))
	return user
}

describe('Settings', () => {
	it('lists the sections in order, with only Appearance open', async () => {
		fakeBackground()
		const user = await openSettings()
		const rows = within(screen.getByRole('list')).getAllByRole('button')
		expect(rows.map((row) => row.textContent)).toEqual(['Account security', 'Autofill', 'Notifications', 'Vault', 'Appearance', 'About'])
		const unbuilt = rows.filter((row) => row.getAttribute('aria-disabled') === 'true')
		expect(unbuilt.map((row) => row.textContent)).toEqual(['Account security', 'Autofill', 'Notifications', 'Vault', 'About'])
		expect(unbuilt.every((row) => row.title === 'Available in a later update')).toBe(true)
		await user.click(rows[0]!)
		expect((await title()).textContent).toBe('Settings')
	})

	it('opens Appearance with the stored theme and goes back to the list', async () => {
		fakeBackground({ settings: { theme: 'light' } })
		const user = await openSettings()
		await user.click(screen.getByRole('button', { name: 'Appearance' }))
		expect((await title()).textContent).toBe('Appearance')
		expect((await screen.findByRole<HTMLSelectElement>('combobox', { name: 'Theme' })).value).toBe('light')
		await user.click(screen.getByRole('button', { name: 'Back' }))
		expect((await title()).textContent).toBe('Settings')
		expect(screen.getByRole('button', { name: 'Appearance' })).toBeTruthy()
	})

	it('repaints and mirrors a new theme at once', async () => {
		const sent = fakeBackground()
		const user = await openSettings()
		await user.click(screen.getByRole('button', { name: 'Appearance' }))
		await user.selectOptions(await screen.findByRole('combobox', { name: 'Theme' }), 'Dark')
		expect(sent).toHaveBeenCalledWith({ kind: 'settings.set', patch: { theme: 'dark' } })
		await waitFor(() => expect(theme()).toBe('dark'))
		expect(localStorage.getItem(THEME_MIRROR_KEY)).toBe('dark')
	})

	it('keeps the stored theme when the background does not answer', async () => {
		fakeBackground({ unanswered: ['settings.set'] })
		const user = await openSettings()
		await user.click(screen.getByRole('button', { name: 'Appearance' }))
		const select = await screen.findByRole<HTMLSelectElement>('combobox', { name: 'Theme' })
		await user.selectOptions(select, 'Dark')
		await screen.findByText('Keepiq is not responding. Reload the extension and reopen this popup.')
		expect(select.value).toBe('system')
		expect(theme()).toBeUndefined()
	})

	it('returns to the list after leaving the tab', async () => {
		fakeBackground()
		const user = await openSettings()
		await user.click(screen.getByRole('button', { name: 'Appearance' }))
		const tabs = within(screen.getByRole('navigation', { name: 'Main' }))
		await user.click(tabs.getByRole('button', { name: 'Vault' }))
		await user.click(tabs.getByRole('button', { name: 'Settings' }))
		expect((await title()).textContent).toBe('Settings')
		expect(screen.queryByRole('combobox', { name: 'Theme' })).toBeNull()
	})
})

describe('the stored theme', () => {
	it('applies to the unlock screen and replaces a stale mirror', async () => {
		localStorage.setItem(THEME_MIRROR_KEY, 'light')
		fakeBackground({ state: popupState({ screen: 'unlock' }), settings: { theme: 'dark' } })
		render(<App />)
		expect((await title()).textContent).toBe('Unlock')
		await waitFor(() => expect(theme()).toBe('dark'))
		expect(localStorage.getItem(THEME_MIRROR_KEY)).toBe('dark')
	})

	it('follows a change made in another window', async () => {
		fakeBackground()
		render(<App />)
		await title()
		window.dispatchEvent(new StorageEvent('storage', { key: THEME_MIRROR_KEY, newValue: 'light' }))
		expect(theme()).toBe('light')
	})
})
