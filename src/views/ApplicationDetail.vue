<template>
	<div class="application-detail">
		<div v-if="application" class="application-detail__content">
			<div class="application-detail__header">
				<NcButton type="tertiary" @click="goBack">
					<template #icon>
						<ArrowLeftIcon :size="20" />
					</template>
					{{ t('doriath', 'Back') }}
				</NcButton>
				<h2 class="application-detail__title">
					{{ application.name }}
				</h2>
				<NcButton v-if="isAdmin"
					type="error"
					@click="confirmDelete">
					<template #icon>
						<DeleteIcon :size="20" />
					</template>
					{{ t('doriath', 'Delete') }}
				</NcButton>
			</div>

			<dl class="application-detail__meta">
				<dt>{{ t('doriath', 'Description') }}</dt>
				<dd>{{ application.description || '—' }}</dd>
				<dt>{{ t('doriath', 'Type') }}</dt>
				<dd>{{ application.type }}</dd>
				<dt>{{ t('doriath', 'Status') }}</dt>
				<dd>{{ application.status }}</dd>
				<dt>{{ t('doriath', 'Registered by') }}</dt>
				<dd>{{ application.registeredBy || t('doriath', 'Anonymous') }}</dd>
				<dt>{{ t('doriath', 'Approved by') }}</dt>
				<dd>{{ application.approvedBy || '—' }}</dd>
				<dt>{{ t('doriath', 'Created') }}</dt>
				<dd>{{ formatDate(application.createdAt) }}</dd>
			</dl>

			<ApplicationSecretsPanel :application-id="application.id"
				:active="application.status === 'active'"
				:secrets="[]" />
		</div>

		<NcLoadingIcon v-else :size="32" />
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { getCurrentUser } from '@nextcloud/auth'
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import ArrowLeftIcon from 'vue-material-design-icons/ArrowLeft.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import { useApplicationStore } from '../store/modules/application.js'
import ApplicationSecretsPanel from '../components/ApplicationSecretsPanel.vue'

export default {
	name: 'ApplicationDetail',

	components: {
		NcButton,
		NcLoadingIcon,
		ArrowLeftIcon,
		DeleteIcon,
		ApplicationSecretsPanel,
	},

	props: {
		id: {
			type: String,
			default: null,
		},
	},

	data() {
		return {
			application: null,
		}
	},

	computed: {
		applicationId() {
			return this.id || this.$route?.params?.id
		},

		isAdmin() {
			const user = getCurrentUser()
			return !!(user && user.isAdmin)
		},
	},

	async mounted() {
		this.application = await useApplicationStore().fetchApplication(this.applicationId)
	},

	methods: {
		goBack() {
			this.$router.push({ name: 'Applications' })
		},

		async confirmDelete() {
			// eslint-disable-next-line no-alert
			if (!window.confirm(t('doriath', 'Permanently delete this application and all its secrets? This cannot be undone.'))) {
				return
			}
			await useApplicationStore().deleteApplication(this.applicationId)
			this.goBack()
		},

		formatDate(iso) {
			if (!iso) {
				return ''
			}
			return new Date(iso).toLocaleString()
		},
	},
}
</script>

<style scoped>
.application-detail {
	padding: 20px;
}

.application-detail__header {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-bottom: 16px;
}

.application-detail__title {
	flex: 1;
	margin: 0;
}

.application-detail__meta {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 4px 16px;
	margin-bottom: 24px;
}

.application-detail__meta dt {
	font-weight: bold;
	color: var(--color-text-maxcontrast);
}

.application-detail__meta dd {
	margin: 0;
}
</style>
