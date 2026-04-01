<!--
  SPDX-License-Identifier: EUPL-1.2
  Copyright (C) 2026 Conduction B.V.
-->
<template>
	<div class="pending-apps-card">
		<DashboardKpiCard :value="pendingCount"
			:label="t('app-template', 'Pending app approvals')"
			:variant="pendingCount > 0 ? 'warning' : 'default'">
			<template #icon>
				<ApplicationIcon :size="28" />
			</template>
		</DashboardKpiCard>
		<NcButton v-if="pendingCount > 0"
			type="tertiary"
			class="pending-apps-card__link"
			@click="goToQueue">
			{{ t('app-template', 'Review queue') }}
		</NcButton>
	</div>
</template>

<script setup>
/**
 * PendingAppsCard — admin-only
 *
 * Shows the count of applications waiting for admin approval and provides a
 * link to the application queue in admin settings.
 */
import { NcButton } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import ApplicationIcon from 'vue-material-design-icons/Application.vue'
import DashboardKpiCard from './DashboardKpiCard.vue'

defineProps({
	/** Number of applications awaiting approval. */
	pendingCount: {
		type: Number,
		default: 0,
	},
})

/**
 *
 */
function goToQueue() {
	window.location.href = generateUrl('/settings/admin/app-template')
}
</script>

<style scoped>
.pending-apps-card {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.pending-apps-card__link {
	align-self: flex-start;
}
</style>
