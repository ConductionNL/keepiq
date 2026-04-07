<template>
	<NcDialog
		:open="open"
		:name="t('doriath', 'Create secret')"
		@update:open="$emit('update:open', $event)">
		<div class="create-secret-form">
			<NcInputField
				v-model="newSecret.name"
				:label="t('doriath', 'Name')"
				:placeholder="t('doriath', 'e.g. GitHub, AWS Console')"
				required />
			<NcInputField
				v-model="newSecret.url"
				:label="t('doriath', 'URL')"
				:placeholder="t('doriath', 'e.g. https://github.com')" />
			<NcInputField
				v-model="newSecret.login"
				:label="t('doriath', 'Username / Login')"
				:placeholder="t('doriath', 'e.g. user@example.com')" />
			<NcPasswordField
				v-model="newSecret.key"
				:label="t('doriath', 'Password / Key')" />
			<NcSelect
				v-model="newSecret.typeId"
				:options="typeOptions"
				label="label"
				:reduce="opt => opt.value"
				:placeholder="t('doriath', 'Type')" />
			<NcSelect
				v-model="newSecret.folderId"
				:options="folderOptions"
				label="label"
				:reduce="opt => opt.value"
				:placeholder="t('doriath', 'Folder (optional)')" />
			<NcNoteCard v-if="createError" type="error">
				{{ createError }}
			</NcNoteCard>
			<div class="create-secret-form__actions">
				<NcButton type="tertiary" @click="$emit('update:open', false)">
					{{ t('doriath', 'Cancel') }}
				</NcButton>
				<NcButton
					type="primary"
					:disabled="!newSecret.name || !newSecret.key || creating"
					@click="handleCreate">
					{{ creating ? t('doriath', 'Creating...') : t('doriath', 'Create') }}
				</NcButton>
			</div>
		</div>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcInputField, NcNoteCard, NcPasswordField, NcSelect } from '@nextcloud/vue'
import { useFolderStore } from '../store/modules/folder.js'
import { useSecretStore } from '../store/modules/secret.js'
import { useSecretTypeStore } from '../store/modules/secretType.js'

export default {
	name: 'CreateSecretDialog',
	components: {
		NcButton,
		NcDialog,
		NcInputField,
		NcNoteCard,
		NcPasswordField,
		NcSelect,
	},
	props: {
		open: {
			type: Boolean,
			default: false,
		},
		folderId: {
			type: String,
			default: null,
		},
	},
	emits: ['update:open', 'created'],
	data() {
		return {
			creating: false,
			createError: null,
			newSecret: {
				name: '',
				folderId: null,
				url: '',
				login: '',
				key: '',
				typeId: null,
			},
		}
	},
	computed: {
		secretStore() {
			return useSecretStore()
		},
		secretTypeStore() {
			return useSecretTypeStore()
		},
		folderStore() {
			return useFolderStore()
		},
		folderOptions() {
			return this.folderStore.folders.map(f => ({
				value: f.id,
				label: f.name,
			}))
		},
		typeOptions() {
			return this.secretTypeStore.types.map(t => ({
				value: t.id,
				label: t.label,
			}))
		},
	},
	watch: {
		open(val) {
			if (val) {
				this.newSecret = { name: '', url: '', login: '', key: '', typeId: null, folderId: null }
				this.createError = null
			}
		},
	},
	methods: {
		async handleCreate() {
			this.creating = true
			this.createError = null

			try {
				const data = {
					name: this.newSecret.name,
					key: this.newSecret.key,
				}
				if (this.newSecret.url) data.url = this.newSecret.url
				if (this.newSecret.login) data.login = this.newSecret.login
				if (this.newSecret.typeId) data.typeId = this.newSecret.typeId
				if (this.newSecret.folderId) data.folderId = this.newSecret.folderId
				else if (this.folderId) data.folderId = this.folderId

				const created = await this.secretStore.createSecret(data)
				this.$emit('update:open', false)
				this.$emit('created', created)
			} catch (e) {
				this.createError = e.response?.data?.message || e.message || t('doriath', 'Failed to create secret')
			} finally {
				this.creating = false
			}
		},
	},
}
</script>

<style scoped>
.create-secret-form {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 8px 0;
}

.create-secret-form__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 8px;
}
</style>
