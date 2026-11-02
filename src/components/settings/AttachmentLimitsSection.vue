<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Admin section for encrypted-attachment limits (encrypted-attachments
  §6.4): per-attachment size limit and per-user quota, expressed in MiB
  for humans, stored and enforced server-side in ciphertext bytes.

  Version and trash retention moved to RetentionPolicySection in the
  Policies area (admin-scoped-roles, decision of 2 Oct).

  @spec openspec/specs/encrypted-attachments/spec.md#requirement-per-attachment-size-limit-and-per-user-quota
  @spec openspec/changes/archive/2026-10-04-admin-scoped-roles/tasks.md#3.1
-->
<template>
	<CnSettingsSection
		:name="t('keepiq', 'Attachments')"
		:description="
			t(
				'keepiq',
				'Limits for encrypted file attachments, enforced on the server in stored ciphertext bytes.',
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
		</div>
	</CnSettingsSection>
</template>

<script>
import { CnSettingsSection } from '@conduction/nextcloud-vue'
import { NcNoteCard } from '@nextcloud/vue'
import { adminSettingsForm } from '../../mixins/adminSettingsForm.js'

const MIB = 1048576

export default {
	name: 'AttachmentLimitsSection',
	components: { CnSettingsSection, NcNoteCard },
	mixins: [adminSettingsForm('/apps/keepiq/api/settings/admin/general')],

	data() {
		return {
			maxMib: 25,
			quotaMib: 100,
		}
	},

	methods: {
		/**
		 * Take the stored settings into the form.
		 *
		 * @param {object} data The stored settings.
		 * @return {void}
		 * @spec openspec/specs/encrypted-attachments/spec.md#requirement-per-attachment-size-limit-and-per-user-quota
		 */
		readSettings(data) {
			this.maxMib = Math.round((data.attachment_max_bytes ?? 25 * MIB) / MIB)
			this.quotaMib = Math.round(
				(data.attachment_user_quota_bytes ?? 100 * MIB) / MIB,
			)
		},

		/**
		 * The limits to store (the server validates positivity).
		 *
		 * @return {object|null} The settings to store, or null when the form is invalid.
		 * @spec openspec/specs/encrypted-attachments/spec.md#requirement-per-attachment-size-limit-and-per-user-quota
		 */
		settingsPayload() {
			return {
				attachment_max_bytes: Math.max(1, this.maxMib) * MIB,
				attachment_user_quota_bytes: Math.max(1, this.quotaMib) * MIB,
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
