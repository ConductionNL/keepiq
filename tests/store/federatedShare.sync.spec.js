/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A value change reaches federated recipients through the share save
 * (keepiq#789, keepiq#1254): the browser fetches each live federated
 * recipient's certificate again, verifies it (real verifier, real openssl
 * fixtures), encrypts the whole new value and sends only ciphertext, in the
 * same request as the row. A certificate that no longer verifies, or a
 * recipient the partner no longer knows, is suspended in that request; a
 * partner that cannot be reached stops the save unless the user chose to
 * save without it.
 *
 * @spec openspec/specs/federated-sharing/spec.md#scenario-a-password-change-reaches-bob
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import {
	forgetVerifiedPartners,
	saveShared,
	ShareSaveError,
	VERIFIED_PARTNER_TTL_MS,
} from '../../src/services/shareSave.js'
import { useSessionStore } from '../../src/store/modules/session.js'
import {
	httpError,
	settleWithTimers,
	shareSaveServer,
} from '../fixtures/shareSaveServer.js'

const F = JSON.parse(
	readFileSync(resolve(__dirname, '../fixtures/federation-chain.json'), 'utf8'),
)
const BOB = 'bob@cloud.partner.example'
const NEW_PASSWORD = 'a brand new password'
const BASE = '2026-10-01T10:00:00+00:00'

const VALID = {
	cloudId: BOB,
	certificate: F.bob,
	chain: [F.intermediateA, F.rootA],
	partnerRootFingerprint: F.rootAFingerprint,
}
const OTHER_ROOT = {
	...VALID,
	certificate: F.bobOtherRoot,
	chain: [F.intermediateB, F.rootB],
}
const PARTNERS = [{ shareId: 'fs-1', recipientCloudId: BOB }]

/** A password change of `src`, whose stored value has a login. */
const SAVE = {
	id: 'src',
	plainDiff: { key: NEW_PASSWORD },
	patch: { key: 'OWN' },
	baseUpdatedAt: BASE,
	loadFull: async () => ({
		key: 'old',
		login: 'alice',
		additionalFields: null,
		updatedAt: BASE,
	}),
}

/**
 * Save a password change of `src`, whose stored value has a login, against
 * the fake server.
 *
 * @param {object} lookup The partner lookup answer for Bob.
 * @param {object} [options] Extra saveShared options.
 * @return {Promise<{log: object, result: object|Error}>}
 */
async function save(lookup, options = {}) {
	const log = shareSaveServer({
		id: 'src',
		partners: PARTNERS,
		lookup: { [BOB]: lookup },
	})
	const result = await saveShared({ ...SAVE, ...options }).catch((e) => e)
	return { log, result }
}

describe('a value change reaches federated recipients', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
		forgetVerifiedPartners()
	})

	it('encrypts the whole new value for a verified recipient and sends only ciphertext', async () => {
		const { log, result } = await save(VALID)

		expect(result.federated.written).toBe(1)
		const [entry] = log.saves.at(-1).federated
		expect(entry.shareId).toBe('fs-1')
		expect(entry.certFingerprint).toMatch(/^[0-9a-f]{64}$/)
		expect(entry.key).toBeTruthy()
		// The whole value: the unchanged login travels too.
		expect(entry.login).toBeTruthy()
		expect(entry.additionalFields).toBeNull()
		expect(JSON.stringify(log.saves)).not.toContain(NEW_PASSWORD)
		expect(JSON.stringify(log.saves)).not.toContain('alice')
		expect(log.saves.at(-1).suspend).toEqual([])
	})

	it('suspends a share whose certificate no longer chains to the pinned root', async () => {
		const { log } = await save(OTHER_ROOT)

		expect(log.saves.at(-1).federated).toEqual([])
		expect(log.saves.at(-1).suspend).toEqual([
			{ shareId: 'fs-1', reason: 'untrusted_root' },
		])
	})

	it('suspends a share to a recipient the partner no longer knows', async () => {
		const { log } = await save({
			reject: httpError(404, { message: 'unknown_recipient' }),
		})

		expect(log.saves.at(-1).suspend).toEqual([
			{ shareId: 'fs-1', reason: 'unknown_recipient' },
		])
	})

	it('saves nothing while the partner cannot be reached', async () => {
		const { log, result } = await save({
			reject: httpError(502, { message: 'partner_unreachable' }),
		})

		expect(result).toBeInstanceOf(ShareSaveError)
		expect(result.code).toBe('partner_unreachable')
		expect(result.partners).toEqual([
			{ ...PARTNERS[0], reason: 'partner_unreachable' },
		])
		// Only the optimistic request, which the server answered incomplete.
		expect(log.saves).toHaveLength(1)
	})

	it('suspends an unreachable partner share when the user saves without it', async () => {
		const { log, result } = await save(
			{ reject: httpError(502, { message: 'partner_unreachable' }) },
			{ withoutUnreachable: ['fs-1'] },
		)

		expect(result.federated.suspended).toBe(1)
		expect(log.saves.at(-1).suspend).toEqual([
			{ shareId: 'fs-1', reason: 'partner_unreachable' },
		])
	})

	it('refuses to build a partner value from a row that moved on', async () => {
		const { log, result } = await save(VALID, {
			loadFull: async () => ({
				key: 'x',
				updatedAt: '2026-10-01T11:00:00+00:00',
			}),
		})

		expect(result).toBeInstanceOf(ShareSaveError)
		expect(result.code).toBe('stale')
		expect(log.saves).toHaveLength(1)
	})

	it('waits out the lookup limit and saves 70 partner shares in one call', async () => {
		vi.useFakeTimers({ toFake: ['setTimeout', 'Date'] })
		try {
			const partners = Array.from({ length: 70 }, (_, i) => ({
				shareId: `fs-${i}`,
				recipientCloudId: BOB,
			}))
			const log = shareSaveServer({
				id: 'src',
				partners,
				lookup: { [BOB]: VALID },
				lookupLimit: { perMinute: 60, retryAfter: '60' },
			})
			const waits = []

			const result = await settleWithTimers(
				saveShared({
					...SAVE,
					onWait: (ms) => waits.push(ms),
				}),
			)

			expect(waits).toEqual([60000])
			expect(result.federated.written).toBe(70)
			expect(log.partnerLookups).toHaveLength(71)
			expect(log.saves).toHaveLength(2)
			expect(log.saves.at(-1).federated).toHaveLength(70)
			expect(log.saves.at(-1).suspend).toEqual([])
		} finally {
			vi.useRealTimers()
		}
	}, 60000)

	it('stops after the lookup-limit wait when the vault locked during it', async () => {
		vi.useFakeTimers({ toFake: ['setTimeout', 'Date'] })
		try {
			const log = shareSaveServer({
				id: 'src',
				partners: [PARTNERS[0], { shareId: 'fs-2', recipientCloudId: BOB }],
				lookup: { [BOB]: VALID },
				lookupLimit: { perMinute: 1, retryAfter: '60' },
			})

			const result = await settleWithTimers(
				saveShared({ ...SAVE, onWait: () => useSessionStore().lock() }),
			)

			expect(result).toBeInstanceOf(Error)
			expect(result.message).toBe('Vault is locked')
			// The limited lookup is not retried, and nothing beyond the optimistic save is sent.
			expect(log.partnerLookups).toEqual([BOB, BOB])
			expect(log.saves).toHaveLength(1)
		} finally {
			vi.useRealTimers()
		}
	})
})

describe('a follow-up save after a failed one', () => {
	const CAROL = 'carol@cloud.partner.example'
	const TWO = [
		{ shareId: 'fs-1', recipientCloudId: BOB },
		{ shareId: 'fs-2', recipientCloudId: CAROL },
	]

	/**
	 * The partner shares a failed save named, which "Save without them"
	 * agrees to suspend.
	 *
	 * @param {ShareSaveError} failed The partner_unreachable error.
	 * @return {Array<string>}
	 */
	const shown = (failed) => failed.partners.map((share) => share.shareId)

	/**
	 * Fail a save on an unreachable Carol, run `between`, then save without
	 * her.
	 *
	 * @param {object} bob Bob's lookup answer.
	 * @param {() => void} [between] What happens between the two saves.
	 * @return {Promise<{first: Array<string>, second: Array<string>, final: object}>} The partner lookups of each save.
	 */
	async function saveTwice(bob, between = () => {}) {
		const log = shareSaveServer({
			id: 'src',
			partners: TWO,
			lookup: { [BOB]: bob },
		})
		const failed = await saveShared(SAVE).catch((e) => e)
		expect(failed.code).toBe('partner_unreachable')
		const first = [...log.partnerLookups]
		between()
		await saveShared({ ...SAVE, withoutUnreachable: shown(failed) })
		return {
			first,
			second: log.partnerLookups.slice(first.length),
			final: log.saves.at(-1),
		}
	}

	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
		forgetVerifiedPartners()
	})

	it('"Save without them" reuses the partner certificates the failed save verified', async () => {
		const { first, second, final } = await saveTwice(VALID)

		expect(first).toEqual([BOB, CAROL])
		expect(second).toEqual([CAROL])
		expect(final.federated.map((f) => f.shareId)).toEqual(['fs-1'])
		expect(final.federated[0].certFingerprint).toMatch(/^[0-9a-f]{64}$/)
		expect(final.suspend).toEqual([
			{ shareId: 'fs-2', reason: 'partner_unreachable' },
		])
	})

	it('looks every partner up again after the vault locks', async () => {
		const { second } = await saveTwice(VALID, () => useSessionStore().lock())

		expect(second).toEqual([BOB, CAROL])
	})

	it('never keeps a certificate that failed verification', async () => {
		const { second, final } = await saveTwice(OTHER_ROOT)

		expect(second).toEqual([BOB, CAROL])
		expect(final.suspend).toContainEqual({
			shareId: 'fs-1',
			reason: 'untrusted_root',
		})
	})

	it('keeps nothing from a successful save, so the next save looks every partner up again', async () => {
		await saveTwice(VALID)
		const log = shareSaveServer({
			id: 'src',
			partners: TWO,
			lookup: { [BOB]: VALID },
		})

		await saveShared({ ...SAVE, withoutUnreachable: ['fs-2'] })

		expect(log.partnerLookups).toEqual([BOB, CAROL])
	})

	it('keeps nothing from a failed save the vault locked during', async () => {
		const log = shareSaveServer({
			id: 'src',
			partners: TWO,
			lookup: { [BOB]: VALID },
			onSave: (body, n) => n === 1 && useSessionStore().lock(),
		})
		const failed = await saveShared(SAVE).catch((e) => e)
		expect(failed.code).toBe('partner_unreachable')
		const first = log.partnerLookups.length

		await saveShared({ ...SAVE, withoutUnreachable: shown(failed) })

		expect(log.partnerLookups.slice(first)).toEqual([BOB, CAROL])
	})

	it('looks a share up again when its recipient changed since the failed save', async () => {
		const DAVE = 'dave@cloud.partner.example'
		shareSaveServer({ id: 'src', partners: TWO, lookup: { [BOB]: VALID } })
		const failed = await saveShared(SAVE).catch((e) => e)
		expect(failed.code).toBe('partner_unreachable')
		const log = shareSaveServer({
			id: 'src',
			partners: [{ shareId: 'fs-1', recipientCloudId: DAVE }, TWO[1]],
			lookup: {
				[BOB]: VALID,
				[DAVE]: { reject: httpError(404, { message: 'unknown_recipient' }) },
			},
		})

		await saveShared({ ...SAVE, withoutUnreachable: shown(failed) })

		expect(log.partnerLookups).toEqual([DAVE, CAROL])
		expect(log.saves.at(-1).federated).toEqual([])
		expect(log.saves.at(-1).suspend).toContainEqual({
			shareId: 'fs-1',
			reason: 'unknown_recipient',
		})
	})

	it(`keeps a certificate for ${VERIFIED_PARTNER_TTL_MS / 60000} minutes only`, async () => {
		vi.useFakeTimers({ toFake: ['Date'] })
		try {
			const { second } = await saveTwice(VALID, () =>
				vi.advanceTimersByTime(VERIFIED_PARTNER_TTL_MS),
			)

			expect(second).toEqual([BOB, CAROL])
		} finally {
			vi.useRealTimers()
		}
	})

	it('suspends only the partners the user was shown, and asks again for one that fails only now', async () => {
		vi.useFakeTimers({ toFake: ['Date'] })
		try {
			const lookup = { [BOB]: VALID }
			const log = shareSaveServer({ id: 'src', partners: TWO, lookup })
			const failed = await saveShared(SAVE).catch((e) => e)
			expect(shown(failed)).toEqual(['fs-2'])
			// Bob's kept certificate expires, and Bob is down by now.
			vi.advanceTimersByTime(VERIFIED_PARTNER_TTL_MS)
			delete lookup[BOB]

			const again = await saveShared({
				...SAVE,
				withoutUnreachable: shown(failed),
			}).catch((e) => e)

			expect(again).toBeInstanceOf(ShareSaveError)
			expect(again.code).toBe('partner_unreachable')
			expect(again.partners.map((share) => share.recipientCloudId)).toEqual([
				BOB,
				CAROL,
			])
			expect(log.saves.flatMap((s) => s.suspend)).toEqual([])

			// Agreeing to what the second error named suspends both.
			await saveShared({ ...SAVE, withoutUnreachable: shown(again) })
			expect(log.saves.at(-1).suspend).toEqual([
				{ shareId: 'fs-1', reason: 'partner_unreachable' },
				{ shareId: 'fs-2', reason: 'partner_unreachable' },
			])
		} finally {
			vi.useRealTimers()
		}
	})
})
