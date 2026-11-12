<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Admin section for machine-lease policy (machine-secret-leases §6.2):
  instance default/max lease TTLs, renewability, and the block-on-revoke
  switch. Per-application overrides live on the application via the
  lease-policy endpoint.

  @spec openspec/specs/machine-secret-leases/spec.md#requirement-admin-lease-ttl-policy
-->
<template>
	<CnSettingsSection
		:name="t('keepiq', 'Machine leases')"
		:description="
			t(
				'keepiq',
				'Access-grant lifetimes for the machine secret-store API. Leases govern grant lifetime only — stored ciphertext is untouched.',
			)
		">
		<div class="lease-policy" data-testid="machine-lease-section">
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>
			<label class="lease-policy__field">
				<span>{{ t('keepiq', 'Default lease TTL (seconds)') }}</span>
				<input
					v-model.number="defaultTtl"
					type="number"
					min="60"
					data-testid="lease-default-ttl"
					@change="save" />
			</label>
			<label class="lease-policy__field">
				<span>{{ t('keepiq', 'Maximum lease TTL (seconds)') }}</span>
				<input
					v-model.number="maxTtl"
					type="number"
					min="60"
					data-testid="lease-max-ttl"
					@change="save" />
			</label>
			<label class="lease-policy__check">
				<input
					v-model="renewable"
					type="checkbox"
					data-testid="lease-renewable"
					@change="save" />
				<span>{{ t('keepiq', 'Leases are renewable') }}</span>
			</label>
			<label class="lease-policy__check">
				<input
					v-model="blockOnRevoke"
					type="checkbox"
					data-testid="lease-block-on-revoke"
					@change="save" />
				<span>{{
					t(
						'keepiq',
						'A revoked lease blocks re-fetching the secret until re-granted',
					)
				}}</span>
			</label>
		</div>
	</CnSettingsSection>
</template>

<script>
import { CnSettingsSection } from '@conduction/nextcloud-vue'
import { NcNoteCard } from '@nextcloud/vue'
import { adminSettingsForm } from '../../mixins/adminSettingsForm.js'

export default {
	name: 'MachineLeaseSection',
	components: { CnSettingsSection, NcNoteCard },
	mixins: [adminSettingsForm('/apps/keepiq/api/settings/admin/applications')],

	data() {
		return {
			defaultTtl: 900,
			maxTtl: 86400,
			renewable: true,
			blockOnRevoke: false,
		}
	},

	methods: {
		/**
		 * Take the stored lease policy into the form.
		 *
		 * @param {object} data The stored settings.
		 * @return {void}
		 * @spec openspec/specs/machine-secret-leases/spec.md#requirement-admin-lease-ttl-policy
		 */
		readSettings(data) {
			this.defaultTtl = data.lease_default_ttl_seconds ?? 900
			this.maxTtl = data.lease_max_ttl_seconds ?? 86400
			this.renewable = data.lease_renewable !== false
			this.blockOnRevoke = data.lease_revocation_blocks_refetch === true
		},

		/**
		 * The lease policy to store.
		 *
		 * @return {object} The settings to store.
		 * @spec openspec/specs/machine-secret-leases/spec.md#requirement-admin-lease-ttl-policy
		 */
		settingsPayload() {
			return {
				lease_default_ttl_seconds: this.defaultTtl,
				lease_max_ttl_seconds: this.maxTtl,
				lease_renewable: this.renewable,
				lease_revocation_blocks_refetch: this.blockOnRevoke,
			}
		},
	},
}
</script>

<style scoped>
.lease-policy {
	display: flex;
	flex-direction: column;
	gap: 12px;
	max-width: 480px;
}

.lease-policy__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.lease-policy__check {
	display: flex;
	align-items: center;
	gap: 8px;
}
</style>
