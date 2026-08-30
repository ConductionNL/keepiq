<template>
	<div class="recent-secrets-widget">
		<h3>{{ t('keepiq', 'Recently accessed secrets') }}</h3>
		<div v-if="loading" class="recent-secrets-widget__row">
			{{ t('keepiq', 'Loading…') }}
		</div>
		<div
			v-else-if="secrets.length === 0"
			class="recent-secrets-widget__row recent-secrets-widget__row--empty">
			{{ t('keepiq', 'No secrets accessed yet') }}
		</div>
		<ul v-else class="recent-secrets-widget__list">
			<li
				v-for="secret in secrets"
				:key="secret.id"
				class="recent-secrets-widget__item">
				<!--
					The row is a real <button>: a bare @click on the <li> was
					unreachable by keyboard and announced as a plain list item.
					Using the element that already carries the role, focus and
					Enter/Space handling beats bolting role/tabindex/@keydown
					onto a non-interactive tag.
				-->
				<button
					type="button"
					class="recent-secrets-widget__button"
					@click="open(secret)">
					<span
						class="recent-secrets-widget__icon"
						:data-type="secret.type" />
					<span class="recent-secrets-widget__name">{{
						secret.name
					}}</span>
				</button>
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
			const response = await axios.get(
				generateUrl('/apps/keepiq/api/v1/secrets'),
				{
					params: { limit: 5, sort: 'last_accessed_at:desc' },
				},
			)
			const list =
				response.data.secrets || response.data.results || response.data || []
			this.secrets = Array.isArray(list) ? list.slice(0, 5) : []
		} catch (e) {
			console.warn('Keepiq: failed to load recent secrets', e)
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
				this.$router.push({
					name: 'secret-detail',
					params: { id: secret.id },
				})
			} else {
				window.location.href = generateUrl(
					`/apps/keepiq/secrets/${secret.id}`,
				)
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
	display: block;
}

/*
 * The row button carries the layout the <li> used to own, and resets the
 * default button chrome so the visual result is unchanged from before.
 */
.recent-secrets-widget__button {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 6px 0;
	cursor: pointer;
	width: 100%;
	background: none;
	border: 0;
	margin: 0;
	font: inherit;
	color: inherit;
	text-align: start;
}

.recent-secrets-widget__button:hover {
	background: var(--color-background-hover);
}

.recent-secrets-widget__button:focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: -2px;
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
