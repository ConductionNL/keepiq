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
		:name="t('keepiq', 'Rotation & expiry')"
		:description="
			t(
				'keepiq',
				'Instance-wide credential-age defaults. Expiry is resolved from server-visible metadata only — no secret values are ever read.',
			)
		">
		<div class="rotation-policy" data-testid="rotation-policy-section">
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>
			<label class="rotation-policy__field">
				<span>{{
					t('keepiq', 'Default maximum credential age (days, 0 = off)')
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
						'keepiq',
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
import { NcNoteCard } from '@nextcloud/vue'
import { adminSettingsForm } from '../../mixins/adminSettingsForm.js'

export default {
	name: 'RotationPolicySection',
	components: { CnSettingsSection, NcNoteCard },
	mixins: [adminSettingsForm('/apps/keepiq/api/settings/admin/policies')],

	data() {
		return {
			maxAgeDays: 0,
			reminderCsv: '30, 7, 1',
		}
	},

	computed: {
		/**
		 * The positive day counts in the thresholds field.
		 *
		 * @return {number[]} The thresholds, in the order typed.
		 * @spec openspec/specs/rotation-expiry-policies/spec.md#requirement-approaching-expiry-and-overdue-reminders
		 */
		reminderThresholds() {
			return this.reminderCsv
				.split(',')
				.map((part) => parseInt(part.trim(), 10))
				.filter((day) => Number.isInteger(day) && day > 0)
		},
	},

	methods: {
		/**
		 * Show the stored thresholds in their cleaned-up form.
		 *
		 * @param {object} payload The settings just stored.
		 * @return {void}
		 * @spec openspec/specs/rotation-expiry-policies/spec.md#requirement-expiry-policies-with-admin-default-and-user-override
		 * @spec openspec/specs/rotation-expiry-policies/spec.md#requirement-approaching-expiry-and-overdue-reminders
		 */
		afterSave(payload) {
			this.reminderCsv = payload.expiry_reminder_days.join(', ')
		},

		/**
		 * Take the stored settings into the form.
		 *
		 * @param {object} data The stored settings.
		 * @return {void}
		 * @spec openspec/specs/rotation-expiry-policies/spec.md#requirement-expiry-policies-with-admin-default-and-user-override
		 * @spec openspec/specs/rotation-expiry-policies/spec.md#requirement-approaching-expiry-and-overdue-reminders
		 */
		readSettings(data) {
			this.maxAgeDays = data.expiry_default_max_age_days ?? 0
			const days = data.expiry_reminder_days
			if (Array.isArray(days) && days.length) {
				this.reminderCsv = days.join(', ')
			}
		},

		/**
		 * The defaults to store (the server validates positivity).
		 *
		 * @return {object} The settings to store.
		 * @spec openspec/specs/rotation-expiry-policies/spec.md#requirement-expiry-policies-with-admin-default-and-user-override
		 * @spec openspec/specs/rotation-expiry-policies/spec.md#requirement-approaching-expiry-and-overdue-reminders
		 */
		settingsPayload() {
			return {
				expiry_default_max_age_days: Math.max(0, this.maxAgeDays || 0),
				expiry_reminder_days: this.reminderThresholds,
			}
		},

		/**
		 * Refuse a thresholds field without a positive day.
		 *
		 * @return {string|null} The message to show, or null when the form can be saved.
		 * @spec openspec/specs/rotation-expiry-policies/spec.md#requirement-approaching-expiry-and-overdue-reminders
		 */
		validateSettings() {
			if (this.reminderThresholds.length) {
				return null
			}
			return this.t(
				'keepiq',
				'At least one positive reminder threshold is required.',
			)
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
