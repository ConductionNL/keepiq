<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Create-folder dialog. Folders hold no encrypted material, so this is a plain
  name (+ optional parent) form wired to the folder store.
-->
<template>
	<NcDialog
		:name="t('keepiq', 'New folder')"
		:open="open"
		size="normal"
		@update:open="onUpdateOpen">
		<div class="folder-form">
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<NcTextField
				v-model="name"
				:label="t('keepiq', 'Folder name')"
				:required="true" />

			<NcSelect
				v-model="selectedParentId"
				:options="parentOptions"
				:reduce="(opt) => opt.value"
				:inputLabel="t('keepiq', 'Parent folder')"
				:clearable="false" />
		</div>

		<template #actions>
			<NcButton variant="tertiary" @click="onUpdateOpen(false)">
				{{ t('keepiq', 'Cancel') }}
			</NcButton>
			<NcButton variant="primary" :disabled="!canSubmit" @click="submit">
				<template #icon>
					<NcLoadingIcon v-if="saving" :size="20" />
					<FolderPlus v-else :size="20" />
				</template>
				{{ t('keepiq', 'Create folder') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import {
	NcButton,
	NcDialog,
	NcLoadingIcon,
	NcNoteCard,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import FolderPlus from 'vue-material-design-icons/FolderPlus.vue'
import { useFolderStore } from '../store/modules/folder.js'

/**
 * Create a folder via the folder store. Emits `saved` with the new folder on
 * success and `close` on dismiss.
 */
export default {
	name: 'FolderCreateDialog',

	components: {
		NcButton,
		NcDialog,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		NcTextField,
		FolderPlus,
	},

	props: {
		/** The parent folder to create under (defaults to root / current view). */
		parentId: {
			type: String,
			default: null,
		},

		/** Optional callback fired with the created folder after success. */
		onSaved: {
			type: Function,
			default: null,
		},
	},

	data() {
		return {
			open: true,
			name: '',
			selectedParentId: this.parentId,
			saving: false,
			error: '',
		}
	},

	computed: {
		/**
		 * The parent-folder picker options: the vault root plus every folder
		 * the user owns.
		 *
		 * @return {Array<{value: string|null, label: string}>}
		 * @spec openspec/specs/secrets-write-ui/spec.md#requirement-create-a-folder-and-move-a-secret
		 */
		parentOptions() {
			const roots = [{ value: null, label: t('keepiq', 'Vault root') }]
			return roots.concat(
				useFolderStore().folders.map((folder) => ({
					value: folder.id,
					label: folder.name,
				})),
			)
		},

		canSubmit() {
			return !this.saving && this.name.trim() !== ''
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
		 * Create the folder via the store.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/secrets-write-ui/spec.md#requirement-create-a-folder-and-move-a-secret
		 */
		async submit() {
			if (!this.canSubmit) {
				return
			}
			this.saving = true
			this.error = ''
			try {
				const created = await useFolderStore().createFolder(
					this.name.trim(),
					this.selectedParentId,
				)
				this.$emit('saved', created)
				if (this.onSaved) {
					this.onSaved(created)
				}
				this.onUpdateOpen(false)
			} catch (e) {
				this.error =
					e?.response?.data?.message
					|| e?.message
					|| t('keepiq', 'Failed to create folder')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.folder-form {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 4px 0;
}
</style>
