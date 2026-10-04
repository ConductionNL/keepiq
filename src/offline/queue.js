/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The offline edit queue (offline-edit-queue), as pure functions: sealing an
 * entry to the owner's own certificate, opening it with the private key,
 * coalescing changes to one secret, overlaying the queue on the cached vault,
 * and classifying a replay answer. No IndexedDB and no network here.
 *
 * An entry at rest:
 *   { entryId, op: 'create'|'update'|'delete', secretId, baseUpdatedAt,
 *     queuedAt, suiteId, status: 'queued'|'sync'|'conflict'|'failed',
 *     sealed }
 * `sealed` is the hybrid envelope (RSA-OAEP + AES-256-GCM, the one
 * emergencyEnvelope.js builds) of the JSON body: the same `key`, `login` and
 * `additionalFields` ciphertext an online save sends, plus the index fields
 * `name`, `url`, `typeId` and `folderId`. Recipient ciphertext is never
 * queued; it is made at replay time.
 *
 * @spec openspec/specs/offline-edit-queue/spec.md#requirement-offline-changes-go-into-a-sealed-local-queue
 */
import {
	buildRecoveryEnvelope,
	openRecoveryEnvelope,
} from '../crypto/emergencyEnvelope.js'
import { isRefusal } from '../utils/refusal.js'

/** The fields an entry keeps in plain. Everything else is sealed. */
export const PLAIN_FIELDS = Object.freeze([
	'entryId',
	'op',
	'secretId',
	'baseUpdatedAt',
	'queuedAt',
	'suiteId',
	'status',
	'sealed',
])

/**
 *
 */
function uuid() {
	return crypto.randomUUID()
}

/**
 * Seal an opened entry for storage.
 *
 * @param {object} entry The opened entry, with `body`.
 * @param {string} certificatePem The owner's own certificate.
 * @return {Promise<object>} The entry as stored.
 */
export async function sealEntry(entry, certificatePem) {
	return {
		entryId: entry.entryId || uuid(),
		op: entry.op,
		secretId: entry.secretId,
		baseUpdatedAt: entry.baseUpdatedAt ?? null,
		queuedAt: entry.queuedAt || new Date().toISOString(),
		suiteId: entry.suiteId,
		status: entry.status || 'queued',
		sealed: await buildRecoveryEnvelope(
			JSON.stringify(entry.body || {}),
			certificatePem,
		),
	}
}

/**
 * Open a stored entry with the owner's private key.
 *
 * @param {object} stored The stored entry.
 * @param {CryptoKey} privateKey The owner's private key.
 * @return {Promise<object>} The entry with its `body`.
 */
export async function openEntry(stored, privateKey) {
	const { sealed, ...plain } = stored
	return {
		...plain,
		body: JSON.parse(await openRecoveryEnvelope(sealed, privateKey)),
	}
}

/**
 * Combine a new change with the pending change for the same secret (D4).
 *
 * @param {object|null} existing The opened pending entry for the secret, or null.
 * @param {object} incoming The opened new change.
 * @return {object|null} The entry to keep, or null when both cancel out.
 */
export function coalesce(existing, incoming) {
	if (!existing) return incoming
	if (existing.op === 'delete') return existing
	const merged = { ...existing.body, ...incoming.body }
	if (existing.op === 'create') {
		if (incoming.op === 'delete') return null
		return { ...existing, body: merged, status: 'queued' }
	}
	// existing update: keep the earliest base, the latest values.
	if (incoming.op === 'delete') {
		return {
			...incoming,
			entryId: existing.entryId,
			baseUpdatedAt: existing.baseUpdatedAt,
			queuedAt: existing.queuedAt,
			body: {},
		}
	}
	return { ...existing, body: merged, status: 'queued' }
}

/**
 * The cached secrets with the pending changes on top, each changed one
 * marked `pendingSync`.
 *
 * @param {Array<object>} secrets The cached, decrypted secrets.
 * @param {Array<object>} entries The opened entries.
 * @return {Array<object>}
 */
export function applyQueue(secrets, entries) {
	let list = [...(secrets || [])]
	for (const entry of entries || []) {
		const fields = indexAndCiphertext(entry.body)
		if (entry.op === 'create') {
			list.push({
				id: entry.secretId,
				...fields,
				pendingSync: true,
				localOnly: true,
			})
		} else if (entry.op === 'update') {
			list = list.map((s) =>
				s.id === entry.secretId ? { ...s, ...fields, pendingSync: true } : s,
			)
		} else if (entry.op === 'delete') {
			list = list.filter((s) => s.id !== entry.secretId)
		}
	}
	return list
}

/**
 *
 * @param body
 */
function indexAndCiphertext(body) {
	const out = {}
	for (const k of [
		'name',
		'url',
		'typeId',
		'folderId',
		'key',
		'login',
		'additionalFields',
	]) {
		if (body && body[k] !== undefined) out[k] = body[k]
	}
	return out
}

/**
 * Replay order: oldest first.
 *
 * @param {Array<object>} entries The entries.
 * @return {Array<object>}
 */
export function replayOrder(entries) {
	return [...(entries || [])].sort((a, b) =>
		String(a.queuedAt).localeCompare(String(b.queuedAt)),
	)
}

/**
 * What a failed replay request means for its entry (D5).
 *
 * @param {object} error The axios error.
 * @return {'conflict'|'failed'|'retry'}
 */
export function classifyReplayError(error) {
	const status = error?.response?.status
	if (status === 409) return 'conflict'
	if (isRefusal(error) || status === 404) return 'failed'
	return 'retry'
}
