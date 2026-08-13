/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Pinia store for user-to-user share targets.
 *
 * The store wraps `/api/v1/secrets/{secretId}/shares` (list + create) and
 * `/api/v1/shares/{id}` (revoke). It exposes a small helper for browser-side
 * RSA encryption so callers can pass a plaintext snapshot and a recipient
 * certificate without re-implementing the envelope; the helper imports the
 * recipient's public key and encrypts each field via `rsaEncrypt`.
 *
 * The plaintext NEVER leaves the browser — the helper returns the encrypted
 * blobs as a `{ fieldName: base64 }` map ready to POST to the recipient's
 * Secret copy endpoint and to record as a share target.
 *
 * @spec openspec/changes/implement-user-sharing/tasks.md#task-11.1
 */

import { defineStore } from 'pinia'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { importPublicKey, rsaEncrypt } from '../../crypto/index.js'

export const useShareStore = defineStore('share', {
	state: () => ({
		/** @type {Array<object>} The shares for the currently focused secret. */
		shares: [],
		/** @type {boolean} Whether a request is in flight. */
		loading: false,
		/** @type {string|null} The last error message. */
		error: null,
	}),

	getters: {
		/**
		 * Number of distinct recipients currently sharing the secret.
		 *
		 * @param {object} state The store state.
		 * @return {number}
		 */
		recipientCount: (state) => state.shares.length,
	},

	actions: {
		/**
		 * Hydrate the share list for a source secret.
		 *
		 * @param {string} secretId The source secret ID.
		 * @return {Promise<void>}
		 */
		async fetchShares(secretId) {
			this.loading = true
			this.error = null
			try {
				const response = await axios.get(
					generateUrl(`/apps/doriath/api/v1/secrets/${secretId}/shares`),
				)
				this.shares = response.data || []
			} catch (e) {
				this.error =
					e?.response?.data?.message
					|| e?.message
					|| 'Failed to load shares'
				throw e
			} finally {
				this.loading = false
			}
		},

		/**
		 * Encrypt a plaintext snapshot for a recipient.
		 *
		 * Mirrors what the server-side EncryptService would do for an internal
		 * application: imports the recipient's PEM certificate, then encrypts
		 * each field with RSA-OAEP-SHA256. The plaintext never leaves the
		 * browser; the return value is the `{ fieldName: base64 }` envelope.
		 *
		 * @param {object}              snapshot          Plaintext field map.
		 * @param {string}              publicCertificate The recipient's PEM certificate.
		 * @return {Promise<Record<string,string>>}
		 */
		async encryptForRecipient(snapshot, publicCertificate) {
			if (publicCertificate == null || publicCertificate === '') {
				throw new Error('Recipient has no active encryption suite')
			}
			const publicKey = await importPublicKey(publicCertificate)
			const out = {}
			for (const [field, value] of Object.entries(snapshot)) {
				if (value == null || value === '') {
					continue
				}
				out[field] = await rsaEncrypt(String(value), publicKey)
			}
			return out
		},

		/**
		 * Record a share target.
		 *
		 * Expects the caller to have already PUT the recipient's encrypted
		 * Secret copy through `useSecretStore`; this call only registers the
		 * link.
		 *
		 * @param {string}      secretId          The source secret ID.
		 * @param {string}      targetUserId      The recipient Nextcloud UID.
		 * @param {string}      recipientSecretId The recipient's Secret copy ID.
		 * @param {string|null} groupShareId      Optional group-share linkage.
		 * @return {Promise<object>}
		 */
		async createShare(
			secretId,
			targetUserId,
			recipientSecretId,
			groupShareId = null,
		) {
			this.loading = true
			this.error = null
			try {
				const response = await axios.post(
					generateUrl(`/apps/doriath/api/v1/secrets/${secretId}/shares`),
					{ targetUserId, recipientSecretId, groupShareId },
				)
				this.shares.push(response.data)
				return response.data
			} catch (e) {
				this.error =
					e?.response?.data?.message || e?.message || 'Failed to share'
				throw e
			} finally {
				this.loading = false
			}
		},

		/**
		 * The write context of a secret for the current user
		 * (folder-permission-grades §4).
		 *
		 * @param {string} secretId The secret (source or copy) id.
		 * @return {Promise<object>} { sourceSecretId, effectiveGrade, ownerCertificate, sourceUpdatedAt }.
		 */
		async fetchWriteContext(secretId) {
			const response = await axios.get(
				generateUrl(
					`/apps/doriath/api/v1/secrets/${secretId}/write-context`,
				),
			)
			return response.data
		},

		/**
		 * Write-grade member fan-out (folder-permission-grades §4.2):
		 * when the edited row is a recipient copy and the caller holds a
		 * `write` grade, re-encrypt the plaintext for the SOURCE row
		 * (owner certificate) and every recipient, then PUT the sync.
		 * No-op for owners/read grades.
		 *
		 * @param {string} editedSecretId The edited (copy) secret id.
		 * @param {object} plaintext New plaintext field map.
		 * @return {Promise<{updated: number}>}
		 */
		async syncAsTeamWriter(editedSecretId, plaintext) {
			const context = await this.fetchWriteContext(editedSecretId)
			if (
				context.effectiveGrade !== 'write'
				|| context.sourceSecretId === editedSecretId
			) {
				return { updated: 0 }
			}

			// Recipient rows of the SOURCE (write grade grants the list).
			const response = await axios.get(
				generateUrl(
					`/apps/doriath/api/v1/secrets/${context.sourceSecretId}/shares`,
				),
			)
			const shares = response.data || []

			const updates = []
			// The owner's SOURCE row itself, re-encrypted under the
			// owner's certificate.
			if (context.ownerCertificate) {
				const ownerBlob = await this.encryptForRecipient(
					plaintext,
					context.ownerCertificate,
				)
				updates.push({
					secretId: context.sourceSecretId,
					key: ownerBlob.key ?? null,
					login: ownerBlob.login ?? null,
					additionalFields: ownerBlob.additionalFields ?? null,
				})
			}
			for (const share of shares) {
				const certificate = share.recipientCertificate || share.certificate
				if (
					certificate == null
					|| certificate === ''
					|| share.secretId === editedSecretId
				) {
					// The writer's own copy was already updated by the
					// regular edit; suite-less recipients are skipped.
					continue
				}
				// eslint-disable-next-line no-await-in-loop
				const blob = await this.encryptForRecipient(plaintext, certificate)
				updates.push({
					secretId: share.secretId,
					key: blob.key ?? null,
					login: blob.login ?? null,
					additionalFields: blob.additionalFields ?? null,
				})
			}

			if (updates.length === 0) {
				return { updated: 0 }
			}

			const syncResponse = await axios.put(
				generateUrl(
					`/apps/doriath/api/v1/secrets/${context.sourceSecretId}/sync`,
				),
				{
					expectedUpdatedAt: context.sourceUpdatedAt ?? '',
					updates,
				},
			)
			return syncResponse.data
		},

		/**
		 * Revoke a share target (cascade-deletes the recipient's Secret copy
		 * server-side).
		 *
		 * @param {string} shareId The share-target row ID.
		 * @return {Promise<void>}
		 */
		async revokeShare(shareId) {
			this.loading = true
			this.error = null
			try {
				await axios.delete(
					generateUrl(`/apps/doriath/api/v1/shares/${shareId}`),
				)
				this.shares = this.shares.filter((s) => s.id !== shareId)
			} catch (e) {
				this.error =
					e?.response?.data?.message
					|| e?.message
					|| 'Failed to revoke share'
				throw e
			} finally {
				this.loading = false
			}
		},

		/**
		 * Create a batch of recipient share targets (group-share expansion).
		 *
		 * The caller supplies the already-encrypted Secret copies (one per
		 * member); this method records them and returns the server response
		 * for each.
		 *
		 * @param {string} secretId          The source secret ID.
		 * @param {Array<{targetUserId: string, recipientSecretId: string}>} recipients Recipient copies.
		 * @param {string} groupShareId      The group-share linkage ID.
		 * @return {Promise<Array<object>>}
		 */
		async createBatchShares(secretId, recipients, groupShareId) {
			const created = []
			for (const r of recipients) {
				// Sequential POST keeps the UI feedback predictable on slow
				// networks; total recipient count for a group is bounded by
				// the Nextcloud group size which is small in practice.
				// eslint-disable-next-line no-await-in-loop
				const row = await this.createShare(
					secretId,
					r.targetUserId,
					r.recipientSecretId,
					groupShareId,
				)
				created.push(row)
			}
			return created
		},

		/**
		 * Sync an updated plaintext to every recipient. Implements
		 * tasks §11.5 (browser-side encryption loop) + §11.6 (called by
		 * useSecretStore.updateSecret after a successful owner update if
		 * the secret has active shares).
		 *
		 * Flow:
		 *  1. Hydrate the current share list for the secret (if not yet
		 *     loaded).
		 *  2. For each recipient, fetch their active EncryptionSuite
		 *     certificate and RSA-encrypt the new plaintext for them.
		 *  3. PUT the batch to `/api/v1/secrets/{id}/sync` along with the
		 *     `expectedUpdatedAt` for optimistic-lock validation.
		 *
		 * The server side (ShareService::syncUpdate) does the atomic
		 * write inside a transaction, clears the `possiblyCompromisedAt`
		 * flag if previously set, and rejects stale locks with HTTP 409
		 * so the caller can re-encrypt.
		 *
		 * @param {string}              secretId          The source secret ID.
		 * @param {object}              plaintext         New plaintext field map
		 *   ({ key, login, additionalFields } — only fields that should be
		 *   re-encrypted for recipients).
		 * @param {string}              expectedUpdatedAt The owner's last-seen updatedAt (ISO).
		 * @return {Promise<{updated: number}>}
		 */
		async syncUpdate(secretId, plaintext, expectedUpdatedAt) {
			this.loading = true
			this.error = null
			try {
				if (this.shares.length === 0) {
					await this.fetchShares(secretId)
				}

				if (this.shares.length === 0) {
					// No recipients — nothing to sync. The UI uses this
					// as a fast-path skip in useSecretStore.updateSecret.
					return { updated: 0 }
				}

				// Build the per-recipient encrypted blob batch. The
				// recipient's PEM certificate is returned by the share
				// row (the server hydrates it from the active
				// EncryptionSuite of the recipient).
				const updates = []
				for (const share of this.shares) {
					const certificate =
						share.recipientCertificate || share.certificate
					if (certificate == null || certificate === '') {
						// Skip recipients whose suite was revoked while the
						// owner edited; the server-side cascade has already
						// cleaned them up but the UI list may be stale.
						continue
					}
					// eslint-disable-next-line no-await-in-loop
					const blob = await this.encryptForRecipient(
						plaintext,
						certificate,
					)
					updates.push({
						secretId: share.secretId,
						encryptedKey: blob.key ?? null,
						encryptedLogin: blob.login ?? null,
						encryptedAdditionalFields: blob.additionalFields ?? null,
					})
				}

				if (updates.length === 0) {
					return { updated: 0 }
				}

				const response = await axios.put(
					generateUrl(`/apps/doriath/api/v1/secrets/${secretId}/sync`),
					{
						expectedUpdatedAt,
						updates,
					},
				)
				return response.data ?? { updated: updates.length }
			} catch (e) {
				this.error =
					e?.response?.data?.message
					|| e?.message
					|| 'Failed to sync update'
				throw e
			} finally {
				this.loading = false
			}
		},

		/**
		 * Reset the store (used on secret detail unmount).
		 *
		 * @return {void}
		 */
		reset() {
			this.shares = []
			this.error = null
		},
	},
})
