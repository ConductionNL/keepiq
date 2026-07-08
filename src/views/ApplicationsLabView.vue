<template>
	<div class="applications-lab-view">
		<CnIndexPage
			view-mode="list"
			:available-view-modes="['list', 'cards', 'table']"
			list-label="List"
			:selectable="false"
			:objects="applications"
			:schema="labSchema"
			:list-config="listConfig"
			:loading="loading"
			:add-label="t('doriath', 'Register application')"
			add-icon="Plus"
			inline-search
			:search-value="searchTerm"
			:search-placeholder="t('doriath', 'Search applications')"
			row-key="id"
			:empty-text="t('doriath', 'You have not registered any applications yet.')"
			@add="openRegister"
			@search="onSearch"
			@row-click="openApplication">
			<!-- Status + internal/external tag — matches the production row -->
			<template #row-badges="{ object }">
				<CnStatusBadge
					:label="statusLabel(object.status)"
					:variant="statusVariant(object.status)"
					size="small" />
				<CnStatusBadge :label="object.type" variant="default" size="small" />
			</template>
		</CnIndexPage>
	</div>
</template>

<script>
// eslint-disable-next-line import/named
import { CnIndexPage, CnStatusBadge } from '@conduction/nextcloud-vue'
import { useApplicationStore } from '../store/modules/application.js'

/**
 * Side-by-side lab rebuild of the "My applications" list on the shared
 * `CnIndexPage` list view, for visual comparison against
 * ApplicationRegisterView. Backed by the same application store.
 */
export default {
	name: 'ApplicationsLabView',

	components: {
		CnIndexPage,
		CnStatusBadge,
	},

	inject: {
		cnOpenModal: { default: () => () => {} },
	},

	data() {
		return {
			searchTerm: '',
		}
	},

	computed: {
		store() {
			return useApplicationStore()
		},
		applications() {
			const term = this.searchTerm.trim().toLowerCase()
			const all = this.store.applications
			if (!term) return all
			return all.filter((a) => (a.name || '').toLowerCase().includes(term))
		},
		loading() {
			return this.store.loading
		},
		labSchema() {
			return {
				properties: {
					name: { title: t('doriath', 'Name'), type: 'string' },
					description: { title: t('doriath', 'Description'), type: 'string' },
				},
				configuration: { objectNameField: 'name' },
			}
		},
		listConfig() {
			return { titleField: 'name', subtitleField: 'description' }
		},
	},

	async mounted() {
		await this.store.fetchApplications()
	},

	methods: {
		t,

		onSearch(value) {
			this.searchTerm = value
		},

		openApplication(object) {
			this.$router.push(`/applications/${object.id}`)
		},

		openRegister() {
			this.cnOpenModal('application-register', {
				onSaved: () => this.store.fetchApplications(),
			})
		},

		statusLabel(status) {
			switch (status) {
			case 'active': return t('doriath', 'Active')
			case 'pending': return t('doriath', 'Pending approval')
			case 'revoked': return t('doriath', 'Revoked')
			default: return status
			}
		},

		statusVariant(status) {
			switch (status) {
			case 'active': return 'success'
			case 'pending': return 'warning'
			case 'revoked': return 'error'
			default: return 'default'
			}
		},
	},
}
</script>

<style scoped>
.applications-lab-view {
	padding: 12px 16px;
	height: 100%;
	overflow: auto;
}
</style>
