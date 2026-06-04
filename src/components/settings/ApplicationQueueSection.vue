<template>
	<CnSettingsSection
		:name="t('doriath', 'Application approval queue')"
		:description="t('doriath', 'Approve or reject applications awaiting access to the vault')">
		<div class="app-queue">
			<NcEmptyContent v-if="!loading && pendingApplications.length === 0"
				:name="t('doriath', 'No pending applications')">
				<template #icon>
					<CheckIcon :size="32" />
				</template>
			</NcEmptyContent>

			<table v-else class="app-queue__table">
				<thead>
					<tr>
						<th>{{ t('doriath', 'Name') }}</th>
						<th>{{ t('doriath', 'Type') }}</th>
						<th>{{ t('doriath', 'Registered by') }}</th>
						<th>{{ t('doriath', 'Actions') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="app in pendingApplications" :key="app.id">
						<td>{{ app.name }}</td>
						<td>{{ app.type }}</td>
						<td>{{ app.registeredBy || t('doriath', 'Anonymous') }}</td>
						<td class="app-queue__actions">
							<NcButton type="primary"
								:disabled="busyId === app.id"
								@click="approve(app.id)">
								{{ t('doriath', 'Approve') }}
							</NcButton>
							<NcButton type="error"
								:disabled="busyId === app.id"
								@click="reject(app.id)">
								{{ t('doriath', 'Reject') }}
							</NcButton>
						</td>
					</tr>
				</tbody>
			</table>

			<PrivateKeyDownloadDialog v-if="oneTimePrivateKey"
				:private-key="oneTimePrivateKey"
				@close="clearKey" />
		</div>
	</CnSettingsSection>
</template>

<script>
import { mapState } from 'pinia'
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcEmptyContent } from '@nextcloud/vue'
import { CnSettingsSection } from '@conduction/nextcloud-vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import { useApplicationStore } from '../../store/modules/application.js'
import PrivateKeyDownloadDialog from '../PrivateKeyDownloadDialog.vue'

export default {
	name: 'ApplicationQueueSection',

	components: {
		NcButton,
		NcEmptyContent,
		CnSettingsSection,
		CheckIcon,
		PrivateKeyDownloadDialog,
	},

	data() {
		return {
			busyId: null,
		}
	},

	computed: {
		...mapState(useApplicationStore, ['pendingApplications', 'loading', 'oneTimePrivateKey']),
	},

	mounted() {
		useApplicationStore().fetchPending()
	},

	methods: {
		async approve(id) {
			this.busyId = id
			try {
				await useApplicationStore().approveApplication(id)
			} finally {
				this.busyId = null
			}
		},

		async reject(id) {
			this.busyId = id
			try {
				await useApplicationStore().rejectApplication(id)
			} finally {
				this.busyId = null
			}
		},

		clearKey() {
			useApplicationStore().clearOneTimePrivateKey()
		},
	},
}
</script>

<style scoped>
.app-queue__table {
	width: 100%;
	border-collapse: collapse;
}

.app-queue__table th,
.app-queue__table td {
	text-align: left;
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);
}

.app-queue__actions {
	display: flex;
	gap: 8px;
}
</style>
