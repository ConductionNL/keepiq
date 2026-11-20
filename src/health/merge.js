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
 * @spec openspec/specs/vault-duplicates/spec.md#requirement-merge-a-duplicate-group
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { useSecretStore } from '../store/modules/secret.js'
import { useSecretTypeStore } from '../store/modules/secretType.js'
import { builtinFieldNamed } from '../utils/builtinTypeFields.js'
import {
	fieldsOf,
	schemasOf,
	typeFieldSchema,
	withSchemas,
} from '../utils/fieldSchema.js'
import { foldFields } from './duplicates.js'

/**
 * The decrypted secrets of a group, in the order given.
 *
 * @param {Array<string>} ids The secret ids.
 * @return {Promise<Array<object>>}
 * @spec openspec/specs/vault-duplicates/spec.md#requirement-merge-a-duplicate-group
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
 * The additional fields the kept secret will carry, with the schemas of the
 * folded fields under the names they land at.
 *
 * @param {object} kept The kept secret, decrypted.
 * @param {Array<object>} others The other secrets, decrypted.
 * @return {object}
 * @spec openspec/specs/vault-duplicates/spec.md#requirement-merge-a-duplicate-group
 * @spec openspec/specs/vault-custom-fields/spec.md#requirement-custom-fields-carry-a-schema
 */
export function mergedFields(kept, others) {
	const parts = (blob) => ({ fields: fieldsOf(blob), schemas: schemasOf(blob) })
	const { fields, schemas } = foldFields(
		parts(kept.additionalFields),
		others.map((other) => withTypeSchemas(parts(other.additionalFields), other)),
	)
	return withSchemas(fields, schemas)
}

/**
 * A secret's fields with the schemas its type gives its own fields, which
 * are not stored: a group can hold secrets of different types, and an API
 * key's secret or a certificate's private key must stay masked on the kept
 * secret.
 *
 * @param {{fields: object, schemas: object}} parts The secret's fields and stored schemas.
 * @param {object} secret The secret, decrypted.
 * @return {{fields: object, schemas: object}}
 * @spec openspec/specs/vault-custom-fields/spec.md#requirement-any-custom-field-can-be-hidden
 */
function withTypeSchemas(parts, secret) {
	const typeName = useSecretTypeStore().typesById?.[secret.typeId]?.name ?? ''
	const schemas = { ...parts.schemas }
	for (const name of Object.keys(parts.fields)) {
		const own = builtinFieldNamed(typeName, name)
		if (own !== null && !Object.hasOwn(schemas, name)) {
			schemas[name] = typeFieldSchema(own)
		}
	}
	return { fields: parts.fields, schemas }
}

/**
 * The people who lose access when these secrets are removed: the distinct
 * recipients of their shares.
 *
 * @param {Array<string>} ids The secrets that will be removed.
 * @return {Promise<number>}
 * @spec openspec/specs/vault-duplicates/spec.md#requirement-merge-a-duplicate-group
 */
export async function peopleLosingAccess(ids) {
	const people = new Set()
	for (const id of ids) {
		const response = await axios.get(
			generateUrl(`/apps/keepiq/api/v1/secrets/${id}/shares`),
		)
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
 * @spec openspec/specs/vault-duplicates/spec.md#requirement-merge-a-duplicate-group
 */
export async function mergeGroup(kept, others) {
	const store = useSecretStore()
	const fields = mergedFields(kept, others)
	// Compared with the kept blob read the same way, so a reordered schema
	// member alone does not cause a write.
	if (JSON.stringify(fields) !== JSON.stringify(mergedFields(kept, []))) {
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
