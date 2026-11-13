/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pins the emergency-access store end to end with real WebCrypto (no crypto
 * mocks): a designation posts an envelope only the grantee's key opens and a
 * proof signature the grantor's public key verifies; revoke signs over the
 * relationship id and a wrong master password stops it before the DELETE;
 * request/decline hit their endpoints and reload the right list; recover opens
 * the envelope with the grantee's own session key; the list loads tolerate a
 * non-array body and always reset loading; the two state getters filter.
 *
 * @spec openspec/changes/add-emergency-access/specs/emergency-access/spec.md#requirement-client-side-recovery-envelope-escrow
 * @spec openspec/specs/emergency-access/spec.md#requirement-revoke-emergency-contact
 */

import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { encryptPrivateKey } from '../../src/crypto/aes.js'
import {
	buildRecoveryEnvelope,
	openRecoveryEnvelope,
} from '../../src/crypto/emergencyEnvelope.js'
import { HEADER_NONCE, HEADER_PROOF } from '../../src/crypto/keyProof.js'
import { decodePem } from '../../src/crypto/pem.js'
import { generateKeyPair, importPrivateKey } from '../../src/crypto/rsa.js'
import { useEmergencyAccessStore } from '../../src/store/modules/emergencyAccess.js'
import { useSessionStore } from '../../src/store/modules/session.js'

const API = '/apps/keepiq/api/v1/emergency-access'

/**
 * A fresh RSA pair as { pem, publicKeyPem, decryptKey }.
 *
 * @return {Promise<object>}
 */
async function rsaPair() {
	const pair = await generateKeyPair()
	const pkcs8 = await crypto.subtle.exportKey('pkcs8', pair.privateKey)
	const pem =
		'-----BEGIN PRIVATE KEY-----\n'
		+ btoa(String.fromCharCode(...new Uint8Array(pkcs8)))
			.match(/.{1,64}/g)
			.join('\n')
		+ '\n-----END PRIVATE KEY-----'
	return {
		pem,
		publicKeyPem: pair.publicKeyPem,
		decryptKey: await importPrivateKey(pem),
	}
}

/**
 * Verify a VaultKeyProof signature the way the server does: nonce, then the
 * SHA-256 hex of each bound value, newline-joined, RSASSA-PKCS1-v1_5/SHA-256.
 *
 * @param {string} publicKeyPem The signer's SPKI PEM.
 * @param {string} nonce The challenge nonce.
 * @param {string[]} boundValues The bound values, in order.
 * @param {string} signatureB64 The base64 signature.
 * @return {Promise<boolean>}
 */
async function verifyProof(publicKeyPem, nonce, boundValues, signatureB64) {
	const encoder = new TextEncoder()
	const lines = [nonce]
	for (const value of boundValues) {
		const digest = await crypto.subtle.digest('SHA-256', encoder.encode(value))
		lines.push(
			Array.from(new Uint8Array(digest))
				.map((b) => b.toString(16).padStart(2, '0'))
				.join(''),
		)
	}
	const key = await crypto.subtle.importKey(
		'spki',
		decodePem(publicKeyPem, ['PUBLIC KEY']).der,
		{ name: 'RSASSA-PKCS1-v1_5', hash: 'SHA-256' },
		false,
		['verify'],
	)
	const signature = Uint8Array.from(atob(signatureB64), (c) => c.charCodeAt(0))
	return crypto.subtle.verify(
		'RSASSA-PKCS1-v1_5',
		key,
		signature,
		encoder.encode(lines.join('\n')),
	)
}

let grantor
let grantee
let grantorEnvelope

describe('useEmergencyAccessStore — more', () => {
	beforeEach(async () => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
		if (!grantor) {
			grantor = await rsaPair()
			grantee = await rsaPair()
			grantorEnvelope = await encryptPrivateKey(grantor.pem, 'master-pw')
		}
	})

	/**
	 * An unlocked grantor session holding the real AES-wrapped key.
	 *
	 * @return {object} The session store.
	 */
	function unlockedGrantor() {
		const session = useSessionStore()
		session.suiteId = 'suite-1'
		session.encryptedPrivateKey = grantorEnvelope
		session.cryptoKey = grantor.decryptKey
		return session
	}

	describe('getters', () => {
		it('filter contacts by requested and invalidated state', () => {
			const store = useEmergencyAccessStore()
			store.contacts = [
				{ id: '1', state: 'requested' },
				{ id: '2', state: 'active' },
				{ id: '3', state: 'invalidated' },
				{ id: '4', state: 'requested' },
			]
			expect(store.pendingRequests.map((c) => c.id)).toEqual(['1', '4'])
			expect(store.invalidatedContacts.map((c) => c.id)).toEqual(['3'])
		})
	})

	describe('list loads', () => {
		it('fetchContacts stores an array body and resets loading', async () => {
			const get = vi.spyOn(axios, 'get').mockResolvedValue({ data: [{ id: 'c1' }] })
			const store = useEmergencyAccessStore()
			const pending = store.fetchContacts()
			expect(store.loading).toBe(true)
			await pending
			expect(get).toHaveBeenCalledWith(`${API}/contacts`)
			expect(store.contacts).toEqual([{ id: 'c1' }])
			expect(store.loading).toBe(false)
		})

		it('fetchContacts replaces a non-array body with an empty list', async () => {
			vi.spyOn(axios, 'get').mockResolvedValue({ data: { id: 'c1' } })
			const store = useEmergencyAccessStore()
			store.contacts = [{ id: 'old' }]
			await store.fetchContacts()
			expect(store.contacts).toEqual([])
		})

		it('fetchIncoming stores an array body, and an empty list otherwise', async () => {
			const get = vi
				.spyOn(axios, 'get')
				.mockResolvedValueOnce({ data: [{ id: 'i1' }] })
				.mockResolvedValueOnce({ data: null })
			const store = useEmergencyAccessStore()
			await store.fetchIncoming()
			expect(get).toHaveBeenCalledWith(`${API}/incoming`)
			expect(store.incoming).toEqual([{ id: 'i1' }])
			await store.fetchIncoming()
			expect(store.incoming).toEqual([])
		})

		it('both loads reset loading on failure', async () => {
			vi.spyOn(axios, 'get').mockRejectedValue(new Error('500'))
			const store = useEmergencyAccessStore()
			await expect(store.fetchContacts()).rejects.toThrow('500')
			expect(store.loading).toBe(false)
			await expect(store.fetchIncoming()).rejects.toThrow('500')
			expect(store.loading).toBe(false)
		})
	})

	describe('designate (real crypto)', () => {
		it('posts an envelope only the grantee opens, with a verifiable proof', async () => {
			unlockedGrantor()
			const get = vi.spyOn(axios, 'get').mockImplementation(async (url, config) => {
				if (url === `${API}/grantee-certificate`) {
					return { data: { certificate: grantee.publicKeyPem } }
				}
				if (url === '/apps/keepiq/api/v1/suites/suite-1/proof-challenge') {
					expect(config).toEqual({
						params: { purpose: 'emergency-access-designate' },
					})
					return { data: { nonce: 'nonce-1' } }
				}
				return { data: [{ id: 'rel-1', state: 'active' }] }
			})
			const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: { id: 'rel-1' } })
			const store = useEmergencyAccessStore()

			const result = await store.designate({
				granteeUserId: 'bob',
				waitPeriodDays: 30,
				masterPassword: 'master-pw',
			})

			expect(get).toHaveBeenCalledWith(`${API}/grantee-certificate`, {
				params: { granteeUserId: 'bob' },
			})
			const [url, body, config] = post.mock.calls[0]
			expect(url).toBe(`${API}/contacts`)
			expect(Object.keys(body)).toEqual([
				'granteeUserId',
				'waitPeriodDays',
				'accessLevel',
				'recoveryEnvelope',
			])
			expect(body).toMatchObject({
				granteeUserId: 'bob',
				waitPeriodDays: 30,
				accessLevel: 'view',
			})
			expect(body.recoveryEnvelope).not.toContain('PRIVATE KEY')
			await expect(
				openRecoveryEnvelope(body.recoveryEnvelope, grantee.decryptKey),
			).resolves.toBe(grantor.pem)
			await expect(
				openRecoveryEnvelope(body.recoveryEnvelope, grantor.decryptKey),
			).rejects.toThrow()

			expect(config.headers[HEADER_NONCE]).toBe('nonce-1')
			await expect(
				verifyProof(
					grantor.publicKeyPem,
					'nonce-1',
					['bob', '30', body.recoveryEnvelope],
					config.headers[HEADER_PROOF],
				),
			).resolves.toBe(true)
			expect(JSON.stringify(post.mock.calls)).not.toContain('master-pw')

			expect(result).toEqual({ id: 'rel-1' })
			expect(store.contacts).toEqual([{ id: 'rel-1', state: 'active' }])
		}, 30000)

		it('refuses a wrong master password before anything is posted', async () => {
			unlockedGrantor()
			vi.spyOn(axios, 'get').mockResolvedValue({
				data: { certificate: grantee.publicKeyPem },
			})
			const post = vi.spyOn(axios, 'post')
			const store = useEmergencyAccessStore()

			await expect(
				store.designate({
					granteeUserId: 'bob',
					waitPeriodDays: 7,
					masterPassword: 'wrong',
				}),
			).rejects.toThrow()
			expect(post).not.toHaveBeenCalled()
		}, 30000)
	})

	describe('revoke (real crypto)', () => {
		it('deletes with a proof bound to the relationship id, then reloads contacts', async () => {
			unlockedGrantor()
			const get = vi.spyOn(axios, 'get').mockImplementation(async (url, config) => {
				if (url.endsWith('/proof-challenge')) {
					expect(url).toBe('/apps/keepiq/api/v1/suites/suite-1/proof-challenge')
					expect(config.params.purpose).toBe('emergency-access-destroy')
					return { data: { nonce: 'nonce-2' } }
				}
				return { data: [] }
			})
			const del = vi.spyOn(axios, 'delete').mockResolvedValue({ data: {} })
			const store = useEmergencyAccessStore()
			store.contacts = [{ id: 'rel-9' }]

			await store.revoke('rel-9', 'master-pw')

			const [url, config] = del.mock.calls[0]
			expect(url).toBe(`${API}/contacts/rel-9`)
			expect(config.headers[HEADER_NONCE]).toBe('nonce-2')
			await expect(
				verifyProof(grantor.publicKeyPem, 'nonce-2', ['rel-9'], config.headers[HEADER_PROOF]),
			).resolves.toBe(true)
			expect(get).toHaveBeenLastCalledWith(`${API}/contacts`)
			expect(store.contacts).toEqual([])
		}, 30000)

		it('never sends the DELETE on a wrong master password', async () => {
			unlockedGrantor()
			vi.spyOn(axios, 'get').mockResolvedValue({ data: { nonce: 'n' } })
			const del = vi.spyOn(axios, 'delete')
			const store = useEmergencyAccessStore()

			await expect(store.revoke('rel-9', 'nope')).rejects.toThrow()
			expect(del).not.toHaveBeenCalled()
		}, 30000)
	})

	describe('request and decline', () => {
		it('request posts to the request endpoint and reloads incoming', async () => {
			const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: {} })
			const get = vi
				.spyOn(axios, 'get')
				.mockResolvedValue({ data: [{ id: 'rel-1', state: 'requested' }] })
			const store = useEmergencyAccessStore()

			await store.request('rel-1')

			expect(post).toHaveBeenCalledWith(`${API}/contacts/rel-1/request`)
			expect(get).toHaveBeenCalledWith(`${API}/incoming`)
			expect(store.incoming).toEqual([{ id: 'rel-1', state: 'requested' }])
			expect(store.contacts).toEqual([])
		})

		it('decline posts to the decline endpoint and reloads contacts', async () => {
			const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: {} })
			const get = vi
				.spyOn(axios, 'get')
				.mockResolvedValue({ data: [{ id: 'rel-1', state: 'active' }] })
			const store = useEmergencyAccessStore()

			await store.decline('rel-1')

			expect(post).toHaveBeenCalledWith(`${API}/contacts/rel-1/decline`)
			expect(get).toHaveBeenCalledWith(`${API}/contacts`)
			expect(store.contacts).toEqual([{ id: 'rel-1', state: 'active' }])
		})

		it('does not reload when the request fails', async () => {
			vi.spyOn(axios, 'post').mockRejectedValue(new Error('409'))
			const get = vi.spyOn(axios, 'get')
			const store = useEmergencyAccessStore()
			await expect(store.request('rel-1')).rejects.toThrow('409')
			await expect(store.decline('rel-1')).rejects.toThrow('409')
			expect(get).not.toHaveBeenCalled()
		})
	})

	describe('recover (real crypto)', () => {
		it('opens the fetched envelope with the grantee own session key', async () => {
			const envelope = await buildRecoveryEnvelope(grantor.pem, grantee.publicKeyPem)
			const session = useSessionStore()
			session.cryptoKey = grantee.decryptKey
			const get = vi
				.spyOn(axios, 'get')
				.mockResolvedValue({ data: { recoveryEnvelope: envelope } })
			const store = useEmergencyAccessStore()

			await expect(store.recover('rel-1')).resolves.toBe(grantor.pem)
			expect(get).toHaveBeenCalledWith(`${API}/contacts/rel-1/envelope`)
		}, 30000)

		it('refuses while locked, before any request', async () => {
			const get = vi.spyOn(axios, 'get')
			const store = useEmergencyAccessStore()
			await expect(store.recover('rel-1')).rejects.toThrow(
				'Unlock your vault to recover emergency access',
			)
			expect(get).not.toHaveBeenCalled()
		})
	})
})
