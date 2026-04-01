<!--
  SPDX-License-Identifier: EUPL-1.2
  Copyright (C) 2026 Conduction B.V.
-->
<template>
	<div class="user-prefs">
		<!-- Session timeout -->
		<div class="user-prefs__field">
			<label class="user-prefs__label" for="session-timeout">
				{{ t('app-template', 'Session timeout (minutes)') }}:
				<strong>{{ form.sessionTimeout }}</strong>
			</label>
			<input id="session-timeout"
				v-model.number="form.sessionTimeout"
				type="range"
				min="5"
				max="1440"
				step="5"
				class="user-prefs__slider"
				@change="save">
			<div class="user-prefs__range-hints">
				<span>{{ t('app-template', '5 min') }}</span>
				<span>{{ t('app-template', '24 h') }}</span>
			</div>
		</div>

		<!-- Notification toggles -->
		<div class="user-prefs__group">
			<h4 class="user-prefs__group-title">
				{{ t('app-template', 'Notifications') }}
			</h4>

			<NcCheckboxRadioSwitch v-model="form.notifyShares"
				type="switch"
				@update:model-value="save">
				{{ t('app-template', 'Notify me when a secret is shared with me') }}
			</NcCheckboxRadioSwitch>

			<NcCheckboxRadioSwitch v-model="form.notifyRequests"
				type="switch"
				@update:model-value="save">
				{{ t('app-template', 'Notify me on incoming secret requests') }}
			</NcCheckboxRadioSwitch>

			<!-- V1: group shares and security notifications -->
			<NcCheckboxRadioSwitch v-model="form.notifyGroupShares"
				type="switch"
				:disabled="true"
				@update:model-value="save">
				<!-- TODO (V1): Enable once group-shares (#3) is implemented. -->
				{{ t('app-template', 'Notify me on group share changes (coming soon)') }}
			</NcCheckboxRadioSwitch>

			<NcCheckboxRadioSwitch v-model="form.notifySecurity"
				type="switch"
				:disabled="true"
				@update:model-value="save">
				<!-- TODO (V1): Enable once security events (#1) are tracked. -->
				{{ t('app-template', 'Notify me on security events (coming soon)') }}
			</NcCheckboxRadioSwitch>
		</div>

		<div v-if="saved" class="user-prefs__saved">
			{{ t('app-template', 'Preferences saved') }}
		</div>
	</div>
</template>

<script setup>
/**
 * UserPreferencesSection
 *
 * Allows users to configure session timeout and notification preferences.
 * V1 toggles (group shares, security) are scaffolded but disabled.
 */
import { ref, watch } from 'vue'
import { NcCheckboxRadioSwitch } from '@nextcloud/vue'
import { useUserSettingsStore } from '../../store/modules/userSettings.js'

const userSettingsStore = useUserSettingsStore()

const form = ref({
	sessionTimeout: userSettingsStore.preferences.sessionTimeout ?? 30,
	notifyShares: userSettingsStore.preferences.notifyShares ?? true,
	notifyRequests: userSettingsStore.preferences.notifyRequests ?? true,
	notifyGroupShares: userSettingsStore.preferences.notifyGroupShares ?? false,
	notifySecurity: userSettingsStore.preferences.notifySecurity ?? true,
})

const saved = ref(false)

watch(() => userSettingsStore.preferences, (p) => {
	form.value.sessionTimeout = p.sessionTimeout ?? 30
	form.value.notifyShares = p.notifyShares ?? true
	form.value.notifyRequests = p.notifyRequests ?? true
	form.value.notifyGroupShares = p.notifyGroupShares ?? false
	form.value.notifySecurity = p.notifySecurity ?? true
}, { deep: true })

/**
 *
 */
async function save() {
	await userSettingsStore.updatePreferences({
		sessionTimeout: form.value.sessionTimeout,
		notifyShares: form.value.notifyShares,
		notifyRequests: form.value.notifyRequests,
		notifyGroupShares: form.value.notifyGroupShares,
		notifySecurity: form.value.notifySecurity,
	})
	saved.value = true
	setTimeout(() => { saved.value = false }, 2000)
}
</script>

<style scoped>
.user-prefs {
	display: flex;
	flex-direction: column;
	gap: 16px;
	max-width: 480px;
}

.user-prefs__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.user-prefs__label {
	font-weight: 600;
	font-size: 0.9rem;
}

.user-prefs__slider {
	width: 100%;
	cursor: pointer;
}

.user-prefs__range-hints {
	display: flex;
	justify-content: space-between;
	font-size: 0.75rem;
	color: var(--color-text-maxcontrast);
}

.user-prefs__group {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.user-prefs__group-title {
	font-size: 0.9rem;
	font-weight: 600;
	margin: 0;
}

.user-prefs__saved {
	color: var(--color-success);
	font-size: 0.85rem;
}
</style>
