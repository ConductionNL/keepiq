<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Move-secret dialog. Re-parents a secret into a folder (or back to the vault
  root). This is a metadata-only change — no re-encryption is needed.
-->
<template>
	<NcDialog
		:name="t('keepiq', 'Move secret')"
		:open="open"
		size="normal"
		@update:open="onUpdateOpen">
		<div class="move-form">
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<!--
				⚠️ `append-to-body` MUST stay false inside a dialog.

				NcSelect defaults it to true, which makes vue-select TELEPORT the
				options list to <body>. A teleported list opened from inside an
				NcDialog is painted behind that dialog, and the control is then
				simply dead: the options are in the DOM, visible and enabled, but
				every click lands on the dialog instead. Choosing a destination
				folder was impossible — Playwright retried the click ~100 times
				over 60s and each attempt hit `<div class="dialog">` (CI runs
				30798827764, 30800957925, 30804327498).

				Raising the menu's z-index does NOT fix it — tried at 10002 in
				30804327498 and 30805835374, no change — because the teleported
				list and the dialog end up in different stacking contexts, so the
				list's own z-index never competes with the dialog's. Keeping the
				list inside the dialog removes the question entirely.

				The underlying defect is nc-vue's: NcSelect teleports by default
				while NcModal (which NcDialog builds on) creates a stacking
				context the teleported node cannot rise above. Every other
				NcSelect this app renders inside a dialog has the same problem.
			-->
			<NcSelect
				v-model="folderId"
				:options="folderOptions"
				:reduce="(opt) => opt.value"
				:inputLabel="t('keepiq', 'Destination folder')"
				:appendToBody="false"
				:clearable="false" />
		</div>

		<template #actions>
			<NcButton variant="tertiary" @click="onUpdateOpen(false)">
				{{ t('keepiq', 'Cancel') }}
			</NcButton>
			<NcButton variant="primary" :disabled="saving" @click="submit">
				<template #icon>
					<NcLoadingIcon v-if="saving" :size="20" />
					<FolderMove v-else :size="20" />
				</template>
				{{ t('keepiq', 'Move') }}
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
} from '@nextcloud/vue'
import FolderMove from 'vue-material-design-icons/FolderMove.vue'
import { useFolderStore } from '../store/modules/folder.js'
import { useSecretStore } from '../store/modules/secret.js'
import { folderPathLabel } from '../utils/vaultList.js'

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
		/**
		 * The move-target options: the vault root plus every folder the user
		 * owns, at ANY depth. Labelled with the full "A / B / C" path so a
		 * nested folder is distinguishable from a same-named one elsewhere
		 * (restyle Stage 6).
		 *
		 * @return {Array<{value: string|null, label: string}>}
		 * @spec openspec/specs/secrets-write-ui/spec.md#requirement-create-a-folder-and-move-a-secret
		 */
		folderOptions() {
			const folders = useFolderStore().folders
			const roots = [{ value: null, label: t('keepiq', 'Vault root') }]
			return roots.concat(
				folders.map((folder) => ({
					value: folder.id,
					label: folderPathLabel(folders, folder.id) || folder.name,
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
		 * @spec openspec/specs/secrets-write-ui/spec.md#requirement-create-a-folder-and-move-a-secret
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
				this.error =
					e?.response?.data?.message
					|| e?.message
					|| t('keepiq', 'Failed to move secret')
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
