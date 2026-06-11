<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Create-secret dialog. Collects name + type + value (+ optional URL / login /
  folder) and creates the secret via the secret store, which RSA-encrypts the
  sensitive fields client-side before the POST (zero-knowledge, ADR-003).
-->
<template>
	<NcDialog :name="t('doriath', 'New secret')"
		:open="open"
		size="normal"
		@update:open="onUpdateOpen">
		<div class="secret-form">
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>
			<NcNoteCard v-if="locked" type="warning">
				{{ t('doriath', 'Unlock the vault before creating a secret.') }}
			</NcNoteCard>

			<NcTextField :value.sync="name"
				:label="t('doriath', 'Name')"
				:required="true" />

			<NcSelect v-model="typeId"
				:options="typeOptions"
				:reduce="opt => opt.value"
				:input-label="t('doriath', 'Type')"
				:clearable="false" />

			<NcPasswordField :value.sync="value"
				:label="valueLabel" />

			<NcTextField :value.sync="url"
				:label="t('doriath', 'URL (optional)')" />

			<NcTextField :value.sync="login"
				:label="t('doriath', 'Login (optional)')" />

			<NcSelect v-model="selectedFolderId"
				:options="folderOptions"
				:reduce="opt => opt.value"
				:input-label="t('doriath', 'Folder')"
				:clearable="false" />
		</div>

		<template #actions>
			<NcButton type="tertiary" @click="onUpdateOpen(false)">
				{{ t('doriath', 'Cancel') }}
			</NcButton>
			<NcButton type="primary"
				:disabled="!canSubmit"
				@click="submit">
				<template #icon>
					<NcLoadingIcon v-if="saving" :size="20" />
					<Plus v-else :size="20" />
				</template>
				{{ t('doriath', 'Create secret') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcLoadingIcon, NcNoteCard, NcPasswordField, NcSelect, NcTextField } from '@nextcloud/vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import { useSecretStore } from '../store/modules/secret.js'
import { useSecretTypeStore } from '../store/modules/secretType.js'
import { useFolderStore } from '../store/modules/folder.js'
import { useSessionStore } from '../store/modules/session.js'

/**
 * Create a secret. The value (and optional login) are RSA-encrypted by the
 * store using the suite certificate before the request leaves the browser.
 * Emits `saved` with the created secret on success and `close` on dismiss.
 */
export default {
	name: 'SecretCreateDialog',

	components: {
		NcButton,
		NcDialog,
		NcLoadingIcon,
		NcNoteCard,
		NcPasswordField,
		NcSelect,
		NcTextField,
		Plus,
	},

	props: {
		/** The folder to create the secret in (defaults to the current view). */
		folderId: {
			type: String,
			default: null,
		},
		/** Optional callback fired with the created secret after success. */
		onSaved: {
			type: Function,
			default: null,
		},
	},

	data() {
		return {
			open: true,
			name: '',
			typeId: null,
			value: '',
			url: '',
			login: '',
			selectedFolderId: this.folderId,
			saving: false,
			error: '',
		}
	},

	computed: {
		locked() {
			return useSessionStore().isLocked
		},
		typeOptions() {
			return useSecretTypeStore().types.map(type => ({
				value: type.id,
				label: type.label || type.name,
			}))
		},
		folderOptions() {
			const roots = [{ value: null, label: t('doriath', 'Vault root') }]
			return roots.concat(
				useFolderStore().folders.map(folder => ({
					value: folder.id,
					label: folder.name,
				})),
			)
		},
		valueLabel() {
			const type = useSecretTypeStore().typesById[this.typeId]
			return type && type.name === 'note'
				? t('doriath', 'Note')
				: t('doriath', 'Secret value')
		},
		canSubmit() {
			return !this.saving && !this.locked && this.name.trim() !== '' && this.value !== ''
		},
	},

	async mounted() {
		const typeStore = useSecretTypeStore()
		if (typeStore.types.length === 0) {
			await typeStore.fetchTypes()
		}
		if (this.typeId === null && typeStore.types.length > 0) {
			const login = typeStore.types.find(type => type.name === 'login')
			this.typeId = login ? login.id : typeStore.types[0].id
		}
		const folderStore = useFolderStore()
		if (folderStore.folders.length === 0) {
			await folderStore.fetchFolders()
		}
	},

	methods: {
		t,

		/**
		 * Forward the open-state change; emit `close` when dismissed.
		 *
		 * @param {boolean} value The new open state.
		 * @return {void}
		 */
		onUpdateOpen(value) {
			this.open = value
			if (!value) {
				this.$emit('close')
			}
		},

		/**
		 * Encrypt (in the store) and create the secret.
		 *
		 * @return {Promise<void>}
		 */
		async submit() {
			if (!this.canSubmit) {
				return
			}
			this.saving = true
			this.error = ''
			try {
				const created = await useSecretStore().createSecret({
					name: this.name.trim(),
					typeId: this.typeId,
					folderId: this.selectedFolderId,
					url: this.url || null,
					login: this.login || '',
					key: this.value,
				})
				this.$emit('saved', created)
				if (this.onSaved) {
					this.onSaved(created)
				}
				this.onUpdateOpen(false)
			} catch (e) {
				this.error = e?.response?.data?.message || e?.message || t('doriath', 'Failed to create secret')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.secret-form {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 4px 0;
}
</style>
