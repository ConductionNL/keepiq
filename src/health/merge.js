/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Merging a duplicate group (vault-duplicate-finder D3): the kept secret is
 * re-saved with the others' additional fields folded in, encrypted in the
 * browser through the owner's existing update path, then the others are
 * deleted through the existing delete path (a move to the trash). Nothing
 * about the group reaches the server beyond those writes.
 *
 * @spec openspec/changes/vault-duplicate-finder/specs/vault-duplicates/spec.md#requirement-merge-a-duplicate-group
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { useSecretStore } from '../store/modules/secret.js'
import { foldFields } from './duplicates.js'

/**
 * The decrypted secrets of a group, in the order given.
 *
 * @param {Array<string>} ids The secret ids.
 * @return {Promise<Array<object>>}
 * @spec openspec/changes/vault-duplicate-finder/specs/vault-duplicates/spec.md#requirement-merge-a-duplicate-group
 */
export async function loadGroup(ids) {
	const store = useSecretStore()
	const out = []
	for (const id of ids) {
		// fetchSecret answers the decrypted copy and also opens it; spread so a
		// later fetch does not change what this list holds.
		out.push({ ...(await store.fetchSecret(id)) })
	}
	return out
}

/**
 * The additional fields the kept secret will carry.
 *
 * @param {object} kept The kept secret, decrypted.
 * @param {Array<object>} others The other secrets, decrypted.
 * @return {object}
 * @spec openspec/changes/vault-duplicate-finder/specs/vault-duplicates/spec.md#requirement-merge-a-duplicate-group
 */
export function mergedFields(kept, others) {
	return foldFields(kept.additionalFields, others.map((other) => other.additionalFields))
}

/**
 * The people who lose access when these secrets are removed: the distinct
 * recipients of their shares.
 *
 * @param {Array<string>} ids The secrets that will be removed.
 * @return {Promise<number>}
 * @spec openspec/changes/vault-duplicate-finder/specs/vault-duplicates/spec.md#requirement-merge-a-duplicate-group
 */
export async function peopleLosingAccess(ids) {
	const people = new Set()
	for (const id of ids) {
		const response = await axios.get(generateUrl(`/apps/keepiq/api/v1/secrets/${id}/shares`))
		for (const share of Array.isArray(response?.data) ? response.data : []) {
			if (share?.targetUserId) {
				people.add(String(share.targetUserId))
			}
		}
	}
	return people.size
}

/**
 * Merge: save the kept secret with the folded fields when they changed, then
 * delete the others. Stops at the first failure, so the kept secret is never
 * left without the fields of an item that was removed.
 *
 * @param {object} kept The kept secret, decrypted.
 * @param {Array<object>} others The other secrets, decrypted.
 * @return {Promise<{removed: Array<string>}>}
 * @spec openspec/changes/vault-duplicate-finder/specs/vault-duplicates/spec.md#requirement-merge-a-duplicate-group
 */
export async function mergeGroup(kept, others) {
	const store = useSecretStore()
	const fields = mergedFields(kept, others)
	if (JSON.stringify(fields) !== JSON.stringify(isMap(kept.additionalFields) ? kept.additionalFields : {})) {
		await store.writeBackSettled(kept.id)
		const data = { additionalFields: fields }
		if (kept.mergedPending > 0) {
			data.mergedPending = kept.mergedPending
		}
		await store.updateSecret(kept.id, data, { baseUpdatedAt: kept.updatedAt })
	}
	const removed = []
	for (const other of others) {
		await store.deleteSecret(other.id)
		removed.push(other.id)
	}
	return { removed }
}

/**
 * @param {*} fields A decrypted additional-fields blob.
 * @return {boolean} Whether it is a name → value map.
 */
function isMap(fields) {
	return fields !== null && typeof fields === 'object' && !Array.isArray(fields)
}
