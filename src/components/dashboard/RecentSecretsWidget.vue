<template>
	<div class="recent-secrets-widget">
		<h3>{{ t('doriath', 'Recently accessed secrets') }}</h3>
		<div v-if="loading" class="recent-secrets-widget__row">
			{{ t('doriath', 'Loading…') }}
		</div>
		<div v-else-if="secrets.length === 0" class="recent-secrets-widget__row recent-secrets-widget__row--empty">
			{{ t('doriath', 'No secrets accessed yet') }}
		</div>
		<ul v-else class="recent-secrets-widget__list">
			<li
				v-for="secret in secrets"
				:key="secret.id"
				class="recent-secrets-widget__item"
				@click="open(secret)">
				<span class="recent-secrets-widget__icon" :data-type="secret.type" />
				<span class="recent-secrets-widget__name">{{ secret.name }}</span>
			</li>
		</ul>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'RecentSecretsWidget',

	data() {
		return {
			secrets: [],
			loading: true,
		}
	},

	/**
	 * Fetch up to 5 recently accessed secrets.
	 *
	 * @spec openspec/changes/implement-dashboard-settings/tasks.md#task-3.5
	 */
	async created() {
		try {
			const response = await axios.get(generateUrl('/apps/doriath/api/v1/secrets'), {
				params: { limit: 5, sort: 'last_accessed_at:desc' },
			})
			const list = response.data.secrets || response.data.results || response.data || []
			this.secrets = Array.isArray(list) ? list.slice(0, 5) : []
		} catch (e) {
			console.warn('Doriath: failed to load recent secrets', e)
			this.secrets = []
		} finally {
			this.loading = false
		}
	},

	methods: {
		/**
		 * Navigate to the secret detail.
		 *
		 * @param {object} secret The secret
		 *
		 * @spec openspec/changes/implement-dashboard-settings/tasks.md#task-3.5
		 */
		open(secret) {
			if (this.$router) {
				this.$router.push({ name: 'secret-detail', params: { id: secret.id } })
			} else {
				window.location.href = generateUrl(`/apps/doriath/secrets/${secret.id}`)
			}
		},
	},
}
</script>

<style scoped>
.recent-secrets-widget {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 1rem;
}
.recent-secrets-widget h3 {
	margin: 0 0 0.5rem 0;
	font-size: 1rem;
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
	cursor: pointer;
}
.recent-secrets-widget__item:hover {
	background: var(--color-background-hover);
}
.recent-secrets-widget__icon {
	width: 12px;
	height: 12px;
	background: var(--color-primary-element);
	border-radius: 50%;
}
.recent-secrets-widget__row--empty {
	color: var(--color-text-lighter);
}
</style>
