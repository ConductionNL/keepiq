// @vitest-environment happy-dom
import { cleanup, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { setLocale } from '@/src/testing/i18n'
import { SettingsProvider } from '../../hooks/useSettings'
import { fakeBackground, mounts, sentOf } from '../../testing'
import { applyAppearance, applyTheme } from '../../theme'
import { Appearance } from './Appearance'

afterEach(() => {
	cleanup()
	applyTheme('system')
	applyAppearance({ compactMode: false, showAnimations: true })
})

function renderView(options: Parameters<typeof fakeBackground>[0] = {}) {
	const sent = fakeBackground(options)
	render(<SettingsProvider><Appearance /></SettingsProvider>)
	return { sent, user: userEvent.setup() }
}

const checkbox = (name: string) => screen.findByRole<HTMLInputElement>('checkbox', { name })
const root = () => document.documentElement.classList

describe('Appearance', () => {
	it('lists Theme first, then the entries with their stored values', async () => {
		renderView({ settings: { quickCopy: false } })
		expect((await checkbox('Compact mode')).checked).toBe(false)
		expect((await checkbox('Show animations')).checked).toBe(true)
		expect((await checkbox('Show quick copy actions on vault')).checked).toBe(false)
		const labels = [...document.querySelectorAll('.field__label, .check')].map((element) => element.textContent)
		expect(labels.slice(0, 4)).toEqual(['Theme', 'Compact mode', 'Show animations', 'Show quick copy actions on vault'])
	})

	it('tightens the popup at once in compact mode', async () => {
		const { sent, user } = renderView()
		await user.click(await checkbox('Compact mode'))
		expect(sentOf(sent, 'settings.set')).toEqual([{ kind: 'settings.set', patch: { compactMode: true } }])
		await waitFor(() => expect(root().contains('compact')).toBe(true))
	})

	it('stops animations at once', async () => {
		const { user } = renderView()
		await user.click(await checkbox('Show animations'))
		await waitFor(() => expect(root().contains('no-animations')).toBe(true))
	})

	it('stores quick copy', async () => {
		const { sent, user } = renderView()
		await user.click(await checkbox('Show quick copy actions on vault'))
		await waitFor(() => expect(sentOf(sent, 'settings.set')).toEqual([{ kind: 'settings.set', patch: { quickCopy: false } }]))
	})

	it('reads the settings again once the background answers after a failed first read', async () => {
		vi.spyOn(console, 'error').mockImplementation(() => {})
		let reads = 0
		renderView({ replies: { 'settings.get': () => (++reads <= mounts() ? undefined : { theme: 'system', compactMode: true, showAnimations: true, quickCopy: true }) } })
		expect((await checkbox('Compact mode')).checked).toBe(true)
	})

	it('shows the browser\'s language without a control', async () => {
		renderView()
		await checkbox('Compact mode')
		const language = screen.getByRole('group', { name: 'Language' })
		expect(language.textContent).toContain('English')
		expect(language.textContent).toContain('Follows your browser')
		expect(language.querySelector('input, select, button')).toBeNull()
	})

	it('names the language in Dutch in a Dutch browser', async () => {
		setLocale('nl')
		renderView()
		await checkbox('Compacte weergave')
		expect(screen.getByRole('group', { name: 'Taal' }).textContent).toContain('Nederlands')
	})
})
