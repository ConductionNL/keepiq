/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The health store's site directory half: the vault facts each row carries
 * (a seed or passkey on the login, or an authenticator or passkey item for its
 * site), and the directory copy fetched whole, with 404 read as switched off.
 * No request carries a login address.
 *
 * @spec openspec/changes/health-site-security-checks/specs/health-site-checks/spec.md#requirement-flag-unused-two-factor-login-and-available-passkeys
 */

import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useHealthStore } from '../../src/store/modules/health.js'
import { useSecretTypeStore } from '../../src/store/modules/secretType.js'
import { useSessionStore } from '../../src/store/modules/session.js'

vi.mock('../../src/crypto/index.js', () => ({
	rsaDecrypt: vi.fn(async (cipher) => cipher.replace('enc:', '')),
	importPublicKey: vi.fn(),
	rsaEncrypt: vi.fn(),
}))

const SESSION = { cryptoKey: {} }

const TYPES = [
	{ id: 1, name: 'password' },
	{ id: 2, name: 'totp' },
	{ id: 3, name: 'passkey' },
]

const ITEMS = [
	{ id: 'gh', name: 'GitHub', key: 'enc:a', typeId: 1, url: 'https://github.com' },
	{
		id: 'gh-seed',
		name: 'GitHub code',
		key: 'enc:s',
		typeId: 2,
		url: 'https://github.com',
	},
	{
		id: 'ex',
		name: 'Example',
		key: 'enc:b',
		typeId: 1,
		url: 'https://www.example.com',
	},
	{
		id: 'ex-pk',
		name: 'Example passkey',
		key: 'enc:p',
		typeId: 3,
		url: 'example.com',
	},
	{
		id: 'own',
		name: 'Own code',
		key: 'enc:c',
		typeId: 1,
		url: 'https://other.org',
		totp: 'enc:seed',
		passkeys: 'enc:[1]',
	},
	{ id: 'none', name: 'None', key: 'enc:d', typeId: 1, url: 'https://none.org' },
]

describe('health store and the site directory', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
		useSecretTypeStore().types = TYPES
	})

	it('marks a row that has a seed or a passkey, on the login or as an item for its site', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: { items: ITEMS } })

		const rows = await useHealthStore().loadDecryptedRows(SESSION)
		const facts = Object.fromEntries(
			rows.map((r) => [r.id, [r.hasSeed, r.hasPasskey]]),
		)

		expect(facts).toEqual({
			gh: [true, false],
			ex: [false, true],
			own: [true, true],
			none: [false, false],
		})
	})

	it('reads the directory copy and marks it ready', async () => {
		const get = vi.spyOn(axios, 'get').mockResolvedValue({
			data: { fetchedAt: 'x', totp: { 'github.com': '' }, passkeys: {} },
		})
		const store = useHealthStore()

		expect(await store.loadSiteDirectory()).toEqual({
			totp: { 'github.com': '' },
			passkeys: {},
		})
		expect(store.siteDirectoryStatus).toBe('ready')
		expect(get).toHaveBeenCalledWith('/apps/keepiq/api/v1/site-directory')
		expect(get.mock.calls[0]).toHaveLength(1)
	})

	it('reads 404 as switched off', async () => {
		vi.spyOn(axios, 'get').mockRejectedValue({ response: { status: 404 } })
		const store = useHealthStore()

		expect(await store.loadSiteDirectory()).toBeNull()
		expect(store.siteDirectoryStatus).toBe('off')
	})

	it.each([
		['a server error', () => Promise.reject({ response: { status: 500 } })],
		['a network error', () => Promise.reject(new Error('offline'))],
		['an answer that is not a copy', () => Promise.resolve({ data: '<html>' })],
	])('reads %s as unavailable', async (_name, answer) => {
		vi.spyOn(axios, 'get').mockImplementation(answer)
		const store = useHealthStore()

		expect(await store.loadSiteDirectory()).toBeNull()
		expect(store.siteDirectoryStatus).toBe('unavailable')
	})

	it('runs the engine with the directory and lists the login without a seed', async () => {
		vi.spyOn(axios, 'get').mockImplementation(async (url) => {
			if (url.endsWith('/site-directory')) {
				return {
					data: {
						totp: { 'github.com': '', 'none.org': '' },
						passkeys: {},
					},
				}
			}
			return { data: { items: ITEMS } }
		})
		useSessionStore().cryptoKey = { fake: true }
		const store = useHealthStore()

		await store.analyseVault({ stalenessThreshold: 'never' })

		expect(store.status).toBe('ready')
		expect(store.twoFactorAvailableFindings.map((f) => f.id)).toEqual(['none'])
		expect(store.passkeyAvailableFindings).toEqual([])
	})

	it('goes back to off when the vault locks', () => {
		const store = useHealthStore()
		store.siteDirectoryStatus = 'ready'

		store.reset()

		expect(store.siteDirectoryStatus).toBe('off')
	})
})
