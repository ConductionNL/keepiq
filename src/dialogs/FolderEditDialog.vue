<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Edit-folder dialog: rename plus — at VAULT level — the
  Proton-style icon & color picker. This dialog REINTRODUCES rename: the
  store's updateFolder had no UI caller since the old FolderTree left, so
  this is the rename surface, not just the customize surface.

  Save always sends the two customization keys for a vault (explicit null
  CLEARS server-side, so picking a Default cell genuinely resets); the
  name rides along only when it changed, and the backend applies absent
  keys as untouched.
-->
<template>
	<NcDialog
		:name="isVaultLevel ? t('keepiq', 'Edit vault') : t('keepiq', 'Edit folder')"
		:open="open"
		size="normal"
		@update:open="onUpdateOpen">
		<div class="folder-form">
			<FolderNameField
				v-model="name"
				:vaultLevel="isVaultLevel"
				:error="error" />

			<!-- Personalization applies to VAULTS (top-level folders) only —
			     nested folders keep the plain glyph. -->
			<CnIconColorPicker
				v-if="isVaultLevel"
				v-model:icon="customIcon"
				v-model:color="customColor"
				:fallbackIcon="safeIcon"
				:translate="translateLabel"
				data-testid="folder-edit-style-picker" />
		</div>

		<template #actions>
			<CnDialogActions
				:saving="saving"
				:canSubmit="canSubmit"
				confirmIcon="ContentSaveOutline"
				:confirmLabel="t('keepiq', 'Save')"
				:cancelLabel="t('keepiq', 'Cancel')"
				@cancel="onUpdateOpen(false)"
				@submit="submit" />
		</template>
	</NcDialog>
</template>

<script>
import { CnDialogActions, CnIconColorPicker } from '@conduction/nextcloud-vue'
import { NcDialog } from '@nextcloud/vue'
import FolderNameField from '../components/FolderNameField.vue'
import folderDialog from '../mixins/folderDialog.js'
import { useFolderStore } from '../store/modules/folder.js'

/**
 * Edit a folder via the folder store: rename, and (vault-level) icon +
 * color. Emits `saved` with the updated folder on success and `close` on
 * dismiss.
 */
export default {
	name: 'FolderEditDialog',

	components: {
		CnDialogActions,
		CnIconColorPicker,
		FolderNameField,
		NcDialog,
	},

	mixins: [folderDialog],

	props: {
		/** The folder being edited ({id, name, parentId, customIcon, customColor}). */
		folder: {
			type: Object,
			required: true,
		},
	},

	emits: ['saved', 'close'],

	data() {
		return {
			name: this.folder.name || '',
			customIcon: this.folder.customIcon ?? null,
			customColor: this.folder.customColor ?? null,
		}
	},

	computed: {
		/**
		 * Whether the edited folder is a top-level Vault — customization
		 * (and the dialog's wording) follows the vault/folder terminology split.
		 *
		 * @return {boolean}
		 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
		 */
		isVaultLevel() {
			return !this.folder.parentId
		},
	},

	methods: {
		/**
		 * Save the changes via the store. The name rides along only when it
		 * changed (the backend renames on any present name); for a vault the
		 * two customization keys are ALWAYS sent so an explicit null clears
		 * a stored value (key-present-with-null semantics).
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
		 */
		async submit() {
			await this.saveFolder(
				() => useFolderStore().updateFolder(this.folder.id, this.changes()),
				t('keepiq', 'Failed to update folder'),
			)
		},

		/**
		 * The update to send: the trimmed name only when it changed, and for
		 * a vault both customization keys, where an explicit null clears a
		 * stored value.
		 *
		 * @return {object} The update.
		 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
		 */
		changes() {
			const data = {}
			const trimmed = this.name.trim()
			if (trimmed !== this.folder.name) {
				data.name = trimmed
			}
			if (this.isVaultLevel) {
				data.customIcon = this.customIcon
				data.customColor = this.customColor
			}
			return data
		},
	},
}
</script>

<style scoped src="./folderForm.css"></style>
