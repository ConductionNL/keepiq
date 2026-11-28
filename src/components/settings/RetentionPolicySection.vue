<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Admin section for version history and trash retention, in the Policies
  area (admin-scoped-roles, decision of 2 Oct: these are vault rules).
  Split out of AttachmentLimitsSection, which stays in General.

  @spec openspec/specs/secret-version-history/spec.md#requirement-admin-configurable-retention-and-pruning
  @spec openspec/specs/vault-trash-and-archive/spec.md#requirement-restoring-and-purging-trashed-secrets
  @spec openspec/changes/archive/2026-10-04-admin-scoped-roles/tasks.md#3.1
-->
<template>
	<CnSettingsSection
		:name="t('keepiq', 'Version history')"
		:description="
			t(
				'keepiq',
				'How many versions of a secret are kept, for how long, and how long deleted secrets stay in the trash.',
			)
		">
		<div class="attachment-limits" data-testid="retention-policy-section">
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>
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
			<label class="attachment-limits__field">
				<span>{{
					t(
						'keepiq',
						'Days a deleted secret stays in the trash (1 to 365)',
					)
				}}</span>
				<input
					v-model.number="trashDays"
					type="number"
					min="1"
					max="365"
					data-testid="trash-retention-days"
					@change="save" />
			</label>
		</div>
	</CnSettingsSection>
</template>

<script>
import { CnSettingsSection } from '@conduction/nextcloud-vue'
import { NcNoteCard } from '@nextcloud/vue'
import { adminSettingsForm } from '../../mixins/adminSettingsForm.js'

export default {
	name: 'RetentionPolicySection',
	components: { CnSettingsSection, NcNoteCard },
	mixins: [adminSettingsForm('/apps/keepiq/api/settings/admin/policies')],

	data() {
		return {
			retentionCount: 20,
			retentionDays: 365,
			trashDays: 30,
		}
	},

	methods: {
		/**
		 * Take the stored settings into the form.
		 *
		 * @param {object} data The stored settings.
		 * @return {void}
		 * @spec openspec/specs/secret-version-history/spec.md#requirement-admin-configurable-retention-and-pruning
		 */
		readSettings(data) {
			this.retentionCount = data.version_retention_count ?? 20
			this.retentionDays = data.version_retention_days ?? 365
			this.trashDays = data.trash_retention_days ?? 30
		},

		/**
		 * The retention settings to store (the server validates the bounds).
		 *
		 * @return {object} The settings to store.
		 * @spec openspec/specs/secret-version-history/spec.md#requirement-admin-configurable-retention-and-pruning
		 */
		settingsPayload() {
			return {
				version_retention_count: Math.max(1, this.retentionCount),
				version_retention_days: Math.max(0, this.retentionDays),
				trash_retention_days: Math.min(365, Math.max(1, this.trashDays)),
			}
		},
	},
}
</script>

<style scoped src="./limitFields.css"></style>
