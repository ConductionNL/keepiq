// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { defineStore } from 'pinia'

/**
 * Secrets that users of partner organisations shared with the current user
 * (sharing-federated-recipients D4). Accepting asks this server to pull the
 * ciphertext from the sender and keep a read-only copy in the vault; the
 * browser never sees anything here but names and states.
 */
export const useFederatedInboundStore = defineStore('federatedInbound', {
	state: () => ({
		/** @type {Array<object>} The user's inbound shares, newest first. */
		shares: [],
		/** @type {boolean} Whether the list is loading. */
		loading: false,
		/** @type {string|null} The id of the share an action is running on. */
		busyId: null,
		/** @type {string|null} The last error, for the view to show. */
		error: null,
	}),

	getters: {
		/**
		 * The shares still waiting for an answer.
		 *
		 * @param {object} state The store state.
		 * @return {Array<object>}
		 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#scenario-bob-accepts-a-shared-login
		 */
		pending: (state) => state.shares.filter((share) => share.status === 'pending'),
	},

	actions: {
		/**
		 * Load the inbound shares.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#scenario-bob-accepts-a-shared-login
		 */
		async fetch() {
			this.loading = true
			this.error = null
			try {
				const response = await axios.get(
					generateUrl('/apps/keepiq/api/v1/federation/incoming'),
				)
				this.shares = Array.isArray(response.data) ? response.data : []
			} catch (e) {
				this.error = e?.response?.data?.message || e?.message || 'load_failed'
			} finally {
				this.loading = false
			}
		},

		/**
		 * Accept or decline one share, and put the answer in the list.
		 *
		 * @param {string} id The inbound share id.
		 * @param {'accept'|'decline'} action What to do.
		 * @return {Promise<object>} The updated share.
		 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#scenario-bob-accepts-a-shared-login
		 */
		async answer(id, action) {
			this.busyId = id
			this.error = null
			try {
				const response = await axios.post(
					generateUrl(`/apps/keepiq/api/v1/federation/incoming/${id}/${action}`),
				)
				this.shares = this.shares.map((share) => (share.id === id ? response.data : share))
				return response.data
			} catch (e) {
				this.error = e?.response?.data?.message || e?.message || 'answer_failed'
				throw e
			} finally {
				this.busyId = null
			}
		},
	},
})
