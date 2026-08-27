<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Create-folder dialog. Folders hold no encrypted material, so this is a plain
  name (+ optional parent) form wired to the folder store.
-->
<template>
	<!-- Level-appropriate wording (restyle terminology): a TOP-LEVEL folder
	     is a Vault, one inside a vault is a folder. The wording follows the
	     SELECTED parent, so switching the parent picker retitles the dialog
	     live. -->
	<NcDialog
		:name="isVaultLevel ? t('keepiq', 'New vault') : t('keepiq', 'New folder')"
		:open="open"
		size="normal"
		@update:open="onUpdateOpen">
		<div class="folder-form">
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<NcTextField
				v-model="name"
				:label="
					isVaultLevel
						? t('keepiq', 'Vault name')
						: t('keepiq', 'Folder name')
				"
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
					<Safe v-else-if="isVaultLevel" :size="20" />
					<FolderPlus v-else :size="20" />
				</template>
				{{
					isVaultLevel
						? t('keepiq', 'Create vault')
						: t('keepiq', 'Create folder')
				}}
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
import Safe from 'vue-material-design-icons/Safe.vue'
import { useFolderStore } from '../store/modules/folder.js'
import { folderPathLabel } from '../utils/vaultList.js'

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
		Safe,
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
		 * Whether the SELECTED parent is the root — creating there makes a
		 * top-level folder, i.e. a Vault (restyle terminology), and the
		 * dialog's wording follows.
		 *
		 * @return {boolean}
		 */
		isVaultLevel() {
			return !this.selectedParentId
		},

		/**
		 * The parent-folder picker options: the vault root plus every folder
		 * the user owns, path-labelled ("A / B / C") so same-named nested
		 * folders stay distinguishable (restyle Stage 6).
		 *
		 * @return {Array<{value: string|null, label: string}>}
		 * @spec openspec/specs/secrets-write-ui/spec.md#requirement-create-a-folder-and-move-a-secret
		 */
		parentOptions() {
			const folders = useFolderStore().folders
			const roots = [{ value: null, label: t('keepiq', 'Vault root') }]
			return roots.concat(
				folders.map((folder) => ({
					value: folder.id,
					label: folderPathLabel(folders, folder.id) || folder.name,
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
