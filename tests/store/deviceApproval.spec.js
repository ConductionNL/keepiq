/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * New device approval in the browser (crypto-new-device-approval tasks 2.1
 * to 2.3): the phrase, sealing and opening the unlock key, what the approve
 * request carries, and the unlock after a mocked approval.
 *
 * @spec openspec/changes/crypto-new-device-approval/specs/new-device-approval/spec.md#requirement-approval-seals-the-unlock-key-and-needs-proof-of-the-master-password
 */

import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import DeviceApprovalSection from '../../src/components/settings/DeviceApprovalSection.vue'
import { encryptPrivateKey } from '../../src/crypto/aes.js'
import {
	openUnlockKey,
	sealUnlockKey,
	toBase64,
	unlockKeyFromPassword,
} from '../../src/crypto/deviceApproval.js'
import { generateRecipientKeyPair } from '../../src/crypto/hpke.js'
import {
	PHRASE_WORDS,
	verificationPhrase,
} from '../../src/crypto/verificationPhrase.js'
import { useDeviceApprovalStore } from '../../src/store/modules/deviceApproval.js'
import { useSessionStore } from '../../src/store/modules/session.js'

vi.mock('../../src/crypto/keyProof.js', () => ({
	PROOF_PURPOSE: { APPROVE_DEVICE: 'approve-device' },
	buildKeyProofHeaders: vi.fn().mockResolvedValue({
		'X-Keepiq-Key-Proof-Nonce': 'nonce',
		'X-Keepiq-Key-Proof': 'signature',
	}),
}))

const PEM = '-----BEGIN PRIVATE KEY-----\nTEST\n-----END PRIVATE KEY-----'

describe('verification phrase', () => {
	it('is five words from 256 distinct ones, the same for the same key', async () => {
		expect(new Set(PHRASE_WORDS).size).toBe(256)
		const key = new Uint8Array(32).fill(7)
		const phrase = await verificationPhrase(key)
		expect(phrase.split(' ')).toHaveLength(5)
		expect(await verificationPhrase(key.slice())).toBe(phrase)
	})

	it('differs for a swapped key', async () => {
		const a = await verificationPhrase(new Uint8Array(32).fill(7))
		const b = await verificationPhrase(new Uint8Array(32).fill(8))
		expect(a).not.toBe(b)
	})
})

describe('sealing the unlock key', () => {
	it('opens only with the request key and the request id', async () => {
		const pair = await generateRecipientKeyPair()
		const raw = new Uint8Array(32).fill(3)
		const sealed = await sealUnlockKey(raw, toBase64(pair.publicKeyRaw), 'r-1')

		expect(sealed).not.toContain(toBase64(raw))
		expect(
			await openUnlockKey(sealed, pair.privateKey, pair.publicKeyRaw, 'r-1'),
		).toEqual(raw)
		await expect(
			openUnlockKey(sealed, pair.privateKey, pair.publicKeyRaw, 'r-2'),
		).rejects.toThrow()
		const other = await generateRecipientKeyPair()
		await expect(
			openUnlockKey(sealed, other.privateKey, other.publicKeyRaw, 'r-1'),
		).rejects.toThrow()
	})

	it('refuses a wrong master password before anything is sealed', async () => {
		const envelope = await encryptPrivateKey(PEM, 'correct horse')
		await expect(unlockKeyFromPassword('wrong', envelope)).rejects.toThrow()
		expect(await unlockKeyFromPassword('correct horse', envelope)).toHaveLength(
			32,
		)
	})
})

describe('device approval store', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
	})

	it('approves with only the sealed key and the proof headers', async () => {
		const envelope = await encryptPrivateKey(PEM, 'correct horse')
		const session = useSessionStore()
		session.encryptedPrivateKey = envelope
		session.suiteId = 'suite-1'
		const pair = await generateRecipientKeyPair()
		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: {} })
		const store = useDeviceApprovalStore()

		await store.approve(
			{ id: 'r-1', requestPublicKey: toBase64(pair.publicKeyRaw) },
			'correct horse',
		)

		const [url, body, config] = post.mock.calls[0]
		expect(url).toContain('/device-approvals/r-1/approve')
		expect(Object.keys(body)).toEqual(['sealedUnlockKey'])
		expect(JSON.stringify(body)).not.toContain('correct horse')
		expect(config.headers['X-Keepiq-Key-Proof']).toBe('signature')
		const raw = await openUnlockKey(
			body.sealedUnlockKey,
			pair.privateKey,
			pair.publicKeyRaw,
			'r-1',
		)
		expect(raw).toEqual(await unlockKeyFromPassword('correct horse', envelope))
	})

	it('unlocks this session after a mocked approval and drops the one-time key', async () => {
		const store = useDeviceApprovalStore()
		let publicKey = null
		vi.spyOn(axios, 'post').mockImplementation((url, body) => {
			publicKey = body.publicKey
			return Promise.resolve({
				data: { id: 'r-1', requestSecret: 'secret', expiresAt: 'later' },
			})
		})
		const request = await store.startRequest('web')
		expect(request.phrase.split(' ')).toHaveLength(5)

		const raw = new Uint8Array(32).fill(9)
		const sealed = await sealUnlockKey(raw, publicKey, 'r-1')
		const get = vi.spyOn(axios, 'get').mockResolvedValue({
			data: { status: 'approved', sealedUnlockKey: sealed },
		})
		const session = useSessionStore()
		const unlocked = []
		session.unlockWithRawKey = vi.fn(async (key) => {
			unlocked.push(Array.from(key))
		})

		expect(await store.pollOnce()).toBe('unlocked')
		expect(get.mock.calls[0][1].headers['X-Keepiq-Request-Secret']).toBe(
			'secret',
		)
		expect(unlocked[0]).toEqual(Array.from(raw))
		expect(await store.pollOnce()).toBe('none')
	})

	it('the admin switch saves device_approval_enabled', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: { device_approval_enabled: true },
		})
		const put = vi.spyOn(axios, 'put').mockResolvedValue({ data: {} })
		const wrapper = mount(DeviceApprovalSection, {
			global: {
				stubs: {
					CnSettingsSection: { template: '<section><slot /></section>' },
				},
			},
		})
		await new Promise((resolve) => setTimeout(resolve, 0))
		const box = wrapper.find('[data-testid="device-approval-enabled"]')
		expect(box.element.checked).toBe(true)
		await box.setValue(false)
		expect(put.mock.calls[0][1]).toEqual({ device_approval_enabled: false })
	})
})
