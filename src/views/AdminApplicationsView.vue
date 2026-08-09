<template>
	<div class="doriath-applications-view">
		<h2>{{ t('doriath', 'Registered applications') }}</h2>

		<section class="doriath-applications-view__queue">
			<header>
				<h3>
					{{ t('doriath', 'Pending approval') }}
					<span v-if="pendingCount > 0" class="doriath-applications-view__badge">{{ pendingCount }}</span>
				</h3>
			</header>

			<p v-if="store.loading">
				{{ t('doriath', 'Loading…') }}
			</p>

			<p v-else-if="pendingCount === 0" class="doriath-applications-view__empty">
				{{ t('doriath', 'No applications are awaiting approval.') }}
			</p>

			<ul v-else class="doriath-applications-view__list">
				<li
					v-for="app in pending"
					:key="app.id"
					class="doriath-applications-view__item"
					data-testid="pending-application">
					<div class="doriath-applications-view__meta">
						<strong>{{ app.name }}</strong>
						<span v-if="app.description" class="doriath-applications-view__description">{{ app.description }}</span>
						<small>
							{{ t('doriath', 'Registered by {user}', { user: app.registered_by || t('doriath', 'anonymous') }) }}
						</small>
					</div>
					<div class="doriath-applications-view__actions">
						<button
							type="button"
							class="primary"
							data-testid="approve-button"
							@click="approve(app.id)">
							{{ t('doriath', 'Approve') }}
						</button>
						<button
							type="button"
							data-testid="reject-button"
							@click="reject(app.id)">
							{{ t('doriath', 'Reject') }}
						</button>
					</div>
				</li>
			</ul>
		</section>

		<section v-if="hasPrivateKey" class="doriath-applications-view__keydialog" data-testid="private-key-dialog">
			<h3>{{ t('doriath', 'Save the application private key') }}</h3>
			<p class="doriath-applications-view__warning">
				{{ t('doriath', 'This is the only time the private key is shown. Save it securely; it cannot be recovered.') }}
			</p>
			<textarea
				:value="store.oneTimePrivateKey"
				readonly
				class="doriath-applications-view__keytext"
				:aria-label="t('doriath', 'Generated private key')"
				data-testid="private-key-text" />
			<div class="doriath-applications-view__actions">
				<label>
					<input v-model="acknowledged" type="checkbox" data-testid="acknowledge-key">
					{{ t('doriath', 'I have saved the private key.') }}
				</label>
				<button
					type="button"
					class="primary"
					:disabled="acknowledged === false"
					data-testid="dismiss-key"
					@click="dismissKey">
					{{ t('doriath', 'Dismiss') }}
				</button>
			</div>
		</section>
	</div>
</template>

<script>
import { useApplicationStore } from '../store/modules/application.js'

/**
 * Admin queue view for registered applications.
 *
 * Loads the pending queue from useApplicationStore, lets admins
 * approve / reject each row, and surfaces the one-time private key
 * returned by an approve-without-CSR via an inline dialog block.
 *
 * @spec openspec/changes/implement-application-mgmt/tasks.md#task-10.7
 */
export default {
	name: 'AdminApplicationsView',

	data() {
		return {
			store: useApplicationStore(),
			acknowledged: false,
		}
	},

	computed: {
		pending() {
			return this.store.pendingApplications
		},
		pendingCount() {
			return this.store.pendingCount
		},
		hasPrivateKey() {
			return !!this.store.oneTimePrivateKey
		},
	},

	async created() {
		await this.store.fetchPending()
	},

	methods: {
		/**
		 * Approve a pending application — server may return a
		 * private_key when the original request had no CSR.
		 *
		 * @param {string} id The application ID.
		 * @return {Promise<void>}
		 */
		async approve(id) {
			await this.store.approveApplication(id)
		},
		/**
		 * Reject (hard-delete) a pending application.
		 *
		 * @param {string} id The application ID.
		 * @return {Promise<void>}
		 */
		async reject(id) {
			await this.store.rejectApplication(id)
		},
		/**
		 * Clear the one-time private-key dialog state after the admin
		 * has acknowledged saving it.
		 *
		 * @return {void}
		 */
		dismissKey() {
			this.store.clearOneTimePrivateKey()
			this.acknowledged = false
		},
	},
}
</script>

<style scoped>
.doriath-applications-view {
	max-width: 800px;
	padding: 1rem;
}

.doriath-applications-view__badge {
	display: inline-block;
	min-width: 1.5rem;
	padding: 0 0.4rem;
	border-radius: 0.75rem;
	background: var(--color-primary, #0082c9);
	color: var(--color-primary-text, #fff);
	font-size: 0.8rem;
	text-align: center;
}

.doriath-applications-view__list {
	list-style: none;
	padding: 0;
	margin: 0;
}

.doriath-applications-view__item {
	display: flex;
	gap: 1rem;
	align-items: flex-start;
	justify-content: space-between;
	padding: 0.75rem 0;
	border-bottom: 1px solid var(--color-border, #ddd);
}

.doriath-applications-view__description {
	display: block;
	color: var(--color-text-lighter);
}

.doriath-applications-view__actions {
	display: flex;
	gap: 0.5rem;
	align-items: center;
}

.doriath-applications-view__empty {
	color: var(--color-text-lighter);
}

.doriath-applications-view__keydialog {
	margin-top: 2rem;
	padding: 1rem;
	border: 1px solid var(--color-warning, #f00);
	background: var(--color-warning-rest, #ffe);
}

.doriath-applications-view__warning {
	font-weight: 600;
	color: var(--color-error, #c00);
}

.doriath-applications-view__keytext {
	width: 100%;
	min-height: 12rem;
	font-family: monospace;
	font-size: 0.8rem;
}
</style>
