<!--
  SPDX-License-Identifier: EUPL-1.2
  Copyright (C) 2026 Conduction B.V.
-->
<template>
	<div class="kpi-card" :class="{ 'kpi-card--warning': variant === 'warning', 'kpi-card--error': variant === 'error' }">
		<div class="kpi-card__icon">
			<slot name="icon" />
		</div>
		<div class="kpi-card__body">
			<span class="kpi-card__value">{{ formattedValue }}</span>
			<span class="kpi-card__label">{{ label }}</span>
		</div>
	</div>
</template>

<script setup>
/**
 * DashboardKpiCard
 *
 * Displays a single KPI metric with an icon, numeric value and label.
 * Supports 'default', 'warning' and 'error' variants.
 */
</script>

<script>
import { computed } from 'vue'
const props = defineProps({
	/** Numeric value to display. */
	value: {
		type: Number,
		required: true,
	},
	/** Human-readable label shown below the value. */
	label: {
		type: String,
		required: true,
	},
	/** Visual variant: 'default' | 'warning' | 'error'. */
	variant: {
		type: String,
		default: 'default',
		validator: (v) => ['default', 'warning', 'error'].includes(v),
	},
})

const formattedValue = computed(() => {
	if (props.value >= 1_000_000) return `${(props.value / 1_000_000).toFixed(1)}M`
	if (props.value >= 1_000) return `${(props.value / 1_000).toFixed(1)}K`
	return String(props.value)
})
</script>

<style scoped>
.kpi-card {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 16px 20px;
	border-radius: var(--border-radius-large, 8px);
	background: var(--color-main-background);
	box-shadow: 0 1px 3px rgba(0, 0, 0, .08);
	min-width: 160px;
}

.kpi-card--warning {
	border-left: 4px solid var(--color-warning);
}

.kpi-card--error {
	border-left: 4px solid var(--color-error);
}

.kpi-card__body {
	display: flex;
	flex-direction: column;
}

.kpi-card__value {
	font-size: 1.75rem;
	font-weight: 700;
	line-height: 1;
	color: var(--color-main-text);
}

.kpi-card__label {
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
	margin-top: 4px;
}
</style>
