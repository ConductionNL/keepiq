<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Admin section for the browser extension: the longest idle time a user may
  pick before the extension locks. The extension reads it at every unlock
  and uses the shorter of the user's choice and this maximum.

  @spec openspec/specs/browser-extension-autofill/spec.md#requirement-user-chosen-idle-lock-period-with-an-administrator-maximum
-->
<template>
	<CnSettingsSection
		:name="t('keepiq', 'Browser extension')"
		:description="
			t(
				'keepiq',
				'Users pick how long the extension stays unlocked while idle. You set the longest they may pick.',
			)
		">
		<div class="extension-settings" data-testid="extension-settings-section">
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>
			<label class="extension-settings__field">
				<span>{{
					t('keepiq', 'Longest idle time before the extension locks')
				}}</span>
				<select
					v-model.number="maxIdleMinutes"
					data-testid="extension-max-idle"
					@change="save">
					<option
						v-for="choice in choices"
						:key="choice.value"
						:value="choice.value">
						{{ choice.label }}
					</option>
				</select>
			</label>
		</div>
	</CnSettingsSection>
</template>

<script>
import { CnSettingsSection } from '@conduction/nextcloud-vue'
import { NcNoteCard } from '@nextcloud/vue'
import { adminSettingsForm } from '../../mixins/adminSettingsForm.js'

export default {
	name: 'ExtensionSection',
	components: { CnSettingsSection, NcNoteCard },
	mixins: [adminSettingsForm('/apps/keepiq/api/settings/admin/policies')],

	data() {
		return {
			maxIdleMinutes: 240,
		}
	},

	computed: {
		/**
		 * @spec openspec/specs/browser-extension-autofill/spec.md#requirement-user-chosen-idle-lock-period-with-an-administrator-maximum
		 */
		choices() {
			return [
				{ value: 1, label: this.t('keepiq', '1 minute') },
				{ value: 5, label: this.t('keepiq', '5 minutes') },
				{ value: 15, label: this.t('keepiq', '15 minutes') },
				{ value: 30, label: this.t('keepiq', '30 minutes') },
				{ value: 60, label: this.t('keepiq', '1 hour') },
				{ value: 240, label: this.t('keepiq', '4 hours') },
			]
		},
	},

	methods: {
		/**
		 * Take the stored settings into the form.
		 *
		 * @param {object} data The stored settings.
		 * @return {void}
		 * @spec openspec/specs/browser-extension-autofill/spec.md#requirement-user-chosen-idle-lock-period-with-an-administrator-maximum
		 */
		readSettings(data) {
			this.maxIdleMinutes = data.extension_max_idle_minutes ?? 240
		},

		/**
		 * The maximum to store (the server accepts only the offered delays).
		 *
		 * @return {object} The settings to store.
		 * @spec openspec/specs/browser-extension-autofill/spec.md#requirement-user-chosen-idle-lock-period-with-an-administrator-maximum
		 */
		settingsPayload() {
			return { extension_max_idle_minutes: this.maxIdleMinutes }
		},
	},
}
</script>

<style scoped>
.extension-settings {
	display: flex;
	flex-direction: column;
	gap: 12px;
	max-width: 420px;
}

.extension-settings__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.extension-settings__field select {
	width: 200px;
}
</style>
