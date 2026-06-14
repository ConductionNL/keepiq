<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Personal activity view (add-secret-audit-trail §5.3). Lists the session
  user's own recent operations, newest first. Strictly actor-scoped at the API
  — a user only ever sees entries they themselves performed.

  @spec openspec/changes/add-secret-audit-trail/tasks.md#task-5.3
-->
<template>
	<div class="personal-activity" data-testid="personal-activity-view">
		<h2 class="personal-activity__title">
			{{ t('doriath', 'My activity') }}
		</h2>

		<NcLoadingIcon v-if="loading" :size="24" />

		<NcEmptyContent v-else-if="entries.length === 0"
			:name="t('doriath', 'No activity recorded yet')"
			:description="t('doriath', 'Your secret operations will appear here. The trail starts at the deployment of this feature.')"
			data-testid="personal-activity-empty">
			<template #icon>
				<History :size="20" />
			</template>
		</NcEmptyContent>

		<ul v-else class="personal-activity__list">
			<li v-for="entry in entries"
				:key="entry.id"
				class="personal-activity__item"
				data-testid="personal-activity-item">
				<span class="personal-activity__event">{{ label(entry.eventType) }}</span>
				<span class="personal-activity__object">{{ entry.objectName || entry.objectId || '' }}</span>
				<span class="personal-activity__time">{{ formatTime(entry.occurredAt) }}</span>
			</li>
		</ul>
	</div>
</template>

<script>
import { NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import History from 'vue-material-design-icons/History.vue'
import { useAuditStore } from '../store/modules/audit.js'
import { auditEventLabel } from '../utils/auditEventLabels.js'

/**
 * The session user's personal activity feed.
 *
 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-5.3
 */
export default {
	name: 'PersonalActivityView',

	components: {
		NcEmptyContent,
		NcLoadingIcon,
		History,
	},

	computed: {
		/**
		 * The session user's personal audit entries.
		 *
		 * @return {object[]} The personal audit entries.
		 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-5.3
		 */
		entries() {
			return useAuditStore().personalEntries
		},
		/**
		 * Whether the audit store is loading.
		 *
		 * @return {boolean} The loading state.
		 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-5.3
		 */
		loading() {
			return useAuditStore().loading
		},
	},

	/**
	 * Load the personal activity feed on mount.
	 *
	 * @return {Promise<void>}
	 */
	async created() {
		await useAuditStore().fetchPersonalActivity()
	},

	methods: {
		/**
		 * Resolve a human-readable label for an event type.
		 *
		 * @param {string} eventType The audit event type.
		 * @return {string} The label.
		 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-5.3
		 */
		label(eventType) {
			return auditEventLabel(eventType)
		},
		/**
		 * Format an ISO timestamp as a localized date-time.
		 *
		 * @param {string} iso The ISO-8601 timestamp.
		 * @return {string} The formatted time.
		 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-5.3
		 */
		formatTime(iso) {
			if (!iso) {
				return ''
			}
			try {
				return new Date(iso).toLocaleString()
			} catch (e) {
				return iso
			}
		},
	},
}
</script>

<style scoped lang="scss">
.personal-activity {
	padding: 1rem;
	max-width: 900px;

	&__title {
		font-weight: bold;
		margin-block-end: 1rem;
	}

	&__list {
		list-style: none;
		padding: 0;
		margin: 0;
	}

	&__item {
		display: flex;
		gap: 0.75rem;
		align-items: baseline;
		padding-block: 0.4rem;
		border-bottom: 1px solid var(--color-border);
	}

	&__event {
		font-weight: 500;
	}

	&__object {
		color: var(--color-text-maxcontrast);
	}

	&__time {
		margin-inline-start: auto;
		color: var(--color-text-maxcontrast);
		font-size: 0.85em;
	}
}
</style>
