// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Saving a value change of a possibly shared secret through
 * `PUT /api/v1/secrets/{id}/share-save`, which writes the row, every local copy
 * and every partner share in one transaction (keepiq#1254).
 *
 * The first request carries empty lists, so an unshared secret saves at once.
 * The server answers 409 `incomplete` with the copies and partner shares it
 * requires; only then are certificates fetched and the change encrypted for
 * each holder. Partner certificates are verified in this browser
 * (`federatedShare.lookup()`); the server only ever receives ciphertext.
 *
 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-shared-secret-and-every-copy-save-in-one-transaction
 */

import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { FederatedCertificateError } from '../crypto/federatedCertificate.js'
import { importPublicKey, rsaDecrypt, rsaEncrypt } from '../crypto/index.js'
import { importSpkiPublicKey } from '../crypto/rsa.js'
import {
	CloudIdMismatchError,
	fingerprintHex,
	partnerStores,
	partnerUpgradeText,
	useFederatedShareStore,
} from '../store/modules/federatedShare.js'
import { onVaultLock, useSessionStore } from '../store/modules/session.js'
import { useShareStore } from '../store/modules/share.js'
import { isViewOnlyCopy } from '../utils/copyGrade.js'
import { isRefusal } from '../utils/refusal.js'
import { BLOB_FIELDS } from '../utils/valueFields.js'

/** The encrypted value fields of a secret. */
export const VALUE_FIELDS = ['key', 'login', 'additionalFields', ...BLOB_FIELDS]

/**
 * The server's bound per call, for copies+skipped and for federated+suspend
 * alike, and the recipient-certificates lookup's bound.
 */
export const SHARE_SAVE_BATCH = 100

/** How many `incomplete` answers end a save, so a churning share list cannot loop. */
export const MAX_INCOMPLETE_ROUNDS = 3

/**
 * Partner lookup refusals that mean the share is gone for good, so it is
 * suspended, each with the status this server answers it with; on another
 * status the reason is not this server's refusal. `bad_chain` from the server
 * is the refusal the browser makes.
 */
const PARTNER_GONE = new Map([
	['unknown_recipient', 404],
	['not_a_partner', 403],
	['bad_chain', 502],
])

/**
 * How long one save waits in total on the partner lookup limit (60 per
 * minute per user) before it gives up.
 */
export const PARTNER_LIMIT_WAIT_CAP_MS = 5 * 60 * 1000

/** The wait when a 429 names no `Retry-After`. */
const PARTNER_LIMIT_FALLBACK_MS = 60 * 1000

/** How long a failed save's verified partner certificates serve a follow-up save. */
export const VERIFIED_PARTNER_TTL_MS = 5 * 60 * 1000

/**
 * Partner certificates a failed save verified, by share id, so "Save without
 * them" or "Try again" does not look every partner up again. Memory only.
 *
 * @type {Map<string, {recipientCloudId: string, recipient: object, at: number}>}
 */
const verifiedPartners = new Map()

/** Bumped on every vault lock, so a save running across one caches nothing. */
let lockEpoch = 0

/**
 * Forget every kept partner certificate; runs on every vault lock.
 *
 * @return {void}
 * @spec openspec/changes/sharing-atomic-share-save/specs/federated-sharing/spec.md#requirement-an-unreachable-partner-stops-the-save-unless-the-user-saves-without-them
 */
export function forgetVerifiedPartners() {
	verifiedPartners.clear()
	lockEpoch++
}

/**
 * How long a 429 asks to wait, at least a second so the cap is always reached.
 *
 * @param {object} response The axios response.
 * @return {number} Milliseconds.
 */
function retryAfterMs(response) {
	const raw = String(response?.headers?.['retry-after'] ?? '').trim()
	let ms = PARTNER_LIMIT_FALLBACK_MS
	if (/^\d+$/.test(raw)) {
		ms = Number(raw) * 1000
	} else if (!Number.isNaN(Date.parse(raw))) {
		ms = Date.parse(raw) - Date.now()
	}
	return Math.max(1000, ms)
}

/**
 * Whether a lookup failed at the partner rather than on this server: only
 * then may the user choose to suspend the share and save without it.
 *
 * @param {object} error The lookup's axios error.
 * @return {boolean}
 */
function partnerUnreachable(error) {
	return (
		error?.response?.status === 502
		&& error.response.data?.message === 'partner_unreachable'
	)
}

/**
 * Whether a lookup refusal says the partner share is gone for good.
 *
 * @param {object} error The lookup's axios error.
 * @return {boolean}
 */
function partnerGone(error) {
	const reason = error?.response?.data?.message
	return (
		PARTNER_GONE.has(reason)
		&& PARTNER_GONE.get(reason) === error.response.status
	)
}

/**
 * A save that stopped in this browser before anything was written.
 *
 * `code` is `incomplete` (the share list kept changing), `stale` (the row moved
 * on before the partner value was built), `partner_lookup_limit` (the partner
 * lookup limit held for longer than the save waits), `view_only` (the caller's
 * copy has no write grade), `partner_unreachable` (`partners` names them,
 * each with its `reason`) or `partner_upgrade_required` (`partners` names the
 * ones whose Keepiq cannot store a one-time code or passkeys the change gives
 * the login).
 */
export class ShareSaveError extends Error {
	/**
	 * @param {string} code The reason.
	 * @param {Array<{shareId: string, recipientCloudId: string, reason: string}>} [partners] Unreachable partner shares.
	 * @param {{cause?: Error}} [options] The error this one stands for.
	 * @spec openspec/changes/sharing-atomic-share-save/specs/secrets-write-ui/spec.md#requirement-a-failed-share-save-is-shown-and-nothing-is-saved
	 */
	constructor(code, partners = [], options = undefined) {
		super(code, options)
		this.name = 'ShareSaveError'
		this.code = code
		this.partners = partners
	}
}

/**
 * Encrypt the changed value fields for one holder: a cleared field stays
 * `null` and an absent one stays absent, so a sparse edit never clears what it
 * did not touch (keepiq#1253). A cleared `totp` or `passkeys` is sent as `''`.
 *
 * @param {object} plainDiff Changed fields as strings, `null` for cleared (`''` too for `totp` and `passkeys`).
 * @param {string} certificate The holder's PEM certificate.
 * @return {Promise<object>}
 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-shared-secret-and-every-copy-save-in-one-transaction
 */
export async function encryptSparse(plainDiff, certificate) {
	const publicKey = await importPublicKey(certificate)
	const out = {}
	for (const field of VALUE_FIELDS) {
		const value = plainDiff[field]
		if (value === undefined) {
			continue
		}
		if (BLOB_FIELDS.includes(field)) {
			out[field] = value ? await rsaEncrypt(String(value), publicKey) : ''
			continue
		}
		out[field] =
			value === null ? null : await rsaEncrypt(String(value), publicKey)
	}
	return out
}

/**
 * The value fields of a stored row in plain, without merging pending
 * request-filled fields, so they match the row's own ciphertext.
 *
 * @param {object} row A secret row with ciphertext.
 * @return {Promise<object>} `{ key, login, additionalFields, totp, passkeys }`, `null` where empty.
 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-shared-secret-and-every-copy-save-in-one-transaction
 */
export async function plainOf(row) {
	const session = useSessionStore()
	if (!session.cryptoKey) {
		throw new Error('Vault is locked')
	}
	const out = {}
	for (const field of VALUE_FIELDS) {
		out[field] = row?.[field]
			? await rsaDecrypt(row[field], session.cryptoKey)
			: null
	}
	return out
}

/**
 * The full current value of a row, for partner shares that take the whole
 * value.
 *
 * @param {string} id The secret.
 * @return {Promise<object>} The plain value fields plus `updatedAt`.
 * @spec openspec/changes/sharing-atomic-share-save/specs/federated-sharing/spec.md#requirement-partner-copies-change-only-with-the-owners-share-save
 */
async function loadRow(id) {
	const row = (await axios.get(generateUrl(`/apps/keepiq/api/v1/secrets/${id}`)))
		.data
	return { ...(await plainOf(row)), updatedAt: row.updatedAt }
}

/**
 * Certificates of local recipients, looked up 100 users per call.
 *
 * @param {Array<string>} userIds The holders.
 * @return {Promise<Map<string, string|null>>} userId to certificate, `null` when not shareable.
 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-share-save-names-every-copy-or-is-refused
 */
async function fetchCertificates(userIds) {
	const found = new Map()
	const distinct = [...new Set(userIds)]
	for (let i = 0; i < distinct.length; i += SHARE_SAVE_BATCH) {
		const response = await axios.post(
			generateUrl('/apps/keepiq/api/v1/shares/recipient-certificates'),
			{ userIds: distinct.slice(i, i + SHARE_SAVE_BATCH) },
		)
		for (const row of response.data?.recipients ?? []) {
			found.set(
				String(row.userId),
				row.shareable === true && row.certificate ? row.certificate : null,
			)
		}
	}
	return found
}

/**
 * The ids an `incomplete` list names, whether as ids or as entries.
 *
 * @param {Array<string|object>|undefined} list The list.
 * @param {string} key `secretId` or `shareId`.
 * @return {Set<string>}
 */
function idsOf(list, key) {
	return new Set(
		(list ?? []).map((item) => (typeof item === 'string' ? item : item?.[key])),
	)
}

/**
 * Save a value change of `{id}` for every holder in one transaction.
 *
 * On the copy-editor path partner shares are never written; the answer's
 * `federated.ownerOnly` counts them. A copy holder without a write grade is
 * refused before anything is sent, for an edit and a restore alike.
 *
 * @param {object} options The save.
 * @param {string} options.id The caller's own row.
 * @param {object} options.plainDiff Changed value fields in plain, `null` for cleared.
 * @param {object} options.patch The own-row patch, value fields encrypted for the caller.
 * @param {string} options.baseUpdatedAt The `{id}` version the edit was made from.
 * @param {string|null} [options.sourceBaseUpdatedAt] The source version the edit was made from, on the copy-editor path; without one, the source version when this save starts.
 * @param {() => Promise<object>} [options.loadFull] Resolves `{key, login, additionalFields, totp, passkeys, updatedAt}` of `{id}` in plain.
 * @param {Iterable<string>} [options.withoutUnreachable] The partner shares (ids) the user agreed to suspend when they cannot be reached; any other unreachable one stops the save.
 * @param {string} [options.restoredFrom] The version (uuid) this save restores, sent with the patch; the patch and `plainDiff` must then carry `key`, `login` and `additionalFields`, and `totp` and `passkeys` when the version holds them.
 * @param {(ms: number) => void} [options.onWait] Called when the save starts waiting on the partner lookup limit.
 * @return {Promise<object>} The 200 body; `secret` is the saved row.
 * @throws {ShareSaveError|Error} Nothing was written. An error the share-save
 *   request itself answered carries `fromShareSave`.
 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-shared-secret-and-every-copy-save-in-one-transaction
 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-share-save-names-every-copy-or-is-refused
 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-large-share-save-is-staged-in-chunks
 * @spec openspec/changes/sharing-atomic-share-save/specs/federated-sharing/spec.md#requirement-partner-copies-change-only-with-the-owners-share-save
 * @spec openspec/changes/sharing-atomic-share-save/specs/federated-sharing/spec.md#requirement-an-unreachable-partner-stops-the-save-unless-the-user-saves-without-them
 * @spec openspec/changes/sharing-atomic-share-save/specs/secret-version-history/spec.md#requirement-a-restore-is-one-share-save-of-the-version
 */
export async function saveShared({
	id,
	plainDiff,
	patch,
	baseUpdatedAt,
	sourceBaseUpdatedAt = null,
	loadFull = null,
	withoutUnreachable = [],
	restoredFrom = null,
	onWait = null,
}) {
	// Registered here, not at import: session.js may still be loading then.
	onVaultLock(forgetVerifiedPartners)
	let context = await useShareStore().fetchWriteContext(id)
	const copyPath = context.sourceSecretId !== id
	// The source version the edit was made from; only an edit that recorded
	// none (a queue entry from before it was recorded) takes this moment's.
	const sourceBase = sourceBaseUpdatedAt ?? context.sourceUpdatedAt ?? ''
	if (isViewOnlyCopy(context, id)) {
		throw new ShareSaveError('view_only')
	}

	const epoch = lockEpoch
	const verifiedNow = new Map()
	let waited = 0

	/**
	 * A partner's verified certificate: one a failed save kept, or a fresh
	 * lookup that waits out the lookup limit up to the cap.
	 *
	 * @param {{shareId: string, recipientCloudId: string}} share The partner share.
	 * @return {Promise<{cloudId: string, fingerprint: string, spki: Uint8Array}>}
	 */
	const partnerCertificate = async (share) => {
		const kept = verifiedPartners.get(share.shareId)
		if (
			kept?.recipientCloudId === share.recipientCloudId
			&& Date.now() - kept.at < VERIFIED_PARTNER_TTL_MS
		) {
			verifiedNow.set(share.shareId, kept)
			return kept.recipient
		}
		for (;;) {
			let limited
			try {
				const recipient = await useFederatedShareStore().lookup(
					share.recipientCloudId,
				)
				verifiedNow.set(share.shareId, {
					recipientCloudId: share.recipientCloudId,
					recipient,
					at: Date.now(),
				})
				return recipient
			} catch (e) {
				if (e?.response?.status !== 429) {
					throw e
				}
				limited = e
			}
			const wait = retryAfterMs(limited.response)
			if (waited + wait > PARTNER_LIMIT_WAIT_CAP_MS) {
				throw new ShareSaveError('partner_lookup_limit', [], {
					cause: limited,
				})
			}
			onWait?.(wait)
			await new Promise((resolve) => setTimeout(resolve, wait))
			waited += wait
			if (epoch !== lockEpoch) {
				throw new Error('Vault is locked')
			}
		}
	}

	const url = generateUrl(`/apps/keepiq/api/v1/secrets/${id}/share-save`)
	const copies = new Map()
	const skipped = new Map()
	const federated = new Map()
	const suspend = new Map()
	const certificates = new Map()
	let partnerValue = null
	/** The row's value before the change, read with the partner value. */
	let storedValue = null
	let stage = { id: null, sent: new Set() }

	const envelope = () => ({
		baseUpdatedAt,
		...(copyPath ? { sourceBaseUpdatedAt: sourceBase } : {}),
	})

	const send = async () => {
		const unsent = (map, prefix) =>
			[...map.entries()]
				.filter(([key]) => !stage.sent.has(prefix + key))
				.map(([key, entry]) => ({ key: prefix + key, entry }))
		let local = [...unsent(copies, 'c:'), ...unsent(skipped, 's:')]
		let remote = [...unsent(federated, 'f:'), ...unsent(suspend, 'p:')]
		const listsOf = (localPart, remotePart) => {
			const pick = (part, prefix) =>
				part.filter((x) => x.key.startsWith(prefix)).map((x) => x.entry)
			return {
				copies: pick(localPart, 'c:'),
				skipped: pick(localPart, 's:'),
				...(copyPath
					? {}
					: {
							federated: pick(remotePart, 'f:'),
							suspend: pick(remotePart, 'p:'),
						}),
			}
		}

		// Above the per-call bound the lists go ahead in staged chunks; the
		// final call carries the patch and commits everything at once. A chunk
		// is only sent while a list holds more than the bound, so each one is
		// full on that list: never empty, and never more than ceil(rows/100).
		while (local.length > SHARE_SAVE_BATCH || remote.length > SHARE_SAVE_BATCH) {
			const localPart = local.slice(0, SHARE_SAVE_BATCH)
			const remotePart = remote.slice(0, SHARE_SAVE_BATCH)
			const response = await axios.put(url, {
				...envelope(),
				...listsOf(localPart, remotePart),
				stage: { ...(stage.id ? { id: stage.id } : {}), final: false },
			})
			stage.id = response.data?.stageId ?? stage.id
			for (const part of [...localPart, ...remotePart]) {
				stage.sent.add(part.key)
			}
			local = local.slice(SHARE_SAVE_BATCH)
			remote = remote.slice(SHARE_SAVE_BATCH)
		}
		const response = await axios.put(url, {
			...envelope(),
			patch,
			...(restoredFrom === null ? {} : { restoredFrom }),
			...listsOf(local, remote),
			...(stage.id ? { stage: { id: stage.id, final: true } } : {}),
		})
		return response.data
	}

	const wholeValue = async () => {
		const full = await (loadFull ? loadFull() : loadRow(id))
		if (Date.parse(full.updatedAt) !== Date.parse(baseUpdatedAt)) {
			throw new ShareSaveError('stale')
		}
		const value = {}
		for (const field of VALUE_FIELDS) {
			value[field] = full[field] ?? null
		}
		storedValue = value
		return { ...value, ...plainDiff }
	}

	const fillPartners = async (required, drop) => {
		const requiredIds = new Set(required.map((r) => r.shareId))
		drop(federated, 'f:', (key) => requiredIds.has(key))
		drop(suspend, 'p:', (key) => requiredIds.has(key))
		const todo = required.filter(
			(r) => !federated.has(r.shareId) && !suspend.has(r.shareId),
		)
		const unreachable = []
		const tooOld = []
		for (const share of todo) {
			let recipient
			try {
				recipient = await partnerCertificate(share)
			} catch (e) {
				// An answer for another cloud id is not a certificate for this
				// recipient, so like one that does not verify it suspends the share.
				const unverified =
					e instanceof FederatedCertificateError
					|| e instanceof CloudIdMismatchError
				const reason = unverified ? e.reason : e?.response?.data?.message
				if (unverified || partnerGone(e)) {
					suspend.set(share.shareId, { shareId: share.shareId, reason })
				} else if (partnerUnreachable(e)) {
					unreachable.push({ ...share, reason })
				} else {
					// This server failed, not the partner: suspending would be wrong.
					throw e
				}
				continue
			}
			partnerValue ??= await wholeValue()
			// Giving the login a field it did not hold, which a partner cannot
			// store, stops the save. Any other change, a replaced seed or a
			// deleted passkey included, reaches that partner with the field
			// null, as it never held one.
			if (
				BLOB_FIELDS.some(
					(field) =>
						plainDiff[field]
						&& !storedValue[field]
						&& !partnerStores(recipient, field),
				)
			) {
				tooOld.push(share)
				continue
			}
			federated.set(share.shareId, {
				shareId: share.shareId,
				certFingerprint: fingerprintHex(recipient.fingerprint),
				...(await encryptWhole(partnerValue, recipient)),
			})
		}
		if (tooOld.length > 0) {
			throw new ShareSaveError('partner_upgrade_required', tooOld)
		}
		// Only the shares the user was shown and agreed to are suspended; one
		// that fails only now stops the save again, naming each share this
		// round could not reach (a round after `incomplete` looks up only the
		// shares the server newly named).
		const agreed = new Set(withoutUnreachable)
		if (unreachable.some((share) => !agreed.has(share.shareId))) {
			throw new ShareSaveError('partner_unreachable', unreachable)
		}
		for (const share of unreachable) {
			suspend.set(share.shareId, {
				shareId: share.shareId,
				reason: 'partner_unreachable',
			})
		}
	}

	const fill = async (answer) => {
		let stageDirty = false
		// An entry already staged cannot be taken back, so dropping one
		// starts a new stage.
		const drop = (map, prefix, keep) => {
			for (const key of [...map.keys()]) {
				if (!keep(key)) {
					map.delete(key)
					stageDirty ||= stage.sent.has(prefix + key)
				}
			}
		}

		const required = answer.copies?.required ?? []
		const requiredIds = new Set(required.map((r) => r.secretId))
		const refused = idsOf(answer.copies?.skipRefused, 'secretId')
		drop(copies, 'c:', (key) => requiredIds.has(key))
		drop(skipped, 's:', (key) => requiredIds.has(key) && !refused.has(key))
		for (const holder of required.filter((r) => refused.has(r.secretId))) {
			// The holder has a vault key after all: look it up again.
			if (holder.holder === 'owner') {
				context = await useShareStore().fetchWriteContext(id)
			} else {
				certificates.delete(holder.targetUserId)
			}
		}

		const todo = required.filter(
			(r) => !copies.has(r.secretId) && !skipped.has(r.secretId),
		)
		const lookups = todo
			.filter((r) => r.holder !== 'owner' && r.targetUserId)
			.map((r) => r.targetUserId)
			.filter((userId) => !certificates.has(userId))
		if (lookups.length > 0) {
			for (const [userId, cert] of await fetchCertificates(lookups)) {
				certificates.set(userId, cert)
			}
		}
		for (const holder of todo) {
			const certificate =
				holder.holder === 'owner'
					? context.ownerCertificate
					: certificates.get(holder.targetUserId)
			if (certificate) {
				copies.set(holder.secretId, {
					secretId: holder.secretId,
					...(await encryptSparse(plainDiff, certificate)),
				})
			} else {
				skipped.set(holder.secretId, {
					secretId: holder.secretId,
					reason: 'no_vault_key',
				})
			}
		}

		if (!copyPath) {
			await fillPartners(answer.federated?.required ?? [], drop)
		}
		if (stageDirty) {
			stage = { id: null, sent: new Set() }
		}
	}

	const run = async () => {
		let incomplete = 0
		for (;;) {
			try {
				return await send()
			} catch (e) {
				const status = e?.response?.status
				const answer = e?.response?.data
				const restart =
					status === 404 && answer?.error === 'stage_unknown' && stage.id
				if (
					!(status === 409 && answer?.error === 'incomplete')
					&& !restart
				) {
					e.fromShareSave = true
					throw e
				}
				incomplete++
				if (incomplete >= MAX_INCOMPLETE_ROUNDS) {
					throw new ShareSaveError('incomplete')
				}
				if (restart) {
					stage = { id: null, sent: new Set() }
				} else {
					await fill(answer)
				}
			}
		}
	}

	try {
		const result = await run()
		for (const shareId of verifiedNow.keys()) {
			verifiedPartners.delete(shareId)
		}
		return result
	} catch (e) {
		// A partner refused as too old is looked up afresh, so a save right
		// after it upgraded reads the value fields it now advertises.
		const tooOld =
			e?.code === 'partner_upgrade_required'
			|| e?.response?.data?.error === 'partner_upgrade_required'
		if (epoch === lockEpoch && !tooOld) {
			for (const [shareId, kept] of verifiedNow) {
				verifiedPartners.set(shareId, kept)
			}
		}
		throw e
	}
}

/**
 * Encrypt a partner share's whole value to the key of the certificate that
 * was verified, never to the lookup answer's text; the key is always sent.
 * A `totp` or `passkeys` goes only to a partner that advertised it stores
 * that field, and is null otherwise.
 *
 * @param {object} value `{ key, login, additionalFields, totp, passkeys }` in plain.
 * @param {{spki: Uint8Array, valueFields?: Array<string>}} recipient The verified lookup answer.
 * @return {Promise<object>}
 */
async function encryptWhole(value, recipient) {
	const publicKey = await importSpkiPublicKey(recipient.spki)
	const out = { key: await rsaEncrypt(String(value.key ?? ''), publicKey) }
	for (const field of ['login', 'additionalFields', ...BLOB_FIELDS]) {
		const allowed =
			!BLOB_FIELDS.includes(field) || partnerStores(recipient, field)
		out[field] =
			value[field] && allowed
				? await rsaEncrypt(String(value[field]), publicKey)
				: null
	}
	return out
}

/**
 * Tell a copy editor that partner shares get the change only when the owner
 * saves next, since partners stay owner-only.
 *
 * @param {object} result A share-save answer.
 * @return {Promise<void>}
 * @spec openspec/changes/sharing-atomic-share-save/specs/federated-sharing/spec.md#requirement-partner-copies-change-only-with-the-owners-share-save
 */
export async function notifyOwnerOnly(result) {
	if (result?.federated?.ownerOnly === true) {
		// Loaded on use: the toast library needs a DOM at import time.
		const { showInfo } = await import('@nextcloud/dialogs')
		showInfo(
			t(
				'keepiq',
				'Saved. Recipients at partner organisations get this change when the owner next saves this secret.',
			),
		)
	}
}

/**
 * The translated text for a failed share save, or null when the error has no
 * specific text.
 *
 * @param {Error|object} error The thrown error.
 * @return {string|null}
 * @spec openspec/changes/sharing-atomic-share-save/specs/secrets-write-ui/spec.md#requirement-a-failed-share-save-is-shown-and-nothing-is-saved
 * @spec openspec/changes/sharing-atomic-share-save/specs/federated-sharing/spec.md#requirement-an-unreachable-partner-stops-the-save-unless-the-user-saves-without-them
 * @spec openspec/specs/federated-sharing/spec.md#requirement-federated-shares-carry-only-browser-made-ciphertext
 */
export function shareSaveErrorText(error) {
	const data = error?.response?.data
	const code = error instanceof ShareSaveError ? error.code : data?.error
	if (code === 'stale' || (error?.response?.status === 409 && data?.current)) {
		return t(
			'keepiq',
			'This secret was changed elsewhere after you opened it. Nothing was saved; open it again and redo your change.',
		)
	}
	if (code === 'stale_source') {
		return t(
			'keepiq',
			'The original of this shared secret was changed after you opened it. Nothing was saved; open it again and redo your change.',
		)
	}
	if (code === 'version_refused') {
		return t(
			'keepiq',
			'This version cannot be restored to this secret. Nothing was saved.',
		)
	}
	if (code === 'incomplete') {
		return t(
			'keepiq',
			'The people this secret is shared with kept changing while it was saved. Nothing was saved; please try again.',
		)
	}
	if (code === 'partner_lookup_limit') {
		return t(
			'keepiq',
			'Too many partner lookups. Nothing was saved; try again in a minute.',
		)
	}
	if (code === 'view_only') {
		return t(
			'keepiq',
			'Shared with you to view only. Nothing was saved; ask its owner for edit rights to change it.',
		)
	}
	const tooOld = partnerUpgradeText(error)
	if (tooOld !== null) {
		return tooOld
	}
	if (code === 'partner_unreachable') {
		return t(
			'keepiq',
			'Nothing was saved: a partner organisation this secret is shared with cannot be reached ({partners}). Try again later, or save without them, which stops sharing this secret with them until you share it with them again.',
			{
				partners: error.partners
					.map((share) => share.recipientCloudId)
					.join(', '),
			},
		)
	}
	// Only the share save's own refusal; a policy refusal or one of the plain
	// update keeps the server's specific message.
	if (
		error?.fromShareSave === true
		&& isRefusal(error)
		&& ['forbidden', 'not_allowed'].includes(data?.error)
		&& !data?.code
	) {
		return t(
			'keepiq',
			'You are not allowed to save this change for everyone this secret is shared with. Nothing was saved.',
		)
	}
	return null
}
