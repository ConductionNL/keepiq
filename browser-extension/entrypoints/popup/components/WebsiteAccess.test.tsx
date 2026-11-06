// @vitest-environment happy-dom
import { cleanup, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { setLocale } from '@/src/testing/i18n'
import { SITE_ORIGINS } from '@/src/website-access'
import { SettingsProvider } from '../hooks/useSettings'
import { fakeBackground, fakePermissions, sentOf } from '../testing'
import { WebsiteAccessCard, WebsiteAccessEntry } from './WebsiteAccess'

const SERVER_URL = 'https://cloud.example.org/nextcloud'
const SERVER = 'https://cloud.example.org/*'
const STEPS = 'In Safari, choose Safari > Settings, then Websites. Select Keepiq in the list and set "When visiting other websites" to Allow.'
const PER_SITE = 'To allow one site at a time, click the Keepiq button on that site and choose to always allow it.'

function renderCard(options: Parameters<typeof fakeBackground>[0] = {}) {
	const sent = fakeBackground(options)
	render(<SettingsProvider><WebsiteAccessCard serverUrl={SERVER_URL} /></SettingsProvider>)
	return { sent, user: userEvent.setup() }
}

const sitesCard = () => screen.findByRole('region', { name: 'Let Keepiq work on websites' })
const serverCard = () => screen.findByRole('region', { name: 'Keepiq can\'t reach cloud.example.org' })
/** The read on mount has settled. */
const settled = () => waitFor(() => expect(browser.permissions.contains).toHaveBeenCalled())

beforeEach(() => vi.stubEnv('BROWSER', 'safari'))
afterEach(() => {
	cleanup()
	vi.unstubAllEnvs()
})

describe('WebsiteAccessCard', () => {
	it('asks for every website while the server is granted and the sites are not', async () => {
		fakePermissions([SERVER])
		renderCard()
		const card = await sitesCard()
		expect(card.textContent).toContain('Safari runs Keepiq only on websites you allow. Allow it on every website to fill in logins, save new ones and use passkeys wherever you sign in.')
		expect(screen.getByRole('button', { name: 'Allow on every website' })).toBeTruthy()
		expect(screen.getByRole('button', { name: 'Not now' })).toBeTruthy()
	})

	it('is not shown with the server and every website granted', async () => {
		fakePermissions([SERVER, ...SITE_ORIGINS])
		renderCard()
		await settled()
		expect(screen.queryByRole('region')).toBeNull()
	})

	it('names the server while it is not granted, without Not now, also after an earlier Not now', async () => {
		fakePermissions([...SITE_ORIGINS])
		renderCard({ settings: { websiteAccessDismissed: true } })
		const card = await serverCard()
		expect(card.textContent).toContain('Safari doesn\'t let Keepiq reach your server, so your vault can\'t sync.')
		expect(screen.getByRole('button', { name: 'Allow cloud.example.org' })).toBeTruthy()
		expect(screen.queryByRole('button', { name: 'Not now' })).toBeNull()
	})

	it('hides the sites card after Not now, also on the next open', async () => {
		fakePermissions([SERVER])
		const { sent, user } = renderCard()
		await user.click(await screen.findByRole('button', { name: 'Not now' }))
		expect(screen.queryByRole('region')).toBeNull()
		await waitFor(() => expect(sentOf(sent, 'settings.set')).toEqual([{ kind: 'settings.set', patch: { websiteAccessDismissed: true } }]))
		cleanup()
		render(<SettingsProvider><WebsiteAccessCard serverUrl={SERVER_URL} /></SettingsProvider>)
		await settled()
		await waitFor(() => expect(sentOf(sent, 'settings.get').length).toBeGreaterThan(1))
		expect(screen.queryByRole('region')).toBeNull()
	})

	it('requests both site patterns in the click and goes once Safari grants them', async () => {
		const permissions = fakePermissions([SERVER])
		const { user } = renderCard()
		await user.click(await screen.findByRole('button', { name: 'Allow on every website' }))
		expect(permissions.request).toHaveBeenCalledWith({ origins: SITE_ORIGINS })
		await waitFor(() => expect(screen.queryByRole('region')).toBeNull())
	})

	it('shows the Settings steps when the request leaves every website ungranted', async () => {
		const permissions = fakePermissions([SERVER])
		permissions.answer((origins) => origins.slice(0, 1))
		const { user } = renderCard()
		await user.click(await screen.findByRole('button', { name: 'Allow on every website' }))
		const status = await screen.findByRole('status')
		expect(status.textContent).toBe(STEPS + PER_SITE)
		expect((await sitesCard()).contains(status)).toBe(true)
	})

	it('treats a rejected request as nothing granted', async () => {
		const permissions = fakePermissions([SERVER])
		permissions.request.mockRejectedValue(new Error('no gesture'))
		const { user } = renderCard()
		await user.click(await screen.findByRole('button', { name: 'Allow on every website' }))
		expect((await screen.findByRole('status')).textContent).toBe(STEPS + PER_SITE)
	})

	it('requests the server origin, then shows the sites card', async () => {
		const permissions = fakePermissions([])
		const { user } = renderCard()
		await user.click(await screen.findByRole('button', { name: 'Allow cloud.example.org' }))
		expect(permissions.request).toHaveBeenCalledWith({ origins: [SERVER] })
		expect(await sitesCard()).toBeTruthy()
		expect(screen.queryByRole('status')).toBeNull()
	})

	it('follows a permission added or removed while open', async () => {
		const permissions = fakePermissions([SERVER])
		renderCard()
		await sitesCard()
		permissions.change('onAdded', SITE_ORIGINS)
		await waitFor(() => expect(screen.queryByRole('region')).toBeNull())
		permissions.change('onRemoved', [SERVER])
		expect(await serverCard()).toBeTruthy()
	})

	it('works from the keyboard', async () => {
		const permissions = fakePermissions([SERVER])
		const { user } = renderCard()
		await sitesCard()
		await user.tab()
		expect(document.activeElement).toBe(screen.getByRole('button', { name: 'Allow on every website' }))
		await user.keyboard('{Enter}')
		expect(permissions.request).toHaveBeenCalledWith({ origins: SITE_ORIGINS })
		await waitFor(() => expect(screen.queryByRole('region')).toBeNull())
	})

	it('is worded in Dutch', async () => {
		setLocale('nl')
		const permissions = fakePermissions([SERVER])
		permissions.answer(() => [])
		const { user } = renderCard()
		const card = await screen.findByRole('region', { name: 'Laat Keepiq werken op websites' })
		expect(card.textContent).toContain('Safari laat Keepiq alleen werken op websites die je toestaat. Sta het toe op alle websites om overal inloggegevens in te vullen, nieuwe op te slaan en passkeys te gebruiken.')
		expect(screen.getByRole('button', { name: 'Niet nu' })).toBeTruthy()
		await user.click(screen.getByRole('button', { name: 'Op alle websites toestaan' }))
		expect((await screen.findByRole('status')).textContent).toBe(
			'Kies in Safari Safari > Instellingen en dan Websites. Selecteer Keepiq in de lijst en zet "Bij bezoek aan andere websites" op Sta toe.'
			+ 'Wil je per website toestaan? Klik op die website op de Keepiq-knop en kies om het altijd toe te staan.',
		)
	})

	it('names the server in Dutch', async () => {
		setLocale('nl')
		fakePermissions([])
		renderCard()
		const card = await screen.findByRole('region', { name: 'Keepiq kan cloud.example.org niet bereiken' })
		expect(card.textContent).toContain('Safari geeft Keepiq geen toegang tot je server, dus je kluis kan niet synchroniseren.')
		expect(screen.getByRole('button', { name: 'cloud.example.org toestaan' })).toBeTruthy()
	})
})

describe('WebsiteAccessEntry', () => {
	function renderEntry() {
		fakeBackground({ settings: { websiteAccessDismissed: true } })
		render(<SettingsProvider><WebsiteAccessEntry serverUrl={SERVER_URL} /></SettingsProvider>)
		return userEvent.setup()
	}

	it.each([
		[[SERVER, ...SITE_ORIGINS], 'Every website', null],
		[[SERVER], 'Only websites you allowed', 'Allow on every website'],
		[[], 'Not your Keepiq server', 'Allow cloud.example.org'],
	])('reads %j as %s, ignoring Not now', async (granted, text, button) => {
		fakePermissions(granted)
		renderEntry()
		expect(await screen.findByText(text)).toBeTruthy()
		expect(screen.getByText('Website access')).toBeTruthy()
		if (button) expect(screen.getByRole('button', { name: button }).getAttribute('aria-describedby')).toBeTruthy()
		else expect(screen.queryByRole('button')).toBeNull()
	})

	it('shows the steps after a request that granted nothing, then follows a later grant', async () => {
		const permissions = fakePermissions([SERVER])
		permissions.answer(() => [])
		const user = renderEntry()
		await user.click(await screen.findByRole('button', { name: 'Allow on every website' }))
		expect((await screen.findByRole('status')).textContent).toBe(STEPS + PER_SITE)
		permissions.change('onAdded', SITE_ORIGINS)
		expect(await screen.findByText('Every website')).toBeTruthy()
		expect(screen.queryByRole('status')).toBeNull()
	})

	it('is worded in Dutch', async () => {
		setLocale('nl')
		fakePermissions([SERVER])
		renderEntry()
		expect(await screen.findByText('Alleen websites die je toestond')).toBeTruthy()
		expect(screen.getByText('Toegang tot websites')).toBeTruthy()
	})
})
