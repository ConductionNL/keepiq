<!--
  SPDX-License-Identifier: EUPL-1.2
  Copyright (C) 2026 Conduction B.V.
-->
<template>
	<CnSettingsSection :name="t('app-template', 'Application Approval Queue')"
		:description="t('app-template', 'Review and approve or reject applications requesting vault access.')">
		<div class="app-queue">
			<NcLoadingIcon v-if="loading" :size="32" />

			<NcEmptyContent v-else-if="queue.length === 0"
				:name="t('app-template', 'No pending applications')"
				:description="t('app-template', 'All application requests have been processed.')">
				<template #icon>
					<CheckIcon :size="20" />
				</template>
			</NcEmptyContent>

			<ul v-else class="app-queue__list">
				<li v-for="app in queue"
					:key="app.id"
					class="app-queue__item">
					<div class="app-queue__item-info">
						<span class="app-queue__item-name">{{ app.name }}</span>
						<span class="app-queue__item-meta">{{ app.requestedAt }}</span>
					</div>
					<div class="app-queue__item-actions">
						<NcButton type="success"
							:aria-label="t('app-template', 'Approve {name}', { name: app.name })"
							@click="approve(app.id)">
							{{ t('app-template', 'Approve') }}
						</NcButton>
						<NcButton type="error"
							:aria-label="t('app-template', 'Reject {name}', { name: app.name })"
							@click="reject(app.id)">
							{{ t('app-template', 'Reject') }}
						</NcButton>
					</div>
				</li>
			</ul>
		</div>
	</CnSettingsSection>
</template>

<script setup>
/**
 * ApplicationQueueSection — admin-only
 *
 * Displays applications awaiting approval and provides approve/reject actions.
 * The queue data is managed by useAdminSettingsStore.
 */
import { computed } from 'vue'
import { NcButton, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import { CnSettingsSection } from '@conduction/nextcloud-vue'
import { useAdminSettingsStore } from '../../store/modules/adminSettings.js'

const adminSettingsStore = useAdminSettingsStore()
const queue = computed(() => adminSettingsStore.applicationQueue)
const loading = computed(() => adminSettingsStore.loadingQueue)

/**
 * Approve a pending application.
 *
 * @param {string} appId The application identifier.
 * @return {Promise<void>}
 */
async function approve(appId) {
	await adminSettingsStore.approveApplication(appId)
}

/**
 * Reject a pending application.
 *
 * @param {string} appId The application identifier.
 * @return {Promise<void>}
 */
async function reject(appId) {
	await adminSettingsStore.rejectApplication(appId)
}
</script>

<style scoped>
.app-queue__list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.app-queue__item {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 10px 0;
	border-bottom: 1px solid var(--color-border);
	gap: 12px;
}

.app-queue__item:last-child {
	border-bottom: none;
}

.app-queue__item-info {
	display: flex;
	flex-direction: column;
	flex: 1;
	min-width: 0;
}

.app-queue__item-name {
	font-weight: 600;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.app-queue__item-meta {
	font-size: 0.75rem;
	color: var(--color-text-maxcontrast);
}

.app-queue__item-actions {
	display: flex;
	gap: 8px;
	flex-shrink: 0;
}
</style>
