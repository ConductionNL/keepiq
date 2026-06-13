<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Custom KPI card for the doriath dashboard. Deliberately NOT the
  library's CnStatsBlock — that widget is bound to an OR-backed
  dataSource which doriath does not register. We render the count
  directly from the summary payload returned by DashboardController.

  @spec openspec/changes/implement-dashboard-settings/tasks.md#task-3.1
-->
<template>
	<article
		class="doriath-kpi-card"
		:class="`doriath-kpi-card--${variant}`"
		data-testid="dashboard-kpi-card">
		<span v-if="iconClass"
			class="doriath-kpi-card__icon"
			:class="iconClass"
			aria-hidden="true" />
		<span class="doriath-kpi-card__count" data-testid="dashboard-kpi-count">{{ count }}</span>
		<span class="doriath-kpi-card__title">{{ title }}</span>
		<span v-if="subtitle" class="doriath-kpi-card__subtitle">{{ subtitle }}</span>
	</article>
</template>

<script>
export default {
	name: 'DashboardKpiCard',
	props: {
		title: {
			type: String,
			required: true,
		},
		count: {
			type: [Number, String],
			required: true,
		},
		subtitle: {
			type: String,
			default: '',
		},
		iconClass: {
			type: String,
			default: '',
		},
		variant: {
			type: String,
			default: 'default',
			validator: (v) => ['default', 'primary', 'warning', 'success', 'danger'].includes(v),
		},
	},
}
</script>

<style scoped>
.doriath-kpi-card {
	display: flex;
	flex-direction: column;
	gap: 4px;
	padding: 16px;
	border-radius: var(--border-radius-large, 12px);
	background-color: var(--color-main-background, #fff);
	border: 1px solid var(--color-border, #ddd);
	color: var(--color-main-text, #000);
	min-height: 96px;
}
.doriath-kpi-card__icon {
	display: inline-block;
	width: 24px;
	height: 24px;
}
.doriath-kpi-card__count {
	font-size: 32px;
	font-weight: 700;
	line-height: 1;
}
.doriath-kpi-card__title {
	font-size: 14px;
	font-weight: 600;
}
.doriath-kpi-card__subtitle {
	font-size: 12px;
	color: var(--color-text-maxcontrast, #777);
}
.doriath-kpi-card--primary {
	border-color: var(--color-primary-element, #0082c9);
}
.doriath-kpi-card--warning {
	border-color: var(--color-warning, #c08410);
}
.doriath-kpi-card--success {
	border-color: var(--color-success, #46ba61);
}
.doriath-kpi-card--danger {
	border-color: var(--color-error, #e9322d);
}
</style>
