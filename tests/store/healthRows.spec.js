/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pins which secrets the health pass decrypts and the row each one becomes:
 * the secret types are fetched only when none are loaded and a failed fetch
 * excludes nothing, totp and passkey secrets, blocked ones, ones without a
 * key and ones that do not decrypt are passed over, and the optional fields
 * fall back as before.
 *
 * @spec openspec/changes/password-health/specs/password-health/spec.md#requirement-client-side-health-analysis
 */

import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useHealthStore } from '../../src/store/modules/health.js'
import { useSecretTypeStore } from '../../src/store/modules/secretType.js'

vi.mock('../../src/crypto/index.js', () => ({
	rsaDecrypt: vi.fn(async (cipher, key) => {
		if (cipher === 'enc:broken') {
			throw new Error('cannot decrypt')
		}
		return `${cipher.replace('enc:', '')}/${key.id}`
	}),
	importPublicKey: vi.fn(),
	rsaEncrypt: vi.fn(),
}))

const SESSION = { cryptoKey: { id: 'k' } }

const ITEMS = [
	{ id: 'plain', name: 'Plain', key: 'enc:pw', typeId: 1, createdAt: 'C1' },
	{
		id: 'full',
		name: 'Full',
		key: 'enc:pw2',
		typeId: 1,
		url: 'https://x',
		folderId: 'f',
		keyUpdatedAt: 'K',
		createdAt: 'C2',
		possiblyCompromisedAt: 'P',
	},
	{ id: 'bare', name: 'Bare', key: 'enc:pw3' },
	{ id: 'totp', name: 'Seed', key: 'enc:seed', typeId: 2 },
	{ id: 'passkey', name: 'Pk', key: 'enc:pk', typeId: 3 },
	{ id: 'blocked', name: 'B', key: 'enc:b', blocked: true },
	{ id: 'nokey', name: 'N', key: '' },
	{ id: 'broken', name: 'X', key: 'enc:broken' },
]

const TYPES = [
	{ id: 1, name: 'password' },
	{ id: 2, name: 'totp' },
	{ id: 3, name: 'passkey' },
	null,
]

describe('loadDecryptedRows', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
		vi.spyOn(axios, 'get').mockResolvedValue({ data: { items: ITEMS } })
	})

	it('decrypts only the analysable secrets into rows with their fallbacks', async () => {
		const types = useSecretTypeStore()
		types.types = TYPES
		const fetchTypes = vi.spyOn(types, 'fetchTypes')
		const rows = await useHealthStore().loadDecryptedRows(SESSION)
		expect(fetchTypes).not.toHaveBeenCalled()
		expect(rows).toEqual([
			{
				id: 'plain',
				name: 'Plain',
				url: null,
				folderPath: null,
				value: 'pw/k',
				keyUpdatedAt: 'C1',
				possiblyCompromisedAt: null,
			},
			{
				id: 'full',
				name: 'Full',
				url: 'https://x',
				folderPath: 'f',
				value: 'pw2/k',
				keyUpdatedAt: 'K',
				possiblyCompromisedAt: 'P',
			},
			{
				id: 'bare',
				name: 'Bare',
				url: null,
				folderPath: null,
				value: 'pw3/k',
				keyUpdatedAt: null,
				possiblyCompromisedAt: null,
			},
		])
	})

	it('fetches the types when none are loaded, and uses what it fetched', async () => {
		const types = useSecretTypeStore()
		types.types = []
		vi.spyOn(types, 'fetchTypes').mockImplementation(async () => {
			types.types = TYPES
		})
		const rows = await useHealthStore().loadDecryptedRows(SESSION)
		expect(types.fetchTypes).toHaveBeenCalledTimes(1)
		expect(rows.map((r) => r.id)).toEqual(['plain', 'full', 'bare'])
	})

	it('excludes nothing by type when the types cannot be fetched', async () => {
		const types = useSecretTypeStore()
		types.types = null
		vi.spyOn(types, 'fetchTypes').mockRejectedValue(new Error('down'))
		const rows = await useHealthStore().loadDecryptedRows(SESSION)
		expect(rows.map((r) => r.id)).toEqual([
			'plain',
			'full',
			'bare',
			'totp',
			'passkey',
		])
	})
})
