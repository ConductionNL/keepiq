<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  PasswordPolicySection — admin settings section for the master-password
  policy (minimum length + minimum zxcvbn score). Reads / writes the
  admin settings via the shared settings Pinia store (GET/PUT
  /api/settings/admin). The backend enforces the hardcoded security
  floors (length 12-20, score 3-4); this section validates client-side
  too and surfaces the backend's 400 message inline.

  @spec openspec/changes/implement-dashboard-settings/specs/admin-settings/spec.md
-->
<template>
	<CnSettingsSection
		:name="t('doriath', 'Password Policy')"
		:description="t('doriath', 'Configure master password requirements for all users')">
		<div class="password-policy">
			<div class="password-policy__field">
				<NcTextField
					:value.sync="minLengthInput"
					type="number"
					min="12"
					max="20"
					:label="t('doriath', 'Minimum length')"
					@change="save" />
				<span class="password-policy__hint">{{ t('doriath', '12–20 characters') }}</span>
			</div>

			<div class="password-policy__field">
				<NcSelect
					v-model="selectedScore"
					:options="scoreOptions"
					:input-label="t('doriath', 'Minimum strength score')"
					label="label"
					:reduce="opt => opt.value"
					:clearable="false"
					@input="save" />
			</div>

			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>
		</div>
	</CnSettingsSection>
</template>

<script>
import { NcSelect, NcTextField, NcNoteCard } from '@nextcloud/vue'
import { CnSettingsSection } from '@conduction/nextcloud-vue'
import { translate as ncT } from '@nextcloud/l10n'
import { useSettingsStore } from '../../store/modules/settings.js'

export default {
	name: 'PasswordPolicySection',

	components: { NcSelect, NcTextField, NcNoteCard, CnSettingsSection },

	data() {
		return {
			minLengthInput: '12',
			selectedScore: 3,
			error: null,
			scoreOptions: [
				{ value: 3, label: ncT('doriath', 'Strong (score 3)') },
				{ value: 4, label: ncT('doriath', 'Very strong (score 4)') },
			],
		}
	},

	computed: {
		/**
		 * @spec exclude Store-ref passthrough — returns the Pinia settings store with no domain logic.
		 */
		settingsStore() {
			return useSettingsStore()
		},
	},

	/**
	 * Load the current master-password policy from the admin settings API.
	 *
	 * @spec openspec/changes/implement-dashboard-settings/specs/admin-settings/spec.md
	 */
	async created() {
		await this.settingsStore.fetchAdminSettings()
		const policy = this.settingsStore.passwordPolicy
		this.minLengthInput = String(policy.minLength)
		this.selectedScore = policy.minScore
	},

	methods: {
		/**
		 * Persist the master-password policy. Clamps the length to the
		 * 12-20 floor/ceiling client-side, then defers to the backend
		 * which re-validates and may still reject with a 400 surfaced
		 * inline.
		 *
		 * @spec openspec/changes/implement-dashboard-settings/specs/admin-settings/spec.md
		 */
		async save() {
			this.error = null
			const length = Math.min(20, Math.max(12, parseInt(this.minLengthInput, 10) || 12))
			this.minLengthInput = String(length)
			try {
				await this.settingsStore.saveAdminSettings({
					min_password_length: length,
					min_password_score: this.selectedScore,
				})
			} catch (e) {
				this.error = e.response?.data?.message || ncT('doriath', 'Failed to save password policy')
			}
		},
	},
}
</script>

<style scoped>
.password-policy__field {
	margin-bottom: 1rem;
}

.password-policy__hint {
	color: var(--color-text-lighter);
	font-size: 0.85rem;
}
</style>
