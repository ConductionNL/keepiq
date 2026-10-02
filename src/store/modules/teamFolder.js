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

import axios from '@nextcloud/axios'
import { translatePlural as n } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { defineStore } from 'pinia'
import { useAttachmentStore } from './attachment.js'
import { useSecretStore } from './secret.js'
import { useShareStore } from './share.js'

/** Number of (secret × recipient) rows per registration POST. */
const FAN_OUT_CHUNK_SIZE = 20

/** How often an unlocked vault confirms waiting members (admin-auto-confirm-members D5). */
export const AUTO_CONFIRM_INTERVAL_MS = 15 * 60 * 1000

/**
 * The repeat timer of the automatic confirmation. Module scope, not state:
 * a timer id is not reactive data and must not end up in a store snapshot.
 *
 * @type {ReturnType<typeof setInterval>|null}
 */
let autoConfirmTimer = null

/**
 * Turn decrypted secret fields into the shape encryptForRecipient takes.
 *
 * @param {object} plain The decrypted secret.
 * @return {{key: string, login: string, additionalFields: string}}
 */
function recipientFields(plain) {
	return {
		key: plain.key ?? '',
		login: plain.login ?? '',
		additionalFields:
			typeof plain.additionalFields === 'object' && plain.additionalFields !== null
				? JSON.stringify(plain.additionalFields)
				: (plain.additionalFields ?? ''),
	}
}

export const useTeamFolderStore = defineStore('teamFolder', {
	state: () => ({
		/** @type {{created: number, members: number, at: number}|null} The last automatic confirmation run. */
		lastAutoConfirm: null,
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
		 * @spec openspec/specs/team-folder-sharing/spec.md#requirement-share-a-folder-as-a-team-folder
		 */
		async fetchTeamFolders() {
			this.loading = true
			this.error = null
			try {
				const response = await axios.get(
					generateUrl('/apps/keepiq/api/v1/team-folders'),
				)
				this.owned = response.data?.owned ?? []
				this.memberOf = response.data?.memberOf ?? []
			} catch (e) {
				this.error =
					e?.response?.data?.message
					|| e?.message
					|| 'Failed to load team folders'
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
		 * @spec openspec/specs/team-folder-sharing/spec.md#requirement-share-a-folder-as-a-team-folder
		 */
		async shareFolder(folderId) {
			const response = await axios.post(
				generateUrl('/apps/keepiq/api/v1/team-folders'),
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
		 * @spec openspec/specs/team-folder-sharing/spec.md#requirement-share-a-folder-as-a-team-folder
		 * @spec openspec/specs/team-folder-sharing/spec.md#requirement-nested-subfolder-inheritance
		 */
		async addMember(teamFolderId, memberType, memberId) {
			const response = await axios.post(
				generateUrl(
					`/apps/keepiq/api/v1/team-folders/${teamFolderId}/members`,
				),
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
		 * @spec openspec/specs/team-folder-sharing/spec.md#requirement-inherited-access-on-add-revoked-on-removal
		 */
		async removeMember(teamFolderId, membershipId) {
			const response = await axios.delete(
				generateUrl(
					`/apps/keepiq/api/v1/team-folders/${teamFolderId}/members/${membershipId}`,
				),
			)
			await this.fetchTeamFolders()
			return response.data
		},

		/**
		 * Set a membership's permission grade (owner-only;
		 * folder-permission-grades §4.1).
		 *
		 * @param {string} teamFolderId The team folder id.
		 * @param {string} membershipId The membership row id.
		 * @param {string} grade 'read' | 'write'.
		 * @return {Promise<object>} The updated membership.
		 * @spec openspec/specs/folder-permission-grades/spec.md#requirement-team-folder-membership-carries-a-read-or-write-grade
		 * @spec openspec/specs/folder-permission-grades/spec.md#requirement-grade-changes-and-non-owner-writes-are-audited
		 */
		async setMemberGrade(teamFolderId, membershipId, grade) {
			const response = await axios.patch(
				generateUrl(
					`/apps/keepiq/api/v1/team-folders/${teamFolderId}/members/${membershipId}`,
				),
				{ grade },
			)
			await this.fetchTeamFolders()
			return response.data
		},

		/**
		 * Unshare a folder entirely (cascade-revokes all derived shares).
		 *
		 * @param {string} teamFolderId The team folder.
		 * @return {Promise<{revoked: number}>}
		 * @spec openspec/specs/team-folder-sharing/spec.md#requirement-inherited-access-on-add-revoked-on-removal
		 */
		async unshareFolder(teamFolderId) {
			const response = await axios.delete(
				generateUrl(`/apps/keepiq/api/v1/team-folders/${teamFolderId}`),
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
		 * @spec openspec/specs/team-folder-sharing/spec.md#requirement-share-a-folder-as-a-team-folder
		 * @spec openspec/specs/team-folder-sharing/spec.md#requirement-nested-subfolder-inheritance
		 */
		async reconcile(teamFolderId) {
			const response = await axios.get(
				generateUrl(
					`/apps/keepiq/api/v1/team-folders/${teamFolderId}/reconcile`,
				),
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
		 * @spec openspec/specs/team-folder-sharing/spec.md#requirement-membership-propagation-with-group-membership
		 */
		async approveJoin(teamFolderId, newMemberId) {
			const response = await axios.post(
				generateUrl(
					`/apps/keepiq/api/v1/team-folders/${teamFolderId}/approve-join`,
				),
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
		 * @spec openspec/specs/team-folder-sharing/spec.md#requirement-admin-offboarding
		 */
		async offboard(leavingUserId, successorUserId) {
			const response = await axios.post(
				generateUrl('/apps/keepiq/api/v1/team-folders/offboard'),
				{ leavingUserId, successorUserId },
			)
			return response.data
		},

		/**
		 * Cancel a running fan-out (the current chunk still completes; the
		 * idempotent server upsert makes the next reconcile resume safely).
		 *
		 * @return {void}
		 *
		 * @spec openspec/specs/team-folder-sharing/spec.md#requirement-inherited-access-on-add-revoked-on-removal
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
		 *
		 * @spec openspec/specs/encrypted-attachments/spec.md#scenario-sharing-re-wraps-the-key-not-the-blob
		 */
		async regrantAttachments(rows, certByUser) {
			const attachmentStore = useAttachmentStore()
			for (const row of rows) {
				const certificate = certByUser[row.targetUserId]
				if (!certificate) {
					continue
				}
				try {
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
		 * @spec openspec/specs/team-folder-sharing/spec.md#requirement-share-a-folder-as-a-team-folder
		 * @spec openspec/specs/team-folder-sharing/spec.md#requirement-inherited-access-on-add-revoked-on-removal
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

						const plain = await secretStore.fetchSecret(pair.secretId)
						plaintextCache[pair.secretId] = {
							key: plain.key ?? '',
							login: plain.login ?? '',
							additionalFields:
								typeof plain.additionalFields === 'object'
								&& plain.additionalFields !== null
									? JSON.stringify(plain.additionalFields)
									: (plain.additionalFields ?? ''),
						}
					}

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
						const response = await axios.post(
							generateUrl(
								`/apps/keepiq/api/v1/team-folders/${teamFolderId}/shares`,
							),
							{ shares: chunk },
						)
						created += response.data?.created ?? 0

						await this.regrantAttachments(
							response.data?.rows ?? [],
							certByUser,
						)
						this.fanOut.done += chunk.length
						chunk = []
					}
				}

				if (chunk.length > 0 && !this.fanOutCancelled) {
					const response = await axios.post(
						generateUrl(
							`/apps/keepiq/api/v1/team-folders/${teamFolderId}/shares`,
						),
						{ shares: chunk },
					)
					created += response.data?.created ?? 0
					await this.regrantAttachments(
						response.data?.rows ?? [],
						certByUser,
					)
					this.fanOut.done += chunk.length
				}

				return { created, cancelled: this.fanOutCancelled }
			} catch (e) {
				this.error =
					e?.response?.data?.message || e?.message || 'Fan-out failed'
				throw e
			} finally {
				this.fanOut.running = false
			}
		},

		/**
		 * Confirm waiting team folder members in the background
		 * (admin-auto-confirm-members D5). Asks the server which pairs this
		 * user may confirm, decrypts each needed secret once with the session
		 * key (the owner's source, or the member's own copy), encrypts it for
		 * each recipient and posts only ciphertext in chunks. A failure is
		 * logged and retried on the next run; it never blocks the vault.
		 *
		 * @return {Promise<{enabled: boolean, created: number, members: number}>}
		 * @spec openspec/changes/admin-auto-confirm-members/tasks.md#3.1
		 */
		async autoConfirm() {
			const secretStore = useSecretStore()
			const shareStore = useShareStore()
			const response = await axios.get(
				generateUrl('/apps/keepiq/api/v1/team-folders/pending-confirmations'),
			)
			const enabled = response.data?.enabled === true
			let created = 0
			const members = new Set()

			for (const folder of response.data?.folders ?? []) {
				const certByUser = Object.fromEntries(
					(folder.recipients ?? []).map((r) => [r.userId, r.certificate]),
				)
				const fieldsBySecret = {}
				let chunk = []

				const post = async () => {
					const result = await axios.post(
						generateUrl(`/apps/keepiq/api/v1/team-folders/${folder.teamFolderId}/shares`),
						{ shares: chunk },
					)
					created += result.data?.created ?? 0
					for (const row of result.data?.rows ?? []) {
						members.add(row.targetUserId)
					}
					await this.regrantAttachments(result.data?.rows ?? [], certByUser)
					chunk = []
				}

				for (const pair of folder.missing ?? []) {
					const certificate = certByUser[pair.userId]
					if (!certificate) {
						continue
					}

					// An owner reads the source; a member reads their own copy.
					const readId = pair.ownCopyId ?? pair.secretId
					if (!fieldsBySecret[readId]) {
						const raw = await axios.get(generateUrl(`/apps/keepiq/api/v1/secrets/${readId}`))
						fieldsBySecret[readId] = recipientFields(await secretStore.decryptSecret(raw.data))
					}

					const blob = await shareStore.encryptForRecipient(fieldsBySecret[readId], certificate)
					chunk.push({
						sourceSecretId: pair.secretId,
						targetUserId: pair.userId,
						encryptedKey: blob.key ?? '',
						encryptedLogin: blob.login ?? null,
						encryptedAdditionalFields: blob.additionalFields ?? null,
					})

					if (chunk.length >= FAN_OUT_CHUNK_SIZE) {
						await post()
					}
				}

				if (chunk.length > 0) {
					await post()
				}
			}

			this.lastAutoConfirm = { created, members: members.size, at: Date.now() }
			return { enabled, created, members: members.size }
		},

		/**
		 * Start the automatic confirmation after an unlock: run once now, and
		 * every 15 minutes while the switch is on and the vault stays unlocked.
		 * Never throws; a failed run is logged and the next one retries.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/admin-auto-confirm-members/tasks.md#3.2
		 */
		async startAutoConfirm() {
			this.stopAutoConfirm()
			const run = async () => {
				try {
					const result = await this.autoConfirm()
					if (result.members > 0) {
						// One quiet notice per run, never per secret. Loaded on
						// demand: @nextcloud/dialogs needs a window at import
						// time, and node-run specs import this store.
						const { showSuccess } = await import('@nextcloud/dialogs')
						showSuccess(n(
							'keepiq',
							'Gave %n new member access to a team folder.',
							'Gave %n new members access to a team folder.',
							result.members,
						))
					}
					return result
				} catch (e) {
					// Kept for the dialog; the next run retries.
					this.lastAutoConfirm = { created: 0, members: 0, at: Date.now(), error: e?.message || 'error' }
					return null
				}
			}

			const first = await run()
			// The switch is off: nothing to repeat until the next unlock.
			if (first !== null && first.enabled === false) {
				return
			}

			autoConfirmTimer = setInterval(run, AUTO_CONFIRM_INTERVAL_MS)
		},

		/**
		 * Stop the repeating confirmation (on lock).
		 *
		 * @return {void}
		 * @spec openspec/changes/admin-auto-confirm-members/tasks.md#3.2
		 */
		stopAutoConfirm() {
			if (autoConfirmTimer !== null) {
				clearInterval(autoConfirmTimer)
				autoConfirmTimer = null
			}
		},
	},
})
