<template>
	<CnSettingsSection
		:name="t('keepiq', 'Applications')"
		:description="
			t('keepiq', 'Pending external applications waiting for admin approval')
		">
		<div v-if="loading" class="application-queue">
			{{ t('keepiq', 'Loading…') }}
		</div>
		<div
			v-else-if="pending.length === 0"
			class="application-queue application-queue--empty">
			{{ t('keepiq', 'No pending applications') }}
		</div>
		<ul v-else class="application-queue">
			<li v-for="app in pending" :key="app.id" class="application-queue__row">
				<div class="application-queue__meta">
					<strong>{{ app.name }}</strong>
					<span class="application-queue__description">{{
						app.description
					}}</span>
					<span class="application-queue__date">{{
						formatDate(app.created_at)
					}}</span>
				</div>
				<div class="application-queue__actions">
					<button class="primary" @click="approve(app)">
						{{ t('keepiq', 'Approve') }}
					</button>
					<button @click="reject(app)">
						{{ t('keepiq', 'Reject') }}
					</button>
				</div>
			</li>
		</ul>
	</CnSettingsSection>
</template>

<script>
import { CnSettingsSection } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'ApplicationQueueSection',
	components: { CnSettingsSection },

	data() {
		return {
			pending: [],
			loading: true,
		}
	},

	/**
	 * Load the list of pending applications.
	 *
	 * @spec openspec/changes/implement-dashboard-settings/tasks.md#task-4.3
	 */
	async created() {
		await this.refresh()
	},

	methods: {
		/**
		 * Reload the pending list.
		 *
		 * @spec openspec/changes/implement-dashboard-settings/tasks.md#task-4.3
		 */
		async refresh() {
			this.loading = true
			try {
				const response = await axios.get(
					generateUrl('/apps/keepiq/api/v1/applications/pending'),
				)
				this.pending = Array.isArray(response.data)
					? response.data
					: response.data.applications || []
			} catch (e) {
				console.warn('Keepiq: failed to load pending applications', e)
				this.pending = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * Approve an application.
		 *
		 * @param {object} app Application object
		 *
		 * @spec openspec/changes/implement-dashboard-settings/tasks.md#task-4.3
		 */
		async approve(app) {
			await axios.post(
				generateUrl(`/apps/keepiq/api/v1/applications/${app.id}/approve`),
			)
			await this.refresh()
		},

		/**
		 * Reject an application.
		 *
		 * @param {object} app Application object
		 *
		 * @spec openspec/changes/implement-dashboard-settings/tasks.md#task-4.3
		 */
		async reject(app) {
			await axios.post(
				generateUrl(`/apps/keepiq/api/v1/applications/${app.id}/reject`),
			)
			await this.refresh()
		},

		formatDate(date) {
			if (!date) return ''
			try {
				return new Date(date).toLocaleString()
			} catch {
				return String(date)
			}
		},
	},
}
</script>

<style scoped>
.application-queue__row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 0.5rem 0;
	border-bottom: 1px solid var(--color-border);
}

.application-queue__meta {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.application-queue__description {
	color: var(--color-text-lighter);
	font-size: 0.85rem;
}

.application-queue__date {
	color: var(--color-text-maxcontrast);
	font-size: 0.75rem;
}

.application-queue__actions {
	display: flex;
	gap: 8px;
}

.application-queue--empty {
	padding: 1rem 0;
	color: var(--color-text-lighter);
}
</style>
