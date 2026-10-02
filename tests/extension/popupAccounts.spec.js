/**
 * @spec openspec/specs/extension-account-switching/spec.md#requirement-switching-and-isolation-between-accounts
 * @spec openspec/specs/browser-extension-autofill/spec.md#requirement-user-chosen-idle-lock-period-with-an-administrator-maximum
 *
 * The REAL popup (popup.html + popup.js) against the worker's REAL router:
 * the header lists three accounts with their lock state and switches the
 * active one; the settings view stores the idle delay and shows the delays
 * above the organisation's maximum as unavailable.
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import {
	renderAccountSwitcher,
	renderIdleChoices,
} from '../../browser-extension/src/popup/views.js'
import {
	installChrome,
	installServer,
	makeVault,
	POPUP,
} from './fixtures/fakeBrowser.js'

const SERVERS = [
	'https://one.example',
	'https://two.example',
	'https://three.example',
]

let router
let api
let fixture

/**
 * Load the popup page into the document, its messages going to the router.
 *
 * @return {Promise<void>}
 */
async function openPopup() {
	const html = readFileSync(
		resolve(__dirname, '../../browser-extension/src/popup/popup.html'),
		'utf8',
	)
	document.body.innerHTML = html
		.replace(/^[\s\S]*<body>/, '')
		.replace(/<\/body>[\s\S]*$/, '')
	globalThis.chrome.runtime.sendMessage = (msg, cb) => {
		const pending = router.handleMessage(msg, POPUP)
		if (pending) pending.then(cb)
	}
	globalThis.chrome.windows = { create: vi.fn() }
	await import('../../browser-extension/src/popup/popup.js')
	await vi.waitFor(() =>
		expect(
			document.getElementById('account-select').options.length,
		).toBeGreaterThan(0),
	)
}

beforeEach(async () => {
	vi.resetModules()
	installChrome()
	if (!fixture) fixture = await makeVault('m', 'x')
	installServer(
		Object.fromEntries(
			SERVERS.map((u) => [u, { ...fixture, maxIdleMinutes: 30 }]),
		),
	)
	api = await import('../../browser-extension/src/lib/api.js')
	router = await import('../../browser-extension/src/background/router.js')
	for (const url of SERVERS) {
		await router.handleMessage(
			{ type: 'pair', payload: { url, user: 'alice', appPassword: 'p' } },
			POPUP,
		)
	}
})

describe('popup account switcher', () => {
	it('lists three accounts with their lock state and switches the active one', async () => {
		const [first, , third] = await api.loadAccounts()
		await router.handleMessage(
			{ type: 'switch-account', payload: { accountId: first.id } },
			POPUP,
		)
		await router.handleMessage(
			{ type: 'unlock', payload: { masterPassword: 'm' } },
			POPUP,
		)
		await openPopup()

		const select = document.getElementById('account-select')
		expect([...select.options].map((o) => o.textContent)).toEqual([
			'alice@one.example',
			'alice@two.example (locked)',
			'alice@three.example (locked)',
		])
		expect(select.value).toBe(first.id)
		expect(document.getElementById('view-unlocked').hidden).toBe(false)

		select.value = third.id
		select.dispatchEvent(new Event('change'))

		await vi.waitFor(async () =>
			expect(await api.activeAccountId()).toBe(third.id),
		)
		await vi.waitFor(() =>
			expect(document.getElementById('view-locked').hidden).toBe(false),
		)
	})

	it('stores a picked idle delay and marks delays above the maximum', async () => {
		await router.handleMessage(
			{ type: 'unlock', payload: { masterPassword: 'm' } },
			POPUP,
		)
		await openPopup()
		document.getElementById('settings-btn').click()
		await vi.waitFor(() =>
			expect(
				document.querySelectorAll('input[name="idle-minutes"]').length,
			).toBe(6),
		)

		const radios = [...document.querySelectorAll('input[name="idle-minutes"]')]
		expect(radios.filter((r) => r.disabled).map((r) => r.value)).toEqual([
			'60',
			'240',
		])
		const five = radios.find((r) => r.value === '5')
		five.checked = true
		five.dispatchEvent(new Event('change'))

		const active = await api.activeAccountId()
		await vi.waitFor(async () =>
			expect((await api.loadAccount(active)).idleMinutes).toBe(5),
		)
	})
})

describe('render helpers', () => {
	it('selects the active account', () => {
		const select = document.createElement('select')
		renderAccountSwitcher(select, {
			activeAccountId: 'b',
			accounts: [
				{ id: 'a', user: 'u', host: 'a.example', unlocked: true },
				{
					id: 'b',
					user: 'u',
					host: 'b.example',
					unlocked: false,
					label: 'Work',
				},
			],
		})
		expect(select.value).toBe('b')
		expect(select.options[1].textContent).toBe('Work (locked)')
	})

	it('explains a stored choice above the organisation maximum', () => {
		const div = document.createElement('div')
		renderIdleChoices(
			div,
			{
				idleChoices: [1, 5, 15, 30, 60, 240],
				idleMinutes: 240,
				maxIdleMinutes: 30,
			},
			() => {},
		)
		expect(div.textContent).toContain(
			'locks the extension after 30 minutes at most',
		)
	})
})
