<!--
  SPDX-License-Identifier: EUPL-1.2
  Copyright (C) 2026 Conduction B.V.
-->
<template>
	<NcAppSettingsDialog :open.sync="dialogOpen"
		:name="t('app-template', 'Doriath settings')"
		:show-navigation="true">
		<NcAppSettingsSection id="doriath-user-preferences"
			:name="t('app-template', 'Preferences')">
			<UserPreferencesSection />
		</NcAppSettingsSection>
	</NcAppSettingsDialog>
</template>

<script setup>
/**
 * UserSettingsDialog
 *
 * Registers user-facing preferences inside Nextcloud's NcAppSettingsDialog.
 * Opened programmatically by emitting 'open-settings' on the event bus or by
 * including this component and binding the open prop.
 */
import { ref, onMounted } from 'vue'
import { NcAppSettingsDialog, NcAppSettingsSection } from '@nextcloud/vue'
import { useUserSettingsStore } from '../store/modules/userSettings.js'
import UserPreferencesSection from '../components/settings/UserPreferencesSection.vue'

const props = defineProps({
	/** Controls dialog visibility from the parent. */
	open: {
		type: Boolean,
		default: false,
	},
})

defineEmits(['update:open'])

const dialogOpen = ref(props.open)

const userSettingsStore = useUserSettingsStore()

onMounted(() => {
	userSettingsStore.fetchPreferences()
})
</script>
