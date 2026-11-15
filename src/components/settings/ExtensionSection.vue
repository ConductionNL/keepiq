<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Admin section for the browser extension: the longest idle time a user may
  pick before the extension locks, and which extension builds may use
  passkeys. The extension reads the idle maximum at every unlock and uses the
  shorter of the user's choice and this maximum.

  @spec openspec/specs/browser-extension-autofill/spec.md#requirement-user-chosen-idle-lock-period-with-an-administrator-maximum
  @spec openspec/changes/extension-passkey-enrolment/specs/extension-passkey-trust/spec.md#requirement-extension-ceremonies-pass-only-for-trusted-extension-origins
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
			<label class="extension-settings__switch">
				<input
					v-model="passkeysEnabled"
					type="checkbox"
					data-testid="extension-passkeys-enabled"
					@change="save">
				<span>{{ t('keepiq', 'Allow passkey unlock in the extension') }}</span>
			</label>
			<div v-if="pinnedOrigins.length" class="extension-settings__field">
				<span>{{ t('keepiq', 'Official extension builds') }}</span>
				<ul data-testid="extension-pinned-origins">
					<li v-for="origin in pinnedOrigins" :key="origin">
						<code>{{ origin }}</code>
					</li>
				</ul>
			</div>
			<label class="extension-settings__field">
				<span>{{ t('keepiq', 'Other extension builds allowed to use passkeys') }}</span>
				<textarea
					v-model="originsText"
					rows="3"
					data-testid="extension-passkey-origins"
					placeholder="chrome-extension://…"
					@change="save" />
				<small>{{
					t(
						'keepiq',
						'One exact origin per line, such as chrome-extension:// followed by the 32-letter extension id. Wildcards are not accepted.',
					)
				}}</small>
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
			passkeysEnabled: true,
			originsText: '',
			pinnedOrigins: [],
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
			this.passkeysEnabled = data.extension_passkeys_enabled ?? true
			this.originsText = (data.extension_passkey_origins ?? []).join('\n')
			this.pinnedOrigins = data.extension_passkey_pinned_origins ?? []
		},

		/**
		 * The settings to store: the idle maximum (the server accepts only the
		 * offered delays), the passkey switch and the added origins, one per
		 * line (the server refuses any origin that is not exact).
		 *
		 * @return {object} The settings to store.
		 * @spec openspec/specs/browser-extension-autofill/spec.md#requirement-user-chosen-idle-lock-period-with-an-administrator-maximum
		 * @spec openspec/changes/extension-passkey-enrolment/specs/extension-passkey-trust/spec.md#requirement-extension-ceremonies-pass-only-for-trusted-extension-origins
		 */
		settingsPayload() {
			return {
				extension_max_idle_minutes: this.maxIdleMinutes,
				extension_passkeys_enabled: this.passkeysEnabled,
				extension_passkey_origins: this.originsText
					.split('\n')
					.map((line) => line.trim())
					.filter((line) => line !== ''),
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

.extension-settings__switch {
	display: flex;
	align-items: center;
	gap: 8px;
}

.extension-settings__field textarea {
	font-family: monospace;
}
</style>
