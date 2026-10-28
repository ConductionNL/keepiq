/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * How the secret store carries a login's `totp` seed and `passkeys` (keepiq#1326).
 * The server reads a literal `''` as "not set" and anything else as ciphertext, so
 * these pin that a cleared field goes out as `''`, never as an encrypted empty
 * string, and that a field the caller left out stays out of the request.
 *
 * Crypto is mocked as ENC(<plaintext>): the shape of the request is what is under
 * test here; real round-trips are in shareSave.valueFields.spec.js.
 *
 * @spec openspec/specs/secrets/spec.md#requirement-update-secret
 */

import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../../src/crypto/index.js', () => ({
	importPublicKey: vi.fn(async (pem) => `KEY(${pem})`),
	rsaEncrypt: vi.fn(async (value) => `ENC(${value})`),
	rsaDecrypt: vi.fn(async (value) =>
		String(value).replace(/^ENC\((.*)\)$/s, '$1'),
	),
}))

import { rsaEncrypt } from '../../src/crypto/index.js'
import { useOfflineStore } from '../../src/store/modules/offline.js'
import { useSecretStore } from '../../src/store/modules/secret.js'
import { useSessionStore } from '../../src/store/modules/session.js'
import { shareSaveServer } from '../fixtures/shareSaveServer.js'

const BASE = '2026-10-01T10:00:00+00:00'
const SEED = 'otpauth://totp/Bank:alice?secret=JBSWY3DPEHPK3PXP'
const PASSKEYS = '[{"credentialId":"c1","rpId":"bank.example"}]'

/**
 * Whether the mocked rsaEncrypt was ever asked to encrypt an empty string.
 *
 * @return {boolean}
 */
function encryptedEmpty() {
	return rsaEncrypt.mock.calls.some(([value]) => value === '')
}

describe('secret store — totp and passkeys', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
		rsaEncrypt.mockClear()
		const session = useSessionStore()
		session.certificate = 'PEM-OWN'
		session.cryptoKey = { fake: true }
	})

	describe('createSecret', () => {
		it('encrypts a seed and passkeys the user entered', async () => {
			const post = vi
				.spyOn(axios, 'post')
				.mockResolvedValue({ data: { id: 's1' } })

			await useSecretStore().createSecret({
				name: 'Bank',
				key: 'pw',
				totp: SEED,
				passkeys: PASSKEYS,
			})

			const body = post.mock.calls[0][1]
			expect(body.totp).toBe(`ENC(${SEED})`)
			expect(body.passkeys).toBe(`ENC(${PASSKEYS})`)
		})

		it('sends neither field when none was entered, also not as an encrypted empty string', async () => {
			const post = vi
				.spyOn(axios, 'post')
				.mockResolvedValue({ data: { id: 's1' } })

			await useSecretStore().createSecret({
				name: 'Bank',
				key: 'pw',
				totp: '',
				passkeys: '',
			})

			const body = post.mock.calls[0][1]
			expect('totp' in body).toBe(false)
			expect('passkeys' in body).toBe(false)
		})

		it('queues the same payload offline', async () => {
			const offline = useOfflineStore()
			offline.servedFromCache = true
			const enqueue = vi.spyOn(offline, 'enqueue').mockResolvedValue()

			await useSecretStore().createSecret({
				name: 'Bank',
				key: 'pw',
				totp: SEED,
			})

			const { body } = enqueue.mock.calls[0][0]
			expect(body.totp).toBe(`ENC(${SEED})`)
			expect('passkeys' in body).toBe(false)
		})
	})

	describe('updateSecret', () => {
		/**
		 * Save a change of `s1`, held by Bob through a copy.
		 *
		 * @param {object} data The change.
		 * @return {Promise<object>} The share-save log.
		 */
		async function update(data) {
			const log = shareSaveServer({
				id: 's1',
				required: [
					{ secretId: 'copy-bob', holder: 'recipient', targetUserId: 'bob' },
				],
				suites: { bob: 'PEM-BOB' },
			})
			await useSecretStore().updateSecret('s1', data, {
				baseUpdatedAt: BASE,
			})
			return log
		}

		it('sets a seed for the row and every copy', async () => {
			const log = await update({ totp: SEED })

			const final = log.saves.at(-1)
			expect(final.patch).toEqual({ totp: `ENC(${SEED})` })
			expect(final.copies).toEqual([
				{ secretId: 'copy-bob', totp: `ENC(${SEED})` },
			])
		})

		it("removes a seed with a literal '' for the row and every copy", async () => {
			const log = await update({ totp: '' })

			const final = log.saves.at(-1)
			expect(final.patch).toEqual({ totp: '' })
			expect(final.copies).toEqual([{ secretId: 'copy-bob', totp: '' }])
			expect(encryptedEmpty()).toBe(false)
		})

		it("treats null as a removal, sent as ''", async () => {
			const log = await update({ passkeys: null })

			expect(log.saves.at(-1).patch).toEqual({ passkeys: '' })
			expect(encryptedEmpty()).toBe(false)
		})

		it('replaces the whole passkeys list the caller sends', async () => {
			const log = await update({ passkeys: PASSKEYS })

			expect(log.saves.at(-1).patch).toEqual({
				passkeys: `ENC(${PASSKEYS})`,
			})
		})

		it('leaves both fields out of every entry when the caller does', async () => {
			const log = await update({ key: 'new-pw' })

			const final = log.saves.at(-1)
			expect(Object.keys(final.patch)).toEqual(['key'])
			expect(final.copies.map((copy) => Object.keys(copy))).toEqual([
				['secretId', 'key'],
			])
		})

		it("queues a removal offline as '' and replays it through the share save", async () => {
			const offline = useOfflineStore()
			offline.servedFromCache = true
			const enqueue = vi.spyOn(offline, 'enqueue').mockResolvedValue()

			await useSecretStore().updateSecret(
				's1',
				{ totp: '' },
				{ baseUpdatedAt: BASE },
			)

			const entry = enqueue.mock.calls[0][0]
			expect(entry.body).toEqual({ totp: '' })
			expect(await offline.plaintextOf(entry.body)).toEqual({ totp: '' })
			expect(encryptedEmpty()).toBe(false)
		})
	})

	describe('decryptSecret', () => {
		it('opens both fields as plain strings', async () => {
			const plain = await useSecretStore().decryptSecret({
				id: 's1',
				key: 'ENC(pw)',
				totp: `ENC(${SEED})`,
				passkeys: `ENC(${PASSKEYS})`,
			})

			expect(plain.totp).toBe(SEED)
			expect(plain.passkeys).toBe(PASSKEYS)
		})

		it.each([
			['null', null],
			["''", ''],
			['absent', undefined],
		])("gives '' for a field that is %s", async (_, value) => {
			const plain = await useSecretStore().decryptSecret({
				id: 's1',
				key: 'ENC(pw)',
				totp: value,
				passkeys: value,
			})

			expect(plain.totp).toBe('')
			expect(plain.passkeys).toBe('')
		})

		it('opens them on a use-only copy too, for the code', async () => {
			const plain = await useSecretStore().decryptSecret({
				id: 'copy',
				key: 'ENC(pw)',
				useOnly: true,
				totp: `ENC(${SEED})`,
			})

			expect(plain.totp).toBe(SEED)
		})
	})

	describe('contributeSecret', () => {
		/**
		 * Contribute a login to a team folder with one member besides the owner.
		 *
		 * @param {object} data The plaintext login.
		 * @return {Promise<object>} The posted body.
		 */
		async function contribute(data) {
			vi.spyOn(axios, 'get').mockResolvedValue({
				data: {
					ownerCertificate: 'PEM-IRIS',
					recipients: [{ userId: 'hank', certificate: 'PEM-HANK' }],
				},
			})
			const post = vi
				.spyOn(axios, 'post')
				.mockResolvedValue({ data: { secret: { id: 's1' }, copies: 1 } })
			await useSecretStore().contributeSecret('tf-ops', {
				name: 'Bank',
				key: 'pw',
				...data,
			})
			return post.mock.calls[0][1]
		}

		it('carries both fields on the owner row and every member copy', async () => {
			const body = await contribute({ totp: SEED, passkeys: PASSKEYS })

			expect(body.totp).toBe(`ENC(${SEED})`)
			expect(body.passkeys).toBe(`ENC(${PASSKEYS})`)
			expect(body.copies).toEqual([
				expect.objectContaining({
					targetUserId: 'hank',
					encryptedTotp: `ENC(${SEED})`,
					encryptedPasskeys: `ENC(${PASSKEYS})`,
				}),
			])
		})

		it('sends null, never an encrypted empty string, for a login without them', async () => {
			const body = await contribute({})

			expect(body.totp).toBeNull()
			expect(body.passkeys).toBeNull()
			expect(body.copies[0].encryptedTotp).toBeNull()
			expect(body.copies[0].encryptedPasskeys).toBeNull()
			expect(encryptedEmpty()).toBe(false)
		})
	})
})
