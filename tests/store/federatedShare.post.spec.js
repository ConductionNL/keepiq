/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pins how a federated share is sent, before it is split into named steps:
 * the body with its ciphertext and nulls, the vault key proof asked for on
 * the first refusal and sent with the one retry, a partner too old for a
 * field refused before anything is sent, and other refusals passed on or
 * turned into the partner-upgrade error.
 *
 * @spec openspec/specs/federated-sharing/spec.md#requirement-federated-shares-carry-only-browser-made-ciphertext
 */

import axios from '@nextcloud/axios'
import { createPublicKey } from 'node:crypto'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../../src/crypto/keyProof.js', async (importOriginal) => ({
	...(await importOriginal()),
	sessionKeyProofHeaders: vi.fn(async (input) => ({
		headers: { 'X-Proof': JSON.stringify(input.boundValues) },
	})),
}))

const { PartnerUpgradeRequiredError, useFederatedShareStore } = await import(
	'../../src/store/modules/federatedShare.js'
)

const F = JSON.parse(
	readFileSync(resolve(__dirname, '../fixtures/federation-chain.json'), 'utf8'),
)
const RECIPIENT = {
	cloudId: 'bob@cloud.partner.example',
	fingerprint: 'AA:BB:CC',
	spki: new Uint8Array(createPublicKey(F.bob).export({ type: 'spki', format: 'der' })),
}

beforeEach(() => {
	setActivePinia(createPinia())
	vi.restoreAllMocks()
})

describe('share', () => {
	it('posts the ciphertext with the fingerprint and nulls for what the secret lacks', async () => {
		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: { id: 'fs-1' } })
		const result = await useFederatedShareStore().share('src', { key: 'pw', login: 'alice' }, RECIPIENT)
		expect(result).toEqual({ id: 'fs-1' })
		const [url, body, options] = post.mock.calls[0]
		expect(url).toBe('/apps/keepiq/api/v1/secrets/src/federated-shares')
		expect(options).toBeUndefined()
		expect({ ...body, key: typeof body.key, login: typeof body.login }).toEqual({
			recipientCloudId: 'bob@cloud.partner.example',
			certFingerprint: 'aabbcc',
			key: 'string',
			login: 'string',
			additionalFields: null,
			totp: null,
			passkeys: null,
		})
	})

	it('asks for the vault key proof once and sends the same body with it', async () => {
		const post = vi
			.spyOn(axios, 'post')
			.mockRejectedValueOnce({ response: { data: { error: 'key_proof_required' } } })
			.mockResolvedValueOnce({ data: { id: 'fs-2' } })
		const result = await useFederatedShareStore().share('src', { key: 'pw' }, RECIPIENT)
		expect(result).toEqual({ id: 'fs-2' })
		expect(post.mock.calls[1][1]).toBe(post.mock.calls[0][1])
		expect(post.mock.calls[1][2]).toEqual({
			headers: { 'X-Proof': '["src","bob@cloud.partner.example"]' },
		})
	})

	it('refuses a field the partner cannot store before sending anything', async () => {
		const post = vi.spyOn(axios, 'post')
		await expect(
			useFederatedShareStore().share('src', { key: 'pw', totp: 'SEED' }, RECIPIENT),
		).rejects.toBeInstanceOf(PartnerUpgradeRequiredError)
		expect(post).not.toHaveBeenCalled()
	})

	it('passes another refusal on, and turns an upgrade refusal into the upgrade error, on either try', async () => {
		const other = { response: { data: { error: 'not_a_partner' } } }
		const upgrade = { response: { data: { error: 'partner_upgrade_required', requiredVersion: '9.9' } } }
		const post = vi.spyOn(axios, 'post').mockRejectedValueOnce(other)
		await expect(useFederatedShareStore().share('src', { key: 'pw' }, RECIPIENT)).rejects.toBe(other)
		post.mockRejectedValueOnce(upgrade)
		const first = await useFederatedShareStore().share('src', { key: 'pw' }, RECIPIENT).catch((e) => e)
		expect(first).toBeInstanceOf(PartnerUpgradeRequiredError)
		post
			.mockRejectedValueOnce({ response: { data: { error: 'key_proof_required' } } })
			.mockRejectedValueOnce(upgrade)
		const retried = await useFederatedShareStore().share('src', { key: 'pw' }, RECIPIENT).catch((e) => e)
		expect(retried).toBeInstanceOf(PartnerUpgradeRequiredError)
		post
			.mockRejectedValueOnce({ response: { data: { error: 'key_proof_required' } } })
			.mockRejectedValueOnce(other)
		await expect(useFederatedShareStore().share('src', { key: 'pw' }, RECIPIENT)).rejects.toBe(other)
	})
})
