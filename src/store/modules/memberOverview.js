/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Pinia store for the admin member overview
 * (admin-member-overview-and-offboarding §3).
 *
 * Wraps `GET /api/v1/admin/members` (admin only, metadata only) and holds the
 * two prefill values a list row hands to other admin sections: the leaving
 * user for "Team offboarding" and the suite id for "Encryption suites". The
 * sections watch these values, so an administrator never types an id.
 *
 * @spec openspec/specs/admin-member-overview/spec.md#requirement-administrator-acts-on-a-member-row
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { defineStore } from 'pinia'

/** Rows per page in the Members section. */
export const MEMBER_PAGE_SIZE = 50

export const useMemberOverviewStore = defineStore('memberOverview', {
	state: () => ({
		/** @type {Array<object>} The current page of member rows. */
		members: [],
		/** @type {boolean} Whether a next page exists. */
		hasMore: false,
		/** @type {boolean} Whether a page is loading. */
		loading: false,
		/** @type {string|null} The last load error. */
		error: null,
		/** @type {string} Leaving user handed to the offboarding section. */
		offboardUserId: '',
		/** @type {string} Suite id handed to the encryption suites section. */
		revokeSuiteId: '',
	}),

	actions: {
		/**
		 * Load one page of the member overview.
		 *
		 * @param {object} query The filter.
		 * @param {string} [query.status] Vault status filter, '' for all.
		 * @param {string} [query.search] Search on user id or display name.
		 * @param {number} [query.offset] Rows to skip.
		 * @return {Promise<void>}
		 * @spec openspec/specs/admin-member-overview/spec.md#requirement-administrator-lists-vault-status-per-user
		 */
		async fetchMembers({ status = '', search = '', offset = 0 } = {}) {
			this.loading = true
			this.error = null
			try {
				const response = await axios.get(
					generateUrl('/apps/keepiq/api/v1/admin/members'),
					{ params: { status, search, limit: MEMBER_PAGE_SIZE, offset } },
				)
				this.members = response.data.results ?? []
				this.hasMore = response.data.hasMore === true
			} catch (e) {
				this.members = []
				this.hasMore = false
				this.error = e?.response?.data?.message || e?.message || 'error'
			} finally {
				this.loading = false
			}
		},

		/**
		 * Search users for a user picker. Never touches the list state.
		 *
		 * @param {string} search Search on user id or display name.
		 * @return {Promise<Array<{userId: string, displayName: string}>>}
		 * @spec openspec/specs/admin-member-overview/spec.md#requirement-administrator-acts-on-a-member-row
		 */
		async searchUsers(search) {
			const response = await axios.get(
				generateUrl('/apps/keepiq/api/v1/admin/members'),
				{ params: { search, limit: 20, offset: 0 } },
			)
			return (response.data.results ?? []).map((row) => ({
				userId: row.userId,
				displayName: row.displayName,
			}))
		},

		/**
		 * Hand a user to the team offboarding section.
		 *
		 * @param {string} userId The leaving user.
		 * @return {void}
		 * @spec openspec/specs/admin-member-overview/spec.md#requirement-administrator-acts-on-a-member-row
		 */
		prefillOffboarding(userId) {
			this.offboardUserId = userId
		},

		/**
		 * Hand an active suite id to the encryption suites section.
		 *
		 * @param {string} suiteId The suite to revoke.
		 * @return {void}
		 * @spec openspec/specs/admin-member-overview/spec.md#requirement-administrator-acts-on-a-member-row
		 */
		prefillSuiteRevocation(suiteId) {
			this.revokeSuiteId = suiteId
		},
	},
})
