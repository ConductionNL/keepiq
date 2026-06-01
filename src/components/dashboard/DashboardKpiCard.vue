<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  DashboardKpiCard — custom security-focused KPI tile for the doriath
  vault dashboard. Per the dashboard spec (D2) doriath ships its own KPI
  card instead of the library's CnStatsBlock so the compromised-count
  tile can switch to a warning variant when the count is non-zero.

  Registered as `kind: "widget"` under widgetKey `doriath-kpi-card`
  (see src/registry.js); each manifest dashboard `widgets[]` entry binds
  a `metric` prop that selects which field of the dashboard summary the
  tile renders. The summary is fetched once via the shared dashboard
  Pinia store.

  @spec openspec/changes/implement-dashboard-settings/specs/dashboard/spec.md
-->
<template>
	<div class="doriath-kpi-card" :class="`doriath-kpi-card--${effectiveVariant}`">
		<div class="doriath-kpi-card__icon">
			<ShieldKeyOutline v-if="icon === 'secrets'" :size="32" />
			<ShareVariantOutline v-else-if="icon === 'shared'" :size="32" />
			<FolderOutline v-else-if="icon === 'folders'" :size="32" />
			<AlertCircleOutline v-else :size="32" />
		</div>
		<div class="doriath-kpi-card__body">
			<div class="doriath-kpi-card__count">
				{{ count }}
			</div>
			<div class="doriath-kpi-card__title">
				{{ title }}
			</div>
		</div>
	</div>
</template>

<script>
import { mapState } from 'pinia'
import ShieldKeyOutline from 'vue-material-design-icons/ShieldKeyOutline.vue'
import ShareVariantOutline from 'vue-material-design-icons/ShareVariantOutline.vue'
import FolderOutline from 'vue-material-design-icons/FolderOutline.vue'
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'
import { useDashboardStore } from '../../store/modules/dashboard.js'

export default {
	name: 'DashboardKpiCard',

	components: {
		ShieldKeyOutline,
		ShareVariantOutline,
		FolderOutline,
		AlertCircleOutline,
	},

	props: {
		/**
		 * Translated card title (e.g. "Total Secrets"). Sourced from the
		 * manifest widget props.
		 */
		title: {
			type: String,
			default: '',
		},
		/**
		 * Which summary field this card renders. One of: total_secrets,
		 * shared_secrets, folder_count, compromised_count.
		 */
		metric: {
			type: String,
			default: 'total_secrets',
		},
		/**
		 * Icon token: secrets | shared | folders | compromised.
		 */
		icon: {
			type: String,
			default: 'secrets',
		},
		/**
		 * Base colour variant. The compromised card upgrades to `warning`
		 * automatically when its count is greater than zero.
		 */
		variant: {
			type: String,
			default: 'default',
			validator: (value) => ['default', 'primary', 'warning', 'success'].includes(value),
		},
	},

	/**
	 * Ensure the shared dashboard summary is fetched once when the first
	 * KPI card mounts. The store guards against duplicate concurrent
	 * fetches so the four cards do not each trigger a request.
	 *
	 * @spec openspec/changes/implement-dashboard-settings/specs/dashboard/spec.md
	 */
	created() {
		const store = useDashboardStore()
		if (store.summary === null && store.isLoading === false) {
			store.fetchSummary()
		}
	},

	computed: {
		...mapState(useDashboardStore, ['summary']),

		/**
		 * The numeric value for this card's metric, defaulting to zero
		 * while the summary is still loading.
		 *
		 * @return {number} The metric count.
		 * @spec openspec/changes/implement-dashboard-settings/specs/dashboard/spec.md
		 */
		count() {
			return this.summary?.[this.metric] ?? 0
		},

		/**
		 * Effective variant: the compromised card flips to `warning` when
		 * its count is non-zero, per the spec highlighting rule.
		 *
		 * @return {string} The variant class token.
		 * @spec openspec/changes/implement-dashboard-settings/specs/dashboard/spec.md
		 */
		effectiveVariant() {
			if (this.metric === 'compromised_count' && this.count > 0) {
				return 'warning'
			}
			return this.variant
		},
	},
}
</script>

<style scoped>
.doriath-kpi-card {
	width: 100%;
	height: 100%;
	display: flex;
	align-items: center;
	gap: 1rem;
	padding: 1rem;
	border-radius: var(--border-radius-large);
	background-color: var(--color-background-hover);
	color: var(--color-main-text);
}

.doriath-kpi-card--primary {
	background-color: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
}

.doriath-kpi-card--success {
	background-color: var(--color-success);
	color: var(--color-primary-element-text);
}

.doriath-kpi-card--warning {
	background-color: var(--color-warning);
	color: var(--color-primary-element-text);
}

.doriath-kpi-card__icon {
	display: flex;
	align-items: center;
	justify-content: center;
}

.doriath-kpi-card__count {
	font-size: 2rem;
	font-weight: bold;
	line-height: 1;
}

.doriath-kpi-card__title {
	margin-top: 0.25rem;
	font-size: 0.9rem;
	opacity: 0.9;
}
</style>
