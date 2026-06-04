<template>
	<div class="secrets-panel">
		<div class="secrets-panel__header">
			<h3 class="secrets-panel__title">
				{{ t('doriath', 'Secrets') }}
			</h3>
			<NcButton v-if="active" @click="writeDialogOpen = true">
				<template #icon>
					<PlusIcon :size="20" />
				</template>
				{{ t('doriath', 'Write secret') }}
			</NcButton>
		</div>

		<NcEmptyContent v-if="secrets.length === 0"
			:name="t('doriath', 'No secrets')"
			:description="t('doriath', 'Secrets written for this application are encrypted and can only be read by the application itself.')">
			<template #icon>
				<KeyIcon :size="32" />
			</template>
		</NcEmptyContent>

		<table v-else class="secrets-panel__table">
			<thead>
				<tr>
					<th>{{ t('doriath', 'Name') }}</th>
					<th>{{ t('doriath', 'Created') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="secret in secrets" :key="secret.id">
					<td>{{ secret.name }}</td>
					<td>{{ formatDate(secret.createdAt) }}</td>
				</tr>
			</tbody>
		</table>

		<WriteSecretForAppDialog :open.sync="writeDialogOpen"
			:application-id="applicationId"
			@written="$emit('written')" />
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcEmptyContent } from '@nextcloud/vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import KeyIcon from 'vue-material-design-icons/Key.vue'
import WriteSecretForAppDialog from './WriteSecretForAppDialog.vue'

export default {
	name: 'ApplicationSecretsPanel',

	components: {
		NcButton,
		NcEmptyContent,
		PlusIcon,
		KeyIcon,
		WriteSecretForAppDialog,
	},

	props: {
		applicationId: {
			type: String,
			required: true,
		},
		active: {
			type: Boolean,
			default: false,
		},
		secrets: {
			type: Array,
			default: () => [],
		},
	},

	data() {
		return {
			writeDialogOpen: false,
		}
	},

	methods: {
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
.secrets-panel__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 12px;
}

.secrets-panel__title {
	margin: 0;
}

.secrets-panel__table {
	width: 100%;
	border-collapse: collapse;
}

.secrets-panel__table th,
.secrets-panel__table td {
	text-align: left;
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);
}
</style>
