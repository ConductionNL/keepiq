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

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { defineStore } from 'pinia'
import { importPublicKey, rsaEncrypt } from '../../crypto/index.js'

export const useSecretRequestStore = defineStore('secretRequest', {
	state: () => ({
		/** @type {Array<object>} Requests for the currently focused secret. */
		secretRequests: [],
		/** @type {object|null} The public payload of the request being filled. */
		publicRequest: null,
		/**
		 * @type {Array<object>} One application's requests, for an administrator.
		 *
		 * Kept apart from `secretRequests` on purpose. That collection means "requests
		 * I created"; this one means "requests this application created", and the two
		 * are read under different authority. Sharing one array would make a stale
		 * render from the wrong scope indistinguishable from a correct one.
		 */
		applicationRequests: [],
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
		/**
		 * Create a secret request.
		 *
		 * A FRESH request omits secretId and encryptionSuiteId: the server creates
		 * the placeholder Secret and derives the suite from it.
		 *
		 * @param {object} payload The request fields.
		 *
		 * @return {Promise<object>} The created request.
		 *
		 * @spec openspec/changes/request-first-secret-requests/specs/secret-requests/spec.md#requirement-create-secret-request
		 */
		async createRequest(payload) {
			this.loading = true
			this.error = null
			try {
				const response = await axios.post(
					generateUrl('/apps/doriath/api/v1/secret-requests'),
					{
						// A FRESH request omits secretId and encryptionSuiteId: the
						// server creates the placeholder Secret and derives the suite
						// from it, so the client has nothing to point at or look up.
						secretId: payload.secretId || '',
						encryptionSuiteId: payload.encryptionSuiteId || '',
						requestedFields: payload.requestedFields,
						isReRequest: payload.isReRequest === true,
						expiresAt: payload.expiresAt || null,
						name: payload.name || null,
						folderId: payload.folderId || null,
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
		 * Every secret request one application created (administrators only).
		 *
		 * The scope lives in the URL, not in a flag this store or the component
		 * decides: a non-administrator calling this receives 403 from the server.
		 * That matters because the same list component renders both a user's own
		 * requests and an application's — if the authority were a prop, the
		 * component would become the place an access decision is accidentally made.
		 *
		 * @param {string} applicationId The application whose requests to load.
		 *
		 * @return {Promise<Array<object>>} The application's requests.
		 *
		 * @spec openspec/changes/admin-application-request-visibility/specs/application-mgmt/spec.md#requirement-outstanding-application-requests-visible-to-administrators
		 */
		async fetchApplicationRequests(applicationId) {
			this.loading = true
			this.error = null
			try {
				const response = await axios.get(
					generateUrl(
						`/apps/doriath/api/v1/applications/${applicationId}/secret-requests`,
					),
				)
				this.applicationRequests = response.data || []
				return this.applicationRequests
			} catch (e) {
				this.error =
					e?.response?.data?.message
					|| e?.message
					|| 'Failed to load the application\'s requests'
				throw e
			} finally {
				this.loading = false
			}
		},

		/**
		 * Revoke one of an application's requests (administrators only).
		 *
		 * The application id stays in the path so the server can enforce that the
		 * request really is that application's, rather than trusting a request id
		 * on its own.
		 *
		 * @param {string} applicationId The owning application.
		 * @param {string} requestId The request to revoke.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/admin-application-request-visibility/specs/application-mgmt/spec.md#requirement-outstanding-application-requests-visible-to-administrators
		 */
		async revokeApplicationRequest(applicationId, requestId) {
			this.loading = true
			this.error = null
			try {
				await axios.delete(
					generateUrl(
						`/apps/doriath/api/v1/applications/${applicationId}/secret-requests/${requestId}`,
					),
				)
				this.applicationRequests = this.applicationRequests.filter(
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
		 * The server never sees a plaintext SECRET value: `key`, `login` and
		 * every additional member are encrypted with RSA-OAEP-SHA256 against
		 * the requester's certificate before the POST.
		 *
		 * `url` is the exception, and deliberately so — it is plaintext
		 * searchable metadata per the secrets spec, so it is submitted in its
		 * own `plainFields` bucket. Encrypting it would put ciphertext in a
		 * searchable column.
		 *
		 * @param {string}                  token  The opaque request token.
		 * @param {Record<string,string>}   fields The plaintext field map.
		 * @return {Promise<object>}
		 * @spec openspec/specs/secret-requests/spec.md#requirement-requestable-fields
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

			// The field model (secret-requests spec, Requestable Fields):
			//   key / login          -> their own ciphertext columns
			//   url                  -> PLAINTEXT metadata, searchable
			//   anything else        -> a member of the ONE encrypted
			//                           additionalFields blob
			//
			// `url` must not be encrypted: the column is searchable plaintext,
			// so ciphertext there breaks search and shows the owner base64.
			const ENCRYPTED_FIELDS = ['key', 'login']
			const PLAINTEXT_FIELDS = ['url']
			const ADDITIONAL_BLOB = 'additionalFields'

			const encryptedFields = {}
			const plainFields = {}
			const additionalMembers = {}

			for (const [name, value] of Object.entries(fields)) {
				if (value == null || value === '') {
					throw new Error(`Field "${name}" is required`)
				}

				if (PLAINTEXT_FIELDS.includes(name)) {
					plainFields[name] = String(value)
					continue
				}

				if (ENCRYPTED_FIELDS.includes(name)) {
					// eslint-disable-next-line no-await-in-loop
					encryptedFields[name] = await rsaEncrypt(
						String(value),
						publicKey,
					)
					continue
				}

				// Collected and encrypted together below: the server stores one
				// blob and cannot see inside it, so the members are assembled
				// here rather than sent individually.
				additionalMembers[name] = String(value)
			}

			if (Object.keys(additionalMembers).length > 0) {
				encryptedFields[ADDITIONAL_BLOB] = await rsaEncrypt(
					JSON.stringify(additionalMembers),
					publicKey,
				)
			}

			const response = await axios.post(
				generateUrl(
					`/apps/doriath/api/v1/public/secret-requests/${token}/fill`,
				),
				{ encryptedFields, plainFields },
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
