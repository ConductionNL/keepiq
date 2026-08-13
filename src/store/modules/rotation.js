/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Pinia store for rotation & expiry (rotation-expiry-policies §7).
 *
 * Expiry is server-visible metadata only — no ciphertext is involved in
 * any call here. Batch-flag sends secret IDs ONLY (no breach verdicts,
 * scores, or digests ever leave the client).
 *
 * @spec openspec/specs/rotation-expiry-policies/spec.md#requirement-per-secret-expiry-without-ciphertext-change
 * @spec openspec/specs/rotation-expiry-policies/spec.md#requirement-expiry-policies-with-admin-default-and-user-override
 * @spec openspec/specs/rotation-expiry-policies/spec.md#requirement-rotate-after-breach-and-rotate-after-compromise-flagging
 * @spec openspec/specs/rotation-expiry-policies/spec.md#requirement-proven-mark-rotated-flow
 */

import { defineStore } from 'pinia'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export const useRotationStore = defineStore('rotation', {
	state: () => ({
		/** @type {Array<object>} The caller's open rotation flags. */
		flags: [],
		/** @type {Array<object>} The caller's applicable expiry policies. */
		policies: [],
		/** @type {boolean} Whether a request is in flight. */
		loading: false,
		/** @type {string|null} The last error message. */
		error: null,
	}),

	getters: {
		/**
		 * Open flags keyed by secret id for O(1) chip lookups.
		 *
		 * @param {object} state The store state.
		 * @return {object} Map of secretId -> flag.
		 */
		flagsBySecretId(state) {
			const map = {}
			for (const flag of state.flags) {
				map[flag.secretId] = flag
			}
			return map
		},
	},

	actions: {
		/**
		 * Load the caller's open rotation flags.
		 *
		 * @return {Promise<void>}
		 */
		async fetchFlags() {
			this.loading = true
			this.error = null
			try {
				const response = await axios.get(
					generateUrl('/apps/doriath/api/v1/rotation-flags'),
				)
				this.flags = response.data || []
			} catch (e) {
				this.error =
					e?.response?.data?.message
					|| e?.message
					|| 'Failed to load rotation flags'
				throw e
			} finally {
				this.loading = false
			}
		},

		/**
		 * Load the caller's applicable expiry policies.
		 *
		 * @return {Promise<void>}
		 */
		async fetchPolicies() {
			try {
				const response = await axios.get(
					generateUrl('/apps/doriath/api/v1/expiry-policies'),
				)
				this.policies = response.data || []
			} catch (e) {
				this.error =
					e?.response?.data?.message
					|| e?.message
					|| 'Failed to load policies'
				throw e
			}
		},

		/**
		 * Create or update a type/folder expiry policy.
		 *
		 * @param {object} policy The policy payload.
		 * @param {string} policy.scope The scope ('type'|'folder').
		 * @param {string} policy.scopeId The scoped type/folder id.
		 * @param {number|null} policy.maxAgeDays Max credential age in days.
		 * @param {Array<number>} policy.reminderDays Reminder thresholds.
		 * @return {Promise<object>} The saved policy.
		 */
		async upsertPolicy({ scope, scopeId, maxAgeDays, reminderDays }) {
			const response = await axios.post(
				generateUrl('/apps/doriath/api/v1/expiry-policies'),
				{ scope, scopeId, maxAgeDays, reminderDays },
			)
			await this.fetchPolicies()
			return response.data
		},

		/**
		 * Delete one of the caller's policies.
		 *
		 * @param {string} policyId The policy id.
		 * @return {Promise<void>}
		 */
		async deletePolicy(policyId) {
			await axios.delete(
				generateUrl(`/apps/doriath/api/v1/expiry-policies/${policyId}`),
			)
			this.policies = this.policies.filter((p) => p.id !== policyId)
		},

		/**
		 * Get a secret's stored + effective expiry.
		 *
		 * @param {string} secretId The secret id.
		 * @return {Promise<object>} { expiresAt, effectiveExpiry }.
		 */
		async getExpiry(secretId) {
			const response = await axios.get(
				generateUrl(`/apps/doriath/api/v1/secrets/${secretId}/expiry`),
			)
			return response.data
		},

		/**
		 * Set or clear a secret's expiry (owner-only; metadata only).
		 *
		 * @param {string} secretId The secret id.
		 * @param {string|null} expiresAt ISO expiry or null to clear.
		 * @return {Promise<object>} { secret, effectiveExpiry }.
		 */
		async setExpiry(secretId, expiresAt) {
			const response = await axios.put(
				generateUrl(`/apps/doriath/api/v1/secrets/${secretId}/expiry`),
				{ expiresAt },
			)
			return response.data
		},

		/**
		 * Flag secrets for rotation — IDs ONLY (client breach findings
		 * never send verdicts or digests).
		 *
		 * @param {Array<string>} secretIds The secret ids to flag.
		 * @return {Promise<number>} How many flags are now open.
		 */
		async flagSecrets(secretIds) {
			const response = await axios.post(
				generateUrl('/apps/doriath/api/v1/rotation-flags'),
				{ secretIds },
			)
			await this.fetchFlags()
			return response.data?.flagged ?? 0
		},

		/**
		 * Mark a flag rotated. Resolves only when the server proves the
		 * key advanced past its flag-time value; otherwise the caller is
		 * told rotation is still required.
		 *
		 * @param {string} flagId The flag id.
		 * @return {Promise<object>} { resolved, requiresRotation }.
		 */
		async markRotated(flagId) {
			const response = await axios.post(
				generateUrl(`/apps/doriath/api/v1/rotation-flags/${flagId}/rotated`),
			)
			if (response.data?.resolved === true) {
				this.flags = this.flags.filter((f) => f.id !== flagId)
			}
			return response.data
		},

		/**
		 * Dismiss a flag without rotation (audited).
		 *
		 * @param {string} flagId The flag id.
		 * @return {Promise<void>}
		 */
		async dismissFlag(flagId) {
			await axios.post(
				generateUrl(`/apps/doriath/api/v1/rotation-flags/${flagId}/dismiss`),
			)
			this.flags = this.flags.filter((f) => f.id !== flagId)
		},
	},
})
