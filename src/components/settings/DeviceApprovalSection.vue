<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Admin switch for new device approval (crypto-new-device-approval D5).
  On by default. When off, the lock screen does not offer "Approve from
  another device" and the server refuses new requests.

  @spec openspec/changes/crypto-new-device-approval/specs/new-device-approval/spec.md#requirement-deny-expiry-audit-and-administrator-switch
-->
<template>
	<CnSettingsSection
		:name="t('keepiq', 'New device approval')"
		:description="
			t(
				'keepiq',
				'Let users unlock a new browser by approving it from a device where Keepiq is already unlocked.',
			)
		">
		<div class="device-approval">
			<label class="device-approval__toggle" for="device-approval-enabled">
				<input
					id="device-approval-enabled"
					v-model="enabled"
					type="checkbox"
					data-testid="device-approval-enabled"
					@change="save" />
				{{ t('keepiq', 'Allow approval from another device') }}
			</label>
			<p class="device-approval__disclosure">
				{{
					t(
						'keepiq',
						'The approving device seals the unlock key to the new device. The server only passes it on and cannot open it.',
					)
				}}
			</p>
		</div>
	</CnSettingsSection>
</template>

<script>
import { CnSettingsSection } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'DeviceApprovalSection',
	components: { CnSettingsSection },

	data() {
		return {
			enabled: true,
		}
	},

	/**
	 * Load the current instance-wide device-approval switch.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/changes/crypto-new-device-approval/specs/new-device-approval/spec.md#requirement-deny-expiry-audit-and-administrator-switch
	 */
	async created() {
		try {
			const response = await axios.get(
				generateUrl('/apps/keepiq/api/settings/admin/general'),
			)
			this.enabled =
				response.data?.device_approval_enabled !== false
				&& response.data?.device_approval_enabled !== '0'
		} catch {
			// Leave the default (on) showing; saving still works.
		}
	},

	methods: {
		/**
		 * Persist the instance-wide device-approval switch.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/crypto-new-device-approval/specs/new-device-approval/spec.md#requirement-deny-expiry-audit-and-administrator-switch
		 */
		async save() {
			await axios.put(generateUrl('/apps/keepiq/api/settings/admin/general'), {
				device_approval_enabled: this.enabled,
			})
		},
	},
}
</script>

<style scoped>
.device-approval__toggle {
	display: block;
	margin-bottom: 0.5rem;
}

.device-approval__disclosure {
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}
</style>
