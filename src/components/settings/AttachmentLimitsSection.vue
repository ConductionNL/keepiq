<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Admin section for encrypted-attachment limits (encrypted-attachments
  §6.4): per-attachment size limit and per-user quota, expressed in MiB
  for humans, stored and enforced server-side in ciphertext bytes.

  @spec openspec/specs/encrypted-attachments/spec.md#requirement-per-attachment-size-limit-and-per-user-quota
  @spec openspec/specs/secret-version-history/spec.md#requirement-admin-configurable-retention-and-pruning
-->
<template>
	<CnSettingsSection
		:name="t('keepiq', 'Attachments & version history')"
		:description="
			t(
				'keepiq',
				'Limits for encrypted file attachments (enforced server-side in stored ciphertext bytes) and version-history retention.',
			)
		">
		<div class="attachment-limits" data-testid="attachment-limits-section">
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>
			<label class="attachment-limits__field">
				<span>{{ t('keepiq', 'Maximum size per attachment (MiB)') }}</span>
				<input
					v-model.number="maxMib"
					type="number"
					min="1"
					data-testid="attachment-max-mib"
					@change="save" />
			</label>
			<label class="attachment-limits__field">
				<span>{{ t('keepiq', 'Quota per user (MiB)') }}</span>
				<input
					v-model.number="quotaMib"
					type="number"
					min="1"
					data-testid="attachment-quota-mib"
					@change="save" />
			</label>
			<label class="attachment-limits__field">
				<span>{{ t('keepiq', 'Versions kept per secret') }}</span>
				<input
					v-model.number="retentionCount"
					type="number"
					min="1"
					data-testid="version-retention-count"
					@change="save" />
			</label>
			<label class="attachment-limits__field">
				<span>{{
					t('keepiq', 'Version age limit (days, 0 = unlimited)')
				}}</span>
				<input
					v-model.number="retentionDays"
					type="number"
					min="0"
					data-testid="version-retention-days"
					@change="save" />
			</label>
		</div>
	</CnSettingsSection>
</template>

<script>
import { CnSettingsSection } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcNoteCard } from '@nextcloud/vue'

const MIB = 1048576

export default {
	name: 'AttachmentLimitsSection',
	components: { CnSettingsSection, NcNoteCard },

	data() {
		return {
			maxMib: 25,
			quotaMib: 100,
			retentionCount: 20,
			retentionDays: 365,
			error: null,
		}
	},

	/**
	 * Load the current admin limits.
	 *
	 * @spec openspec/specs/encrypted-attachments/spec.md#requirement-per-attachment-size-limit-and-per-user-quota
	 * @spec openspec/specs/secret-version-history/spec.md#requirement-admin-configurable-retention-and-pruning
	 */
	async created() {
		try {
			const response = await axios.get(
				generateUrl('/apps/keepiq/api/settings/admin'),
			)
			this.maxMib = Math.round(
				(response.data.attachment_max_bytes ?? 25 * MIB) / MIB,
			)
			this.quotaMib = Math.round(
				(response.data.attachment_user_quota_bytes ?? 100 * MIB) / MIB,
			)
			this.retentionCount = response.data.version_retention_count ?? 20
			this.retentionDays = response.data.version_retention_days ?? 365
		} catch (e) {
			this.error = e?.response?.data?.message || e?.message
		}
	},

	methods: {
		/**
		 * Persist the limits (server validates positivity).
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/encrypted-attachments/spec.md#requirement-per-attachment-size-limit-and-per-user-quota
		 * @spec openspec/specs/secret-version-history/spec.md#requirement-admin-configurable-retention-and-pruning
		 */
		async save() {
			this.error = null
			try {
				await axios.put(generateUrl('/apps/keepiq/api/settings/admin'), {
					attachment_max_bytes: Math.max(1, this.maxMib) * MIB,
					attachment_user_quota_bytes: Math.max(1, this.quotaMib) * MIB,
					version_retention_count: Math.max(1, this.retentionCount),
					version_retention_days: Math.max(0, this.retentionDays),
				})
			} catch (e) {
				this.error = e?.response?.data?.message || e?.message
			}
		},
	},
}
</script>

<style scoped>
.attachment-limits {
	display: flex;
	flex-direction: column;
	gap: 12px;
	max-width: 420px;
}

.attachment-limits__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.attachment-limits__field input {
	padding: 8px;
	border: 1px solid var(--color-border-dark, #999);
	border-radius: var(--border-radius, 4px);
	width: 160px;
}
</style>
