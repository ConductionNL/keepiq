<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Approve or deny a new device (crypto-new-device-approval D3, D5). While
  the vault is unlocked it checks for open requests every 30 seconds and
  opens on the first one: device, client, IP address, time and the
  verification phrase, a warning, and the master password to confirm. Only
  the unlock key sealed to the device's one-time key leaves this browser.

  @spec openspec/changes/crypto-new-device-approval/specs/new-device-approval/spec.md#requirement-approval-seals-the-unlock-key-and-needs-proof-of-the-master-password
-->
<template>
	<NcDialog
		v-if="current"
		:name="t('keepiq', 'Approve a new device')"
		:open="true"
		size="normal"
		data-testid="device-approval-dialog"
		@update:open="(open) => !open && dismiss()">
		<div class="device-approval">
			<NcNoteCard type="warning">
				{{ t('keepiq', 'Only approve a device you are using right now.') }}
			</NcNoteCard>
			<dl class="device-approval__facts">
				<dt>{{ t('keepiq', 'Device') }}</dt>
				<dd data-testid="device-approval-label">
					{{ current.deviceLabel || t('keepiq', 'Unknown device') }}
				</dd>
				<dt>{{ t('keepiq', 'App') }}</dt>
				<dd>
					{{
						current.clientKind === 'extension'
							? t('keepiq', 'Browser extension')
							: t('keepiq', 'Web app')
					}}
				</dd>
				<dt>{{ t('keepiq', 'IP address') }}</dt>
				<dd>{{ current.requesterIp }}</dd>
				<dt>{{ t('keepiq', 'Asked at') }}</dt>
				<dd>{{ askedAt }}</dd>
			</dl>
			<p>{{ t('keepiq', 'Check that the new device shows these words:') }}</p>
			<p class="device-approval__phrase" data-testid="device-approval-phrase">
				{{ current.phrase }}
			</p>
			<NcPasswordField
				v-model="masterPassword"
				:label="t('keepiq', 'Master password')"
				autocomplete="current-password"
				:disabled="busy"
				data-testid="device-approval-password" />
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>
			<p v-if="denied" data-testid="device-approval-denied-hint">
				{{
					t(
						'keepiq',
						'Denied. If you did not ask, end your other sessions:',
					)
				}}
				<a :href="securityUrl">{{
					t('keepiq', 'Nextcloud security settings')
				}}</a>
			</p>
		</div>
		<template #actions>
			<NcButton
				variant="tertiary"
				:disabled="busy"
				data-testid="device-approval-deny"
				@click="deny">
				{{ t('keepiq', 'Deny') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="busy || masterPassword === ''"
				data-testid="device-approval-approve"
				@click="approve">
				{{ t('keepiq', 'Approve') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcDialog, NcNoteCard, NcPasswordField } from '@nextcloud/vue'
import { useDeviceApprovalStore } from '../store/modules/deviceApproval.js'

/** How often an unlocked vault looks for new requests, in milliseconds. */
export const PENDING_POLL_MS = 30000

export default {
	name: 'DeviceApprovalDialog',
	components: { NcButton, NcDialog, NcNoteCard, NcPasswordField },
	props: {
		/** Whether the vault is unlocked, so requests can be approved. */
		active: {
			type: Boolean,
			default: false,
		},
	},

	data() {
		return {
			masterPassword: '',
			busy: false,
			error: null,
			denied: false,
			dismissed: [],
			timer: null,
		}
	},

	computed: {
		/**
		 * @spec openspec/changes/crypto-new-device-approval/specs/new-device-approval/spec.md#requirement-approval-seals-the-unlock-key-and-needs-proof-of-the-master-password
		 */
		store() {
			return useDeviceApprovalStore()
		},

		/**
		 * The first open request not dismissed in this session.
		 *
		 * @return {object|null}
		 * @spec openspec/changes/crypto-new-device-approval/specs/new-device-approval/spec.md#requirement-approval-seals-the-unlock-key-and-needs-proof-of-the-master-password
		 */
		current() {
			if (!this.active) {
				return null
			}
			return (
				this.store.pending.find((row) => !this.dismissed.includes(row.id))
				?? null
			)
		},

		/**
		 * @spec openspec/changes/crypto-new-device-approval/specs/new-device-approval/spec.md#requirement-approval-seals-the-unlock-key-and-needs-proof-of-the-master-password
		 */
		askedAt() {
			const at = this.current?.createdAt
				? new Date(this.current.createdAt)
				: null
			return at && !Number.isNaN(at.getTime()) ? at.toLocaleString() : ''
		},

		/**
		 * @spec openspec/changes/crypto-new-device-approval/specs/new-device-approval/spec.md#requirement-approval-seals-the-unlock-key-and-needs-proof-of-the-master-password
		 */
		securityUrl() {
			return generateUrl('/settings/user/security')
		},
	},

	watch: {
		active: {
			immediate: true,
			/**
			 * Poll for open requests while the vault is unlocked.
			 *
			 * @param {boolean} on Whether the vault is unlocked.
			 * @spec openspec/changes/crypto-new-device-approval/specs/new-device-approval/spec.md#requirement-approval-seals-the-unlock-key-and-needs-proof-of-the-master-password
			 */
			handler(on) {
				this.stop()
				if (on) {
					this.refresh()
					this.timer = setInterval(() => this.refresh(), PENDING_POLL_MS)
				}
			},
		},
	},

	beforeUnmount() {
		this.stop()
	},

	methods: {
		/**
		 * Look for open requests; quiet on failure.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/crypto-new-device-approval/specs/new-device-approval/spec.md#requirement-both-devices-show-the-same-verification-phrase
		 */
		async refresh() {
			try {
				await this.store.fetchPending()
			} catch {
				// No requests to show is the safe outcome.
			}
		},

		/**
		 * Approve with the master password.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/crypto-new-device-approval/specs/new-device-approval/spec.md#requirement-approval-seals-the-unlock-key-and-needs-proof-of-the-master-password
		 */
		async approve() {
			this.busy = true
			this.error = null
			try {
				await this.store.approve(this.current, this.masterPassword)
				this.masterPassword = ''
			} catch (e) {
				this.error =
					e?.response?.data?.message
					|| t(
						'keepiq',
						'The master password is not right, or the request has ended.',
					)
			} finally {
				this.busy = false
			}
		},

		/**
		 * Deny the request and point to the session settings.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/crypto-new-device-approval/specs/new-device-approval/spec.md#requirement-deny-expiry-audit-and-administrator-switch
		 */
		async deny() {
			this.busy = true
			this.error = null
			const id = this.current.id
			try {
				await this.store.deny(id)
				this.denied = true
			} catch (e) {
				this.error = e?.response?.data?.message || e?.message
			} finally {
				this.busy = false
			}
		},

		/**
		 * Close without deciding; the request stays open until it expires.
		 *
		 * @return {void}
		 * @spec openspec/changes/crypto-new-device-approval/specs/new-device-approval/spec.md#requirement-approval-seals-the-unlock-key-and-needs-proof-of-the-master-password
		 */
		dismiss() {
			if (this.current) {
				this.dismissed.push(this.current.id)
			}
			this.masterPassword = ''
			this.error = null
		},

		/**
		 * @return {void}
		 * @spec openspec/changes/crypto-new-device-approval/specs/new-device-approval/spec.md#requirement-approval-seals-the-unlock-key-and-needs-proof-of-the-master-password
		 */
		stop() {
			if (this.timer) {
				clearInterval(this.timer)
				this.timer = null
			}
		},
	},
}
</script>

<style scoped>
.device-approval {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 4px 12px 12px;
}

.device-approval__facts {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 4px 12px;
	margin: 0;
}

.device-approval__facts dt {
	color: var(--color-text-maxcontrast);
}

.device-approval__facts dd {
	margin: 0;
}

.device-approval__phrase {
	font-size: 18px;
	font-weight: 600;
	text-align: center;
}
</style>
