<!--
  @visual exclude UNREACHABLE, not un-baselined — tracked in issue #208. Nothing
  in src/ imports this component: it is in no `pages[]` entry, not in
  src/registry.js, and there is no router. The shipped bundle settles it —
  `grep -c keepiq-applications-view js/keepiq-main.js` returns 0 while the
  same probe returns 1 for wired views (personal-activity, cert-inventory), so
  webpack tree-shook it out entirely. There is no route for a browser to visit
  and therefore no screen to capture. The admin approval queue that DOES ship is
  src/components/settings/ApplicationQueueSection.vue, wired into
  src/views/settings/Settings.vue; this file is very likely its predecessor.
  ⚠️ This waiver is not "no baseline needed" — it records a defect. It must be
  removed together with issue #208, by deleting this file or by wiring it up.
-->
<template>
	<div class="keepiq-applications-view">
		<h2>{{ t('keepiq', 'Registered applications') }}</h2>

		<section class="keepiq-applications-view__queue">
			<header>
				<h3>
					{{ t('keepiq', 'Pending approval') }}
					<span
						v-if="pendingCount > 0"
						class="keepiq-applications-view__badge"
						>{{ pendingCount }}</span
					>
				</h3>
			</header>

			<p v-if="store.loading">
				{{ t('keepiq', 'Loading…') }}
			</p>

			<p
				v-else-if="pendingCount === 0"
				class="keepiq-applications-view__empty">
				{{ t('keepiq', 'No applications are awaiting approval.') }}
			</p>

			<ul v-else class="keepiq-applications-view__list">
				<li
					v-for="app in pending"
					:key="app.id"
					class="keepiq-applications-view__item"
					data-testid="pending-application">
					<div class="keepiq-applications-view__meta">
						<strong>{{ app.name }}</strong>
						<span
							v-if="app.description"
							class="keepiq-applications-view__description"
							>{{ app.description }}</span
						>
						<small>
							{{
								t('keepiq', 'Registered by {user}', {
									user:
										app.registered_by
										|| t('keepiq', 'anonymous'),
								})
							}}
						</small>
					</div>
					<div class="keepiq-applications-view__actions">
						<button
							type="button"
							class="primary"
							data-testid="approve-button"
							@click="approve(app.id)">
							{{ t('keepiq', 'Approve') }}
						</button>
						<button
							type="button"
							data-testid="reject-button"
							@click="reject(app.id)">
							{{ t('keepiq', 'Reject') }}
						</button>
					</div>
				</li>
			</ul>
		</section>

		<section
			v-if="hasPrivateKey"
			class="keepiq-applications-view__keydialog"
			data-testid="private-key-dialog">
			<h3>{{ t('keepiq', 'Save the application private key') }}</h3>
			<p class="keepiq-applications-view__warning">
				{{
					t(
						'keepiq',
						'This is the only time the private key is shown. Save it securely; it cannot be recovered.',
					)
				}}
			</p>
			<textarea
				:value="store.oneTimePrivateKey"
				readonly
				class="keepiq-applications-view__keytext"
				:aria-label="t('keepiq', 'Private key')"
				data-testid="private-key-text" />
			<div class="keepiq-applications-view__actions">
				<label>
					<input
						v-model="acknowledged"
						type="checkbox"
						data-testid="acknowledge-key" />
					{{ t('keepiq', 'I have saved the private key.') }}
				</label>
				<button
					type="button"
					class="primary"
					:disabled="acknowledged === false"
					data-testid="dismiss-key"
					@click="dismissKey">
					{{ t('keepiq', 'Dismiss') }}
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
.keepiq-applications-view {
	max-width: 800px;
	padding: 1rem;
}

.keepiq-applications-view__badge {
	display: inline-block;
	min-width: 1.5rem;
	padding: 0 0.4rem;
	border-radius: 0.75rem;
	background: var(--color-primary, #0082c9);
	color: var(--color-primary-text, #fff);
	font-size: 0.8rem;
	text-align: center;
}

.keepiq-applications-view__list {
	list-style: none;
	padding: 0;
	margin: 0;
}

.keepiq-applications-view__item {
	display: flex;
	gap: 1rem;
	align-items: flex-start;
	justify-content: space-between;
	padding: 0.75rem 0;
	border-bottom: 1px solid var(--color-border, #ddd);
}

.keepiq-applications-view__description {
	display: block;
	color: var(--color-text-lighter);
}

.keepiq-applications-view__actions {
	display: flex;
	gap: 0.5rem;
	align-items: center;
}

.keepiq-applications-view__empty {
	color: var(--color-text-lighter);
}

.keepiq-applications-view__keydialog {
	margin-top: 2rem;
	padding: 1rem;
	/* --color-warning-rest is not a Nextcloud variable, so this always fell back
	   to the pale light-theme yellow and the inherited near-white dark-mode text
	   was unreadable on it. The old #f00 border fallback was wrong twice over:
	   red for a warning, and unreachable because --color-warning is defined. */
	border: 1px solid var(--color-warning-text);
	background: var(--color-background-dark);
	color: var(--color-main-text);
}

.keepiq-applications-view__warning {
	font-weight: 600;
	color: var(--color-error-text);
}

.keepiq-applications-view__keytext {
	width: 100%;
	min-height: 12rem;
	font-family: monospace;
	font-size: 0.8rem;
}
</style>
