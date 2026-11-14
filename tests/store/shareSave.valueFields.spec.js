/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A login's `totp` seed and `passkeys` in the share save and the federated share
 * (keepiq#1326), with real RSA keys and the real certificate verifier. A cleared
 * field travels as a literal `''`, never as an encrypted empty string; a partner
 * gets either field only when its lookup answer advertises it stores it, and
 * null otherwise. A change that gives the login a field such a partner cannot
 * store is refused; any other change saves, and a new federated share of a
 * login holding one is refused.
 *
 * @spec openspec/specs/federated-sharing/spec.md#requirement-federated-shares-carry-only-browser-made-ciphertext
 */

import axios from '@nextcloud/axios'
import { createPublicKey } from 'node:crypto'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { importPrivateKey, rsaDecrypt } from '../../src/crypto/rsa.js'
import {
	encryptSparse,
	forgetVerifiedPartners,
	saveShared,
	ShareSaveError,
	shareSaveErrorText,
} from '../../src/services/shareSave.js'
import {
	PartnerUpgradeRequiredError,
	partnerUpgradeText,
	useFederatedShareStore,
} from '../../src/store/modules/federatedShare.js'
import { useSessionStore } from '../../src/store/modules/session.js'
import {
	echoedCloudId,
	httpError,
	shareSaveServer,
} from '../fixtures/shareSaveServer.js'
import {
	RSA4096_PRIVATE_KEY_PKCS8_PEM,
	RSA4096_PUBLIC_KEY_SPKI_PEM,
	RSA4096_SECONDARY_PRIVATE_KEY_PKCS8_PEM,
	RSA4096_SECONDARY_PUBLIC_KEY_SPKI_PEM,
} from '../vitest/fixtures/rsa-fixtures.js'

/** The release the server declares as the first to store a seed and passkeys. */
const SINCE = '0.3.12'

vi.mock('@nextcloud/initial-state', () => ({
	loadState: (app, key, fallback) =>
		key === 'loginValueFields' ? { since: '0.3.12' } : fallback,
}))

const F = JSON.parse(
	readFileSync(resolve(__dirname, '../fixtures/federation-chain.json'), 'utf8'),
)
const BOB = 'bob@cloud.partner.example'
const BASE = '2026-10-01T10:00:00+00:00'
const SEED = 'otpauth://totp/Bank:alice?secret=JBSWY3DPEHPK3PXP'
const PASSKEYS = '[{"credentialId":"c1","rpId":"bank.example"}]'
const PARTNERS = [{ shareId: 'fs-1', recipientCloudId: BOB }]

/** Bob's verified lookup answer, before `valueFields`. */
const VALID = {
	cloudId: BOB,
	certificate: F.bob,
	chain: [F.intermediateA, F.rootA],
	partnerRootFingerprint: F.rootAFingerprint,
}
const UPGRADED = { ...VALID, valueFields: ['totp', 'passkeys'] }
const OLDER = { ...VALID, valueFields: [] }

beforeEach(async () => {
	setActivePinia(createPinia())
	vi.restoreAllMocks()
	forgetVerifiedPartners()
	const session = useSessionStore()
	session.cryptoKey = await importPrivateKey(RSA4096_PRIVATE_KEY_PKCS8_PEM)
	session.certificate = RSA4096_PUBLIC_KEY_SPKI_PEM
})

describe('encryptSparse', () => {
	it('encrypts a seed and passkeys the holder can open', async () => {
		const out = await encryptSparse(
			{ totp: SEED, passkeys: PASSKEYS },
			RSA4096_SECONDARY_PUBLIC_KEY_SPKI_PEM,
		)

		const bob = await importPrivateKey(RSA4096_SECONDARY_PRIVATE_KEY_PKCS8_PEM)
		expect(await rsaDecrypt(out.totp, bob)).toBe(SEED)
		expect(await rsaDecrypt(out.passkeys, bob)).toBe(PASSKEYS)
	})

	it.each([
		["''", ''],
		['null', null],
	])("sends a cleared field (%s) as a literal ''", async (_, cleared) => {
		const out = await encryptSparse(
			{ totp: cleared, passkeys: cleared },
			RSA4096_SECONDARY_PUBLIC_KEY_SPKI_PEM,
		)

		expect(out).toEqual({ totp: '', passkeys: '' })
	})

	it('keeps an absent field absent', async () => {
		const out = await encryptSparse(
			{ key: 'pw' },
			RSA4096_SECONDARY_PUBLIC_KEY_SPKI_PEM,
		)

		expect(Object.keys(out)).toEqual(['key'])
	})
})

describe('a share save reaching a partner', () => {
	/**
	 * Save a change of `src` against the fake server, Bob's partner share
	 * required, with `stored` as the row's current plain value.
	 *
	 * @param {object} lookup Bob's lookup answer.
	 * @param {object} stored The stored value, in plain.
	 * @param {object} [change] The plainDiff and patch.
	 * @return {Promise<{log: object, result: object|Error}>}
	 */
	async function save(lookup, stored, change = {}) {
		const log = shareSaveServer({
			id: 'src',
			partners: PARTNERS,
			lookup: { [BOB]: lookup },
		})
		const result = await saveShared({
			id: 'src',
			plainDiff: { key: 'new-pw' },
			patch: { key: 'OWN' },
			baseUpdatedAt: BASE,
			loadFull: async () => ({
				key: 'old',
				login: 'alice',
				additionalFields: null,
				totp: null,
				passkeys: null,
				...stored,
				updatedAt: BASE,
			}),
			...change,
		}).catch((e) => e)
		return { log, result }
	}

	it('encrypts the seed for a partner that stores it, and sends null for passkeys the login lacks', async () => {
		// The fixture chain has no private key for Bob, so this one partner
		// verifies to a key the test holds.
		const log = shareSaveServer({ id: 'src', partners: PARTNERS })
		vi.spyOn(useFederatedShareStore(), 'lookup').mockResolvedValue({
			cloudId: BOB,
			fingerprint: 'AB:CD',
			spki: new Uint8Array(
				createPublicKey(RSA4096_SECONDARY_PUBLIC_KEY_SPKI_PEM).export({
					type: 'spki',
					format: 'der',
				}),
			),
			valueFields: ['totp', 'passkeys'],
		})

		await saveShared({
			id: 'src',
			plainDiff: { key: 'new-pw' },
			patch: { key: 'OWN' },
			baseUpdatedAt: BASE,
			loadFull: async () => ({ key: 'old', totp: SEED, updatedAt: BASE }),
		})

		const [entry] = log.saves.at(-1).federated
		const bob = await importPrivateKey(RSA4096_SECONDARY_PRIVATE_KEY_PKCS8_PEM)
		expect(await rsaDecrypt(entry.totp, bob)).toBe(SEED)
		expect(await rsaDecrypt(entry.key, bob)).toBe('new-pw')
		expect(entry.passkeys).toBeNull()
		expect(JSON.stringify(log.saves)).not.toContain('JBSWY3DPEHPK3PXP')
	})

	it('sends a partner that stores them null for a seed the change removes', async () => {
		const { log } = await save(
			UPGRADED,
			{ totp: SEED },
			{ plainDiff: { totp: '' }, patch: { totp: '' } },
		)

		const final = log.saves.at(-1)
		expect(final.patch).toEqual({ totp: '' })
		expect(final.federated[0].totp).toBeNull()
	})

	it('stops the save, before any partner entry is sent, when it gives the login a seed an older partner cannot store', async () => {
		const { log, result } = await save(
			OLDER,
			{},
			{ plainDiff: { totp: SEED }, patch: { totp: 'OWN' } },
		)

		expect(result).toBeInstanceOf(ShareSaveError)
		expect(result.code).toBe('partner_upgrade_required')
		expect(result.partners).toEqual([PARTNERS[0]])
		expect(log.saves.every((body) => (body.federated ?? []).length === 0)).toBe(
			true,
		)
		const text = shareSaveErrorText(result)
		expect(text).toContain(BOB)
		expect(text).toContain(SINCE)
	})

	it('stops the save for passkeys the login gains too', async () => {
		const { result } = await save(
			OLDER,
			{},
			{ plainDiff: { passkeys: PASSKEYS }, patch: { passkeys: 'OWN' } },
		)

		expect(result.code).toBe('partner_upgrade_required')
	})

	it('saves a password change of a login with a seed and passkeys, sending an older partner null for both', async () => {
		const { log, result } = await save(OLDER, {
			totp: SEED,
			passkeys: PASSKEYS,
		})

		expect(result).not.toBeInstanceOf(Error)
		const [entry] = log.saves.at(-1).federated
		expect(entry.key).toBeTruthy()
		expect(entry.totp).toBeNull()
		expect(entry.passkeys).toBeNull()
	})

	it('saves a passkey delete with an older partner, which gets null', async () => {
		const two = '[{"credentialId":"c1"},{"credentialId":"c2"}]'
		const one = '[{"credentialId":"c2"}]'
		const { log, result } = await save(
			OLDER,
			{ passkeys: two },
			{ plainDiff: { passkeys: one }, patch: { passkeys: 'OWN' } },
		)

		expect(result).not.toBeInstanceOf(Error)
		expect(log.saves.at(-1).patch).toEqual({ passkeys: 'OWN' })
		expect(log.saves.at(-1).federated[0].passkeys).toBeNull()
	})

	it('saves a replaced seed with an older partner, which never held one, as null', async () => {
		const { log, result } = await save(
			OLDER,
			{ totp: SEED },
			{
				plainDiff: {
					totp: 'otpauth://totp/Bank:alice?secret=NEWSEEDNEWSEED22',
				},
				patch: { totp: 'OWN' },
			},
		)

		expect(result).not.toBeInstanceOf(Error)
		expect(log.saves.at(-1).federated[0].totp).toBeNull()
	})

	it('looks a refused partner up again on the next save, so one that upgraded is not refused from a kept answer', async () => {
		const change = { plainDiff: { totp: SEED }, patch: { totp: 'OWN' } }
		const first = await save(OLDER, {}, change)
		expect(first.result.code).toBe('partner_upgrade_required')

		vi.restoreAllMocks()
		const second = await save(UPGRADED, {}, change)

		expect(second.log.partnerLookups).toEqual([BOB])
		expect(second.result).not.toBeInstanceOf(Error)
		expect(second.log.saves.at(-1).federated[0].totp).toBeTruthy()
	})

	it('saves to an older partner a login that holds neither, with both fields null', async () => {
		const { log, result } = await save(OLDER, {})

		expect(result).not.toBeInstanceOf(Error)
		const [entry] = log.saves.at(-1).federated
		expect(entry.totp).toBeNull()
		expect(entry.passkeys).toBeNull()
	})

	it('saves to an older partner once the change removes the seed', async () => {
		const { log, result } = await save(
			OLDER,
			{ totp: SEED },
			{ plainDiff: { totp: '' }, patch: { totp: '' } },
		)

		expect(result).not.toBeInstanceOf(Error)
		expect(log.saves.at(-1).federated[0].totp).toBeNull()
	})

	it('treats a lookup answer without valueFields as a partner that stores neither', async () => {
		const { result } = await save(
			VALID,
			{},
			{ plainDiff: { totp: SEED }, patch: { totp: 'OWN' } },
		)

		expect(result.code).toBe('partner_upgrade_required')
	})

	it("explains the server's own refusal with the version it names", () => {
		const refusal = Object.assign(
			httpError(409, {
				error: 'partner_upgrade_required',
				message: 'The receiving organisation must upgrade Keepiq',
				shareId: 'fs-1',
				requiredVersion: '0.4.0',
			}),
			{ fromShareSave: true },
		)

		const text = shareSaveErrorText(refusal)
		expect(text).toContain('0.4.0')
		expect(text).toContain('Nothing was saved')
	})
})

describe('a new federated share', () => {
	/**
	 * Look Bob up and share `secret` with him against a fake server.
	 *
	 * @param {object} lookup Bob's lookup answer.
	 * @param {object} secret The decrypted secret.
	 * @param {Error|null} [refusal] What the share request answers instead.
	 * @return {Promise<{shares: Array<object>, result: object|Error}>}
	 */
	async function share(lookup, secret, refusal = null) {
		const shares = []
		vi.spyOn(axios, 'post').mockImplementation(async (url, body) => {
			if (url.endsWith('/federation/recipient-certificate')) {
				return { data: { ...lookup, cloudId: echoedCloudId(body.cloudId) } }
			}
			shares.push(body)
			if (refusal) {
				throw refusal
			}
			return { data: { id: 'fs-1' } }
		})
		const store = useFederatedShareStore()
		const recipient = await store.lookup(BOB)
		const result = await store
			.share('src', { key: 'pw', ...secret }, recipient)
			.catch((e) => e)
		return { shares, result }
	}

	it('reads the value fields the partner advertised, and only known ones', async () => {
		vi.spyOn(axios, 'post').mockResolvedValue({
			data: { ...VALID, valueFields: ['totp', 'notes', 'passkeys'] },
		})

		const recipient = await useFederatedShareStore().lookup(BOB)

		expect(recipient.valueFields).toEqual(['totp', 'passkeys'])
	})

	it('encrypts the seed for a partner that stores it', async () => {
		const { shares, result } = await share(UPGRADED, { totp: SEED })

		expect(result).toEqual({ id: 'fs-1' })
		expect(typeof shares[0].totp).toBe('string')
		expect(shares[0].totp.length).toBeGreaterThan(0)
		expect(shares[0].passkeys).toBeNull()
		expect(JSON.stringify(shares)).not.toContain('JBSWY3DPEHPK3PXP')
	})

	it('refuses a login with a seed for an older partner, before anything is sent', async () => {
		const { shares, result } = await share(OLDER, { totp: SEED })

		expect(result).toBeInstanceOf(PartnerUpgradeRequiredError)
		expect(result.requiredVersion).toBe(SINCE)
		expect(shares).toEqual([])
		expect(partnerUpgradeText(result)).toContain('Nothing was shared')
	})

	it('shares a login without either with an older partner, both null', async () => {
		const { shares, result } = await share(OLDER, { totp: '', passkeys: '' })

		expect(result).toEqual({ id: 'fs-1' })
		expect(shares[0].totp).toBeNull()
		expect(shares[0].passkeys).toBeNull()
	})

	it("turns the server's 409 into a readable refusal naming the version", async () => {
		const { result } = await share(
			UPGRADED,
			{ totp: SEED },
			httpError(409, {
				error: 'partner_upgrade_required',
				message: 'The receiving organisation must upgrade Keepiq',
				requiredVersion: '0.4.0',
			}),
		)

		expect(result).toBeInstanceOf(PartnerUpgradeRequiredError)
		expect(result.requiredVersion).toBe('0.4.0')
		expect(partnerUpgradeText(result)).toContain('0.4.0')
	})
})
