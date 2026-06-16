<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Move-secret dialog. Re-parents a secret into a folder (or back to the vault
  root). This is a metadata-only change — no re-encryption is needed.
-->
<template>
	<NcDialog :name="t('doriath', 'Move secret')"
		:open="open"
		size="normal"
		@update:open="onUpdateOpen">
		<div class="move-form">
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<NcSelect v-model="folderId"
				:options="folderOptions"
				:reduce="opt => opt.value"
				:input-label="t('doriath', 'Destination folder')"
				:clearable="false" />
		</div>

		<template #actions>
			<NcButton type="tertiary" @click="onUpdateOpen(false)">
				{{ t('doriath', 'Cancel') }}
			</NcButton>
			<NcButton type="primary"
				:disabled="saving"
				@click="submit">
				<template #icon>
					<NcLoadingIcon v-if="saving" :size="20" />
					<FolderMove v-else :size="20" />
				</template>
				{{ t('doriath', 'Move') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcLoadingIcon, NcNoteCard, NcSelect } from '@nextcloud/vue'
import FolderMove from 'vue-material-design-icons/FolderMove.vue'
import { useSecretStore } from '../store/modules/secret.js'
import { useFolderStore } from '../store/modules/folder.js'

/**
 * Move a secret into a folder (or to the vault root) via the secret store.
 * Emits `saved` with the updated secret on success and `close` on dismiss.
 */
export default {
	name: 'SecretMoveDialog',

	components: {
		NcButton,
		NcDialog,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		FolderMove,
	},

	props: {
		/** The ID of the secret to move. */
		secretId: {
			type: String,
			required: true,
		},
		/** The secret's current folder ID (preselected). */
		currentFolderId: {
			type: String,
			default: null,
		},
		/** Optional callback fired with the updated secret after success. */
		onSaved: {
			type: Function,
			default: null,
		},
	},

	data() {
		return {
			open: true,
			folderId: this.currentFolderId,
			saving: false,
			error: '',
		}
	},

	computed: {
		folderOptions() {
			const roots = [{ value: null, label: t('doriath', 'Vault root') }]
			return roots.concat(
				useFolderStore().folders.map(folder => ({
					value: folder.id,
					label: folder.name,
				})),
			)
		},
	},

	async mounted() {
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
		 * Persist the new folder via the store.
		 *
		 * @return {Promise<void>}
		 */
		async submit() {
			this.saving = true
			this.error = ''
			try {
				const updated = await useSecretStore().updateSecret(this.secretId, {
					folderId: this.folderId,
				})
				this.$emit('saved', updated)
				if (this.onSaved) {
					this.onSaved(updated)
				}
				this.onUpdateOpen(false)
			} catch (e) {
				this.error = e?.response?.data?.message || e?.message || t('doriath', 'Failed to move secret')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.move-form {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 4px 0;
}
</style>
