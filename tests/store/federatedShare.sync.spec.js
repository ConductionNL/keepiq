/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Sync on update reaches federated recipients (keepiq#789,
 * sharing-federated-recipients task 4.1): after the owner changed a secret,
 * the browser fetches each live federated recipient's certificate again,
 * verifies it (real verifier, real openssl fixtures), encrypts the whole new
 * value and sends only ciphertext. A certificate that no longer verifies,
 * or a recipient the partner no longer knows, suspends that share instead.
 *
 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#scenario-a-password-change-reaches-bob
 */

import axios from '@nextcloud/axios'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useFederatedShareStore } from '../../src/store/modules/federatedShare.js'
import { useSecretStore } from '../../src/store/modules/secret.js'

const F = JSON.parse(readFileSync(resolve(__dirname, '../fixtures/federation-chain.json'), 'utf8'))
const BOB = 'bob@cloud.partner.example'
const NEW_PASSWORD = 'a brand new password'

const VALID = { cloudId: BOB, certificate: F.bob, chain: [F.intermediateA, F.rootA], partnerRootFingerprint: F.rootAFingerprint }
const OTHER_ROOT = { ...VALID, certificate: F.bobOtherRoot, chain: [F.intermediateB, F.rootB] }

/**
 * Wire the owner's server: the share list, the lookup answer, and recorders
 * for what the browser sends.
 *
 * @param {Array<object>} shares The federated shares of the secret.
 * @param {object} lookup The lookup answer, or `{ reject }`.
 * @return {{puts: Array, suspends: Array, fetchSecret: object}}
 */
function server(shares, lookup) {
	const puts = []
	const suspends = []
	vi.spyOn(axios, 'get').mockResolvedValue({ data: shares })
	vi.spyOn(axios, 'post').mockImplementation(async (url, body) => {
		if (url.includes('/federation/recipient-certificate')) {
			if (lookup.reject) {
				throw lookup.reject
			}
			return { data: lookup }
		}
		suspends.push([url, body])
		return { data: {} }
	})
	vi.spyOn(axios, 'put').mockImplementation(async (url, body) => {
		puts.push([url, body])
		return { data: {} }
	})
	const fetchSecret = vi.spyOn(useSecretStore(), 'fetchSecret').mockResolvedValue({
		key: NEW_PASSWORD,
		login: 'alice',
		additionalFields: null,
	})
	return { puts, suspends, fetchSecret }
}

describe('federated sync on update', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
	})

	it('re-encrypts the new value for a live federated recipient and sends only ciphertext', async () => {
		const { puts, suspends } = server(
			[
				{ id: 'fs-1', recipientCloudId: BOB, status: 'active' },
				{ id: 'fs-2', recipientCloudId: 'carol@cloud.partner.example', status: 'suspended' },
			],
			VALID,
		)

		const result = await useFederatedShareStore().syncUpdate('src')

		expect(result).toEqual({ updated: 1, suspended: 0 })
		expect(axios.get).toHaveBeenCalledWith(expect.stringContaining('/api/v1/secrets/src/federated-shares'))
		expect(puts).toHaveLength(1)
		const [url, body] = puts[0]
		expect(url).toContain('/api/v1/federated-shares/fs-1')
		expect(body.certFingerprint).toMatch(/^[0-9a-f]{64}$/)
		expect(body.key).toBeTruthy()
		expect(JSON.stringify(body)).not.toContain(NEW_PASSWORD)
		expect(JSON.stringify(body)).not.toContain('alice')
		expect(suspends).toHaveLength(0)
	})

	it('suspends a share whose certificate no longer chains to the pinned root', async () => {
		const { puts, suspends } = server([{ id: 'fs-1', recipientCloudId: BOB, status: 'active' }], OTHER_ROOT)

		const result = await useFederatedShareStore().syncUpdate('src')

		expect(result).toEqual({ updated: 0, suspended: 1 })
		expect(puts).toHaveLength(0)
		expect(suspends[0][0]).toContain('/api/v1/federated-shares/fs-1/suspend')
		expect(suspends[0][1]).toEqual({ reason: 'untrusted_root' })
	})

	it('suspends a share to a recipient the partner no longer knows', async () => {
		const { puts, suspends } = server(
			[{ id: 'fs-1', recipientCloudId: BOB, status: 'active' }],
			{ reject: { response: { data: { message: 'unknown_recipient' } } } },
		)

		await useFederatedShareStore().syncUpdate('src')

		expect(puts).toHaveLength(0)
		expect(suspends[0][1]).toEqual({ reason: 'unknown_recipient' })
	})

	it('leaves a share alone while the partner cannot be reached', async () => {
		const { puts, suspends } = server(
			[{ id: 'fs-1', recipientCloudId: BOB, status: 'active' }],
			{ reject: { response: { data: { message: 'partner_unreachable' } } } },
		)

		await useFederatedShareStore().syncUpdate('src')

		expect(puts).toHaveLength(0)
		expect(suspends).toHaveLength(0)
	})

	it('reads nothing when no federated share is live', async () => {
		const { fetchSecret } = server([{ id: 'fs-1', recipientCloudId: BOB, status: 'failed' }], VALID)

		expect(await useFederatedShareStore().syncUpdate('src')).toEqual({ updated: 0, suspended: 0 })
		expect(fetchSecret).not.toHaveBeenCalled()
	})
})
