/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Organisation account recovery in the browser
 * (crypto-organisation-account-recovery tasks 2.2, 3.2, 4.1, 4.4, 4.5, 5.3).
 *
 * @spec openspec/changes/crypto-organisation-account-recovery/specs/organisation-account-recovery/spec.md#requirement-the-recovered-key-reaches-only-the-requesting-browser
 */

import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import AdminSuiteSection from '../../src/components/settings/AdminSuiteSection.vue'
import { certificateFingerprint, chainsTo } from '../../src/certificates/x509.js'
import {
	createRecoveryKey,
	generateRequestKeyPair,
	openHandoff,
	sealHandoff,
} from '../../src/crypto/accountRecovery.js'
import { encryptPrivateKey } from '../../src/crypto/aes.js'
import { toBase64 } from '../../src/crypto/deviceApproval.js'
import {
	buildRecoveryEnvelope,
	openRecoveryEnvelope,
} from '../../src/crypto/emergencyEnvelope.js'
import { generateKeyPair, importPrivateKey } from '../../src/crypto/rsa.js'
import {
	requestKeyStore,
	useAccountRecoveryStore,
} from '../../src/store/modules/accountRecovery.js'
import { useSessionStore } from '../../src/store/modules/session.js'
import {
	INTERMEDIATE_PEM,
	LEAF_PEM,
	OTHER_ROOT_PEM,
	ROOT_PEM,
} from '../vitest/fixtures/recovery-chain.js'

vi.mock('../../src/crypto/keyProof.js', () => ({
	PROOF_PURPOSE: {
		APPROVE_ACCOUNT_RECOVERY: 'approve-account-recovery',
		UPDATE_PRIVATE_KEY: 'update-private-key',
	},
	buildKeyProofHeaders: vi
		.fn()
		.mockResolvedValue({ 'X-Keepiq-Key-Proof': 'signature' }),
}))

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

describe('the recovery certificate check', () => {
	it('accepts a certificate issued through the instance chain', async () => {
		expect(await chainsTo(LEAF_PEM, [INTERMEDIATE_PEM, ROOT_PEM])).toBe(true)
	})

	it('refuses another root, a missing chain and a broken order', async () => {
		expect(await chainsTo(LEAF_PEM, [INTERMEDIATE_PEM, OTHER_ROOT_PEM])).toBe(
			false,
		)
		expect(await chainsTo(LEAF_PEM, [])).toBe(false)
		expect(await chainsTo(LEAF_PEM, [ROOT_PEM])).toBe(false)
	})

	it('computes the fingerprint the server shows', async () => {
		expect(await certificateFingerprint(LEAF_PEM)).toMatch(/^[0-9a-f]{64}$/)
	})
})

describe('the recovery key and the handoff', () => {
	it('creates the key with only wrapped copies, each opened by its own officer', async () => {
		const olga = await rsaPair()
		const omar = await rsaPair()
		const result = await createRecoveryKey({
			olga: olga.publicKeyPem,
			omar: omar.publicKeyPem,
		})

		expect(Object.keys(result).sort()).toEqual(['copies', 'publicKey'])
		expect(JSON.stringify(result)).not.toContain('PRIVATE KEY')
		const fromOlga = await openRecoveryEnvelope(
			result.copies.olga,
			olga.decryptKey,
		)
		const fromOmar = await openRecoveryEnvelope(
			result.copies.omar,
			omar.decryptKey,
		)
		expect(fromOlga).toContain('PRIVATE KEY')
		expect(fromOlga).toBe(fromOmar)
		await expect(
			openRecoveryEnvelope(result.copies.olga, omar.decryptKey),
		).rejects.toThrow()
	}, 60000)

	it('hands the user key to the request key only, through the officer', async () => {
		const officer = await rsaPair()
		const recovery = await rsaPair()
		const user = await rsaPair()
		const request = await generateRequestKeyPair()
		const material = {
			wrappedRecoveryKey: await buildRecoveryEnvelope(
				recovery.pem,
				officer.publicKeyPem,
			),
			envelope: await buildRecoveryEnvelope(user.pem, recovery.publicKeyPem),
			requestPublicKey: toBase64(request.publicKeyRaw),
		}

		const sealed = await sealHandoff(material, officer.decryptKey, 'req-1')

		expect(sealed).not.toContain('PRIVATE KEY')
		expect(
			await openHandoff(
				sealed,
				request.privateKey,
				request.publicKeyRaw,
				'req-1',
			),
		).toBe(user.pem)
		await expect(
			openHandoff(sealed, request.privateKey, request.publicKeyRaw, 'req-2'),
		).rejects.toThrow()
	}, 60000)
})

describe('account recovery store', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
	})

	it('enrols with an envelope that opens with the recovery key and holds no PEM or password', async () => {
		const recovery = await rsaPair()
		const user = await rsaPair()
		const session = useSessionStore()
		session.encryptedPrivateKey = await encryptPrivateKey(
			user.pem,
			'correct horse',
		)
		const store = useAccountRecoveryStore()
		store.status = {
			policy: 'optional',
			enrolled: false,
			current: false,
			key: {
				id: 'key-1',
				certificate: recovery.publicKeyPem,
				fingerprint: 'x',
				caChain: [],
			},
		}
		store.verifyKey = vi.fn().mockResolvedValue('x')
		const put = vi
			.spyOn(axios, 'put')
			.mockResolvedValue({ data: { enrolled: true } })

		await store.enrol('correct horse')

		const body = put.mock.calls[0][1]
		expect(body.recoveryKeyId).toBe('key-1')
		expect(JSON.stringify(body)).not.toContain('PRIVATE KEY')
		expect(JSON.stringify(body)).not.toContain('correct horse')
		expect(await openRecoveryEnvelope(body.envelope, recovery.decryptKey)).toBe(
			user.pem,
		)
	}, 60000)

	it('refuses to enrol to a certificate that does not chain to the instance', async () => {
		const store = useAccountRecoveryStore()
		await expect(
			store.verifyKey({
				certificate: LEAF_PEM,
				fingerprint: 'whatever',
				caChain: [INTERMEDIATE_PEM, OTHER_ROOT_PEM],
			}),
		).rejects.toThrow()
		const good = await certificateFingerprint(LEAF_PEM)
		expect(
			await store.verifyKey({
				certificate: LEAF_PEM,
				fingerprint: good,
				caChain: [INTERMEDIATE_PEM, ROOT_PEM],
			}),
		).toBe(good)
	})

	it('completes: new wrapping with a proof, request fulfilled, one-time key dropped', async () => {
		const user = await rsaPair()
		const request = await generateRequestKeyPair()
		const sealed = await (async () => {
			const officer = await rsaPair()
			const recovery = await rsaPair()
			return sealHandoff(
				{
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
				officer.decryptKey,
				'req-1',
			)
		})()
		const stored = new Map([['req-1', request]])
		vi.spyOn(requestKeyStore, 'get').mockImplementation(async (id) =>
			stored.get(id),
		)
		vi.spyOn(requestKeyStore, 'delete').mockImplementation(async (id) => {
			stored.delete(id)
		})
		const put = vi.spyOn(axios, 'put').mockResolvedValue({ data: {} })
		const post = vi
			.spyOn(axios, 'post')
			.mockResolvedValue({ data: { handledBy: 'omar' } })
		const session = useSessionStore()
		session.unlock = vi.fn().mockResolvedValue()
		const store = useAccountRecoveryStore()
		store.myRequest = {
			id: 'req-1',
			suiteId: 'suite-bob',
			status: 'approved',
			sealedResult: sealed,
		}

		expect(await store.complete('a brand new password')).toBe('omar')

		const [url, body, config] = put.mock.calls[0]
		expect(url).toContain('/suites/suite-bob/private-key')
		expect(Object.keys(body)).toEqual(['encryptedPrivateKey'])
		expect(config.headers['X-Keepiq-Key-Proof']).toBe('signature')
		expect(post.mock.calls[0][0]).toContain('/recovery/requests/req-1/complete')
		expect(session.unlock).toHaveBeenCalledWith('a brand new password')
		expect(stored.has('req-1')).toBe(false)
	}, 60000)

	it('warns before revoking the suite of an enrolled user', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: { enrolled: true } })
		const wrapper = mount(AdminSuiteSection, {
			global: {
				stubs: {
					CnSettingsSection: { template: '<section><slot /></section>' },
				},
			},
		})
		wrapper.vm.suiteId = 'suite-of-bob-1234'
		await new Promise((resolve) => setTimeout(resolve, 0))
		await wrapper.vm.$nextTick()
		expect(
			wrapper.find('[data-testid="admin-suite-recovery-warning"]').exists(),
		).toBe(true)
	})
})

describe('filing and re-enrolling', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
	})

	it('files a request with a one-time key that cannot leave this browser', async () => {
		let saved = null
		vi.spyOn(requestKeyStore, 'put').mockImplementation(async (id, value) => {
			saved = { id, value }
		})
		const post = vi
			.spyOn(axios, 'post')
			.mockResolvedValue({ data: { id: 'req-9', status: 'pending' } })
		const store = useAccountRecoveryStore()

		const request = await store.startRequest()

		expect(saved.id).toBe('req-9')
		expect(saved.value.privateKey.extractable).toBe(false)
		expect(post.mock.calls[0][1]).toEqual({
			publicKey: toBase64(saved.value.publicKeyRaw),
			purpose: 'password',
		})
		expect(request.phrase.split(' ')).toHaveLength(5)
	})

	it('re-enrols at unlock when the enrolment fell behind a rotation', async () => {
		const store = useAccountRecoveryStore()
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: {
				policy: 'optional',
				enrolled: true,
				current: false,
				key: { id: 'key-2' },
			},
		})
		store.enrol = vi.fn().mockResolvedValue({})
		expect(await store.enrolAtUnlock('pw')).toBe('enrolled')
		expect(store.enrol).toHaveBeenCalledWith('pw')

		vi.spyOn(axios, 'get').mockResolvedValue({
			data: {
				policy: 'optional',
				enrolled: false,
				current: false,
				key: { id: 'key-2' },
			},
		})
		store.enrol = vi.fn()
		expect(await store.enrolAtUnlock('pw')).toBeNull()
		expect(store.enrol).not.toHaveBeenCalled()
	})
})

describe('the officer path for a new device', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
	})

	it('files with purpose device and unlocks once from the recovered key, without a password reset', async () => {
		const user = await rsaPair()
		const request = await generateRequestKeyPair()
		const officer = await rsaPair()
		const recovery = await rsaPair()
		const sealed = await sealHandoff(
			{
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
			officer.decryptKey,
			'req-d',
		)
		vi.spyOn(requestKeyStore, 'get').mockResolvedValue(request)
		vi.spyOn(requestKeyStore, 'delete').mockResolvedValue()
		const put = vi.spyOn(axios, 'put')
		vi.spyOn(axios, 'post').mockResolvedValue({ data: { handledBy: 'olga' } })
		const session = useSessionStore()
		session.unlockWithPrivateKeyPem = vi.fn().mockResolvedValue()
		const store = useAccountRecoveryStore()
		store.myRequest = {
			id: 'req-d',
			purpose: 'device',
			status: 'approved',
			sealedResult: sealed,
		}

		expect(await store.unlockDevice()).toBe('olga')
		expect(session.unlockWithPrivateKeyPem).toHaveBeenCalledWith(user.pem)
		expect(put).not.toHaveBeenCalled()

		store.myRequest = {
			id: 'req-p',
			purpose: 'password',
			status: 'approved',
			sealedResult: sealed,
		}
		await expect(store.unlockDevice()).rejects.toThrow()
	}, 60000)

	it('sends the purpose with the request', async () => {
		vi.spyOn(requestKeyStore, 'put').mockResolvedValue()
		const post = vi
			.spyOn(axios, 'post')
			.mockResolvedValue({ data: { id: 'req-d' } })
		await useAccountRecoveryStore().startRequest('device')
		expect(post.mock.calls[0][1].purpose).toBe('device')
	})
})
