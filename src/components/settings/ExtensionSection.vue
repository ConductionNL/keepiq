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
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcNoteCard } from '@nextcloud/vue'

export default {
	name: 'ExtensionSection',
	components: { CnSettingsSection, NcNoteCard },

	data() {
		return {
			maxIdleMinutes: 240,
			error: null,
		}
	},

	computed: {
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

	/**
	 * Load the current maximum.
	 *
	 * @spec openspec/specs/browser-extension-autofill/spec.md#requirement-user-chosen-idle-lock-period-with-an-administrator-maximum
	 */
	async created() {
		try {
			const response = await axios.get(
				generateUrl('/apps/keepiq/api/settings/admin'),
			)
			this.maxIdleMinutes = response.data.extension_max_idle_minutes ?? 240
		} catch (e) {
			this.error = e?.response?.data?.message || e?.message
		}
	},

	methods: {
		/**
		 * Store the maximum (the server accepts only the offered delays).
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/browser-extension-autofill/spec.md#requirement-user-chosen-idle-lock-period-with-an-administrator-maximum
		 */
		async save() {
			this.error = null
			try {
				await axios.put(generateUrl('/apps/keepiq/api/settings/admin'), {
					extension_max_idle_minutes: this.maxIdleMinutes,
				})
			} catch (e) {
				this.error = e?.response?.data?.message || e?.message
			}
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
