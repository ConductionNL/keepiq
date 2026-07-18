/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Pinia store for team folder sharing (team-folder-sharing §5.1).
 *
 * Wraps `/api/v1/team-folders` and owns the client fan-out runner: for
 * every missing (secret × recipient) pair reported by the reconcile
 * endpoint, the runner decrypts the owner's secret with the in-memory
 * CryptoKey, RSA-encrypts the fields under the recipient's public
 * certificate, and POSTs the ciphertext in chunks. The server upsert is
 * idempotent, so a cancelled or crashed run resumes safely on the next
 * reconcile. Plaintext NEVER leaves the browser (ADR-003).
 *
 * @spec openspec/changes/team-folder-sharing/tasks.md#5.1
 */

import { defineStore } from 'pinia'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { useSecretStore } from './secret.js'
import { useShareStore } from './share.js'
import { useAttachmentStore } from './attachment.js'

/** Number of (secret × recipient) rows per registration POST. */
const FAN_OUT_CHUNK_SIZE = 20

export const useTeamFolderStore = defineStore('teamFolder', {
	state: () => ({
		/** @type {Array<object>} Team folders the user owns (with members). */
		owned: [],
		/** @type {Array<object>} Team folders shared to the user. */
		memberOf: [],
		/** @type {boolean} Whether a request is in flight. */
		loading: false,
		/** @type {string|null} The last error message. */
		error: null,
		/** @type {{total: number, done: number, running: boolean}} Fan-out progress. */
		fanOut: { total: 0, done: 0, running: false },
		/** @type {boolean} Cancellation flag for the fan-out runner. */
		fanOutCancelled: false,
	}),

	getters: {
		/**
		 * The team folder attached to a given folder id, when the user owns one.
		 *
		 * @param {object} state The store state.
		 * @return {function(string): object|null}
		 */
		byFolderId: (state) => (folderId) =>
			state.owned.find((tf) => tf.folderId === folderId) ?? null,
	},

	actions: {
		/**
		 * Hydrate the owned + member-of team-folder lists.
		 *
		 * @return {Promise<void>}
		 */
		async fetchTeamFolders() {
			this.loading = true
			this.error = null
			try {
				const response = await axios.get(generateUrl('/apps/doriath/api/v1/team-folders'))
				this.owned = response.data?.owned ?? []
				this.memberOf = response.data?.memberOf ?? []
			} catch (e) {
				this.error = e?.response?.data?.message || e?.message || 'Failed to load team folders'
				throw e
			} finally {
				this.loading = false
			}
		},

		/**
		 * Share an owned folder — creates the TeamFolder attachment.
		 *
		 * @param {string} folderId The folder to share.
		 * @return {Promise<object>} The TeamFolder row.
		 */
		async shareFolder(folderId) {
			const response = await axios.post(
				generateUrl('/apps/doriath/api/v1/team-folders'),
				{ folderId },
			)
			await this.fetchTeamFolders()
			return response.data
		},

		/**
		 * Add a member (user or group). Returns the fan-out payload
		 * (new eligible recipients + subtree secrets).
		 *
		 * @param {string} teamFolderId The team folder.
		 * @param {string} memberType   `user` or `group`.
		 * @param {string} memberId     The Nextcloud user/group id.
		 * @return {Promise<object>}
		 */
		async addMember(teamFolderId, memberType, memberId) {
			const response = await axios.post(
				generateUrl(`/apps/doriath/api/v1/team-folders/${teamFolderId}/members`),
				{ memberType, memberId },
			)
			await this.fetchTeamFolders()
			return response.data
		},

		/**
		 * Remove a membership row (revokes shares of uncovered users).
		 *
		 * @param {string} teamFolderId The team folder.
		 * @param {string} membershipId The membership row id.
		 * @return {Promise<{revoked: number}>}
		 */
		async removeMember(teamFolderId, membershipId) {
			const response = await axios.delete(
				generateUrl(`/apps/doriath/api/v1/team-folders/${teamFolderId}/members/${membershipId}`),
			)
			await this.fetchTeamFolders()
			return response.data
		},

		/**
		 * Unshare a folder entirely (cascade-revokes all derived shares).
		 *
		 * @param {string} teamFolderId The team folder.
		 * @return {Promise<{revoked: number}>}
		 */
		async unshareFolder(teamFolderId) {
			const response = await axios.delete(
				generateUrl(`/apps/doriath/api/v1/team-folders/${teamFolderId}`),
			)
			await this.fetchTeamFolders()
			return response.data
		},

		/**
		 * Fetch the reconciliation state: expected secrets/recipients and
		 * the missing (secret × recipient) pairs.
		 *
		 * @param {string} teamFolderId The team folder.
		 * @return {Promise<object>}
		 */
		async reconcile(teamFolderId) {
			const response = await axios.get(
				generateUrl(`/apps/doriath/api/v1/team-folders/${teamFolderId}/reconcile`),
			)
			return response.data
		},

		/**
		 * Approve a group-join request — returns the fan-out payload for
		 * the approved user.
		 *
		 * @param {string} teamFolderId The team folder.
		 * @param {string} newMemberId  The approved user id.
		 * @return {Promise<object>}
		 */
		async approveJoin(teamFolderId, newMemberId) {
			const response = await axios.post(
				generateUrl(`/apps/doriath/api/v1/team-folders/${teamFolderId}/approve-join`),
				{ newMemberId },
			)
			return response.data
		},

		/**
		 * Run the admin offboarding action.
		 *
		 * @param {string} leavingUserId   The user being offboarded.
		 * @param {string} successorUserId The successor.
		 * @return {Promise<{revoked: number, transferred: number, skipped: Array<string>}>}
		 */
		async offboard(leavingUserId, successorUserId) {
			const response = await axios.post(
				generateUrl('/apps/doriath/api/v1/team-folders/offboard'),
				{ leavingUserId, successorUserId },
			)
			return response.data
		},

		/**
		 * Cancel a running fan-out (the current chunk still completes; the
		 * idempotent server upsert makes the next reconcile resume safely).
		 *
		 * @return {void}
		 */
		cancelFanOut() {
			this.fanOutCancelled = true
		},

		/**
		 * Re-wrap attachment file keys for freshly fanned-out recipient
		 * copies (encrypted-attachments §6.3). Best-effort per row: a
		 * failed re-grant never aborts the fan-out; the reconcile pass in
		 * the attachment flow surfaces gaps.
		 *
		 * @param {Array<object>} rows The created fan-out descriptors.
		 * @param {object} certByUser userId → PEM certificate map.
		 * @return {Promise<void>}
		 */
		async regrantAttachments(rows, certByUser) {
			const attachmentStore = useAttachmentStore()
			for (const row of rows) {
				const certificate = certByUser[row.targetUserId]
				if (!certificate) {
					continue
				}
				try {
					// eslint-disable-next-line no-await-in-loop
					await attachmentStore.regrantForRecipient(
						row.sourceSecretId,
						row.recipientSecretId,
						row.targetUserId,
						certificate,
					)
				} catch {
					// Best-effort — surfaced by the attachment list for the recipient.
				}
			}
		},

		/**
		 * The client fan-out runner (§5.1): reconcile → decrypt each
		 * missing secret with the in-memory CryptoKey → RSA-encrypt per
		 * recipient certificate → POST in idempotent chunks.
		 *
		 * @param {string} teamFolderId The team folder to fan out.
		 * @return {Promise<{created: number, cancelled: boolean}>}
		 */
		async runFanOut(teamFolderId) {
			const secretStore = useSecretStore()
			const shareStore = useShareStore()

			this.fanOutCancelled = false
			this.fanOut = { total: 0, done: 0, running: true }

			try {
				const state = await this.reconcile(teamFolderId)
				const missing = state.missing ?? []
				this.fanOut.total = missing.length
				if (missing.length === 0) {
					return { created: 0, cancelled: false }
				}

				const certByUser = Object.fromEntries(
					(state.recipients ?? []).map((r) => [r.userId, r.certificate]),
				)

				// Decrypt each distinct source secret once, not per recipient.
				const plaintextCache = {}
				let created = 0
				let chunk = []

				for (const pair of missing) {
					if (this.fanOutCancelled) {
						break
					}

					const certificate = certByUser[pair.userId]
					if (!certificate) {
						this.fanOut.done++
						continue
					}

					if (!plaintextCache[pair.secretId]) {
						// fetchSecret decrypts with the session CryptoKey and
						// returns the PLAINTEXT secret — do not decrypt twice.
						// eslint-disable-next-line no-await-in-loop
						const plain = await secretStore.fetchSecret(pair.secretId)
						plaintextCache[pair.secretId] = {
							key: plain.key ?? '',
							login: plain.login ?? '',
							additionalFields: typeof plain.additionalFields === 'object' && plain.additionalFields !== null
								? JSON.stringify(plain.additionalFields)
								: (plain.additionalFields ?? ''),
						}
					}

					// eslint-disable-next-line no-await-in-loop
					const blob = await shareStore.encryptForRecipient(
						plaintextCache[pair.secretId],
						certificate,
					)
					chunk.push({
						sourceSecretId: pair.secretId,
						targetUserId: pair.userId,
						encryptedKey: blob.key ?? '',
						encryptedLogin: blob.login ?? null,
						encryptedAdditionalFields: blob.additionalFields ?? null,
					})

					if (chunk.length >= FAN_OUT_CHUNK_SIZE) {
						// eslint-disable-next-line no-await-in-loop
						const response = await axios.post(
							generateUrl(`/apps/doriath/api/v1/team-folders/${teamFolderId}/shares`),
							{ shares: chunk },
						)
						created += response.data?.created ?? 0
						// eslint-disable-next-line no-await-in-loop
						await this.regrantAttachments(response.data?.rows ?? [], certByUser)
						this.fanOut.done += chunk.length
						chunk = []
					}
				}

				if (chunk.length > 0 && !this.fanOutCancelled) {
					const response = await axios.post(
						generateUrl(`/apps/doriath/api/v1/team-folders/${teamFolderId}/shares`),
						{ shares: chunk },
					)
					created += response.data?.created ?? 0
					await this.regrantAttachments(response.data?.rows ?? [], certByUser)
					this.fanOut.done += chunk.length
				}

				return { created, cancelled: this.fanOutCancelled }
			} catch (e) {
				this.error = e?.response?.data?.message || e?.message || 'Fan-out failed'
				throw e
			} finally {
				this.fanOut.running = false
			}
		},
	},
})
