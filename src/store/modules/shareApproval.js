/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { defineStore } from 'pinia'
import { useGroupShareStore } from './groupShare.js'

/** register-batch statuses that mean the recipient now holds a copy. */
const SHARED = ['created', 'exists']

/**
 * Pinia store for the owner's answer to a share request or to a new member
 * of a group share (#747).
 *
 * Both arrive as a notification. Approving needs this browser tab: the owner's
 * copy is decrypted here and encrypted for the one new recipient, exactly as
 * a direct or group share does it, and registered through `register-batch`.
 * The server never sees the value. Denying needs no key.
 *
 * @spec openspec/specs/user-sharing/spec.md#requirement-share-request-recipient-initiated
 * @spec openspec/specs/user-sharing/spec.md#requirement-new-group-member-owner-notification
 */
export const useShareApprovalStore = defineStore('shareApproval', {
	actions: {
		/**
		 * Encrypt the owner's secret for one user and register the copy.
		 *
		 * @param {string} secretId The owner's source secret.
		 * @param {string} userId The recipient.
		 * @param {string|null} groupShareId The group share the copy hangs on, if any.
		 * @return {Promise<string>} The register-batch status, or `no_suite`
		 *   when the recipient has no active encryption suite.
		 * @spec openspec/specs/user-sharing/spec.md#requirement-share-request-recipient-initiated
		 */
		async shareCopyWith(secretId, userId, groupShareId) {
			const lookup = await axios.post(
				generateUrl('/apps/keepiq/api/v1/shares/recipient-certificates'),
				{ userIds: [userId] },
			)
			const recipient = (lookup.data?.recipients ?? [])
				.find((row) => row?.userId === userId)
			if (recipient?.shareable !== true || !recipient.certificate) {
				return 'no_suite'
			}

			const rows = await useGroupShareStore().encryptForMembers(
				secretId,
				groupShareId,
				[{ userId, certificate: recipient.certificate }],
			)
			const response = await axios.post(
				generateUrl('/apps/keepiq/api/v1/shares/register-batch'),
				{ shares: rows },
			)
			return String(response.data?.items?.[0]?.status ?? 'not_registered')
		},

		/**
		 * Approve a share request: share the secret with the requested user,
		 * then confirm, which tells the requester.
		 *
		 * @param {{sourceSecretId: string, requesterId: string, targetUserId: string}} request The request.
		 * @return {Promise<string>} The register-batch status.
		 * @spec openspec/specs/user-sharing/spec.md#requirement-share-request-recipient-initiated
		 */
		async approveShareRequest(request) {
			const status = await this.shareCopyWith(
				request.sourceSecretId,
				request.targetUserId,
				null,
			)
			if (SHARED.includes(status)) {
				await axios.post(
					generateUrl('/apps/keepiq/api/v1/share-requests/approve'),
					request,
				)
			}
			return status
		},

		/**
		 * Deny a share request. The requester is told.
		 *
		 * @param {{sourceSecretId: string, requesterId: string, targetUserId: string}} request The request.
		 * @return {Promise<void>}
		 * @spec openspec/specs/user-sharing/spec.md#requirement-share-request-recipient-initiated
		 */
		async denyShareRequest(request) {
			await axios.post(
				generateUrl('/apps/keepiq/api/v1/share-requests/deny'),
				request,
			)
		},

		/**
		 * Approve a new group member: give them a copy linked to the group
		 * share, so leaving the group or revoking the group share removes it.
		 *
		 * @param {{groupShareId: string, newMemberId: string, secretId: string}} request The request.
		 * @return {Promise<string>} The register-batch status.
		 * @spec openspec/specs/user-sharing/spec.md#requirement-new-group-member-owner-notification
		 */
		async approveGroupMember(request) {
			return this.shareCopyWith(
				request.secretId,
				request.newMemberId,
				request.groupShareId,
			)
		},

		/**
		 * Deny a new group member. Nothing is shared; the group share stays.
		 *
		 * @param {{groupShareId: string, newMemberId: string}} request The request.
		 * @return {Promise<void>}
		 * @spec openspec/specs/user-sharing/spec.md#requirement-new-group-member-owner-notification
		 */
		async denyGroupMember(request) {
			await axios.post(
				generateUrl(
					`/apps/keepiq/api/v1/group-shares/${encodeURIComponent(request.groupShareId)}/deny-new-member`,
				),
				{ newMemberId: request.newMemberId },
			)
		},
	},
})

export { SHARED }
