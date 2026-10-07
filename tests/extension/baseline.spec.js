/**
 * @spec openspec/specs/extension-baseline/spec.md
 *
 * Decisions Keepiq's extension already made, held by tests: one account per
 * user and server, nothing of a send left in extension storage, network
 * requests from the worker only, and no site icons fetched.
 */
import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join, resolve } from 'node:path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import {
	installChrome,
	installServer,
	makeVault,
	POPUP,
} from './fixtures/fakeBrowser.js'

const SERVER = 'https://one.example'
const SRC = resolve(__dirname, '../../browser-extension/src')

/**
 * Every .js and .html file under a folder.
 *
 * @param {string} dir The folder.
 * @return {Array<string>}
 */
function sources(dir) {
	return readdirSync(dir).flatMap((name) => {
		const path = join(dir, name)
		if (statSync(path).isDirectory()) return sources(path)
		return /\.(js|html)$/.test(name) ? [path] : []
	})
}

let router
let browser

beforeEach(async () => {
	vi.resetModules()
	browser = installChrome()
	const fixture = await makeVault('m', 'x')
	installServer({ [SERVER]: { ...fixture, sends: [] } })
	router = await import('../../browser-extension/src/background/router.js')
	await router.handleMessage(
		{ type: 'pair', payload: { url: SERVER, user: 'ann', appPassword: 'p' } },
		POPUP,
	)
})

describe('baseline', () => {
	it('refuses the same user on the same server twice', async () => {
		const again = await router.handleMessage(
			{
				type: 'pair',
				payload: { url: SERVER + '/', user: 'ann', appPassword: 'p2' },
			},
			POPUP,
		)
		expect(again.error).toBe('This account is already connected.')
	})

	it('keeps no send content or link in extension storage', async () => {
		await router.handleMessage(
			{ type: 'unlock', payload: { masterPassword: 'm' } },
			POPUP,
		)
		const res = await router.handleMessage(
			{
				type: 'send-create',
				payload: {
					payloadType: 'text',
					text: 'Lighthouse-Rumble-42',
					maxViews: 1,
					expiry: '1d',
				},
			},
			POPUP,
		)
		expect(res.link).toMatch(/#k=/)
		const stored = JSON.stringify([
			Object.fromEntries(browser.storage),
			Object.fromEntries(browser.session),
		])
		expect(stored).not.toContain('Lighthouse-Rumble-42')
		expect(stored).not.toContain(res.link.split('#k=')[1])
	})

	it('makes network requests from the worker only', () => {
		const pages = ['popup', 'content', 'unlock'].flatMap((d) =>
			sources(join(SRC, d)),
		)
		expect(pages.length).toBeGreaterThan(5)
		for (const file of pages) {
			const text = readFileSync(file, 'utf8')
			expect(text, file).not.toMatch(/\bfetch\s*\(|XMLHttpRequest|sendBeacon/)
			expect(text, file).not.toMatch(/from ['"][./]*lib\/api\.js['"]/)
		}
	})

	it('fetches no site icons', () => {
		for (const file of sources(SRC)) {
			const text = readFileSync(file, 'utf8')
			expect(text, file).not.toMatch(/favicon/i)
			expect(text, file).not.toMatch(/<img\b|new Image\s*\(/)
		}
	})
})
