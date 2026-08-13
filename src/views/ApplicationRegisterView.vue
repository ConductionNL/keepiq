<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Developer-facing "register an application" page. Shows the user's own
  application registrations (on the shared CnIndexPage list view) and lets
  them add new ones via the ApplicationRegisterDialog. When the server returns
  a one-time private key the PrivateKeyDownloadDialog is shown until the user
  acknowledges.

  Admins should use `AdminApplicationsView` for the approval queue;
  this view is intentionally non-admin scoped.

  @spec openspec/changes/implement-application-mgmt/tasks.md#task-10.1
-->
<template>
	<div
		class="doriath-application-register-view"
		data-testid="application-register-view">
		<CnIndexPage
			view-mode="list"
			:available-view-modes="['list', 'cards', 'table']"
			list-label="List"
			:selectable="false"
			:objects="rows"
			:schema="listSchema"
			:list-config="listConfig"
			:loading="store.loading"
			:add-label="t('doriath', 'Register application')"
			add-icon="Plus"
			inline-search
			:search-value="searchTerm"
			:search-placeholder="t('doriath', 'Search applications')"
			row-key="id"
			:empty-text="
				t('doriath', 'You have not registered any applications yet.')
			"
			@add="dialogOpen = true"
			@search="onSearch"
			@row-click="openApplication">
			<template #row-badges="{ object }">
				<CnStatusBadge
					:label="statusLabel(object.status)"
					:variant="statusVariant(object.status)"
					size="small" />
				<CnStatusBadge
					v-if="object.type"
					:label="object.type"
					variant="default"
					size="small" />
			</template>
		</CnIndexPage>

		<ApplicationRegisterDialog
			:open="dialogOpen"
			@close="dialogOpen = false"
			@registered="onRegistered" />

		<PrivateKeyDownloadDialog
			:open="
				store.oneTimePrivateKey !== null && store.oneTimePrivateKey !== ''
			"
			:private-key="store.oneTimePrivateKey || ''"
			@close="onAcknowledgeKey" />
	</div>
</template>

<script>
// eslint-disable-next-line import/named
import { CnIndexPage, CnStatusBadge } from '@conduction/nextcloud-vue'
import { useApplicationStore } from '../store/modules/application.js'
import ApplicationRegisterDialog from '../components/application/ApplicationRegisterDialog.vue'
import PrivateKeyDownloadDialog from '../components/application/PrivateKeyDownloadDialog.vue'

export default {
	name: 'ApplicationRegisterView',

	components: {
		CnIndexPage,
		CnStatusBadge,
		ApplicationRegisterDialog,
		PrivateKeyDownloadDialog,
	},

	data() {
		return {
			dialogOpen: false,
			searchTerm: '',
			store: useApplicationStore(),
		}
	},

	computed: {
		rows() {
			const term = this.searchTerm.trim().toLowerCase()
			const all = this.store.applications || []
			if (!term) return all
			return all.filter((a) => (a.name || '').toLowerCase().includes(term))
		},
		listSchema() {
			return {
				properties: {
					name: { title: t('doriath', 'Name'), type: 'string' },
					description: {
						title: t('doriath', 'Description'),
						type: 'string',
					},
				},
				configuration: {
					objectNameField: 'name',
					objectDescriptionField: 'description',
				},
			}
		},
		listConfig() {
			return { titleField: 'name', subtitleField: 'description' }
		},
	},

	mounted() {
		this.store.fetchApplications().catch(() => {})
	},

	methods: {
		t,

		onSearch(value) {
			this.searchTerm = value
		},

		openApplication(object) {
			this.$router.push(`/applications/${object.id}`)
		},

		statusLabel(status) {
			switch (status) {
				case 'active':
					return t('doriath', 'Active')
				case 'pending':
					return t('doriath', 'Pending approval')
				case 'rejected':
					return t('doriath', 'Rejected')
				default:
					return status || ''
			}
		},

		statusVariant(status) {
			switch (status) {
				case 'active':
					return 'success'
				case 'pending':
					return 'warning'
				case 'rejected':
					return 'error'
				default:
					return 'default'
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
	height: 100%;
	overflow: auto;
}
</style>
