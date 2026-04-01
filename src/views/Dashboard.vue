<!--
  SPDX-License-Identifier: EUPL-1.2
  Copyright (C) 2026 Conduction B.V.
-->
<template>
	<div class="dashboard">
		<!-- Migration warning/error banner -->
		<MigrationBanner :pending-count="summary.migrationPending"
			:failed-count="summary.migrationFailed" />

		<!-- KPI grid -->
		<div class="dashboard__kpi-grid">
			<DashboardKpiCard :value="summary.totalSecrets"
				:label="t('app-template', 'Total secrets')">
				<template #icon>
					<KeyIcon :size="28" />
				</template>
			</DashboardKpiCard>

			<DashboardKpiCard :value="summary.sharedSecrets"
				:label="t('app-template', 'Shared secrets')">
				<template #icon>
					<ShareIcon :size="28" />
				</template>
			</DashboardKpiCard>

			<DashboardKpiCard :value="summary.totalFolders"
				:label="t('app-template', 'Folders')">
				<template #icon>
					<FolderIcon :size="28" />
				</template>
			</DashboardKpiCard>

			<DashboardKpiCard :value="summary.compromisedSecrets"
				:label="t('app-template', 'Compromised')"
				:variant="summary.compromisedSecrets > 0 ? 'error' : 'default'">
				<template #icon>
					<AlertIcon :size="28" />
				</template>
			</DashboardKpiCard>
		</div>

		<!-- Admin-only cards -->
		<div v-if="isAdmin" class="dashboard__admin-row">
			<PendingAppsCard :pending-count="summary.pendingApps" />
			<CaHealthCard :status="summary.caHealthy ? 'healthy' : 'degraded'" />
		</div>

		<!-- V1: Recently accessed widget -->
		<div class="dashboard__recent">
			<RecentSecretsWidget :secrets="recentSecrets" />
		</div>
	</div>
</template>

<script setup>
/**
 * Dashboard
 *
 * Main vault dashboard: KPI grid, migration banner, admin cards, and the
 * recently-accessed widget (V1 stub).
 */
import { computed, onMounted } from 'vue'
import KeyIcon from 'vue-material-design-icons/Key.vue'
import ShareIcon from 'vue-material-design-icons/Share.vue'
import FolderIcon from 'vue-material-design-icons/Folder.vue'
import AlertIcon from 'vue-material-design-icons/Alert.vue'
import { useDashboardStore } from '../store/modules/dashboard.js'
import { useSettingsStore } from '../store/modules/settings.js'
import DashboardKpiCard from '../components/dashboard/DashboardKpiCard.vue'
import MigrationBanner from '../components/dashboard/MigrationBanner.vue'
import PendingAppsCard from '../components/dashboard/PendingAppsCard.vue'
import CaHealthCard from '../components/dashboard/CaHealthCard.vue'
import RecentSecretsWidget from '../components/dashboard/RecentSecretsWidget.vue'

const dashboardStore = useDashboardStore()
const settingsStore = useSettingsStore()

const summary = computed(() => dashboardStore.summary)
const isAdmin = computed(() => settingsStore.getIsAdmin)
// TODO (V1): Replace with real data from SecretMapper once #2 lands.
const recentSecrets = computed(() => [])

onMounted(() => {
	dashboardStore.fetchSummary()
})
</script>

<style scoped>
.dashboard {
	padding: 20px;
	max-width: 1200px;
}

.dashboard__kpi-grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
	gap: 16px;
	margin-bottom: 24px;
}

.dashboard__admin-row {
	display: flex;
	flex-wrap: wrap;
	gap: 16px;
	margin-bottom: 24px;
}

.dashboard__recent {
	margin-top: 8px;
}
</style>
