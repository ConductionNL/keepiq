// @vitest-environment happy-dom
import { cleanup, render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { DEFAULT_SHELL, ShellContext } from '../../shell-context'
import { fakeBackground } from '../../testing'
import { About } from './About'
import { ABOUT_LINKS } from './about-links'

afterEach(() => {
	cleanup()
	vi.unstubAllEnvs()
	ABOUT_LINKS.rate.chrome = null
	ABOUT_LINKS.rate.firefox = null
	ABOUT_LINKS.rate.safari = null
})

const STORES = {
	chrome: 'https://chromewebstore.google.com/detail/keepiq',
	firefox: 'https://addons.mozilla.org/firefox/addon/keepiq/',
	safari: 'https://apps.apple.com/app/keepiq/id0',
}

function renderView(options: Parameters<typeof fakeBackground>[0] = {}) {
	fakeBackground(options)
	render(<ShellContext.Provider value={DEFAULT_SHELL}><About /></ShellContext.Provider>)
}

const href = (name: string) => screen.getByRole('link', { name }).getAttribute('href')

describe('About', () => {
	it('shows the versions, the server and the links', async () => {
		renderView({ accountSettings: { serverUrl: 'https://example.org', keepiqVersion: '0.3.12' } })
		expect(await screen.findByText('Version 0.0.1')).toBeTruthy()
		expect(screen.getByText('Server https://example.org')).toBeTruthy()
		expect(screen.getByText('Keepiq 0.3.12')).toBeTruthy()
		expect(href('Help')).toBe('https://keepiq.conduction.nl/')
		expect(href('Report a bug')).toBe('https://github.com/ConductionNL/keepiq/issues/new?template=bug-report.yml')
		expect(href('Contact support')).toBe('mailto:support@conduction.nl')
		expect(href('Privacy policy')).toBe('https://keepiq.conduction.nl/docs/browser-extension/privacy/')
		expect(href('Keepiq web app')).toBe('https://cloud.example.org/index.php/apps/keepiq/')
	})

	it('shows the version as received', async () => {
		renderView({ accountSettings: { keepiqVersion: '0.4.0-unstable.3' } })
		expect(await screen.findByText('Keepiq 0.4.0-unstable.3')).toBeTruthy()
	})

	it('says when the Keepiq version is unknown', async () => {
		renderView({ accountSettings: { keepiqVersion: null } })
		expect(await screen.findByText('Keepiq version unknown')).toBeTruthy()
	})

	it('hides Rate the extension while the store address is unpublished', async () => {
		renderView()
		await screen.findByText('Version 0.0.1')
		expect(screen.queryByRole('link', { name: 'Rate the extension' })).toBeNull()
	})

	it.each([['chrome', STORES.chrome], ['edge', STORES.chrome], ['firefox', STORES.firefox], ['safari', STORES.safari]])('rates on %s in its store', async (target, store) => {
		Object.assign(ABOUT_LINKS.rate, STORES)
		vi.stubEnv('BROWSER', target)
		renderView()
		await screen.findByText('Version 0.0.1')
		expect(href('Rate the extension')).toBe(store)
	})

	it('hides Rate the extension on Safari while its listing is unpublished', async () => {
		Object.assign(ABOUT_LINKS.rate, { ...STORES, safari: null })
		vi.stubEnv('BROWSER', 'safari')
		renderView()
		await screen.findByText('Version 0.0.1')
		expect(screen.queryByRole('link', { name: 'Rate the extension' })).toBeNull()
	})
})
