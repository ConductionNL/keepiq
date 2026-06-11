<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Developer-facing "register an application" page. Shows the user's own
  application registrations and lets them add new ones via the
  ApplicationRegisterDialog. When the server returns a one-time private
  key the PrivateKeyDownloadDialog is shown until the user
  acknowledges.

  Admins should use `AdminApplicationsView` for the approval queue;
  this view is intentionally non-admin scoped.

  @spec openspec/changes/implement-application-mgmt/tasks.md#task-10.1
-->
<template>
	<div class="doriath-application-register-view" data-testid="application-register-view">
		<header class="doriath-application-register-view__header">
			<div>
				<h2>{{ t('doriath', 'My applications') }}</h2>
				<p class="doriath-application-register-view__intro">
					{{ t('doriath', 'Register an application to access secrets via the JWT-Bearer flow.') }}
				</p>
			</div>
			<button
				type="button"
				class="primary"
				data-testid="application-register-open"
				@click="dialogOpen = true">
				{{ t('doriath', 'Register application') }}
			</button>
		</header>

		<p v-if="store.loading" data-testid="application-register-loading">
			{{ t('doriath', 'Loading…') }}
		</p>

		<p v-else-if="rows.length === 0" class="doriath-application-register-view__empty" data-testid="application-register-empty">
			{{ t('doriath', 'You have not registered any applications yet.') }}
		</p>

		<ul v-else class="doriath-application-register-view__list">
			<li
				v-for="app in rows"
				:key="app.id"
				class="doriath-application-register-view__item"
				data-testid="application-register-row">
				<div>
					<strong>{{ app.name }}</strong>
					<small class="doriath-application-register-view__status">
						{{ statusLabel(app.status) }}
					</small>
					<p v-if="app.description" class="doriath-application-register-view__description">
						{{ app.description }}
					</p>
				</div>
				<span class="doriath-application-register-view__type">{{ app.type }}</span>
			</li>
		</ul>

		<ApplicationRegisterDialog
			:open="dialogOpen"
			@close="dialogOpen = false"
			@registered="onRegistered" />

		<PrivateKeyDownloadDialog
			:open="store.oneTimePrivateKey !== null && store.oneTimePrivateKey !== ''"
			:private-key="store.oneTimePrivateKey || ''"
			@close="onAcknowledgeKey" />
	</div>
</template>

<script>
import { useApplicationStore } from '../store/modules/application.js'
import ApplicationRegisterDialog from '../components/application/ApplicationRegisterDialog.vue'
import PrivateKeyDownloadDialog from '../components/application/PrivateKeyDownloadDialog.vue'

export default {
	name: 'ApplicationRegisterView',
	components: { ApplicationRegisterDialog, PrivateKeyDownloadDialog },
	data() {
		return {
			dialogOpen: false,
			store: useApplicationStore(),
		}
	},
	computed: {
		rows() {
			return this.store.applications || []
		},
	},
	mounted() {
		this.store.fetchApplications().catch(() => {})
	},
	methods: {
		statusLabel(status) {
			switch (status) {
				case 'active': return t('doriath', 'Active')
				case 'pending': return t('doriath', 'Pending approval')
				case 'rejected': return t('doriath', 'Rejected')
				default: return status || ''
			}
		},
		onRegistered() {
			this.dialogOpen = false
			// Refresh the list so the new row shows up.
			this.store.fetchApplications().catch(() => {})
		},
		onAcknowledgeKey() {
			this.store.clearOneTimePrivateKey()
		},
	},
}
</script>

<style scoped>
.doriath-application-register-view {
	padding: 16px;
}
.doriath-application-register-view__header {
	display: flex;
	justify-content: space-between;
	align-items: flex-start;
	margin-bottom: 16px;
}
.doriath-application-register-view__intro {
	color: var(--color-text-maxcontrast, #777);
	font-size: 13px;
	margin: 4px 0 0;
}
.doriath-application-register-view__empty {
	color: var(--color-text-maxcontrast, #777);
	font-size: 13px;
}
.doriath-application-register-view__list {
	list-style: none;
	padding: 0;
	margin: 0;
}
.doriath-application-register-view__item {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 12px;
	padding: 12px 0;
	border-bottom: 1px solid var(--color-border, #eee);
}
.doriath-application-register-view__status {
	margin-left: 8px;
	color: var(--color-text-maxcontrast, #777);
	font-size: 12px;
}
.doriath-application-register-view__description {
	margin: 4px 0 0;
	color: var(--color-text-maxcontrast, #777);
	font-size: 13px;
}
.doriath-application-register-view__type {
	background-color: var(--color-background-darker, #ddd);
	font-size: 11px;
	padding: 2px 8px;
	border-radius: 999px;
}
.primary {
	background-color: var(--color-primary-element, #0082c9);
	color: var(--color-primary-element-text, #fff);
	border: 0;
	padding: 8px 16px;
	border-radius: var(--border-radius, 4px);
}
</style>
