/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

import axios from '@nextcloud/axios'
import { generateOcsUrl, generateUrl } from '@nextcloud/router'
import { defineStore } from 'pinia'
import { copyCiphertext, recipientFields } from '../../services/recipientCopy.js'
import { useSecretStore } from './secret.js'
import { useShareStore } from './share.js'

/** Upper bound on the groups one sharee search returns. */
const MAX_GROUP_RESULTS = 25

/**
 * Pinia store for sharing a secret with a Nextcloud group (sharing-02).
 *
 * The server keeps the group share and hands back the members that have an
 * active encryption suite. Encryption stays in this browser tab: the store
 * decrypts the owner's copy, encrypts it once per member, and registers the
 * copies through `register-batch` linked to the group share, so revoking the
 * group share revokes every copy.
 *
 * @spec openspec/specs/sharing-group/spec.md#requirement-share-with-a-group
 */
/**
 * The group rows of a sharee search answer: the exact matches, then the
 * others.
 *
 * @param {object} response The sharee search response.
 * @return {Array<object>}
 * @spec openspec/specs/sharing-group/spec.md#requirement-share-with-a-group
 */
function shareeGroupRows(response) {
	const data = response.data?.ocs?.data ?? {}
	return [
		...(Array.isArray(data.exact?.groups) ? data.exact.groups : []),
		...(Array.isArray(data.groups) ? data.groups : []),
	]
}

/**
 * Sharee rows as groups, each id once, labelled by its name or else its id;
 * a row without an id is passed over.
 *
 * @param {Array<object>} rows The group rows.
 * @return {Array<{id: string, label: string}>}
 * @spec openspec/specs/sharing-group/spec.md#requirement-share-with-a-group
 */
function uniqueGroups(rows) {
	const groups = []
	const seen = new Set()
	for (const row of rows) {
		const id = String(row?.value?.shareWith ?? '')
		if (id === '' || seen.has(id)) {
			continue
		}
		seen.add(id)
		groups.push({ id, label: String(row?.label || id) })
	}
	return groups
}

/**
 * What the server answered on creating a group share: the share, the
 * members eligible for a copy, and how many it left out itself.
 *
 * @param {object|undefined} data The response body.
 * @return {{groupShare: ?object, members: Array<object>, skippedByServer: number}}
 * @spec openspec/specs/sharing-group/spec.md#requirement-share-with-a-group
 */
function createdGroupShare(data) {
	return {
		groupShare: data?.groupShare ?? null,
		members: Array.isArray(data?.members) ? data.members : [],
		skippedByServer: Number(data?.skipped ?? 0),
	}
}

/**
 * Encrypt a copy for each eligible member and register them. register-batch
 * needs a vault-key proof (keepiq#818).
 *
 * @param {object} store The group share store.
 * @param {object} share The source secret id and the created group share.
 * @return {Promise<{received: number, refused: number, mismatched: number}>}
 * @spec openspec/specs/sharing-group/spec.md#requirement-share-with-a-group
 */
async function registerMemberCopies(store, share) {
	if (share.groupShare === null || share.members.length === 0) {
		return { received: 0, refused: 0, mismatched: 0 }
	}
	const rows = await store.encryptForMembers(
		share.secretId,
		share.groupShare.id,
		share.members,
	)
	const { items } = await useShareStore().registerBatch(rows)
	return registeredCounts(items, share.members.length)
}

/**
 * How the registered copies came out. A copy the server would leave without
 * the login's one-time code or passkeys is refused for that reason, not for
 * a missing encryption suite.
 *
 * @param {Array<object>} items The register-batch items.
 * @param {number} memberCount How many members a copy was made for.
 * @return {{received: number, refused: number, mismatched: number}}
 * @spec openspec/specs/sharing-group/spec.md#requirement-share-with-a-group
 */
function registeredCounts(items, memberCount) {
	const received = items.filter(
		(item) => item.status === 'created' || item.status === 'exists',
	).length
	const mismatched = items.filter(
		(item) => item.status === 'copy_fields_mismatch',
	).length
	return { received, refused: memberCount - received - mismatched, mismatched }
}

/**
 * List a group share, replacing an older entry of it.
 *
 * @param {object} store The group share store.
 * @param {object} groupShare The group share.
 * @return {void}
 */
function rememberGroupShare(store, groupShare) {
	store.groupShares = [
		...store.groupShares.filter((row) => row.id !== groupShare.id),
		groupShare,
	]
}

export const useGroupShareStore = defineStore('groupShare', {
	state: () => ({
		/** @type {Array<object>} The group shares of the secret last fetched. */
		groupShares: [],
		/** @type {boolean} Whether a request is in flight. */
		loading: false,
		/** @type {string|null} The last error message. */
		error: null,
	}),

	actions: {
		/**
		 * Load the group shares of a secret. The server answers an empty list
		 * to anyone but the owner or a delegate.
		 *
		 * @param {string} secretId The source secret id.
		 * @return {Promise<void>}
		 * @spec openspec/specs/sharing-group/spec.md#requirement-share-with-a-group
		 */
		async fetchGroupShares(secretId) {
			this.loading = true
			this.error = null
			try {
				const response = await axios.get(
					generateUrl(
						`/apps/keepiq/api/v1/secrets/${secretId}/group-shares`,
					),
				)
				this.groupShares = Array.isArray(response.data) ? response.data : []
			} finally {
				this.loading = false
			}
		},

		/**
		 * Search the groups the current user may share with. Nextcloud's own
		 * sharee search applies the instance's share settings, and the server
		 * checks the same settings again when the share is created.
		 *
		 * @param {string} search The search term.
		 * @return {Promise<Array<{id: string, label: string}>>}
		 * @spec openspec/specs/sharing-group/spec.md#requirement-share-with-a-group
		 */
		async searchGroups(search) {
			const response = await axios.get(
				generateOcsUrl('apps/files_sharing/api/v1/sharees'),
				{
					headers: { 'OCS-APIRequest': 'true' },
					params: {
						format: 'json',
						search,
						itemType: 'file',
						shareType: 1,
						perPage: MAX_GROUP_RESULTS,
						lookup: false,
					},
				},
			)

			return uniqueGroups(shareeGroupRows(response)).slice(
				0,
				MAX_GROUP_RESULTS,
			)
		},

		/**
		 * Share a secret with a group.
		 *
		 * @param {string} secretId The source secret id.
		 * @param {string} groupId The Nextcloud group id.
		 * @param {{useOnly: boolean, expiresAt: string|null}} [restriction] Use-only and end date.
		 * @return {Promise<{received: number, skipped: number, refused: number}>} How many
		 *   members got a copy, how many did not (no encryption suite, or refused), and how
		 *   many were refused because the login holds a one-time code or passkeys.
		 * @spec openspec/specs/sharing-group/spec.md#requirement-share-with-a-group
		 */
		async shareWithGroup(secretId, groupId, restriction = {}) {
			this.loading = true
			this.error = null
			try {
				const created = await axios.post(
					generateUrl(
						`/apps/keepiq/api/v1/secrets/${secretId}/group-shares`,
					),
					{ groupId, ...restriction },
				)
				const share = { secretId, ...createdGroupShare(created.data) }
				const counts = await registerMemberCopies(this, share)
				if (share.groupShare !== null) {
					rememberGroupShare(this, share.groupShare)
				}
				return {
					received: counts.received,
					skipped: share.skippedByServer + counts.refused,
					refused: counts.mismatched,
				}
			} catch (e) {
				this.error =
					e?.response?.data?.message || e?.message || 'Failed to share'
				throw e
			} finally {
				this.loading = false
			}
		},

		/**
		 * Decrypt the owner's copy in this tab and encrypt it for each member.
		 *
		 * @param {string} secretId The source secret id.
		 * @param {string} groupShareId The group share the copies hang on.
		 * @param {Array<{userId: string, certificate: string}>} members The eligible members.
		 * @return {Promise<Array<object>>} register-batch rows.
		 * @spec openspec/specs/sharing-group/spec.md#requirement-share-with-a-group
		 */
		async encryptForMembers(secretId, groupShareId, members) {
			const shareStore = useShareStore()
			const plain = await useSecretStore().fetchSecret(secretId)
			const snapshot = recipientFields(plain)

			const rows = []
			for (const member of members) {
				const blob = await shareStore.encryptForRecipient(
					snapshot,
					member.certificate,
				)
				rows.push({
					sourceSecretId: secretId,
					targetUserId: member.userId,
					...copyCiphertext(blob),
					groupShareId,
				})
			}
			return rows
		},

		/**
		 * Revoke a group share. The server revokes every member copy with it.
		 *
		 * @param {string} groupShareId The group share id.
		 * @return {Promise<void>}
		 * @spec openspec/specs/sharing-group/spec.md#requirement-share-with-a-group
		 */
		async revokeGroupShare(groupShareId) {
			this.error = null
			try {
				await axios.delete(
					generateUrl(`/apps/keepiq/api/v1/group-shares/${groupShareId}`),
				)
				this.groupShares = this.groupShares.filter(
					(row) => row.id !== groupShareId,
				)
			} catch (e) {
				this.error =
					e?.response?.data?.message || e?.message || 'Failed to revoke'
				throw e
			}
		},
	},
})
