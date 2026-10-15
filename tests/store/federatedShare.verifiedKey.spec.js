/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A federated share is encrypted to the public key of the certificate the
 * browser verified, and a certificate text that is more than its one PEM
 * block is refused. Each test checks which key a ciphertext was made for.
 *
 * @spec openspec/specs/federated-sharing/spec.md#requirement-certificate-lookup-is-signed-allowlisted-and-verified-in-the-browser
 */

import axios from '@nextcloud/axios'
import { createPublicKey } from 'node:crypto'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { rsaDecrypt } from '../../src/crypto/rsa.js'
import {
	forgetVerifiedPartners,
	saveShared,
} from '../../src/services/shareSave.js'
import { useFederatedShareStore } from '../../src/store/modules/federatedShare.js'
import { useSecretStore } from '../../src/store/modules/secret.js'
import { shareSaveServer } from '../fixtures/shareSaveServer.js'
import {
	otherKeyText,
	recordEncryptionKeys,
} from '../vitest/fixtures/pem-extra-text.js'

const F = JSON.parse(
	readFileSync(resolve(__dirname, '../fixtures/federation-chain.json'), 'utf8'),
)
const BOB = 'bob@cloud.partner.example'
const PASSWORD = 'correct horse battery staple'
const SECRET = { key: PASSWORD, login: 'alice', additionalFields: null }
const PARTNERS = [{ shareId: 'fs-1', recipientCloudId: BOB }]
const BASE = '2026-10-01T10:00:00+00:00'

/** A password change of `src` that a partner share must receive. */
const SAVE = {
	id: 'src',
	plainDiff: { key: PASSWORD },
	patch: { key: 'OWN' },
	baseUpdatedAt: BASE,
	loadFull: async () => ({ ...SECRET, key: 'old', updatedAt: BASE }),
}

const BOB_SPKI = new Uint8Array(
	createPublicKey(F.bob).export({ type: 'spki', format: 'der' }),
)

/**
 * A lookup answer for Bob with the partner A chain and pin.
 *
 * @param {string} certificate The certificate text.
 * @return {object}
 */
function answer(certificate) {
	return {
		cloudId: BOB,
		certificate,
		chain: [F.intermediateA, F.rootA],
		partnerRootFingerprint: F.rootAFingerprint,
	}
}

/**
 * Wire the owner's server and record what the browser sends.
 *
 * @param {object} answer The lookup answer.
 * @param {Array<object>} shares The federated shares of the secret.
 * @return {{sent: Array<object>, suspends: Array<object>}}
 */
function server(answer, shares = []) {
	const sent = []
	const suspends = []
	vi.spyOn(axios, 'get').mockResolvedValue({ data: shares })
	vi.spyOn(axios, 'post').mockImplementation(async (url, body) => {
		if (url.includes('/federation/recipient-certificate')) {
			return { data: answer }
		}
		if (url.includes('/suspend')) {
			suspends.push(body)
		} else {
			sent.push(body)
		}
		return { data: {} }
	})
	vi.spyOn(axios, 'put').mockImplementation(async (url, body) => {
		sent.push(body)
		return { data: {} }
	})
	vi.spyOn(useSecretStore(), 'fetchSecret').mockResolvedValue(SECRET)
	return { sent, suspends }
}

/**
 * Whether the other fixture key opens a ciphertext.
 *
 * @param {string} ciphertext A field the browser sent.
 * @param {CryptoKey} privateKey The other key.
 * @return {Promise<boolean>}
 */
async function opensWith(ciphertext, privateKey) {
	try {
		return (await rsaDecrypt(ciphertext, privateKey)) === PASSWORD
	} catch {
		return false
	}
}

describe('federated share encryption key', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
		forgetVerifiedPartners()
	})

	it('shares nothing when the certificate text is more than one PEM block', async () => {
		const other = await otherKeyText()
		const { sent } = server(answer(`${other.text}\n${F.bob}`))
		const store = useFederatedShareStore()

		let refusal = null
		try {
			const recipient = await store.lookup(BOB)
			await store.share('src', SECRET, recipient)
		} catch (e) {
			refusal = e
		}

		for (const body of sent) {
			expect(await opensWith(body.key, other.privateKey)).toBe(false)
		}
		expect(sent).toHaveLength(0)
		expect(refusal?.reason).toBe('malformed')
	})

	it('suspends instead of re-encrypting when the certificate text is more than one PEM block', async () => {
		const other = await otherKeyText()
		const log = shareSaveServer({
			id: 'src',
			partners: PARTNERS,
			lookup: { [BOB]: answer(`${other.text}\n${F.bob}`) },
		})

		const result = await saveShared(SAVE)

		for (const body of log.saves) {
			for (const entry of body.federated) {
				expect(await opensWith(entry.key, other.privateKey)).toBe(false)
			}
		}
		expect(log.saves.at(-1).federated).toEqual([])
		expect(result.federated.suspended).toBe(1)
		expect(log.saves.at(-1).suspend).toEqual([
			{ shareId: 'fs-1', reason: 'malformed' },
		])
	})

	it('encrypts to the key of the verified certificate, not to a certificate text', async () => {
		const other = await otherKeyText()
		const { sent } = server(answer(F.bob))
		const store = useFederatedShareStore()
		const verified = await store.lookup(BOB)
		const keys = recordEncryptionKeys()

		// A certificate text beside the verified result is not used.
		await store.share('src', SECRET, {
			...verified,
			certificate: `${other.text}\n${F.bob}`,
		})

		expect(sent).toHaveLength(1)
		expect(await opensWith(sent[0].key, other.privateKey)).toBe(false)
		expect(keys.length).toBeGreaterThan(0)
		for (const key of keys) {
			expect(key).toEqual(BOB_SPKI)
		}
	})

	it('re-encrypts an update to the key of the certificate verified now', async () => {
		shareSaveServer({
			id: 'src',
			partners: PARTNERS,
			lookup: { [BOB]: answer(F.bob) },
		})
		const keys = recordEncryptionKeys()

		const result = await saveShared(SAVE)

		expect(result.federated.written).toBe(1)
		expect(result.federated.suspended).toBe(0)
		expect(keys.length).toBeGreaterThan(0)
		for (const key of keys) {
			expect(key).toEqual(BOB_SPKI)
		}
	})
})
