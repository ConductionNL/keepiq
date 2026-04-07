<template>
	<NcDialog
		:open="open"
		:name="t('doriath', 'Delete secret')"
		@update:open="$emit('update:open', $event)">
		<p>{{ t('doriath', 'Are you sure you want to delete "{name}"? This cannot be undone.', { name: secretName }) }}</p>
		<template #actions>
			<NcButton @click="$emit('update:open', false)">
				{{ t('doriath', 'Cancel') }}
			</NcButton>
			<NcButton type="error" :disabled="deleting" @click="confirmDelete">
				{{ deleting ? t('doriath', 'Deleting...') : t('doriath', 'Delete') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog } from '@nextcloud/vue'
import { useSecretStore } from '../store/modules/secret.js'

export default {
	name: 'DeleteSecretDialog',
	components: {
		NcButton,
		NcDialog,
	},
	props: {
		open: {
			type: Boolean,
			default: false,
		},
		secretId: {
			type: String,
			default: null,
		},
		secretName: {
			type: String,
			default: '',
		},
	},
	emits: ['update:open', 'deleted'],
	data() {
		return {
			deleting: false,
		}
	},
	computed: {
		secretStore() {
			return useSecretStore()
		},
	},
	methods: {
		async confirmDelete() {
			if (!this.secretId) return
			this.deleting = true
			try {
				await this.secretStore.deleteSecret(this.secretId)
				this.$emit('update:open', false)
				this.$emit('deleted')
			} catch (e) {
				this.$emit('update:open', false)
				this.$emit('error', e.message || t('doriath', 'Failed to delete secret'))
			} finally {
				this.deleting = false
			}
		},
	},
}
</script>
