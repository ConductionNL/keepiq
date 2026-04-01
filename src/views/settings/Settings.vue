<!--
  SPDX-License-Identifier: EUPL-1.2
  Copyright (C) 2026 Conduction B.V.
-->
<template>
	<div class="doriath-admin-settings">
		<PasswordPolicySection />
		<CaHealthSection />
		<ApplicationQueueSection />
	</div>
</template>

<script setup>
/**
 * Settings (admin)
 *
 * Renders the three admin setting sections:
 * 1. Master Password Policy
 * 2. CA Health (V1 stub)
 * 3. Application Approval Queue
 *
 * The old register-form that shipped with the app template has been
 * replaced by vault-specific configuration.
 */
import { onMounted } from 'vue'
import { useAdminSettingsStore } from '../../store/modules/adminSettings.js'
import PasswordPolicySection from '../../components/settings/PasswordPolicySection.vue'
import CaHealthSection from '../../components/settings/CaHealthSection.vue'
import ApplicationQueueSection from '../../components/settings/ApplicationQueueSection.vue'

const adminSettingsStore = useAdminSettingsStore()

onMounted(() => {
	adminSettingsStore.fetchSettings()
})
</script>

<style scoped>
.doriath-admin-settings {
	display: flex;
	flex-direction: column;
	gap: 24px;
}
</style>
