<!--
  SPDX-License-Identifier: EUPL-1.2
  Copyright (C) 2026 Conduction B.V.
-->
<template>
	<!-- TODO (V1): Wire to SecretMapper.findRecentlyAccessed(userId, 5) once #2 lands. -->
	<div class="recent-secrets-widget">
		<h3 class="recent-secrets-widget__title">
			{{ t('app-template', 'Recently accessed') }}
		</h3>
		<NcEmptyContent v-if="secrets.length === 0"
			:name="t('app-template', 'No recent secrets')"
			:description="t('app-template', 'Secrets you access will appear here.')">
			<template #icon>
				<KeyIcon :size="20" />
			</template>
		</NcEmptyContent>
		<ul v-else class="recent-secrets-widget__list">
			<li v-for="secret in secrets" :key="secret.id" class="recent-secrets-widget__item">
				<KeyIcon :size="16" class="recent-secrets-widget__item-icon" />
				<span class="recent-secrets-widget__item-name">{{ secret.name }}</span>
				<span class="recent-secrets-widget__item-time">{{ secret.accessedAt }}</span>
			</li>
		</ul>
	</div>
</template>

<script setup>
/**
 * RecentSecretsWidget (V1 stub)
 *
 * Lists the 5 most recently accessed secrets for the current user.
 * The list is passed in as a prop; fetching is handled by useDashboardStore.
 */
import { NcEmptyContent } from '@nextcloud/vue'
import KeyIcon from 'vue-material-design-icons/Key.vue'

defineProps({
	/**
	 * Array of recently-accessed secrets.
	 * Each item: { id: string, name: string, accessedAt: string }
	 */
	secrets: {
		type: Array,
		default: () => [],
	},
})
</script>

<style scoped>
.recent-secrets-widget__title {
	font-size: 1rem;
	font-weight: 600;
	margin: 0 0 8px;
}

.recent-secrets-widget__list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.recent-secrets-widget__item {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 6px 0;
	border-bottom: 1px solid var(--color-border);
}

.recent-secrets-widget__item:last-child {
	border-bottom: none;
}

.recent-secrets-widget__item-icon {
	flex-shrink: 0;
	color: var(--color-text-maxcontrast);
}

.recent-secrets-widget__item-name {
	flex: 1;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.recent-secrets-widget__item-time {
	font-size: 0.75rem;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
}
</style>
