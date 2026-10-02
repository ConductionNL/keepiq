<!-- @visual exclude Reached only from a notification for one pending request, with ids in the query; it needs two vault users and a pending share request, which no e2e fixture has yet. Behaviour is covered by tests/views/ShareApprovalView.spec.js and KeepiqNotifierTest; the live check is listed in the PR. -->
<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  The owner's answer to a share request or a new group member (#747).

  The Approve action on the notification opens this page. Approving encrypts
  the owner's copy in this tab for the one new recipient, so the page sits
  behind the vault lock like every other vault page. The ids come from the
  notification link; the server checks ownership on every call.

  @spec openspec/specs/user-sharing/spec.md#requirement-share-request-recipient-initiated
  @spec openspec/specs/user-sharing/spec.md#requirement-new-group-member-owner-notification
-->
<template>
	<div class="share-approval" data-testid="share-approval-view">
		<h2 class="share-approval__title">
			{{ t('keepiq', 'Approve a share') }}
		</h2>

		<NcNoteCard
			v-if="!request"
			type="error"
			data-testid="share-approval-invalid">
			{{
				t(
					'keepiq',
					'This approval link is incomplete. Open it again from the notification.',
				)
			}}
		</NcNoteCard>

		<template v-else>
			<p
				class="share-approval__question"
				data-testid="share-approval-question">
				{{ question }}
			</p>

			<NcNoteCard
				v-if="outcome"
				:type="outcome.type"
				data-testid="share-approval-outcome">
				{{ outcome.text }}
			</NcNoteCard>

			<div v-if="!done" class="share-approval__actions">
				<NcButton
					variant="primary"
					:disabled="busy"
					data-testid="share-approval-approve"
					@click="approve">
					{{ t('keepiq', 'Approve') }}
				</NcButton>
				<NcButton
					:disabled="busy"
					data-testid="share-approval-deny"
					@click="deny">
					{{ t('keepiq', 'Deny') }}
				</NcButton>
			</div>
		</template>
	</div>
</template>

<script>
import { NcButton, NcNoteCard } from '@nextcloud/vue'
import { SHARED, useShareApprovalStore } from '../store/modules/shareApproval.js'

/**
 * Read one non-empty string query parameter.
 *
 * @param {object} query The route query.
 * @param {string} key The parameter name.
 * @return {string} The value, or '' when absent or not a string.
 * @spec openspec/specs/user-sharing/spec.md#requirement-share-request-recipient-initiated
 */
function queryString(query, key) {
	const value = query?.[key]
	return typeof value === 'string' ? value : ''
}

export default {
	name: 'ShareApprovalView',

	components: { NcButton, NcNoteCard },

	data() {
		return {
			busy: false,
			done: false,
			outcome: null,
		}
	},

	computed: {
		/**
		 * The request this page answers, from the notification link, or null
		 * when the link does not name one completely.
		 *
		 * @return {object|null}
		 * @spec openspec/specs/user-sharing/spec.md#requirement-share-request-recipient-initiated
		 */
		request() {
			const kind = this.$route?.params?.kind
			const query = this.$route?.query ?? {}
			if (kind === 'share-request') {
				const request = {
					sourceSecretId: queryString(query, 'sourceSecretId'),
					requesterId: queryString(query, 'requesterId'),
					targetUserId: queryString(query, 'targetUserId'),
				}
				return Object.values(request).includes('')
					? null
					: { kind, ...request }
			}
			if (kind === 'group-member') {
				const request = {
					groupShareId: queryString(query, 'groupShareId'),
					newMemberId: queryString(query, 'newMemberId'),
					secretId: queryString(query, 'secretId'),
				}
				return Object.values(request).includes('')
					? null
					: { kind, ...request }
			}
			return null
		},

		/**
		 * The question the owner answers.
		 *
		 * @return {string}
		 * @spec openspec/specs/user-sharing/spec.md#requirement-share-request-recipient-initiated
		 */
		question() {
			if (this.request?.kind === 'group-member') {
				return this.t(
					'keepiq',
					'{user} joined a group you share a secret with. Share the secret with them too?',
					{ user: this.request.newMemberId },
				)
			}
			return this.t(
				'keepiq',
				'{requester} asks you to share a secret with {user}.',
				{
					requester: this.request?.requesterId ?? '',
					user: this.request?.targetUserId ?? '',
				},
			)
		},
	},

	methods: {
		/**
		 * Share the secret with the new recipient.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/user-sharing/spec.md#requirement-share-request-recipient-initiated
		 */
		async approve() {
			const store = useShareApprovalStore()
			const { kind, ...request } = this.request
			this.busy = true
			this.outcome = null
			try {
				const status =
					kind === 'group-member'
						? await store.approveGroupMember(request)
						: await store.approveShareRequest(request)
				if (SHARED.includes(status)) {
					this.done = true
					this.outcome = {
						type: 'success',
						text: this.t(
							'keepiq',
							'Shared. The recipient can now open the secret.',
						),
					}
				} else if (status === 'no_suite') {
					this.outcome = {
						type: 'warning',
						text: this.t(
							'keepiq',
							'The recipient has not set up Keepiq yet, so nothing was shared. Try again once they have.',
						),
					}
				} else {
					this.outcome = {
						type: 'error',
						text: this.t(
							'keepiq',
							'Could not share the secret. Only its owner can approve this.',
						),
					}
				}
			} catch {
				this.outcome = {
					type: 'error',
					text: this.t('keepiq', 'Could not share the secret. Try again.'),
				}
			} finally {
				this.busy = false
			}
		},

		/**
		 * Decline. Nothing is shared.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/user-sharing/spec.md#requirement-share-request-recipient-initiated
		 */
		async deny() {
			const store = useShareApprovalStore()
			const { kind, ...request } = this.request
			this.busy = true
			this.outcome = null
			try {
				if (kind === 'group-member') {
					await store.denyGroupMember(request)
				} else {
					await store.denyShareRequest(request)
				}
				this.done = true
				this.outcome = {
					type: 'info',
					text: this.t('keepiq', 'Denied. Nothing was shared.'),
				}
			} catch {
				this.outcome = {
					type: 'error',
					text: this.t('keepiq', 'Could not deny the request. Try again.'),
				}
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.share-approval {
	max-width: 640px;
	padding: calc(var(--default-grid-baseline) * 4);
}

.share-approval__actions {
	display: flex;
	gap: calc(var(--default-grid-baseline) * 2);
	margin-top: calc(var(--default-grid-baseline) * 4);
}
</style>
