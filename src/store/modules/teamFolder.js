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
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { defineStore } from 'pinia'
import { copyCiphertext, recipientFields } from '../../services/recipientCopy.js'
import { useAttachmentStore } from './attachment.js'
import { useSecretStore } from './secret.js'
import { useShareStore } from './share.js'

/** Number of (secret × recipient) rows per registration POST. */
const FAN_OUT_CHUNK_SIZE = 20

/** The reason the server skips a pair whose copy would lack the source's one-time code or passkeys. */
const COPY_FIELDS_MISMATCH = 'copy_fields_mismatch'

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
 * One posted recipient copy: the pair it is for and the ciphertext
 * encrypted for that recipient.
 *
 * @param {{secretId: string, userId: string}} pair The source secret and recipient.
 * @param {object} blob The fields encrypted for the recipient.
 * @return {object} The share row the server stores.
 */
function copyRow(pair, blob) {
	return {
		sourceSecretId: pair.secretId,
		targetUserId: pair.userId,
		...copyCiphertext(blob),
	}
}

/**
 * Note the secrets the server refused a copy of because the copy would lose
 * a one-time code or passkeys, by name where this browser knows it.
 *
 * @param {Set<string>} refused The refused names, added to.
 * @param {object} refs The source secrets by id.
 * @param {object|undefined} data The server answer.
 * @return {void}
 */
function noteRefused(refused, refs, data) {
	for (const entry of data?.skipped ?? []) {
		if (entry.reason === COPY_FIELDS_MISMATCH) {
			refused.add(refs[entry.sourceSecretId]?.name ?? entry.sourceSecretId)
		}
	}
}

/**
 * The missing pairs of a reconcile, for one member only when asked.
 *
 * @param {object} state The reconcile state.
 * @param {string|null} onlyUserId The one member, or null for every member.
 * @return {Array<object>} The pairs to fan out.
 */
function pendingPairs(state, onlyUserId) {
	return (state.missing ?? []).filter(
		(pair) => onlyUserId === null || pair.userId === onlyUserId,
	)
}

/**
 * The message a failed fan-out reports: the server's, the error's, or a
 * generic one.
 *
 * @param {Error|object} e The failure.
 * @return {string} The message.
 */
function fanOutError(e) {
	return e?.response?.data?.message || e?.message || 'Fan-out failed'
}

/**
 * Post one chunk of copies, note what the server refused and re-grant the
 * attachments of the rows it wrote.
 *
 * @param {object} store The team folder store (for regrantAttachments).
 * @param {string} teamFolderId The team folder.
 * @param {Array<object>} chunk The copy rows.
 * @param {object} context The fan-out context.
 * @param {object} context.certByUser Recipient certificates by user id.
 * @param {object} context.refs The source secrets by id.
 * @param {Set<string>} context.refused Refused source names, added to.
 * @return {Promise<number>} How many copies the server created.
 */
async function postFanOutChunk(store, teamFolderId, chunk, { certByUser, refs, refused }) {
	const response = await axios.post(
		generateUrl(`/apps/keepiq/api/v1/team-folders/${teamFolderId}/shares`),
		{ shares: chunk },
	)
	const created = response.data?.created ?? 0
	noteRefused(refused, refs, response.data)
	await store.regrantAttachments(response.data?.rows ?? [], certByUser)
	return created
}

/**
 * Encrypt one missing pair's copy for its recipient. Null when the pair is
 * passed over: the recipient has no certificate, or this user holds no copy
 * of the source (then named in `skipped`).
 *
 * @param {object} pair The missing pair.
 * @param {object} context The fan-out context.
 * @param {object} context.refs The source secrets by id.
 * @param {object} context.certByUser Recipient certificates by user id.
 * @param {object} context.plaintextCache Recipient fields by source id, filled.
 * @param {Set<string>} context.skipped Sources without a readable copy, added to.
 * @param {object} context.secretStore The secret store.
 * @param {object} context.shareStore The share store.
 * @return {Promise<object|null>} The copy row, or null.
 */
async function fanOutCopy(pair, { refs, certByUser, plaintextCache, skipped, secretStore, shareStore }) {
	const certificate = certByUser[pair.userId]
	if (!certificate) {
		return null
	}

	// The copy this browser decrypts: the owner's own row, or a manager's
	// recipient copy (or none).
	const ref = refs[pair.secretId]
	const readId = ref && 'copyId' in ref ? ref.copyId : pair.secretId
	if (!readId) {
		skipped.add(ref?.name ?? pair.secretId)
		return null
	}

	if (!plaintextCache[pair.secretId]) {
		// fetchSecret decrypts with the session CryptoKey and returns the
		// PLAINTEXT secret — do not decrypt twice.
		const plain = await secretStore.fetchSecret(readId)
		plaintextCache[pair.secretId] = recipientFields(plain)
	}

	const blob = await shareStore.encryptForRecipient(
		plaintextCache[pair.secretId],
		certificate,
	)
	return copyRow(pair, blob)
}

/**
 * Confirm one team folder's waiting pairs: decrypt each needed secret once
 * (the owner's source, or the member's own copy), encrypt it for each
 * recipient with a certificate and post only ciphertext, in chunks.
 *
 * @param {object} store The team folder store (for regrantAttachments).
 * @param {object} folder The folder from the pending-confirmations answer.
 * @param {object} stores The secret and share stores.
 * @param {object} stores.secretStore The secret store.
 * @param {object} stores.shareStore The share store.
 * @param {{created: number, refused: number, members: Set<string>}} totals Running totals, updated.
 * @return {Promise<void>}
 */
async function confirmFolder(store, folder, { secretStore, shareStore }, totals) {
	const certByUser = Object.fromEntries(
		(folder.recipients ?? []).map((r) => [r.userId, r.certificate]),
	)
	const fieldsBySecret = {}
	let chunk = []

	const post = async () => {
		const result = await axios.post(
			generateUrl(
				`/apps/keepiq/api/v1/team-folders/${folder.teamFolderId}/shares`,
			),
			{ shares: chunk },
		)
		totals.created += result.data?.created ?? 0
		totals.refused += (result.data?.skipped ?? []).filter(
			(entry) => entry.reason === COPY_FIELDS_MISMATCH,
		).length
		for (const row of result.data?.rows ?? []) {
			totals.members.add(row.targetUserId)
		}
		await store.regrantAttachments(result.data?.rows ?? [], certByUser)
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
			const raw = await axios.get(
				generateUrl(`/apps/keepiq/api/v1/secrets/${readId}`),
			)
			fieldsBySecret[readId] = recipientFields(
				await secretStore.decryptSecret(raw.data),
			)
		}

		const blob = await shareStore.encryptForRecipient(
			fieldsBySecret[readId],
			certificate,
		)
		chunk.push(copyRow(pair, blob))

		if (chunk.length >= FAN_OUT_CHUNK_SIZE) {
			await post()
		}
	}

	if (chunk.length > 0) {
		await post()
	}
}

export const useTeamFolderStore = defineStore('teamFolder', {
	state: () => ({
		/** @type {{created: number, members: number, refused: number, at: number}|null} The last automatic confirmation run. */
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
			state.owned.find((tf) => tf.folderId === folderId)
			?? state.memberOf.find(
				(tf) => tf.folderId === folderId && tf.grade === 'manage',
			)
			?? null,

		/**
		 * The team folders the current user manages without owning them
		 * (sharing-team-folder-manager-role D5).
		 *
		 * @param {object} state The store state.
		 * @return {Array<object>}
		 */
		managed: (state) => state.memberOf.filter((tf) => tf.grade === 'manage'),
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
		 * @param {{useOnly: boolean, expiresAt: string|null}} [restriction] Use-only (read grade) and end date.
		 * @return {Promise<object>}
		 * @spec openspec/specs/team-folder-sharing/spec.md#requirement-share-a-folder-as-a-team-folder
		 * @spec openspec/specs/team-folder-sharing/spec.md#requirement-nested-subfolder-inheritance
		 */
		async addMember(teamFolderId, memberType, memberId, restriction = {}) {
			const response = await axios.post(
				generateUrl(
					`/apps/keepiq/api/v1/team-folders/${teamFolderId}/members`,
				),
				{ memberType, memberId, ...restriction },
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
		 * @spec openspec/specs/folder-permission-grades/spec.md#requirement-team-folder-membership-carries-a-read-write-or-manage-grade
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
		 * Approve one waiting member (keepiq#747): the server checks that the
		 * caller owns the team folder and that the member is covered by its
		 * membership, then only that member's copies are encrypted and shared.
		 * Everyone else keeps waiting.
		 *
		 * @param {string} teamFolderId The team folder.
		 * @param {string} userId The member to approve.
		 * @return {Promise<{created: number, cancelled: boolean}>}
		 * @spec openspec/specs/team-folder-sharing/spec.md#requirement-approve-one-waiting-member
		 */
		async approveMember(teamFolderId, userId) {
			await this.approveJoin(teamFolderId, userId)
			return this.runFanOut(teamFolderId, { onlyUserId: userId })
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
					await attachmentStore.regrantForRecipient({
						sourceSecretId: row.sourceSecretId,
						copySecretId: row.recipientSecretId,
						recipientId: row.targetUserId,
						recipientCertificate: certificate,
					})
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
		 * A manager fans out from its OWN recipient copies: reconcile names
		 * the caller's copy of each folder secret (`copyId`), and a secret
		 * the caller holds no copy of is skipped and reported, never faked
		 * (sharing-team-folder-manager-role D3).
		 *
		 * @param {string} teamFolderId The team folder to fan out.
		 * @param {object} [options] Options.
		 * @param {string} [options.onlyUserId] Fan out to this member only
		 *   (a per-member approval, keepiq#747); every member when omitted.
		 * @return {Promise<{created: number, cancelled: boolean, skipped: Array<string>, refused: Array<string>}>}
		 *   `skipped` holds the secrets this user holds no copy of, `refused` those
		 *   the server would not copy because it would lack their one-time code or passkeys.
		 * @spec openspec/specs/team-folder-sharing/spec.md#requirement-share-a-folder-as-a-team-folder
		 * @spec openspec/specs/team-folder-sharing/spec.md#requirement-inherited-access-on-add-revoked-on-removal
		 */
		async runFanOut(teamFolderId, { onlyUserId = null } = {}) {
			const secretStore = useSecretStore()
			const shareStore = useShareStore()

			this.fanOutCancelled = false
			this.fanOut = { total: 0, done: 0, running: true }

			try {
				const state = await this.reconcile(teamFolderId)
				const missing = pendingPairs(state, onlyUserId)
				this.fanOut.total = missing.length
				if (missing.length === 0) {
					return { created: 0, cancelled: false, skipped: [], refused: [] }
				}

				const ctx = {
					// The copy this browser decrypts for each source secret: the
					// owner's own row, or a manager's recipient copy (or none).
					refs: Object.fromEntries(
						(state.secrets ?? []).map((ref) => [ref.id, ref]),
					),
					certByUser: Object.fromEntries(
						(state.recipients ?? []).map((r) => [r.userId, r.certificate]),
					),
					// Decrypt each distinct source secret once, not per recipient.
					plaintextCache: {},
					skipped: new Set(),
					// Secrets the server refused a copy of, as `skipped` in its answer.
					refused: new Set(),
					secretStore,
					shareStore,
				}
				let created = 0
				let chunk = []
				// Post one chunk of copies, count what the server created and
				// re-grant the attachments of the rows it wrote.
				const flush = async () => {
					created += await postFanOutChunk(this, teamFolderId, chunk, ctx)
					this.fanOut.done += chunk.length
				}

				for (const pair of missing) {
					if (this.fanOutCancelled) {
						break
					}

					const row = await fanOutCopy(pair, ctx)
					if (row === null) {
						this.fanOut.done++
						continue
					}
					chunk.push(row)

					if (chunk.length >= FAN_OUT_CHUNK_SIZE) {
						await flush()
						chunk = []
					}
				}

				if (chunk.length > 0 && !this.fanOutCancelled) {
					await flush()
				}

				return {
					created,
					cancelled: this.fanOutCancelled,
					skipped: [...ctx.skipped],
					refused: [...ctx.refused],
				}
			} catch (e) {
				this.error = fanOutError(e)
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
		 * @return {Promise<{enabled: boolean, created: number, members: number, refused: number}>}
		 *   `refused` counts the pairs the server would not copy because it would lack the
		 *   secret's one-time code or passkeys.
		 * @spec openspec/specs/team-folder-auto-confirm/spec.md#requirement-an-unlocked-confirmers-browser-confirms-without-a-click
		 */
		async autoConfirm() {
			const secretStore = useSecretStore()
			const shareStore = useShareStore()
			const response = await axios.get(
				generateUrl(
					'/apps/keepiq/api/v1/team-folders/pending-confirmations',
				),
			)
			const enabled = response.data?.enabled === true

			const totals = { created: 0, refused: 0, members: new Set() }

			for (const folder of response.data?.folders ?? []) {
				await confirmFolder(this, folder, { secretStore, shareStore }, totals)
			}

			const { created, refused, members } = totals
			this.lastAutoConfirm = {
				created,
				members: members.size,
				refused,
				at: Date.now(),
			}
			return { enabled, created, members: members.size, refused }
		},

		/**
		 * Start the automatic confirmation after an unlock: run once now, and
		 * every 15 minutes while the switch is on and the vault stays unlocked.
		 * Never throws; a failed run is logged and the next one retries.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/team-folder-auto-confirm/spec.md#requirement-an-unlocked-confirmers-browser-confirms-without-a-click
		 */
		async startAutoConfirm() {
			this.stopAutoConfirm()
			// A refused pair is retried on every run: say so once per unlock, not every 15 minutes.
			let refusedNoticed = false
			const run = async () => {
				try {
					const result = await this.autoConfirm()
					if (result.refused > 0 && refusedNoticed === false) {
						refusedNoticed = true
						const { showWarning } = await import('@nextcloud/dialogs')
						showWarning(
							t(
								'keepiq',
								'A new team folder member did not get a login that holds a one-time code or passkeys, which this version of Keepiq cannot copy yet.',
							),
						)
					}
					if (result.members > 0) {
						// One quiet notice per run, never per secret. Loaded on
						// demand: @nextcloud/dialogs needs a window at import
						// time, and node-run specs import this store.
						const { showSuccess } = await import('@nextcloud/dialogs')
						showSuccess(
							n(
								'keepiq',
								'Gave %n new member access to a team folder.',
								'Gave %n new members access to a team folder.',
								result.members,
							),
						)
					}
					return result
				} catch (e) {
					// Kept for the dialog; the next run retries.
					this.lastAutoConfirm = {
						created: 0,
						members: 0,
						at: Date.now(),
						error: e?.message || 'error',
					}
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
		 * @spec openspec/specs/team-folder-auto-confirm/spec.md#requirement-an-unlocked-confirmers-browser-confirms-without-a-click
		 */
		stopAutoConfirm() {
			if (autoConfirmTimer !== null) {
				clearInterval(autoConfirmTimer)
				autoConfirmTimer = null
			}
		},
	},
})
