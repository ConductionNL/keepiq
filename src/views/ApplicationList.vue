<template>
	<div class="application-list">
		<div class="application-list__header">
			<h2 class="application-list__title">
				{{ t('doriath', 'Applications') }}
			</h2>
			<NcButton type="primary" @click="openRegisterDialog">
				<template #icon>
					<PlusIcon :size="20" />
				</template>
				{{ t('doriath', 'Register application') }}
			</NcButton>
		</div>

		<NcEmptyContent v-if="!loading && applications.length === 0"
			:name="t('doriath', 'No applications yet')"
			:description="t('doriath', 'Register an application to let it consume the vault.')">
			<template #icon>
				<ApplicationIcon :size="48" />
			</template>
		</NcEmptyContent>

		<table v-else class="application-list__table">
			<thead>
				<tr>
					<th>{{ t('doriath', 'Name') }}</th>
					<th>{{ t('doriath', 'Type') }}</th>
					<th>{{ t('doriath', 'Status') }}</th>
					<th>{{ t('doriath', 'Registered by') }}</th>
					<th>{{ t('doriath', 'Created') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="app in applications"
					:key="app.id"
					class="application-list__row"
					@click="openDetail(app.id)">
					<td>{{ app.name }}</td>
					<td>
						<span class="application-list__badge">{{ typeLabel(app.type) }}</span>
					</td>
					<td>
						<span class="application-list__badge" :class="statusClass(app.status)">{{ statusLabel(app.status) }}</span>
					</td>
					<td>{{ app.registeredBy || t('doriath', 'Anonymous') }}</td>
					<td>{{ formatDate(app.createdAt) }}</td>
				</tr>
			</tbody>
		</table>

		<ApplicationRegisterDialog :open.sync="registerDialogOpen" @registered="onRegistered" />
		<PrivateKeyDownloadDialog v-if="oneTimePrivateKey"
			:private-key="oneTimePrivateKey"
			@close="clearKey" />
	</div>
</template>

<script>
import { mapState } from 'pinia'
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcEmptyContent } from '@nextcloud/vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import ApplicationIcon from 'vue-material-design-icons/Application.vue'
import { useApplicationStore } from '../store/modules/application.js'
import ApplicationRegisterDialog from '../components/ApplicationRegisterDialog.vue'
import PrivateKeyDownloadDialog from '../components/PrivateKeyDownloadDialog.vue'

export default {
	name: 'ApplicationList',

	components: {
		NcButton,
		NcEmptyContent,
		PlusIcon,
		ApplicationIcon,
		ApplicationRegisterDialog,
		PrivateKeyDownloadDialog,
	},

	data() {
		return {
			registerDialogOpen: false,
		}
	},

	computed: {
		...mapState(useApplicationStore, ['applications', 'loading', 'oneTimePrivateKey']),
	},

	mounted() {
		this.store().fetchApplications()
	},

	methods: {
		store() {
			return useApplicationStore()
		},

		openRegisterDialog() {
			this.registerDialogOpen = true
		},

		openDetail(id) {
			this.$router.push({ name: 'ApplicationDetail', params: { id } })
		},

		onRegistered() {
			this.registerDialogOpen = false
		},

		clearKey() {
			this.store().clearOneTimePrivateKey()
		},

		typeLabel(type) {
			return type === 'internal' ? t('doriath', 'Internal') : t('doriath', 'External')
		},

		statusLabel(status) {
			return status === 'active' ? t('doriath', 'Active') : t('doriath', 'Pending')
		},

		statusClass(status) {
			return status === 'active' ? 'application-list__badge--active' : 'application-list__badge--pending'
		},

		formatDate(iso) {
			if (!iso) {
				return ''
			}
			return new Date(iso).toLocaleDateString()
		},
	},
}
</script>

<style scoped>
.application-list {
	padding: 20px;
}

.application-list__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 16px;
}

.application-list__title {
	margin: 0;
}

.application-list__table {
	width: 100%;
	border-collapse: collapse;
}

.application-list__table th,
.application-list__table td {
	text-align: left;
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);
}

.application-list__row {
	cursor: pointer;
}

.application-list__row:hover {
	background-color: var(--color-background-hover);
}

.application-list__badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: var(--border-radius-pill);
	background-color: var(--color-background-dark);
	font-size: 0.85em;
}

.application-list__badge--active {
	background-color: var(--color-success);
	color: var(--color-primary-text);
}

.application-list__badge--pending {
	background-color: var(--color-warning);
	color: var(--color-primary-text);
}
</style>
