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
				this.error = e?.response?.data?.message || e?.message || 'Failed to load shares'
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
		async createShare(secretId, targetUserId, recipientSecretId, groupShareId = null) {
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
				this.error = e?.response?.data?.message || e?.message || 'Failed to share'
				throw e
			} finally {
				this.loading = false
			}
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
				await axios.delete(generateUrl(`/apps/doriath/api/v1/shares/${shareId}`))
				this.shares = this.shares.filter((s) => s.id !== shareId)
			} catch (e) {
				this.error = e?.response?.data?.message || e?.message || 'Failed to revoke share'
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
