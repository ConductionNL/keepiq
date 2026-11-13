<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Create-folder dialog. Folders hold no encrypted material, so this is a plain
  name (+ optional parent) form wired to the folder store.
-->
<template>
	<!-- Level-appropriate wording (restyle terminology): a TOP-LEVEL folder
	     is a Vault, one inside a vault is a folder. The LEVEL IS FIXED by
	     the opening context (team decision): a vault is only ever created
	     at the root — no parent picker at all — and a folder only ever
	     inside a vault, so the picker never offers the root. The two flows
	     can no longer morph into each other mid-dialog. -->
	<NcDialog
		:name="isVaultLevel ? t('keepiq', 'New vault') : t('keepiq', 'New folder')"
		:open="open"
		size="normal"
		@update:open="onUpdateOpen">
		<div class="folder-form">
			<FolderNameField
				v-model="name"
				:vaultLevel="isVaultLevel"
				:error="error" />

			<NcSelect
				v-if="!isVaultLevel"
				v-model="selectedParentId"
				:options="parentOptions"
				:reduce="(opt) => opt.value"
				:inputLabel="t('keepiq', 'Parent folder')"
				:clearable="false" />

			<!-- Vault personalization (Proton pattern):
			     only TOP-LEVEL folders are Vaults and carry an icon + color;
			     nested folders keep the plain glyph, so the picker follows
			     the selected parent like the wording does. Unset = the Safe
			     default. -->
			<CnIconColorPicker
				v-if="isVaultLevel"
				v-model:icon="customIcon"
				v-model:color="customColor"
				:fallbackIcon="safeIcon"
				:translate="translateLabel"
				data-testid="folder-create-style-picker" />
		</div>

		<template #actions>
			<CnDialogActions
				:saving="saving"
				:canSubmit="canSubmit"
				:confirmIcon="isVaultLevel ? 'Safe' : 'FolderPlus'"
				:confirmLabel="
					isVaultLevel
						? t('keepiq', 'Create vault')
						: t('keepiq', 'Create folder')
				"
				:cancelLabel="t('keepiq', 'Cancel')"
				@cancel="onUpdateOpen(false)"
				@submit="submit" />
		</template>
	</NcDialog>
</template>

<script>
import { CnDialogActions, CnIconColorPicker } from '@conduction/nextcloud-vue'
import { NcDialog, NcSelect } from '@nextcloud/vue'
import FolderNameField from '../components/FolderNameField.vue'
import folderDialog from '../mixins/folderDialog.js'
import { useFolderStore } from '../store/modules/folder.js'
import { folderPathLabel } from '../utils/vaultList.js'

/**
 * Create a folder via the folder store. Emits `saved` with the new folder on
 * success and `close` on dismiss.
 */
export default {
	name: 'FolderCreateDialog',

	components: {
		CnDialogActions,
		CnIconColorPicker,
		FolderNameField,
		NcDialog,
		NcSelect,
	},

	mixins: [folderDialog],

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

	emits: ['saved', 'close'],

	data() {
		return {
			name: '',
			selectedParentId: this.parentId,
			/** Picked customization keys (vault level only; null = default). */
			customIcon: null,
			customColor: null,
		}
	},

	computed: {
		/**
		 * Whether the dialog creates a VAULT: fixed by the OPENING context
		 * (no parentId = opened at the root), never by the picker. A vault
		 * is only ever created at the root (team decision), so the vault
		 * flow shows no parent picker at all.
		 *
		 * @return {boolean}
		 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
		 */
		isVaultLevel() {
			return !this.parentId
		},

		/**
		 * The parent-folder picker options (FOLDER creation only): every
		 * folder the user owns — WITHOUT the root, because creating at the
		 * root is the vault flow and the two never morph into each other —
		 * path-labelled ("A / B / C") so same-named nested folders stay
		 * distinguishable.
		 *
		 * @return {Array<{value: string, label: string}>}
		 * @spec openspec/specs/secrets-write-ui/spec.md#requirement-create-a-folder-and-move-a-secret
		 */
		parentOptions() {
			const folders = useFolderStore().folders
			return folders.map((folder) => ({
				value: folder.id,
				label: folderPathLabel(folders, folder.id) || folder.name,
			}))
		},
	},

	/**
	 * @spec openspec/specs/secrets-write-ui/spec.md#requirement-create-a-folder-and-move-a-secret
	 */
	async mounted() {
		const folderStore = useFolderStore()
		if (folderStore.folders.length === 0) {
			await folderStore.fetchFolders()
		}
	},

	methods: {
		/**
		 * Create the folder via the store.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/secrets-write-ui/spec.md#requirement-create-a-folder-and-move-a-secret
		 */
		async submit() {
			await this.saveFolder(
				() => useFolderStore().createFolder(
					this.name.trim(),
					this.selectedParentId,
					this.customization(),
				),
				t('keepiq', 'Failed to create folder'),
			)
		},

		/**
		 * The customization to create with. It only exists at vault level —
		 * a picker choice made before switching to a nested parent must not
		 * ride along.
		 *
		 * @return {object} The customization keys, or none.
		 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
		 */
		customization() {
			return this.isVaultLevel
				? { customIcon: this.customIcon, customColor: this.customColor }
				: {}
		},

		/**
		 * Tell the parent of the created folder, and the optional callback.
		 *
		 * @param {object} created The created folder.
		 * @return {void}
		 * @spec openspec/specs/secrets-write-ui/spec.md#requirement-create-a-folder-and-move-a-secret
		 */
		onFolderSaved(created) {
			this.$emit('saved', created)
			if (this.onSaved) {
				this.onSaved(created)
			}
		},
	},
}
</script>

<style scoped src="./folderForm.css"></style>
