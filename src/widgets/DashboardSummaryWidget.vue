<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  DashboardSummaryWidget — the real vault-summary tile for the doriath
  dashboard. Registered as `kind: "widget"` under the widgetKey
  `doriath-summary` (see src/registry.js); referenced from the Dashboard
  page's `widgets[]` array in src/manifest.json.

  Fetches GET /api/dashboard/summary via the dashboard Pinia store and renders:
    - a loading spinner while the summary loads
    - an empty state guiding new users to create their first secret
    - a migration banner (NcNoteCard) when a suite migration is in progress
      or completed with errors
    - four KPI cards (active secrets, shared, folders, compromised)
    - an admin-only CA-health line and pending-applications counter

  Replaces the static placeholder `stats-block` tiles. The four DashboardKpiCard
  instances are custom (per design.md D2), not CnStatsBlock.
-->
<template>
	<div class="doriath-summary">
		<h3 v-if="title" class="doriath-summary__title">
			{{ title }}
		</h3>

		<div v-if="store.isLoading" class="doriath-summary__loading">
			<NcLoadingIcon :size="32" />
		</div>

		<NcEmptyContent
			v-else-if="store.isEmpty"
			:name="t('doriath', 'Your vault is empty')"
			:description="t('doriath', 'Create your first secret to start protecting your credentials.')">
			<template #icon>
				<KeyIcon :size="48" />
			</template>
		</NcEmptyContent>

		<template v-else>
			<NcNoteCard
				v-if="migrationState === 'in_progress'"
				type="warning">
				{{ t('doriath', 'An encryption-suite migration is in progress. Some secrets may be temporarily read-only until it completes.') }}
			</NcNoteCard>
			<NcNoteCard
				v-else-if="migrationState === 'completed_with_errors'"
				type="error">
				{{ t('doriath', 'The last encryption-suite migration completed with errors. Review your secrets and contact an administrator.') }}
			</NcNoteCard>

			<div class="doriath-summary__grid">
				<DashboardKpiCard
					:title="t('doriath', 'Total secrets')"
					:count="summary.total_secrets"
					variant="primary"
					icon-class="icon-password" />
				<DashboardKpiCard
					:title="t('doriath', 'Shared')"
					:count="summary.shared_secrets"
					variant="default"
					icon-class="icon-share" />
				<DashboardKpiCard
					:title="t('doriath', 'Folders')"
					:count="summary.folder_count"
					variant="default"
					icon-class="icon-folder" />
				<DashboardKpiCard
					:title="t('doriath', 'Compromised')"
					:count="summary.compromised_count"
					:variant="summary.compromised_count > 0 ? 'warning' : 'success'"
					icon-class="icon-error" />
			</div>

			<div v-if="summary.pending_apps_count !== null && summary.pending_apps_count > 0" class="doriath-summary__admin">
				<NcNoteCard type="info">
					{{ t('doriath', 'Applications awaiting approval: {count}', { count: summary.pending_apps_count }) }}
				</NcNoteCard>
			</div>

			<div v-if="caStatus" class="doriath-summary__ca">
				<span class="doriath-summary__ca-dot" :class="`doriath-summary__ca-dot--${caDotClass}`" />
				<span>{{ t('doriath', 'Certificate Authority') }}: {{ caStatusLabel }}</span>
			</div>
		</template>
	</div>
</template>

<script>
import { NcEmptyContent, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import KeyIcon from 'vue-material-design-icons/Key.vue'
import DashboardKpiCard from '../components/dashboard/DashboardKpiCard.vue'
import { useDashboardStore } from '../store/modules/dashboard.js'

export default {
	name: 'DashboardSummaryWidget',

	components: {
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		KeyIcon,
		DashboardKpiCard,
	},

	props: {
		/**
		 * Optional widget title. Sourced from `widgets[].props.title`.
		 */
		title: {
			type: String,
			default: '',
		},
	},

	computed: {
		/**
		 * @spec exclude Store-ref passthrough — returns the Pinia dashboard store with no domain logic.
		 */
		store() {
			return useDashboardStore()
		},
		/**
		 * The current summary payload (or an empty object before load).
		 *
		 * @return {object} Summary object.
		 */
		summary() {
			return this.store.summary ?? {}
		},
		/**
		 * The migration banner state, if any.
		 *
		 * @return {string|null} 'in_progress', 'completed_with_errors', or null.
		 */
		migrationState() {
			return this.summary.migration_status?.state ?? null
		},
		/**
		 * The admin CA status object, if present.
		 *
		 * @return {object|null} CA status, or null for non-admins.
		 */
		caStatus() {
			return this.summary.ca_status ?? null
		},
		/**
		 * Colour token for the CA health indicator dot.
		 *
		 * @return {string} 'green' | 'yellow' | 'red' | 'grey'.
		 */
		caDotClass() {
			const map = {
				healthy: 'green',
				expiring_soon: 'yellow',
				action_required: 'red',
				not_configured: 'grey',
			}
			return map[this.caStatus?.status] || 'grey'
		},
		/**
		 * Translated CA health label.
		 *
		 * @return {string} Human-readable status.
		 */
		caStatusLabel() {
			const map = {
				healthy: this.t('doriath', 'Healthy'),
				expiring_soon: this.t('doriath', 'Expiring soon'),
				action_required: this.t('doriath', 'Action required'),
				not_configured: this.t('doriath', 'Not configured'),
			}
			return map[this.caStatus?.status] || this.t('doriath', 'Unknown')
		},
	},

	/**
	 * Load the dashboard summary when the widget mounts.
	 *
	 * @spec openspec/changes/implement-dashboard-settings/tasks.md#task-3.7
	 */
	async mounted() {
		await this.store.fetchSummary()
	},
}
</script>

<style scoped>
.doriath-summary {
	padding: 1rem;
	height: 100%;
	box-sizing: border-box;
}

.doriath-summary__title {
	margin: 0 0 0.75rem 0;
	font-size: 1rem;
	font-weight: bold;
}

.doriath-summary__loading {
	display: flex;
	justify-content: center;
	padding: 2rem 0;
}

.doriath-summary__grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
	gap: 0.75rem;
	margin-top: 0.5rem;
}

.doriath-summary__ca {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	margin-top: 0.75rem;
	font-size: 0.9rem;
}

.doriath-summary__ca-dot {
	width: 10px;
	height: 10px;
	border-radius: 50%;
	display: inline-block;
}

.doriath-summary__ca-dot--green { background: var(--color-success); }

.doriath-summary__ca-dot--yellow { background: var(--color-warning); }

.doriath-summary__ca-dot--red { background: var(--color-error); }

.doriath-summary__ca-dot--grey { background: var(--color-text-lighter); }
</style>
