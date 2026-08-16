<template>
	<section class="secret-activity" data-testid="secret-activity-tab">
		<h3 class="secret-activity__heading">
			{{ t('doriath', 'Activity') }}
		</h3>

		<NcLoadingIcon v-if="loading" :size="24" />

		<NcEmptyContent
			v-else-if="entries.length === 0"
			:name="t('doriath', 'No activity recorded yet')"
			data-testid="secret-activity-empty">
			<template #icon>
				<History :size="20" />
			</template>
		</NcEmptyContent>

		<ul v-else class="secret-activity__list">
			<li
				v-for="entry in entries"
				:key="entry.id"
				class="secret-activity__item"
				data-testid="secret-activity-item">
				<span class="secret-activity__event">{{
					label(entry.eventType)
				}}</span>
				<span class="secret-activity__actor">{{ actor(entry) }}</span>
				<span class="secret-activity__time">{{
					relativeTime(entry.occurredAt)
				}}</span>
			</li>
		</ul>
	</section>
</template>

<script>
import { NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import History from 'vue-material-design-icons/History.vue'
import { useAuditStore } from '../store/modules/audit.js'
import { auditActorLabel, auditEventLabel } from '../utils/auditEventLabels.js'

/**
 * The Activity tab on the secret detail view (add-secret-audit-trail §5.2).
 * Lists the audit entries for one secret, newest first, with the actor, a
 * human-readable event-type label, and a relative timestamp. Owner-scoped at
 * the API — a non-owner gets the same response as a nonexistent secret.
 *
 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-5.2
 */
export default {
	name: 'SecretActivityTab',

	components: {
		NcEmptyContent,
		NcLoadingIcon,
		History,
	},

	props: {
		/** The secret ID whose activity to show. */
		secretId: {
			type: String,
			required: true,
		},
	},

	computed: {
		/**
		 * The audit entries for the current secret.
		 *
		 * @return {object[]} The secret's audit entries.
		 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-5.2
		 */
		entries() {
			return useAuditStore().secretEntries
		},

		/**
		 * Whether the audit store is loading.
		 *
		 * @return {boolean} The loading state.
		 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-5.2
		 */
		loading() {
			return useAuditStore().loading
		},
	},

	watch: {
		secretId: {
			immediate: true,
			/**
			 * Fetch activity when the watched secret ID changes.
			 *
			 * @param {string} id The new secret ID.
			 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-5.2
			 */
			handler(id) {
				if (id) {
					useAuditStore().fetchSecretActivity(id)
				}
			},
		},
	},

	methods: {
		/**
		 * Resolve a human-readable label for an event type.
		 *
		 * @param {string} eventType The audit event type.
		 * @return {string} The label.
		 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-5.2
		 */
		label(eventType) {
			return auditEventLabel(eventType)
		},

		/**
		 * Resolve a human-readable actor label for an entry.
		 *
		 * @param {object} entry The audit entry.
		 * @return {string} The actor label.
		 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-5.2
		 */
		actor(entry) {
			return auditActorLabel(entry)
		},

		/**
		 * Format an ISO timestamp as a localized relative time.
		 *
		 * @param {string} iso The ISO-8601 timestamp.
		 * @return {string} A human-readable relative time.
		 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-5.2
		 */
		relativeTime(iso) {
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
.secret-activity {
	margin-block-start: 1rem;

	&__heading {
		font-weight: bold;
		margin-block-end: 0.5rem;
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
		padding-block: 0.35rem;
		border-bottom: 1px solid var(--color-border);
	}

	&__event {
		font-weight: 500;
	}

	&__actor {
		color: var(--color-text-maxcontrast);
	}

	&__time {
		margin-inline-start: auto;
		color: var(--color-text-maxcontrast);
		font-size: 0.85em;
	}
}
</style>
