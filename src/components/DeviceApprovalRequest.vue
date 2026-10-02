<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  "Approve from another device" on the lock screen (crypto-new-device-
  approval D1 to D4). Asks the server for a request with a one-time key,
  shows the verification phrase, and polls every three seconds until the
  user approves on a device where Keepiq is unlocked. Emits `unlocked`.

  @spec openspec/changes/crypto-new-device-approval/specs/new-device-approval/spec.md#requirement-a-new-device-requests-approval-with-a-one-time-key
-->
<template>
	<div class="device-approval-request" data-testid="device-approval-request">
		<NcButton
			v-if="!request"
			variant="tertiary"
			:wide="true"
			:disabled="starting"
			data-testid="device-approval-start"
			@click="start">
			{{ t('keepiq', 'Approve from another device') }}
		</NcButton>

		<div v-else class="device-approval-request__waiting">
			<p v-if="request.status === 'pending'">
				{{
					t(
						'keepiq',
						'Open Keepiq on a device where it is unlocked and approve this one. Check that it shows the same words:',
					)
				}}
			</p>
			<p
				class="device-approval-request__phrase"
				data-testid="device-approval-phrase">
				{{ request.phrase }}
			</p>
			<NcNoteCard
				v-if="request.status === 'denied'"
				type="error"
				data-testid="device-approval-denied">
				{{ t('keepiq', 'The request was denied.') }}
			</NcNoteCard>
			<NcNoteCard
				v-else-if="
					request.status === 'expired' || request.status === 'consumed'
				"
				type="warning"
				data-testid="device-approval-expired">
				{{
					t(
						'keepiq',
						'The request expired. Ask again or use your master password.',
					)
				}}
			</NcNoteCard>
			<NcButton
				variant="tertiary"
				:wide="true"
				data-testid="device-approval-cancel"
				@click="cancel">
				{{ t('keepiq', 'Cancel') }}
			</NcButton>
		</div>

		<p v-if="error" class="device-approval-request__error">
			{{ error }}
		</p>
	</div>
</template>

<script>
import { NcButton, NcNoteCard } from '@nextcloud/vue'
import { useDeviceApprovalStore } from '../store/modules/deviceApproval.js'

/** Poll interval while waiting, in milliseconds (D4). */
export const POLL_MS = 3000

export default {
	name: 'DeviceApprovalRequest',
	components: { NcButton, NcNoteCard },
	emits: ['unlocked'],

	data() {
		return {
			starting: false,
			error: null,
			timer: null,
		}
	},

	computed: {
		/**
		 * @spec openspec/changes/crypto-new-device-approval/specs/new-device-approval/spec.md#requirement-a-new-device-requests-approval-with-a-one-time-key
		 */
		store() {
			return useDeviceApprovalStore()
		},

		/**
		 * @spec openspec/changes/crypto-new-device-approval/specs/new-device-approval/spec.md#requirement-a-new-device-requests-approval-with-a-one-time-key
		 */
		request() {
			return this.store.request
		},
	},

	beforeUnmount() {
		this.stopPolling()
	},

	methods: {
		/**
		 * Ask for approval and start waiting.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/crypto-new-device-approval/specs/new-device-approval/spec.md#requirement-a-new-device-requests-approval-with-a-one-time-key
		 */
		async start() {
			this.starting = true
			this.error = null
			try {
				await this.store.startRequest('web')
				this.timer = setInterval(() => this.poll(), POLL_MS)
			} catch (e) {
				this.error =
					e?.response?.status === 429
						? t(
								'keepiq',
								'Too many requests. Try again in an hour or use your master password.',
							)
						: e?.response?.data?.message || e?.message
			} finally {
				this.starting = false
			}
		},

		/**
		 * One poll; stop on any outcome other than pending.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/crypto-new-device-approval/specs/new-device-approval/spec.md#requirement-pickup-is-one-time-and-unlocks-one-session
		 */
		async poll() {
			try {
				const status = await this.store.pollOnce()
				if (status === 'pending') {
					return
				}
				this.stopPolling()
				if (status === 'unlocked') {
					this.$emit('unlocked')
				}
			} catch (e) {
				this.stopPolling()
				this.error = e?.response?.data?.message || e?.message
			}
		},

		/**
		 * Stop waiting and forget the one-time key.
		 *
		 * @return {void}
		 * @spec openspec/changes/crypto-new-device-approval/specs/new-device-approval/spec.md#requirement-a-new-device-requests-approval-with-a-one-time-key
		 */
		cancel() {
			this.stopPolling()
			this.store.cancelRequest()
		},

		/**
		 * @return {void}
		 * @spec openspec/changes/crypto-new-device-approval/specs/new-device-approval/spec.md#requirement-a-new-device-requests-approval-with-a-one-time-key
		 */
		stopPolling() {
			if (this.timer) {
				clearInterval(this.timer)
				this.timer = null
			}
		},
	},
}
</script>

<style scoped>
.device-approval-request {
	margin-top: 12px;
}

.device-approval-request__phrase {
	margin: 8px 0;
	font-size: 18px;
	font-weight: 600;
	text-align: center;
}

.device-approval-request__error {
	color: var(--color-error-text);
	font-size: 13px;
}
</style>
