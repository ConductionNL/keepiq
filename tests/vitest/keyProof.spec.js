/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Tests for the key-proof header helpers: the challenge is fetched for the
 * right suite and purpose, the returned headers carry that challenge and a
 * signature that verifies over it and the bound values, and the session
 * variant asks for the master password only when none was given.
 *
 * @spec openspec/specs/user-sharing/spec.md#requirement-sharing-with-a-new-party-requires-a-verified-key-proof
 */

import axios from '@nextcloud/axios'
import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import { encryptPrivateKey } from '../../src/crypto/aes.js'
import {
	buildKeyProofHeaders,
	HEADER_NONCE,
	HEADER_PROOF,
	PROOF_PURPOSE,
	sessionKeyProofHeaders,
} from '../../src/crypto/keyProof.js'

const session = vi.hoisted(() => ({ suiteId: null, encryptedPrivateKey: null }))
const prompt = vi.hoisted(() => ({ ask: null }))

vi.mock('../../src/store/modules/session.js', () => ({
	useSessionStore: () => session,
}))
vi.mock('../../src/store/modules/keyProofPrompt.js', () => ({
	useKeyProofPromptStore: () => prompt,
}))

let envelope
let publicKey

/**
 * Rebuild the signed message: the challenge, then the hex SHA-256 of each
 * bound value, one per line.
 *
 * @param {string} nonce The challenge.
 * @param {string[]} boundValues The bound values.
 * @return {Promise<Uint8Array>} The message bytes.
 */
async function message(nonce, boundValues) {
	const enc = new TextEncoder()
	const lines = [nonce]
	for (const v of boundValues) {
		const d = await crypto.subtle.digest('SHA-256', enc.encode(String(v)))
		lines.push(
			Array.from(new Uint8Array(d))
				.map((b) => b.toString(16).padStart(2, '0'))
				.join(''),
		)
	}
	return enc.encode(lines.join('\n'))
}

/**
 * Whether a base64 signature verifies over the challenge and bound values.
 *
 * @param {string} signatureB64 The signature.
 * @param {string} nonce The challenge.
 * @param {string[]} boundValues The bound values.
 * @return {Promise<boolean>} The verdict.
 */
async function verifies(signatureB64, nonce, boundValues) {
	const signature = Uint8Array.from(atob(signatureB64), (c) => c.charCodeAt(0))
	return crypto.subtle.verify(
		'RSASSA-PKCS1-v1_5',
		publicKey,
		signature,
		await message(nonce, boundValues),
	)
}

describe('keyProof', () => {
	beforeAll(async () => {
		const pair = await crypto.subtle.generateKey(
			{
				name: 'RSASSA-PKCS1-v1_5',
				modulusLength: 2048,
				publicExponent: new Uint8Array([1, 0, 1]),
				hash: 'SHA-256',
			},
			true,
			['sign', 'verify'],
		)
		const pkcs8 = new Uint8Array(await crypto.subtle.exportKey('pkcs8', pair.privateKey))
		// The PEM label is assembled at runtime: the key itself is generated above, and a literal
		// armour line in the source reads to secret scanners like a committed key.
		const dashes = '-'.repeat(5)
		const label = ['PRIVATE', 'KEY'].join(' ')
		const pem =
			`${dashes}BEGIN ${label}${dashes}\n`
			+ btoa(String.fromCharCode(...pkcs8)).match(/.{1,64}/g).join('\n')
			+ `\n${dashes}END ${label}${dashes}`
		envelope = await encryptPrivateKey(pem, 'master-pw')
		publicKey = pair.publicKey
	})

	beforeEach(() => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: { nonce: 'nonce.mac' } })
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('names the proof headers and the purposes the server knows', () => {
		expect(HEADER_NONCE).toBe('X-Keepiq-Key-Proof-Nonce')
		expect(HEADER_PROOF).toBe('X-Keepiq-Key-Proof')
		expect(PROOF_PURPOSE.SHARE_NEW_RECIPIENT).toBe('share-new-recipient')
		expect(PROOF_PURPOSE.REVOKE_SUITE).toBe('revoke-suite')
		expect(new Set(Object.values(PROOF_PURPOSE)).size).toBe(
			Object.keys(PROOF_PURPOSE).length,
		)
	})

	describe('buildKeyProofHeaders', () => {
		it('fetches the challenge for the suite and purpose and signs it with the bound values', async () => {
			const headers = await buildKeyProofHeaders({
				suiteId: 'suite-7',
				purpose: PROOF_PURPOSE.REVOKE_SUITE,
				encryptedPrivateKey: envelope,
				masterPassword: 'master-pw',
				boundValues: ['a', 'b'],
			})

			expect(axios.get).toHaveBeenCalledWith(
				'/apps/keepiq/api/v1/suites/suite-7/proof-challenge',
				{ params: { purpose: 'revoke-suite' } },
			)
			expect(Object.keys(headers)).toEqual([HEADER_NONCE, HEADER_PROOF])
			expect(headers[HEADER_NONCE]).toBe('nonce.mac')
			expect(await verifies(headers[HEADER_PROOF], 'nonce.mac', ['a', 'b'])).toBe(true)
			expect(await verifies(headers[HEADER_PROOF], 'nonce.mac', ['a', 'c'])).toBe(false)
		})

		it('binds no values by default', async () => {
			const headers = await buildKeyProofHeaders({
				suiteId: 's',
				purpose: PROOF_PURPOSE.APPROVE_DEVICE,
				encryptedPrivateKey: envelope,
				masterPassword: 'master-pw',
			})

			expect(await verifies(headers[HEADER_PROOF], 'nonce.mac', [])).toBe(true)
		})

		it('fails on a wrong master password', async () => {
			await expect(
				buildKeyProofHeaders({
					suiteId: 's',
					purpose: PROOF_PURPOSE.REVOKE_SUITE,
					encryptedPrivateKey: envelope,
					masterPassword: 'WRONG',
				}),
			).rejects.toBeDefined()
		})

		it('fails without signing when the challenge cannot be fetched', async () => {
			axios.get.mockRejectedValue(new Error('403'))

			await expect(
				buildKeyProofHeaders({
					suiteId: 's',
					purpose: PROOF_PURPOSE.REVOKE_SUITE,
					encryptedPrivateKey: envelope,
					masterPassword: 'master-pw',
				}),
			).rejects.toThrow('403')
		})
	})

	describe('sessionKeyProofHeaders', () => {
		beforeEach(() => {
			session.suiteId = 'own-suite'
			session.encryptedPrivateKey = envelope
			prompt.ask = vi.fn().mockResolvedValue('master-pw')
		})

		it('asks for the master password with the reason and proves with the session suite', async () => {
			const result = await sessionKeyProofHeaders({
				purpose: PROOF_PURPOSE.SHARE_NEW_RECIPIENT,
				reason: 'Share with bob',
				boundValues: ['bob'],
			})

			expect(prompt.ask).toHaveBeenCalledWith('Share with bob')
			expect(axios.get).toHaveBeenCalledWith(
				'/apps/keepiq/api/v1/suites/own-suite/proof-challenge',
				{ params: { purpose: 'share-new-recipient' } },
			)
			expect(result.masterPassword).toBe('master-pw')
			expect(await verifies(result.headers[HEADER_PROOF], 'nonce.mac', ['bob'])).toBe(true)
		})

		it('does not ask again when a password was already given', async () => {
			const result = await sessionKeyProofHeaders({
				purpose: PROOF_PURPOSE.SHARE_REGISTER_BATCH,
				reason: 'unused',
				masterPassword: 'master-pw',
			})

			expect(prompt.ask).not.toHaveBeenCalled()
			expect(result.masterPassword).toBe('master-pw')
			expect(result.headers[HEADER_NONCE]).toBe('nonce.mac')
		})

		it('propagates a cancelled prompt without fetching a challenge', async () => {
			prompt.ask = vi.fn().mockRejectedValue(new Error('cancelled'))

			await expect(
				sessionKeyProofHeaders({ purpose: PROOF_PURPOSE.DELEGATION_CREATE, reason: 'r' }),
			).rejects.toThrow('cancelled')
			expect(axios.get).not.toHaveBeenCalled()
		})
	})
})
