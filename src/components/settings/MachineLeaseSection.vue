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
		:name="t('doriath', 'Machine leases')"
		:description="t('doriath', 'Access-grant lifetimes for the machine secret-store API. Leases govern grant lifetime only — stored ciphertext is untouched.')">
		<div class="lease-policy" data-testid="machine-lease-section">
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>
			<label class="lease-policy__field">
				<span>{{ t('doriath', 'Default lease TTL (seconds)') }}</span>
				<input v-model.number="defaultTtl"
					type="number"
					min="60"
					data-testid="lease-default-ttl"
					@change="save">
			</label>
			<label class="lease-policy__field">
				<span>{{ t('doriath', 'Maximum lease TTL (seconds)') }}</span>
				<input v-model.number="maxTtl"
					type="number"
					min="60"
					data-testid="lease-max-ttl"
					@change="save">
			</label>
			<label class="lease-policy__check">
				<input v-model="renewable"
					type="checkbox"
					data-testid="lease-renewable"
					@change="save">
				<span>{{ t('doriath', 'Leases are renewable') }}</span>
			</label>
			<label class="lease-policy__check">
				<input v-model="blockOnRevoke"
					type="checkbox"
					data-testid="lease-block-on-revoke"
					@change="save">
				<span>{{ t('doriath', 'A revoked lease blocks re-fetching the secret until re-granted') }}</span>
			</label>
		</div>
	</CnSettingsSection>
</template>

<script>
import { CnSettingsSection } from '@conduction/nextcloud-vue'
import { NcNoteCard } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'MachineLeaseSection',
	components: { CnSettingsSection, NcNoteCard },

	data() {
		return {
			defaultTtl: 900,
			maxTtl: 86400,
			renewable: true,
			blockOnRevoke: false,
			error: null,
		}
	},

	/**
	 * Load the current instance lease policy.
	 */
	async created() {
		try {
			const response = await axios.get(generateUrl('/apps/doriath/api/settings/admin'))
			this.defaultTtl = response.data.lease_default_ttl_seconds ?? 900
			this.maxTtl = response.data.lease_max_ttl_seconds ?? 86400
			this.renewable = response.data.lease_renewable !== false
			this.blockOnRevoke = response.data.lease_revocation_blocks_refetch === true
		} catch (e) {
			this.error = e?.response?.data?.message || e?.message
		}
	},

	methods: {
		/**
		 * Persist the policy (server enforces the 60s floor).
		 *
		 * @return {Promise<void>}
		 */
		async save() {
			this.error = null
			try {
				await axios.put(generateUrl('/apps/doriath/api/settings/admin'), {
					lease_default_ttl_seconds: this.defaultTtl,
					lease_max_ttl_seconds: this.maxTtl,
					lease_renewable: this.renewable,
					lease_revocation_blocks_refetch: this.blockOnRevoke,
				})
			} catch (e) {
				this.error = e?.response?.data?.message || e?.message
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
