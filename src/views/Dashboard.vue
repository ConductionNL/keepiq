<template>
	<div class="doriath-dashboard">
		<header class="doriath-dashboard__header">
			<h2>{{ t('doriath', 'Dashboard') }}</h2>
			<p class="doriath-dashboard__lead">
				{{ t('doriath', 'Starter overview with sample KPIs and activity placeholders. Replace this view with your own data.') }}
			</p>
		</header>

		<CnKpiGrid :columns="4">
			<CnStatsBlock
				:title="t('doriath', 'Open items')"
				:count="12"
				:count-label="t('doriath', 'sample')"
				:icon="FolderOutline"
				variant="primary"
				horizontal />
			<CnStatsBlock
				:title="t('doriath', 'Due this week')"
				:count="5"
				:count-label="t('doriath', 'sample')"
				:icon="CalendarClock"
				variant="warning"
				horizontal />
			<CnStatsBlock
				:title="t('doriath', 'Completed')"
				:count="48"
				:count-label="t('doriath', 'sample')"
				:icon="CheckCircleOutline"
				variant="success"
				horizontal />
			<CnStatsBlock
				:title="t('doriath', 'Team members')"
				:count="7"
				:count-label="t('doriath', 'sample')"
				:icon="AccountGroupOutline"
				variant="default"
				horizontal />
		</CnKpiGrid>

		<div class="doriath-dashboard__columns">
			<CnConfigurationCard :title="t('doriath', 'Recent activity')">
				<ul class="doriath-dashboard__placeholder-list">
					<li>{{ t('doriath', 'Placeholder: user opened a record') }}</li>
					<li>{{ t('doriath', 'Placeholder: status changed to Review') }}</li>
					<li>{{ t('doriath', 'Placeholder: comment added') }}</li>
				</ul>
			</CnConfigurationCard>

			<CnConfigurationCard :title="t('doriath', 'Quick actions')">
				<p class="doriath-dashboard__hint">
					{{ t('doriath', 'Wire buttons here to create records, open lists, or deep links. Use the sidebar for Settings and Documentation.') }}
				</p>
			</CnConfigurationCard>
		</div>
	</div>
</template>

<script>
import { CnConfigurationCard, CnKpiGrid, CnStatsBlock } from '@conduction/nextcloud-vue'
import AccountGroupOutline from 'vue-material-design-icons/AccountGroupOutline.vue'
import CalendarClock from 'vue-material-design-icons/CalendarClock.vue'
import CheckCircleOutline from 'vue-material-design-icons/CheckCircleOutline.vue'
import FolderOutline from 'vue-material-design-icons/FolderOutline.vue'

export default {
	name: 'Dashboard',
	components: {
		CnConfigurationCard,
		CnKpiGrid,
		CnStatsBlock,
	},
	data() {
		return {
			FolderOutline,
			CalendarClock,
			CheckCircleOutline,
			AccountGroupOutline,
		}
	},
}
</script>

<style scoped>
.doriath-dashboard {
	padding: 8px 4px 24px;
	max-width: 1200px;
}

.doriath-dashboard__header {
	margin-bottom: 20px;
}

.doriath-dashboard__header h2 {
	margin: 0 0 8px;
	font-size: 22px;
	font-weight: 600;
}

.doriath-dashboard__lead {
	margin: 0;
	color: var(--color-text-maxcontrast);
	line-height: 1.5;
}

.doriath-dashboard__columns {
	display: grid;
	grid-template-columns: repeat(2, 1fr);
	gap: 16px;
}

@media (max-width: 900px) {
	.doriath-dashboard__columns {
		grid-template-columns: 1fr;
	}
}

.doriath-dashboard__placeholder-list {
	margin: 0;
	padding-left: 1.2em;
	line-height: 1.6;
}

.doriath-dashboard__hint {
	margin: 0;
	line-height: 1.5;
	color: var(--color-text-maxcontrast);
}
</style>
