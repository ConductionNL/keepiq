<!--
  SPDX-License-Identifier: EUPL-1.2
  Copyright (C) 2026 Conduction B.V.
-->
<template>
	<CnSettingsSection :name="t('app-template', 'Certificate Authority')"
		:description="t('app-template', 'View the health of the built-in CA used for end-to-end encryption.')">
		<div class="ca-health-section">
			<CaHealthCard :status="caStatus" />

			<!--
				TODO (V1): Add retry-bootstrap and force-renew buttons once
				CA management APIs are available (#1).
			-->
			<p class="ca-health-section__v1-note">
				{{ t('app-template', 'CA management actions will be available in a future release.') }}
			</p>
		</div>
	</CnSettingsSection>
</template>

<script setup>
/**
 * CaHealthSection (V1 stub) — admin-only
 *
 * Displays the current CA status inside the admin settings page.
 * Management actions (retry bootstrap, force renew) are scaffolded as
 * a TODO and will land in V1.
 */
import { computed } from 'vue'
import { CnSettingsSection } from '@conduction/nextcloud-vue'
import { useAdminSettingsStore } from '../../store/modules/adminSettings.js'
import CaHealthCard from '../dashboard/CaHealthCard.vue'

const adminSettingsStore = useAdminSettingsStore()
const caStatus = computed(() => adminSettingsStore.settings.caStatus ?? 'unknown')
</script>

<style scoped>
.ca-health-section {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.ca-health-section__v1-note {
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}
</style>
