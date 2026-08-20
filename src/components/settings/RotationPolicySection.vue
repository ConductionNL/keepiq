<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Admin section for rotation & expiry (rotation-expiry-policies §7.1):
  instance-wide default max credential age (0 = off) and reminder
  thresholds. Per-user type/folder policies are managed from the vault
  UI; this section owns only the instance defaults.

  @spec openspec/changes/rotation-expiry-policies/specs/rotation-expiry-policies/spec.md#requirement-expiry-policies
-->
<template>
	<CnSettingsSection
		:name="t('doriath', 'Rotation & expiry')"
		:description="
			t(
				'doriath',
				'Instance-wide credential-age defaults. Expiry is resolved from server-visible metadata only — no secret values are ever read.',
			)
		">
		<div class="rotation-policy" data-testid="rotation-policy-section">
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>
			<label class="rotation-policy__field">
				<span>{{
					t('doriath', 'Default maximum credential age (days, 0 = off)')
				}}</span>
				<input
					v-model.number="maxAgeDays"
					type="number"
					min="0"
					data-testid="expiry-default-max-age"
					@change="save" />
			</label>
			<label class="rotation-policy__field">
				<span>{{
					t(
						'doriath',
						'Reminder thresholds (days before expiry, comma-separated)',
					)
				}}</span>
				<input
					v-model="reminderCsv"
					type="text"
					data-testid="expiry-reminder-days"
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

export default {
	name: 'RotationPolicySection',
	components: { CnSettingsSection, NcNoteCard },

	data() {
		return {
			maxAgeDays: 0,
			reminderCsv: '30, 7, 1',
			error: null,
		}
	},

	/**
	 * Load the current instance defaults.
	 */
	async created() {
		try {
			const response = await axios.get(
				generateUrl('/apps/doriath/api/settings/admin'),
			)
			this.maxAgeDays = response.data.expiry_default_max_age_days ?? 0
			const days = response.data.expiry_reminder_days
			if (Array.isArray(days) && days.length) {
				this.reminderCsv = days.join(', ')
			}
		} catch (e) {
			this.error = e?.response?.data?.message || e?.message
		}
	},

	methods: {
		/**
		 * Persist the defaults (server validates positivity).
		 *
		 * @return {Promise<void>}
		 */
		async save() {
			this.error = null
			const thresholds = this.reminderCsv
				.split(',')
				.map((part) => parseInt(part.trim(), 10))
				.filter((day) => Number.isInteger(day) && day > 0)
			if (!thresholds.length) {
				this.error = this.t(
					'doriath',
					'At least one positive reminder threshold is required.',
				)
				return
			}
			try {
				await axios.put(generateUrl('/apps/doriath/api/settings/admin'), {
					expiry_default_max_age_days: Math.max(0, this.maxAgeDays || 0),
					expiry_reminder_days: thresholds,
				})
				this.reminderCsv = thresholds.join(', ')
			} catch (e) {
				this.error = e?.response?.data?.message || e?.message
			}
		},
	},
}
</script>

<style scoped>
.rotation-policy {
	display: flex;
	flex-direction: column;
	gap: 12px;
	max-width: 480px;
}

.rotation-policy__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}
</style>
