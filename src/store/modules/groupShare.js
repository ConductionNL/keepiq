/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

import axios from '@nextcloud/axios'
import { generateOcsUrl, generateUrl } from '@nextcloud/router'
import { defineStore } from 'pinia'
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
 * @spec openspec/changes/sharing-group-share-entry-point/specs/sharing-group/spec.md#requirement-share-with-a-group
 */
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
		 * @spec openspec/changes/sharing-group-share-entry-point/specs/sharing-group/spec.md#requirement-share-with-a-group
		 */
		async fetchGroupShares(secretId) {
			this.loading = true
			this.error = null
			try {
				const response = await axios.get(
					generateUrl(`/apps/keepiq/api/v1/secrets/${secretId}/group-shares`),
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
		 * @spec openspec/changes/sharing-group-share-entry-point/specs/sharing-group/spec.md#requirement-share-with-a-group
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

			const data = response.data?.ocs?.data ?? {}
			const rows = [
				...(Array.isArray(data.exact?.groups) ? data.exact.groups : []),
				...(Array.isArray(data.groups) ? data.groups : []),
			]
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
			return groups.slice(0, MAX_GROUP_RESULTS)
		},

		/**
		 * Share a secret with a group.
		 *
		 * @param {string} secretId The source secret id.
		 * @param {string} groupId The Nextcloud group id.
		 * @return {Promise<{received: number, skipped: number}>} How many members
		 *   got a copy, and how many did not (no encryption suite, or refused).
		 * @spec openspec/changes/sharing-group-share-entry-point/specs/sharing-group/spec.md#scenario-an-owner-shares-with-a-group
		 */
		async shareWithGroup(secretId, groupId) {
			this.loading = true
			this.error = null
			try {
				const created = await axios.post(
					generateUrl(`/apps/keepiq/api/v1/secrets/${secretId}/group-shares`),
					{ groupId },
				)
				const groupShare = created.data?.groupShare ?? null
				const members = Array.isArray(created.data?.members)
					? created.data.members
					: []
				const skippedByServer = Number(created.data?.skipped ?? 0)

				let received = 0
				let refused = 0
				if (groupShare !== null && members.length > 0) {
					const rows = await this.encryptForMembers(
						secretId,
						groupShare.id,
						members,
					)
					const response = await axios.post(
						generateUrl('/apps/keepiq/api/v1/shares/register-batch'),
						{ shares: rows },
					)
					const items = Array.isArray(response.data?.items)
						? response.data.items
						: []
					received = items.filter(
						(item) => item.status === 'created' || item.status === 'exists',
					).length
					refused = members.length - received
				}

				if (groupShare !== null) {
					this.groupShares = [
						...this.groupShares.filter((row) => row.id !== groupShare.id),
						groupShare,
					]
				}

				return { received, skipped: skippedByServer + refused }
			} catch (e) {
				this.error = e?.response?.data?.message || e?.message || 'Failed to share'
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
		 */
		async encryptForMembers(secretId, groupShareId, members) {
			const shareStore = useShareStore()
			const plain = await useSecretStore().fetchSecret(secretId)
			const snapshot = {
				key: plain?.key ?? '',
				login: plain?.login ?? '',
				additionalFields:
					typeof plain?.additionalFields === 'object'
					&& plain.additionalFields !== null
						? JSON.stringify(plain.additionalFields)
						: (plain?.additionalFields ?? ''),
			}

			const rows = []
			for (const member of members) {
				const blob = await shareStore.encryptForRecipient(
					snapshot,
					member.certificate,
				)
				rows.push({
					sourceSecretId: secretId,
					targetUserId: member.userId,
					encryptedKey: blob.key ?? '',
					encryptedLogin: blob.login ?? null,
					encryptedAdditionalFields: blob.additionalFields ?? null,
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
		 * @spec openspec/changes/sharing-group-share-entry-point/specs/sharing-group/spec.md#scenario-the-owner-revokes-a-group-share
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
				this.error = e?.response?.data?.message || e?.message || 'Failed to revoke'
				throw e
			}
		},
	},
})
