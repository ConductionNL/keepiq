/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Version history carries a login's `totp` seed and `passkeys` (keepiq#1326),
 * with real RSA keys. A version is shown with both fields in plain. A restore
 * sends a field the version holds, and leaves out one it does not hold (a version
 * saved before the field existed), so the row and every copy keep theirs, as the
 * server's restore rule expects; a partner share, which takes the whole value,
 * then gets the head's.
 *
 * @spec openspec/changes/sharing-atomic-share-save/specs/secret-version-history/spec.md#requirement-a-restore-is-one-share-save-of-the-version
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import {
	importPrivateKey,
	importPublicKey,
	rsaDecrypt,
	rsaEncrypt,
} from '../../src/crypto/rsa.js'
import { forgetVerifiedPartners } from '../../src/services/shareSave.js'
import { useSecretStore } from '../../src/store/modules/secret.js'
import { useSecretVersionStore } from '../../src/store/modules/secretVersion.js'
import { useSessionStore } from '../../src/store/modules/session.js'
import { shareSaveServer } from '../fixtures/shareSaveServer.js'
import {
	RSA4096_PRIVATE_KEY_PKCS8_PEM,
	RSA4096_PUBLIC_KEY_SPKI_PEM,
	RSA4096_SECONDARY_PRIVATE_KEY_PKCS8_PEM,
	RSA4096_SECONDARY_PUBLIC_KEY_SPKI_PEM,
} from '../vitest/fixtures/rsa-fixtures.js'

const F = JSON.parse(
	readFileSync(resolve(__dirname, '../fixtures/federation-chain.json'), 'utf8'),
)
const BOB = 'bob@cloud.partner.example'
const BASE = '2026-10-01T10:00:00+00:00'
const OLD_SEED = 'otpauth://totp/Bank:alice?secret=OLDSEEDOLDSEED22'
const HEAD_SEED = 'otpauth://totp/Bank:alice?secret=HEADSEEDHEADSEED'
const PASSKEYS = '[{"credentialId":"c1","rpId":"bank.example"}]'

/** Bob's verified lookup answer from a partner that stores neither field. */
const LEGACY_PARTNER = {
	cloudId: BOB,
	certificate: F.bob,
	chain: [F.intermediateA, F.rootA],
	partnerRootFingerprint: F.rootAFingerprint,
	valueFields: [],
}

/**
 * Encrypt a value for the owner.
 *
 * @param {string} value The plaintext.
 * @return {Promise<string>}
 */
async function own(value) {
	return rsaEncrypt(value, await importPublicKey(RSA4096_PUBLIC_KEY_SPKI_PEM))
}

/**
 * Restore version `v1` of `src` against the fake server, with Bob's copy
 * required and, when given, Bob's partner share.
 *
 * @param {object} version The version's value fields, in plain.
 * @param {object} [options] Options.
 * @param {object} [options.head] The head row's value fields, in plain.
 * @param {object} [options.partner] Bob's partner lookup answer; no partner share when absent.
 * @return {Promise<object>} The share-save log.
 */
async function restore(version, { head = {}, partner = null } = {}) {
	const encrypted = async (fields) => {
		const out = {}
		for (const [field, value] of Object.entries(fields)) {
			out[field] = value === null ? null : await own(value)
		}
		return out
	}
	const log = shareSaveServer({
		id: 'src',
		required: [
			{ secretId: 'copy-bob', holder: 'recipient', targetUserId: 'bob' },
		],
		suites: { bob: RSA4096_SECONDARY_PUBLIC_KEY_SPKI_PEM },
		partners: partner ? [{ shareId: 'fs-1', recipientCloudId: BOB }] : [],
		lookup: partner ? { [BOB]: partner } : {},
		rows: {
			v1: {
				id: 'v1',
				name: 'Bank',
				url: null,
				key: await own('old-pw'),
				login: null,
				additionalFields: null,
				totp: null,
				passkeys: null,
				...(await encrypted(version)),
			},
			src: {
				id: 'src',
				name: 'Bank',
				key: await own('pw'),
				totp: null,
				passkeys: null,
				...(await encrypted(head)),
				updatedAt: BASE,
			},
			versions: [],
		},
	})
	useSecretStore().secrets = [{ id: 'src', updatedAt: BASE }]
	await useSecretVersionStore().restore('v1', 'src')
	return log
}

beforeEach(async () => {
	setActivePinia(createPinia())
	vi.restoreAllMocks()
	forgetVerifiedPartners()
	const session = useSessionStore()
	session.cryptoKey = await importPrivateKey(RSA4096_PRIVATE_KEY_PKCS8_PEM)
	session.certificate = RSA4096_PUBLIC_KEY_SPKI_PEM
})

describe('viewVersion', () => {
	it('shows a seed and passkeys in plain', async () => {
		shareSaveServer({
			id: 'src',
			rows: {
				v1: {
					id: 'v1',
					key: await own('old-pw'),
					totp: await own(OLD_SEED),
					passkeys: await own(PASSKEYS),
				},
			},
		})

		const version = await useSecretVersionStore().viewVersion('v1')

		expect(version.key).toBe('old-pw')
		expect(version.totp).toBe(OLD_SEED)
		expect(version.passkeys).toBe(PASSKEYS)
	})

	it("gives '' for a version saved without them", async () => {
		shareSaveServer({
			id: 'src',
			rows: { v1: { id: 'v1', key: await own('old-pw'), totp: null } },
		})

		const version = await useSecretVersionStore().viewVersion('v1')

		expect(version.totp).toBe('')
		expect(version.passkeys).toBe('')
	})
})

describe('restore', () => {
	it('sends the seed and passkeys a version holds to the row and every copy', async () => {
		const log = await restore({ totp: OLD_SEED, passkeys: PASSKEYS })

		const final = log.saves.at(-1)
		expect(final.restoredFrom).toBe('v1')
		const owner = await importPrivateKey(RSA4096_PRIVATE_KEY_PKCS8_PEM)
		expect(await rsaDecrypt(final.patch.totp, owner)).toBe(OLD_SEED)
		expect(await rsaDecrypt(final.patch.passkeys, owner)).toBe(PASSKEYS)
		const bob = await importPrivateKey(RSA4096_SECONDARY_PRIVATE_KEY_PKCS8_PEM)
		const [copy] = final.copies
		expect(await rsaDecrypt(copy.totp, bob)).toBe(OLD_SEED)
		expect(await rsaDecrypt(copy.passkeys, bob)).toBe(PASSKEYS)
	})

	it('leaves out a field the version does not hold, so the row and copies keep theirs', async () => {
		const log = await restore({}, { head: { totp: HEAD_SEED } })

		const final = log.saves.at(-1)
		for (const entry of [final.patch, ...final.copies]) {
			expect('totp' in entry).toBe(false)
			expect('passkeys' in entry).toBe(false)
			expect(Object.keys(entry)).toEqual(
				expect.arrayContaining(['key', 'login', 'additionalFields']),
			)
		}
	})

	it('restores one field the version holds and keeps the other on the head', async () => {
		const log = await restore({ passkeys: PASSKEYS })

		const final = log.saves.at(-1)
		expect('totp' in final.patch).toBe(false)
		expect(typeof final.patch.passkeys).toBe('string')
		expect(final.patch.passkeys).not.toBe('')
	})

	it("restores a version without a seed for a login shared with an older partner, which gets null", async () => {
		const log = await restore(
			{},
			{ head: { totp: HEAD_SEED }, partner: { ...LEGACY_PARTNER } },
		)

		expect(log.saves.at(-1).federated[0].totp).toBeNull()
	})

	it('refuses a restore that gives the login a seed an older partner cannot store', async () => {
		const error = await restore(
			{ totp: OLD_SEED },
			{ partner: { ...LEGACY_PARTNER } },
		).catch((e) => e)

		expect(error.code).toBe('partner_upgrade_required')
		expect(useSecretVersionStore().error).toContain('older Keepiq')
	})

	it("gives a partner that stores them the head's seed when the version holds none", async () => {
		const log = await restore(
			{},
			{
				head: { totp: HEAD_SEED },
				partner: {
					cloudId: BOB,
					certificate: F.bob,
					chain: [F.intermediateA, F.rootA],
					partnerRootFingerprint: F.rootAFingerprint,
					valueFields: ['totp', 'passkeys'],
				},
			},
		)

		const [entry] = log.saves.at(-1).federated
		// The whole value: the head's seed, not a null that would clear it.
		expect(typeof entry.totp).toBe('string')
		expect(entry.totp.length).toBeGreaterThan(0)
		expect(entry.passkeys).toBeNull()
	})
})
