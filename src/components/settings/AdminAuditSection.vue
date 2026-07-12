<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Admin audit-trail section (add-secret-audit-trail §5.4 + §5.5 + §4.2).
  Combines the instance-wide audit view (filter bar + paginated table + a
  client-side CSV export of the whole filter result) with the retention-window
  setting. The view states that the trail starts at the deployment of this
  capability — there is no historical backfill.

  @spec openspec/changes/add-secret-audit-trail/tasks.md#task-5.4
-->
<template>
	<CnSettingsSection
		:name="t('doriath', 'Audit trail')"
		:description="t('doriath', 'Review who accessed and changed secrets across the instance. The trail starts at the deployment of this feature; there is no historical backfill.')">
		<div class="audit-admin">
			<!-- Retention setting -->
			<div class="audit-admin__retention" data-testid="audit-retention">
				<label for="audit-retention-days">{{ t('doriath', 'Retention window (days)') }}</label>
				<input
					id="audit-retention-days"
					v-model.number="retentionDays"
					type="number"
					min="30"
					data-testid="audit-retention-input"
					@change="saveRetention">
				<span class="audit-admin__hint">{{ t('doriath', 'Minimum 30 days') }}</span>
				<span v-if="retentionError" class="audit-admin__error">{{ retentionError }}</span>
			</div>

			<!-- Filter bar -->
			<div class="audit-admin__filters" data-testid="audit-filters">
				<NcSelect
					v-model="selectedEventType"
					class="audit-admin__filter"
					:options="eventOptions"
					label="label"
					:input-label="t('doriath', 'Event type')"
					:placeholder="t('doriath', 'All event types')"
					data-testid="audit-filter-eventtype"
					@input="onFilterChange" />

				<div class="audit-admin__filter">
					<label for="audit-filter-actor">{{ t('doriath', 'Actor') }}</label>
					<input
						id="audit-filter-actor"
						v-model="filterActor"
						type="text"
						data-testid="audit-filter-actor"
						@change="onFilterChange">
				</div>

				<div class="audit-admin__filter">
					<label for="audit-filter-from">{{ t('doriath', 'From') }}</label>
					<input
						id="audit-filter-from"
						v-model="filterFrom"
						type="date"
						data-testid="audit-filter-from"
						@change="onFilterChange">
				</div>

				<div class="audit-admin__filter">
					<label for="audit-filter-to">{{ t('doriath', 'To') }}</label>
					<input
						id="audit-filter-to"
						v-model="filterTo"
						type="date"
						data-testid="audit-filter-to"
						@change="onFilterChange">
				</div>

				<NcButton type="secondary"
					data-testid="audit-export-csv"
					@click="exportCsv">
					{{ t('doriath', 'Export CSV') }}
				</NcButton>
			</div>

			<NcLoadingIcon v-if="loading" :size="24" />

			<NcEmptyContent v-else-if="entries.length === 0"
				:name="t('doriath', 'No audit entries match the current filter')"
				data-testid="audit-empty">
				<template #icon>
					<History :size="20" />
				</template>
			</NcEmptyContent>

			<table v-else class="audit-admin__table" data-testid="audit-table">
				<thead>
					<tr>
						<th>{{ t('doriath', 'When') }}</th>
						<th>{{ t('doriath', 'Event') }}</th>
						<th>{{ t('doriath', 'Actor') }}</th>
						<th>{{ t('doriath', 'Object') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="entry in entries"
						:key="entry.id"
						data-testid="audit-row">
						<td>{{ formatTime(entry.occurredAt) }}</td>
						<td>{{ label(entry.eventType) }}</td>
						<td>{{ actor(entry) }}</td>
						<td>{{ entry.objectName || entry.objectId || '—' }}</td>
					</tr>
				</tbody>
			</table>

			<div v-if="entries.length > 0" class="audit-admin__pagination" data-testid="audit-pagination">
				<NcButton :disabled="page <= 1" @click="goToPage(page - 1)">
					{{ t('doriath', 'Previous') }}
				</NcButton>
				<span>{{ t('doriath', 'Page {page} of {pages}', { page, pages: pageCount }) }}</span>
				<NcButton :disabled="page >= pageCount" @click="goToPage(page + 1)">
					{{ t('doriath', 'Next') }}
				</NcButton>
			</div>
		</div>
	</CnSettingsSection>
</template>

<script>
import { CnSettingsSection } from '@conduction/nextcloud-vue'
import { NcButton, NcEmptyContent, NcLoadingIcon, NcSelect } from '@nextcloud/vue'
import History from 'vue-material-design-icons/History.vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { useAuditStore } from '../../store/modules/audit.js'
import { auditActorLabel, auditEventLabel, auditEventOptions } from '../../utils/auditEventLabels.js'
import { buildCsv, downloadCsv } from '../../utils/csv.js'

/**
 * Admin instance-wide audit view + retention setting.
 *
 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-5.4
 */
export default {
	name: 'AdminAuditSection',

	components: {
		CnSettingsSection,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcSelect,
		History,
	},

	data() {
		return {
			retentionDays: 365,
			retentionError: '',
			selectedEventType: null,
			filterActor: '',
			filterFrom: '',
			filterTo: '',
			eventOptions: auditEventOptions(),
		}
	},

	computed: {
		/**
		 * The instance-wide audit entries for the current page.
		 *
		 * @return {object[]} The admin audit entries.
		 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-5.4
		 */
		entries() {
			return useAuditStore().adminEntries
		},
		/**
		 * Whether the audit store is loading.
		 *
		 * @return {boolean} The loading state.
		 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-5.4
		 */
		loading() {
			return useAuditStore().loading
		},
		/**
		 * The current 1-based admin page number.
		 *
		 * @return {number} The page number.
		 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-5.4
		 */
		page() {
			return useAuditStore().adminPage
		},
		/**
		 * The total number of admin pages.
		 *
		 * @return {number} The page count.
		 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-5.4
		 */
		pageCount() {
			return useAuditStore().adminPageCount
		},
	},

	/**
	 * Load the retention setting and the first audit page.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-5.4
	 */
	async created() {
		await this.loadRetention()
		await useAuditStore().fetchAdminAudit(1)
	},

	methods: {
		/**
		 * Resolve a human-readable label for an event type.
		 *
		 * @param {string} eventType The audit event type.
		 * @return {string} The label.
		 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-5.4
		 */
		label(eventType) {
			return auditEventLabel(eventType)
		},
		/**
		 * Resolve a human-readable actor label for an entry.
		 *
		 * @param {object} entry The audit entry.
		 * @return {string} The actor label.
		 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-5.4
		 */
		actor(entry) {
			return auditActorLabel(entry)
		},
		/**
		 * Format an ISO timestamp as a localized date-time.
		 *
		 * @param {string} iso The ISO-8601 timestamp.
		 * @return {string} The formatted time.
		 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-5.4
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

		/**
		 * Load the current retention window from the admin settings API.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-5.4
		 */
		async loadRetention() {
			try {
				const response = await axios.get(generateUrl('/apps/doriath/api/settings/admin'))
				this.retentionDays = response.data.audit_retention_days ?? 365
			} catch (e) {
				this.retentionDays = 365
			}
		},

		/**
		 * Persist the retention window; surfaces the server-side 30-day floor.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-5.4
		 */
		async saveRetention() {
			this.retentionError = ''
			try {
				await axios.put(generateUrl('/apps/doriath/api/settings/admin'), {
					audit_retention_days: this.retentionDays,
				})
			} catch (e) {
				this.retentionError = e?.response?.data?.message
					|| t('doriath', 'Retention must be at least 30 days')
				await this.loadRetention()
			}
		},

		/**
		 * Apply the current filter selection and reload from page 1.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-5.4
		 */
		async onFilterChange() {
			await useAuditStore().applyAdminFilters({
				eventType: this.selectedEventType ? this.selectedEventType.id : '',
				actor: this.filterActor,
				from: this.filterFrom,
				to: this.filterTo,
			})
		},

		/**
		 * Navigate to a specific admin page.
		 *
		 * @param {number} target The 1-based page.
		 * @return {Promise<void>}
		 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-5.4
		 */
		async goToPage(target) {
			await useAuditStore().fetchAdminAudit(target)
		},

		/**
		 * Export the whole current filter result as a client-side CSV.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-5.5
		 */
		async exportCsv() {
			const rows = await useAuditStore().fetchAllAdminForExport()
			const headers = [
				t('doriath', 'When'),
				t('doriath', 'Event'),
				t('doriath', 'Actor'),
				t('doriath', 'Object type'),
				t('doriath', 'Object'),
			]
			const data = rows.map((entry) => [
				entry.occurredAt,
				this.label(entry.eventType),
				this.actor(entry),
				entry.objectType,
				entry.objectName || entry.objectId || '',
			])
			downloadCsv('doriath-audit.csv', buildCsv(headers, data))
		},
	},
}
</script>

<style scoped lang="scss">
.audit-admin {
	&__retention,
	&__filters {
		display: flex;
		flex-wrap: wrap;
		gap: 0.75rem;
		align-items: end;
		margin-block-end: 1rem;
	}

	&__filter {
		min-width: 160px;
		display: flex;
		flex-direction: column;
		gap: 0.25rem;
	}

	&__error {
		color: var(--color-error);
	}

	&__hint {
		color: var(--color-text-maxcontrast);
		font-size: 0.85em;
	}

	&__table {
		width: 100%;
		border-collapse: collapse;

		th,
		td {
			text-align: start;
			padding: 0.4rem 0.6rem;
			border-bottom: 1px solid var(--color-border);
		}
	}

	&__pagination {
		display: flex;
		gap: 0.75rem;
		align-items: center;
		margin-block-start: 1rem;
	}
}
</style>
