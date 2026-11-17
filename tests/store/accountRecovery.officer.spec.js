/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The officer side of organisation account recovery and the user's own
 * request: the officer view with each request's phrase, creating the
 * recovery key wrapped for every officer, approving with a proof of the
 * master password, declining, handing the key over sealed to the request
 * key, and reading back the user's latest request in this browser.
 *
 * @spec openspec/specs/organisation-account-recovery/spec.md#requirement-recovery-needs-a-threshold-of-proven-officer-approvals
 * @spec openspec/specs/organisation-account-recovery/spec.md#requirement-the-recovery-private-key-is-generated-and-held-by-officers-only
 * @spec openspec/specs/organisation-account-recovery/spec.md#requirement-the-recovered-key-reaches-only-the-requesting-browser
 */

import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
	generateRequestKeyPair,
	openHandoff,
} from '../../src/crypto/accountRecovery.js'
import { encryptPrivateKey } from '../../src/crypto/aes.js'
import { toBase64 } from '../../src/crypto/deviceApproval.js'
import {
	buildRecoveryEnvelope,
	openRecoveryEnvelope,
} from '../../src/crypto/emergencyEnvelope.js'
import { HEADER_NONCE, HEADER_PROOF } from '../../src/crypto/keyProof.js'
import { generateKeyPair, importPrivateKey } from '../../src/crypto/rsa.js'
import { verificationPhrase } from '../../src/crypto/verificationPhrase.js'
import {
	requestKeyStore,
	useAccountRecoveryStore,
} from '../../src/store/modules/accountRecovery.js'
import { useSessionStore } from '../../src/store/modules/session.js'
import { RSA4096_PRIVATE_KEY_PKCS8_PEM } from '../vitest/fixtures/rsa-fixtures.js'

const API = '/apps/keepiq/api/v1/recovery'
const KEY_A = new Uint8Array(32).fill(5)
const KEY_B = new Uint8Array(32).fill(6)

/**
 * A fresh RSA pair as { pem, publicKeyPem, decryptKey }.
 *
 * @return {Promise<object>}
 */
async function rsaPair() {
	const pair = await generateKeyPair()
	const pkcs8 = await crypto.subtle.exportKey('pkcs8', pair.privateKey)
	// The PEM label is assembled so the file holds no literal key block for secret scanners.
	const label = ['PRIVATE', 'KEY'].join(' ')
	const pem =
		`-----BEGIN ${label}-----\n`
		+ btoa(String.fromCharCode(...new Uint8Array(pkcs8)))
			.match(/.{1,64}/g)
			.join('\n')
		+ `\n-----END ${label}-----`
	return {
		pem,
		publicKeyPem: pair.publicKeyPem,
		decryptKey: await importPrivateKey(pem),
	}
}

/**
 * Answer GET requests per URL.
 *
 * @param {object} answers URL → response data.
 * @return {object} The spy.
 */
function answerGets(answers) {
	return vi.spyOn(axios, 'get').mockImplementation(async (url) => {
		if (!(url in answers)) {
			throw new Error(`unexpected GET ${url}`)
		}
		return { data: answers[url] }
	})
}

beforeEach(() => {
	setActivePinia(createPinia())
})

afterEach(() => {
	vi.restoreAllMocks()
})

describe('withdraw', () => {
	it('deletes the enrolment and keeps the status the server returns', async () => {
		const del = vi
			.spyOn(axios, 'delete')
			.mockResolvedValue({ data: { policy: 'optional', enrolled: false } })
		const store = useAccountRecoveryStore()
		store.status = { policy: 'optional', enrolled: true }

		expect(await store.withdraw()).toEqual({
			policy: 'optional',
			enrolled: false,
		})
		expect(del).toHaveBeenCalledWith(`${API}/enrolment`)
		expect(store.status.enrolled).toBe(false)
	})
})

describe('fetchOfficer', () => {
	it('adds the phrase of each request key to the officer view', async () => {
		answerGets({
			[`${API}/officer`]: {
				officer: true,
				officers: ['olga'],
				requests: [
					{ id: 'r1', requestPublicKey: toBase64(KEY_A) },
					{ id: 'r2', requestPublicKey: toBase64(KEY_B) },
				],
			},
		})
		const store = useAccountRecoveryStore()

		const view = await store.fetchOfficer()

		expect(view.officer).toBe(true)
		expect(view.officers).toEqual(['olga'])
		expect(view.requests.map((r) => r.phrase)).toEqual([
			await verificationPhrase(KEY_A),
			await verificationPhrase(KEY_B),
		])
		expect(store.officer).toEqual(view)
	})

	it('reads an empty answer as not an officer', async () => {
		answerGets({ [`${API}/officer`]: null })
		const store = useAccountRecoveryStore()

		expect(await store.fetchOfficer()).toEqual({
			officer: false,
			requests: [],
		})
	})
})

describe('createKey', () => {
	it('refuses when an officer has no encryption, and posts no key', async () => {
		const post = vi.spyOn(axios, 'post').mockResolvedValue({
			data: {
				recipients: [
					{ userId: 'olga', shareable: true, certificate: 'x' },
					{ userId: 'omar', shareable: false },
				],
			},
		})
		const store = useAccountRecoveryStore()
		store.officer = { officer: true, officers: ['olga', 'omar'] }

		await expect(store.createKey()).rejects.toThrow(
			'Officer {user} has no encryption set up yet.',
		)
		expect(post).toHaveBeenCalledTimes(1)
		expect(post).toHaveBeenCalledWith(
			'/apps/keepiq/api/v1/shares/recipient-certificates',
			{ userIds: ['olga', 'omar'] },
		)
	})

	it('posts the public key and a copy only the officer can open', async () => {
		const olga = await rsaPair()
		const post = vi.spyOn(axios, 'post').mockImplementation(async (url) => {
			if (url.endsWith('/recipient-certificates')) {
				return {
					data: {
						recipients: [
							{
								userId: 'olga',
								shareable: true,
								certificate: olga.publicKeyPem,
							},
						],
					},
				}
			}
			return { data: {} }
		})
		answerGets({ [`${API}/officer`]: { officer: true, requests: [] } })
		const store = useAccountRecoveryStore()
		store.officer = { officer: true, officers: ['olga'] }

		await store.createKey()

		const [url, body] = post.mock.calls[1]
		expect(url).toBe(`${API}/officer/keys`)
		expect(Object.keys(body).sort()).toEqual(['copies', 'publicKey'])
		expect(Object.keys(body.copies)).toEqual(['olga'])
		expect(JSON.stringify(body)).not.toContain('PRIVATE KEY')
		expect(
			await openRecoveryEnvelope(body.copies.olga, olga.decryptKey),
		).toContain('PRIVATE KEY')
		expect(store.officer).toEqual({ officer: true, requests: [] })
	}, 60000)
})

describe('approve and decline', () => {
	it('approves with a proof bound to the master password and reloads', async () => {
		const session = useSessionStore()
		session.suiteId = 'suite-1'
		session.encryptedPrivateKey = await encryptPrivateKey(
			RSA4096_PRIVATE_KEY_PKCS8_PEM,
			'correct horse',
		)
		const get = answerGets({
			'/apps/keepiq/api/v1/suites/suite-1/proof-challenge': {
				nonce: 'n-1',
			},
			[`${API}/officer`]: { officer: true, requests: [] },
		})
		const post = vi
			.spyOn(axios, 'post')
			.mockResolvedValue({ data: { status: 'approved' } })
		const store = useAccountRecoveryStore()

		expect(await store.approve({ id: 'r1' }, 'correct horse')).toBe('approved')

		expect(get.mock.calls[0][1]).toEqual({
			params: { purpose: 'approve-account-recovery' },
		})
		const [url, body, config] = post.mock.calls[0]
		expect(url).toBe(`${API}/requests/r1/approve`)
		expect(body).toEqual({})
		expect(config.headers[HEADER_NONCE]).toBe('n-1')
		expect(typeof config.headers[HEADER_PROOF]).toBe('string')
		expect(config.headers[HEADER_PROOF].length).toBeGreaterThan(0)
		expect(JSON.stringify(config)).not.toContain('correct horse')
		expect(store.officer).toEqual({ officer: true, requests: [] })
	}, 60000)

	it('sends no approval with a wrong master password', async () => {
		const session = useSessionStore()
		session.suiteId = 'suite-1'
		session.encryptedPrivateKey = await encryptPrivateKey(
			RSA4096_PRIVATE_KEY_PKCS8_PEM,
			'correct horse',
		)
		answerGets({
			'/apps/keepiq/api/v1/suites/suite-1/proof-challenge': {
				nonce: 'n-1',
			},
		})
		const post = vi.spyOn(axios, 'post')
		const store = useAccountRecoveryStore()

		await expect(store.approve({ id: 'r1' }, 'wrong')).rejects.toThrow()
		expect(post).not.toHaveBeenCalled()
	}, 60000)

	it('declines and reloads the officer view', async () => {
		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: {} })
		answerGets({ [`${API}/officer`]: { officer: true, requests: [] } })
		const store = useAccountRecoveryStore()

		await store.decline('r7')

		expect(post).toHaveBeenCalledWith(`${API}/requests/r7/decline`)
		expect(store.officer).toEqual({ officer: true, requests: [] })
	})
})

describe('handOff', () => {
	it('posts only a result sealed to the request key', async () => {
		const officer = await rsaPair()
		const recovery = await rsaPair()
		const user = await rsaPair()
		const request = await generateRequestKeyPair()
		useSessionStore().cryptoKey = officer.decryptKey
		answerGets({
			[`${API}/requests/r1/handoff`]: {
				wrappedRecoveryKey: await buildRecoveryEnvelope(
					recovery.pem,
					officer.publicKeyPem,
				),
				envelope: await buildRecoveryEnvelope(
					user.pem,
					recovery.publicKeyPem,
				),
				requestPublicKey: toBase64(request.publicKeyRaw),
			},
			[`${API}/officer`]: { officer: true, requests: [] },
		})
		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: {} })
		const store = useAccountRecoveryStore()

		await store.handOff({ id: 'r1' })

		const [url, body] = post.mock.calls[0]
		expect(url).toBe(`${API}/requests/r1/sealed`)
		expect(Object.keys(body)).toEqual(['sealedResult'])
		expect(body.sealedResult).not.toContain('PRIVATE KEY')
		expect(
			await openHandoff(
				body.sealedResult,
				request.privateKey,
				request.publicKeyRaw,
				'r1',
			),
		).toBe(user.pem)
		expect(store.officer).toEqual({ officer: true, requests: [] })
	}, 60000)
})

describe('fetchMyRequest', () => {
	it('marks a request filed in this browser and shows its phrase', async () => {
		answerGets({
			[`${API}/requests/mine`]: { request: { id: 'r1', status: 'pending' } },
		})
		const lookup = vi
			.spyOn(requestKeyStore, 'get')
			.mockResolvedValue({ privateKey: {}, publicKeyRaw: KEY_A })
		const store = useAccountRecoveryStore()

		const mine = await store.fetchMyRequest()

		expect(lookup).toHaveBeenCalledWith('r1')
		expect(mine).toEqual({
			id: 'r1',
			status: 'pending',
			fromThisBrowser: true,
			phrase: await verificationPhrase(KEY_A),
		})
		expect(store.myRequest).toEqual(mine)
	})

	it('marks a request filed elsewhere, without a phrase', async () => {
		answerGets({
			[`${API}/requests/mine`]: { request: { id: 'r2', status: 'approved' } },
		})
		vi.spyOn(requestKeyStore, 'get').mockResolvedValue(undefined)
		const store = useAccountRecoveryStore()

		expect(await store.fetchMyRequest()).toEqual({
			id: 'r2',
			status: 'approved',
			fromThisBrowser: false,
			phrase: null,
		})
	})

	it('keeps no request when the user has none, without a key lookup', async () => {
		answerGets({ [`${API}/requests/mine`]: {} })
		const lookup = vi.spyOn(requestKeyStore, 'get')
		const store = useAccountRecoveryStore()
		store.myRequest = { id: 'old' }

		expect(await store.fetchMyRequest()).toBeNull()
		expect(store.myRequest).toBeNull()
		expect(lookup).not.toHaveBeenCalled()
	})
})

describe('enrolAtUnlock', () => {
	it('enrols under the required policy when not current', async () => {
		answerGets({
			[`${API}/enrolment`]: {
				policy: 'required',
				enrolled: false,
				current: false,
				key: { id: 'k' },
			},
		})
		const store = useAccountRecoveryStore()
		store.enrol = vi.fn().mockResolvedValue({})

		expect(await store.enrolAtUnlock('pw')).toBe('enrolled')
		expect(store.enrol).toHaveBeenCalledWith('pw')
	})

	it('does nothing when the enrolment is current or no key is active', async () => {
		const store = useAccountRecoveryStore()
		store.enrol = vi.fn()
		answerGets({
			[`${API}/enrolment`]: {
				policy: 'required',
				current: true,
				key: { id: 'k' },
			},
		})
		expect(await store.enrolAtUnlock('pw')).toBeNull()

		vi.restoreAllMocks()
		answerGets({
			[`${API}/enrolment`]: { policy: 'required', current: false, key: null },
		})
		expect(await store.enrolAtUnlock('pw')).toBeNull()
		expect(store.enrol).not.toHaveBeenCalled()
	})

	it('never blocks the unlock when the enrolment fails', async () => {
		answerGets({
			[`${API}/enrolment`]: {
				policy: 'required',
				current: false,
				key: { id: 'k' },
			},
		})
		const store = useAccountRecoveryStore()
		store.enrol = vi.fn().mockRejectedValue(new Error('certificate refused'))

		expect(await store.enrolAtUnlock('pw')).toBeNull()
	})
})
