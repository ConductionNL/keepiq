/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Pinia store for SecretDelegation rows.
 *
 * Backs the §12.5 DelegationManager UI and the share-flow authorization
 * check that consults whether a user is the active delegate before
 * letting them issue further shares. State is per-secret — the store is
 * reset between detail-view mounts.
 *
 * @spec openspec/changes/implement-user-sharing/tasks.md#11.2
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { defineStore } from 'pinia'

export const useDelegationStore = defineStore('delegation', {
	state: () => ({
		/** @type {Array<object>} Delegations for the currently focused secret. */
		delegations: [],
		/** @type {boolean} Whether a request is in flight. */
		loading: false,
		/** @type {string|null} The last error message. */
		error: null,
		/**
		 * Whether the CURRENT USER is in the vault_admin group. Null until
		 * asked. Group membership, not a per-secret verdict — the per-secret
		 * preconditions are enforced server-side on the write.
		 *
		 * @type {boolean|null}
		 */
		isVaultAdmin: null,
	}),

	getters: {
		/**
		 * Number of delegations currently displayed.
		 *
		 * @param {object} state The store state.
		 * @return {number}
		 */
		count: (state) => state.delegations.length,

		/**
		 * Whether the secret has any PERMANENT delegations. Permanent
		 * delegations cannot be reclaimed — the UI disables the reclaim
		 * button when only permanent rows remain.
		 *
		 * @param {object} state The store state.
		 * @return {boolean}
		 */
		hasPermanent: (state) =>
			state.delegations.some((row) => row.isPermanent === true),

		/**
		 * Whether the secret has any TEMPORARY delegations — drives the
		 * reclaim button's enabled state.
		 *
		 * @param {object} state The store state.
		 * @return {boolean}
		 */
		hasTemporary: (state) =>
			state.delegations.some((row) => row.isPermanent === false),
	},

	actions: {
		/**
		 * Hydrate the delegation list for a secret.
		 *
		 * @param {string} secretId The Secret ID
		 * @return {Promise<void>}
		 */
		async fetchDelegations(secretId) {
			this.loading = true
			this.error = null
			try {
				const response = await axios.get(
					generateUrl(
						`/apps/doriath/api/v1/secrets/${secretId}/delegations`,
					),
				)
				this.delegations = response.data || []
			} catch (e) {
				this.error =
					e?.response?.data?.message
					|| e?.message
					|| 'Failed to load delegations'
				throw e
			} finally {
				this.loading = false
			}
		},

		/**
		 * Create a temporary delegation.
		 *
		 * @param {string} secretId    The Secret ID
		 * @param {string} delegatedTo The Nextcloud UID of the delegate
		 * @return {Promise<object>} The created delegation
		 * @spec openspec/specs/user-sharing/spec.md#requirement-ownership-delegation
		 */
		async createDelegation(secretId, delegatedTo) {
			this.loading = true
			this.error = null
			try {
				const response = await axios.post(
					generateUrl(
						`/apps/doriath/api/v1/secrets/${secretId}/delegations`,
					),
					{ delegatedTo },
				)
				this.delegations.push(response.data)
				return response.data
			} catch (e) {
				this.error =
					e?.response?.data?.message || e?.message || 'Failed to delegate'
				throw e
			} finally {
				this.loading = false
			}
		},

		/**
		 * Learn whether the current user may be offered the takeover.
		 *
		 * Kept out of `fetchDelegations`: that call answers only to a
		 * secret's OWNER, and the whole point of this flag is to decide what
		 * to show someone who is NOT the owner.
		 *
		 * A failure leaves the flag false — the takeover is never offered
		 * because a request failed.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/user-sharing/spec.md#requirement-ownership-delegation
		 */
		async fetchCapabilities() {
			try {
				const response = await axios.get(
					generateUrl('/apps/doriath/api/v1/delegations/capabilities'),
				)
				this.isVaultAdmin = response.data?.isVaultAdmin === true
			} catch (e) {
				this.isVaultAdmin = false
			}
		},

		/**
		 * Take over a secret as a vault administrator (the "power grab").
		 *
		 * Distinct from `createDelegation`: no delegate is named, because the
		 * server always promotes the CALLER. Sending a delegate here would
		 * suggest an admin can hand a secret to a third party, which the spec
		 * does not allow — the admin must already hold a share, and the
		 * handover promotes their own copy.
		 *
		 * @param {string} secretId The Secret ID
		 * @return {Promise<object>} The created delegation
		 * @spec openspec/specs/user-sharing/spec.md#requirement-ownership-delegation
		 */
		async adminHandover(secretId) {
			this.loading = true
			this.error = null
			try {
				const response = await axios.post(
					generateUrl(
						`/apps/doriath/api/v1/secrets/${secretId}/delegations/handover`,
					),
				)
				this.delegations.push(response.data)
				return response.data
			} catch (e) {
				this.error =
					e?.response?.data?.message
					|| e?.message
					|| 'Failed to take over the secret'
				throw e
			} finally {
				this.loading = false
			}
		},

		/**
		 * Reclaim every temporary delegation for a secret.
		 *
		 * @param {string} secretId The Secret ID
		 * @return {Promise<number>} Removed count
		 */
		async reclaimDelegation(secretId) {
			this.loading = true
			this.error = null
			try {
				const response = await axios.post(
					generateUrl(
						`/apps/doriath/api/v1/secrets/${secretId}/delegations/reclaim`,
					),
				)
				// Drop temporary rows locally to match the server-side
				// behaviour without a refetch.
				this.delegations = this.delegations.filter(
					(row) => row.isPermanent === true,
				)
				return response.data?.removed ?? 0
			} catch (e) {
				this.error =
					e?.response?.data?.message || e?.message || 'Failed to reclaim'
				throw e
			} finally {
				this.loading = false
			}
		},

		/**
		 * Reset the store between detail-view mounts.
		 *
		 * @return {void}
		 * @spec openspec/specs/user-sharing/spec.md#requirement-share-visibility
		 */
		reset() {
			this.delegations = []
			this.error = null
			// `isVaultAdmin` is deliberately NOT reset: it describes the
			// signed-in user, not the focused secret, so clearing it between
			// detail mounts would re-fetch it on every navigation.
		},
	},
})
