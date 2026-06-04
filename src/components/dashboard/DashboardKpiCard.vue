<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  DashboardKpiCard — security-focused KPI tile for the doriath dashboard.
  Deliberately NOT CnStatsBlock: doriath's cards carry a `warning` variant for
  the compromised-secrets count and security-specific theming. Rendered by
  DashboardSummaryWidget.
-->
<template>
	<div class="doriath-kpi" :class="`doriath-kpi--${variant}`">
		<span v-if="iconClass" class="doriath-kpi__icon" :class="iconClass" />
		<div class="doriath-kpi__body">
			<div class="doriath-kpi__count">
				{{ count }}
			</div>
			<div class="doriath-kpi__title">
				{{ title }}
			</div>
		</div>
	</div>
</template>

<script>
export default {
	name: 'DashboardKpiCard',

	props: {
		/**
		 * The KPI label rendered under the count.
		 */
		title: {
			type: String,
			default: '',
		},
		/**
		 * The numeric KPI value displayed prominently.
		 */
		count: {
			type: [Number, String],
			default: 0,
		},
		/**
		 * Colour variant driving the background/foreground theming.
		 */
		variant: {
			type: String,
			default: 'default',
			validator: (value) => ['default', 'primary', 'success', 'warning'].includes(value),
		},
		/**
		 * Optional Nextcloud core icon class rendered beside the count.
		 */
		iconClass: {
			type: String,
			default: '',
		},
	},
}
</script>

<style scoped>
.doriath-kpi {
	display: flex;
	align-items: center;
	gap: 0.75rem;
	padding: 1rem;
	height: 100%;
	box-sizing: border-box;
	border-radius: var(--border-radius-large);
	background-color: var(--color-background-hover);
	color: var(--color-main-text);
}

.doriath-kpi--primary {
	background-color: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
}

.doriath-kpi--success {
	background-color: var(--color-success);
	color: var(--color-primary-element-text);
}

.doriath-kpi--warning {
	background-color: var(--color-warning);
	color: var(--color-primary-element-text);
}

.doriath-kpi__icon {
	font-size: 1.5rem;
	opacity: 0.85;
}

.doriath-kpi__count {
	font-size: 2rem;
	font-weight: bold;
	line-height: 1;
}

.doriath-kpi__title {
	margin-top: 0.25rem;
	font-size: 0.9rem;
	opacity: 0.9;
}
</style>
