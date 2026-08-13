/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Pinia store for the secret-request fill-in flow.
 *
 * The store covers BOTH sides of the protocol:
 *
 *   - **Owner / requester** uses `fetchRequests`, `createRequest`,
 *     `createReRequest`, and `revokeRequest` (all authenticated; the
 *     scaffold-level controller maps `revoke` to `decline`).
 *   - **Recipient browser** uses `fetchPublicRequest(token)` and
 *     `submitFill(token, plaintextFields)`. The submit helper performs
 *     the RSA-OAEP-SHA256 encryption client-side so plaintext NEVER hits
 *     the server.
 *
 * @spec openspec/changes/implement-secret-requests/tasks.md#task-7.1
 */

import { defineStore } from 'pinia'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { importPublicKey, rsaEncrypt } from '../../crypto/index.js'

export const useSecretRequestStore = defineStore('secretRequest', {
	state: () => ({
		/** @type {Array<object>} Requests for the currently focused secret. */
		secretRequests: [],
		/** @type {object|null} The public payload of the request being filled. */
		publicRequest: null,
		/** @type {boolean} Whether a request is in flight. */
		loading: false,
		/** @type {string|null} The last error message (or null). */
		error: null,
	}),

	getters: {
		/**
		 * Number of pending requests for the focused secret.
		 *
		 * @param {object} state The store state.
		 * @return {number}
		 */
		pendingCount: (state) =>
			state.secretRequests.filter((r) => r.status === 'pending').length,
	},

	actions: {
		/**
		 * Hydrate the requests created by the current user.
		 *
		 * @return {Promise<void>}
		 */
		async fetchRequests() {
			this.loading = true
			this.error = null
			try {
				const response = await axios.get(
					generateUrl('/apps/doriath/api/v1/secret-requests'),
				)
				this.secretRequests = response.data || []
			} catch (e) {
				this.error =
					e?.response?.data?.message
					|| e?.message
					|| 'Failed to load requests'
				throw e
			} finally {
				this.loading = false
			}
		},

		/**
		 * Create a new secret request.
		 *
		 * @param {object} payload The create payload.
		 * @param {string} payload.secretId          The Secret ID.
		 * @param {string} payload.encryptionSuiteId The recipient suite ID.
		 * @param {Array<string>} payload.requestedFields The field names to ask for.
		 * @param {boolean} [payload.isReRequest] Whether this is a re-request.
		 * @param {string|null} [payload.expiresAt] Optional ISO-8601 expiry.
		 * @return {Promise<object>}
		 */
		async createRequest(payload) {
			this.loading = true
			this.error = null
			try {
				const response = await axios.post(
					generateUrl('/apps/doriath/api/v1/secret-requests'),
					{
						secretId: payload.secretId,
						encryptionSuiteId: payload.encryptionSuiteId,
						requestedFields: payload.requestedFields,
						isReRequest: payload.isReRequest === true,
						expiresAt: payload.expiresAt || null,
					},
				)
				this.secretRequests.unshift(response.data)
				return response.data
			} catch (e) {
				this.error =
					e?.response?.data?.message
					|| e?.message
					|| 'Failed to create request'
				throw e
			} finally {
				this.loading = false
			}
		},

		/**
		 * Convenience wrapper for re-requests (existing secret being refreshed).
		 *
		 * @param {string}        secretId          The Secret ID.
		 * @param {string}        encryptionSuiteId The recipient suite ID.
		 * @param {Array<string>} requestedFields   The field names to ask for.
		 * @param {string|null}   [expiresAt]  Optional ISO-8601 expiry.
		 * @return {Promise<object>}
		 */
		async createReRequest(
			secretId,
			encryptionSuiteId,
			requestedFields,
			expiresAt = null,
		) {
			return this.createRequest({
				secretId,
				encryptionSuiteId,
				requestedFields,
				isReRequest: true,
				expiresAt,
			})
		},

		/**
		 * Revoke (decline) a pending request.
		 *
		 * @param {string} requestId The request ID.
		 * @return {Promise<void>}
		 */
		async revokeRequest(requestId) {
			this.loading = true
			this.error = null
			try {
				await axios.delete(
					generateUrl(`/apps/doriath/api/v1/secret-requests/${requestId}`),
				)
				this.secretRequests = this.secretRequests.filter(
					(r) => r.id !== requestId,
				)
			} catch (e) {
				this.error =
					e?.response?.data?.message
					|| e?.message
					|| 'Failed to revoke request'
				throw e
			} finally {
				this.loading = false
			}
		},

		/**
		 * Phase 1 (recipient): fetch the public metadata for a token.
		 *
		 * Populates `publicRequest` with `{ token, status, requested_fields,
		 * is_re_request, expires_at, public_certificate }`.
		 *
		 * @param {string} token The opaque request token.
		 * @return {Promise<object>}
		 */
		async fetchPublicRequest(token) {
			this.loading = true
			this.error = null
			try {
				const response = await axios.get(
					generateUrl(
						`/apps/doriath/api/v1/public/secret-requests/${token}`,
					),
				)
				this.publicRequest = response.data
				return response.data
			} catch (e) {
				this.error =
					e?.response?.data?.message
					|| e?.message
					|| 'Failed to load request'
				throw e
			} finally {
				this.loading = false
			}
		},

		/**
		 * Phase 2 (recipient): encrypt the plaintext field map and submit it.
		 *
		 * The server NEVER sees plaintext. Each value is encrypted with
		 * RSA-OAEP-SHA256 against the recipient's public certificate before
		 * the POST.
		 *
		 * @param {string}                  token  The opaque request token.
		 * @param {Record<string,string>}   fields The plaintext field map.
		 * @return {Promise<object>}
		 */
		async submitFill(token, fields) {
			if (this.publicRequest == null || this.publicRequest.token !== token) {
				await this.fetchPublicRequest(token)
			}
			const certificate = this.publicRequest?.public_certificate
			if (certificate == null || certificate === '') {
				throw new Error('Public certificate is missing for this request')
			}
			const publicKey = await importPublicKey(certificate)

			const encryptedFields = {}
			for (const [name, value] of Object.entries(fields)) {
				if (value == null || value === '') {
					throw new Error(`Field "${name}" is required`)
				}
				// eslint-disable-next-line no-await-in-loop
				encryptedFields[name] = await rsaEncrypt(String(value), publicKey)
			}

			const response = await axios.post(
				generateUrl(
					`/apps/doriath/api/v1/public/secret-requests/${token}/fill`,
				),
				{ encryptedFields },
			)
			return response.data
		},

		/**
		 * Reset the focused-secret slice of state.
		 *
		 * @return {void}
		 */
		reset() {
			this.secretRequests = []
			this.publicRequest = null
			this.error = null
		},
	},
})
