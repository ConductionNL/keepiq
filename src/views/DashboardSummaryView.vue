<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Server-summary panel for the doriath dashboard. Renders the KPI grid,
  the migration banner, and the pending-apps card from the payload
  returned by `GET /api/dashboard/summary`. Designed to drop into the
  manifest-v2 dashboard page as a standalone widget panel.

  @spec openspec/changes/implement-dashboard-settings/tasks.md#task-3.7
-->
<template>
	<section class="doriath-dashboard-summary" data-testid="dashboard-summary">
		<p v-if="store.isLoading && store.summary === null" data-testid="dashboard-summary-loading">
			{{ t('doriath', 'Loading dashboard…') }}
		</p>

		<div v-else-if="store.error" class="doriath-dashboard-summary__error" data-testid="dashboard-summary-error">
			{{ store.error }}
		</div>

		<div v-else-if="store.isEmpty" class="doriath-dashboard-summary__empty" data-testid="dashboard-summary-empty">
			<h3>{{ t('doriath', 'Welcome to Doriath') }}</h3>
			<p>{{ t('doriath', 'Create your first secret to start using the vault.') }}</p>
		</div>

		<div v-else-if="store.summary" class="doriath-dashboard-summary__body">
			<MigrationBanner
				:status="store.migrationStatus"
				:remaining="store.summary.migration_remaining || 0"
				:failed="store.summary.migration_failed || 0"
				@navigate="$emit('navigate', 'migration')" />

			<div class="doriath-dashboard-summary__grid">
				<DashboardKpiCard
					:title="t('doriath', 'Total secrets')"
					:count="store.summary.total_secrets || 0"
					variant="primary"
					icon-class="icon-password" />
				<DashboardKpiCard
					:title="t('doriath', 'Shared with you')"
					:count="store.summary.shared_secrets || 0"
					variant="default"
					icon-class="icon-share" />
				<DashboardKpiCard
					:title="t('doriath', 'Folders')"
					:count="store.summary.folder_count || 0"
					variant="default"
					icon-class="icon-folder" />
				<DashboardKpiCard
					:title="t('doriath', 'Possibly compromised')"
					:count="store.summary.compromised_count || 0"
					variant="danger"
					icon-class="icon-error" />
			</div>

			<PendingAppsCard
				v-if="isAdmin"
				:count="store.pendingAppsCount"
				@navigate="$emit('navigate', 'applications')" />
		</div>
	</section>
</template>

<script>
import { useDashboardStore } from '../store/modules/dashboard.js'
import DashboardKpiCard from '../widgets/DashboardKpiCard.vue'
import MigrationBanner from '../widgets/MigrationBanner.vue'
import PendingAppsCard from '../widgets/PendingAppsCard.vue'

export default {
	name: 'DashboardSummaryView',
	components: { DashboardKpiCard, MigrationBanner, PendingAppsCard },
	props: {
		isAdmin: {
			type: Boolean,
			default: false,
		},
	},
	emits: ['navigate'],
	data() {
		return {
			store: useDashboardStore(),
		}
	},
	mounted() {
		this.store.fetchSummary().catch(() => {})
	},
}
</script>

<style scoped>
.doriath-dashboard-summary__grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
	gap: 12px;
	margin: 12px 0;
}
.doriath-dashboard-summary__empty {
	text-align: center;
	padding: 32px;
}
.doriath-dashboard-summary__error {
	color: var(--color-error, #e9322d);
	padding: 12px;
	border: 1px solid var(--color-error, #e9322d);
	border-radius: var(--border-radius, 4px);
}
</style>
