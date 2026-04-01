<!--
  SPDX-License-Identifier: EUPL-1.2
  Copyright (C) 2026 Conduction B.V.
-->
<template>
	<!-- TODO (V1): Replace stub with full CA health details once CA management (#1) is done. -->
	<div class="ca-health-card">
		<div class="ca-health-card__indicator" :class="indicatorClass" />
		<div class="ca-health-card__body">
			<span class="ca-health-card__title">{{ t('app-template', 'Certificate Authority') }}</span>
			<span class="ca-health-card__status">{{ statusLabel }}</span>
		</div>
	</div>
</template>

<script setup>
/**
 * CaHealthCard (V1 stub) — admin-only
 *
 * Displays the current CA health status with a colour indicator.
 * Full management actions (retry bootstrap, force renew) are deferred to V1.
 */
import { computed } from 'vue'

const props = defineProps({
	/** CA status: 'healthy' | 'degraded' | 'unknown' */
	status: {
		type: String,
		default: 'unknown',
		validator: (v) => ['healthy', 'degraded', 'unknown'].includes(v),
	},
})

const indicatorClass = computed(() => ({
	'ca-health-card__indicator--healthy': props.status === 'healthy',
	'ca-health-card__indicator--degraded': props.status === 'degraded',
	'ca-health-card__indicator--unknown': props.status === 'unknown',
}))

const statusLabel = computed(() => {
	const map = {
		healthy: t('app-template', 'Healthy'),
		degraded: t('app-template', 'Degraded'),
		unknown: t('app-template', 'Unknown'),
	}
	return map[props.status] ?? map.unknown
})
</script>

<style scoped>
.ca-health-card {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 12px 16px;
	border-radius: var(--border-radius, 4px);
	background: var(--color-main-background);
	box-shadow: 0 1px 3px rgba(0, 0, 0, .08);
}

.ca-health-card__indicator {
	width: 12px;
	height: 12px;
	border-radius: 50%;
	flex-shrink: 0;
}

.ca-health-card__indicator--healthy  { background: var(--color-success); }
.ca-health-card__indicator--degraded { background: var(--color-warning); }
.ca-health-card__indicator--unknown  { background: var(--color-text-maxcontrast); }

.ca-health-card__body {
	display: flex;
	flex-direction: column;
}

.ca-health-card__title {
	font-weight: 600;
	font-size: 0.9rem;
}

.ca-health-card__status {
	font-size: 0.8rem;
	color: var(--color-text-maxcontrast);
}
</style>
