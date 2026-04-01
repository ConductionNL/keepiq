<!--
  SPDX-License-Identifier: EUPL-1.2
  Copyright (C) 2026 Conduction B.V.
-->
<template>
	<NcNoteCard v-if="show" :type="type" class="migration-banner">
		<template #default>
			<span v-if="failedCount > 0">
				{{ n('app-template',
					'%n secret failed migration — please review.',
					'%n secrets failed migration — please review.',
					failedCount) }}
			</span>
			<span v-else-if="pendingCount > 0">
				{{ n('app-template',
					'%n secret is pending migration.',
					'%n secrets are pending migration.',
					pendingCount) }}
			</span>
		</template>
	</NcNoteCard>
</template>

<script setup>
/**
 * MigrationBanner
 *
 * Shows a warning or error banner when secrets have pending or failed migrations.
 * Hidden when both counts are zero.
 */
import { computed } from 'vue'
import { NcNoteCard } from '@nextcloud/vue'

const props = defineProps({
	/** Number of secrets with pending migration. */
	pendingCount: {
		type: Number,
		default: 0,
	},
	/** Number of secrets whose migration failed. */
	failedCount: {
		type: Number,
		default: 0,
	},
})

const show = computed(() => props.pendingCount > 0 || props.failedCount > 0)
const type = computed(() => props.failedCount > 0 ? 'error' : 'warning')
</script>

<style scoped>
.migration-banner {
	margin-bottom: 16px;
}
</style>
