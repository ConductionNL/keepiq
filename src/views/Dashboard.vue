<template>
	<CnDashboardPage
		:title="t('doriath', 'Dashboard')"
		:description="t('doriath', 'Starter overview with sample KPIs and activity placeholders. Replace this view with your own data.')"
		:widgets="widgetDefs"
		:layout="dashboardLayout"
		@layout-change="onLayoutChange">
		<!-- Open items KPI -->
		<template #widget-count-open-items>
			<CnStatsBlock
				:title="t('doriath', 'Open items')"
				:count="12"
				:count-label="t('doriath', 'sample')"
				:icon="FolderOutline"
				variant="primary"
				horizontal />
		</template>

		<!-- Due this week KPI -->
		<template #widget-count-due-this-week>
			<CnStatsBlock
				:title="t('doriath', 'Due this week')"
				:count="5"
				:count-label="t('doriath', 'sample')"
				:icon="CalendarClock"
				variant="warning"
				horizontal />
		</template>

		<!-- Completed KPI -->
		<template #widget-count-completed>
			<CnStatsBlock
				:title="t('doriath', 'Completed')"
				:count="48"
				:count-label="t('doriath', 'sample')"
				:icon="CheckCircleOutline"
				variant="success"
				horizontal />
		</template>

		<!-- Team members KPI -->
		<template #widget-count-team-members>
			<CnStatsBlock
				:title="t('doriath', 'Team members')"
				:count="7"
				:count-label="t('doriath', 'sample')"
				:icon="AccountGroupOutline"
				variant="default"
				horizontal />
		</template>

		<!-- Recent activity -->
		<template #widget-recent-activity>
			<ul class="doriath-dashboard__placeholder-list">
				<li>{{ t('doriath', 'Placeholder: user opened a record') }}</li>
				<li>{{ t('doriath', 'Placeholder: status changed to Review') }}</li>
				<li>{{ t('doriath', 'Placeholder: comment added') }}</li>
			</ul>
		</template>

		<!-- Quick actions -->
		<template #widget-quick-actions>
			<p class="doriath-dashboard__hint">
				{{ t('doriath', 'Wire buttons here to create records, open lists, or deep links. Use the sidebar for Settings and Documentation.') }}
			</p>
		</template>
	</CnDashboardPage>
</template>

<script>
import { CnDashboardPage, CnStatsBlock } from '@conduction/nextcloud-vue'
import AccountGroupOutline from 'vue-material-design-icons/AccountGroupOutline.vue'
import CalendarClock from 'vue-material-design-icons/CalendarClock.vue'
import CheckCircleOutline from 'vue-material-design-icons/CheckCircleOutline.vue'
import FolderOutline from 'vue-material-design-icons/FolderOutline.vue'

const DEFAULT_LAYOUT = [
	{ id: 1, widgetId: 'count-open-items', gridX: 0, gridY: 0, gridWidth: 3, gridHeight: 2, showTitle: false },
	{ id: 2, widgetId: 'count-due-this-week', gridX: 3, gridY: 0, gridWidth: 3, gridHeight: 2, showTitle: false },
	{ id: 3, widgetId: 'count-completed', gridX: 6, gridY: 0, gridWidth: 3, gridHeight: 2, showTitle: false },
	{ id: 4, widgetId: 'count-team-members', gridX: 9, gridY: 0, gridWidth: 3, gridHeight: 2, showTitle: false },
	{ id: 5, widgetId: 'recent-activity', gridX: 0, gridY: 2, gridWidth: 6, gridHeight: 4 },
	{ id: 6, widgetId: 'quick-actions', gridX: 6, gridY: 2, gridWidth: 6, gridHeight: 4 },
]

export default {
	name: 'Dashboard',
	components: {
		CnDashboardPage,
		CnStatsBlock,
	},
	data() {
		return {
			FolderOutline,
			CalendarClock,
			CheckCircleOutline,
			AccountGroupOutline,
			dashboardLayout: [...DEFAULT_LAYOUT],
		}
	},
	computed: {
		widgetDefs() {
			return [
				{ id: 'count-open-items', title: t('doriath', 'Open items'), type: 'custom' },
				{ id: 'count-due-this-week', title: t('doriath', 'Due this week'), type: 'custom' },
				{ id: 'count-completed', title: t('doriath', 'Completed'), type: 'custom' },
				{ id: 'count-team-members', title: t('doriath', 'Team members'), type: 'custom' },
				{ id: 'recent-activity', title: t('doriath', 'Recent activity'), type: 'custom' },
				{ id: 'quick-actions', title: t('doriath', 'Quick actions'), type: 'custom' },
			]
		},
	},
	methods: {
		onLayoutChange(newLayout) {
			this.dashboardLayout = newLayout
		},
	},
}
</script>

<style scoped>
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
