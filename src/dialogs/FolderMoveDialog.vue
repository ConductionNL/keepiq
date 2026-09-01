<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Move-vault-contents dialog (restyle Stage 9): vaults themselves never
  re-parent — a vault stays a vault (team decision). "Move" transfers
  EVERYTHING INSIDE the vault (its direct secrets and its subfolders,
  which carry their own subtrees along) into another vault.

  The transfer is client-driven item by item (secret moves via
  updateSecret, subfolder moves via updateFolder) — there is no bulk
  endpoint — so a mid-way failure can leave part of the contents moved;
  the inline error says so honestly.
-->
<template>
	<NcDialog
		:name="t('keepiq', 'Move vault contents')"
		:open="open"
		size="normal"
		@update:open="onUpdateOpen">
		<div class="folder-form">
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<p class="folder-form__hint">
				{{
					isEmpty
						? t(
								'keepiq',
								'This vault is empty — there is nothing to move.',
							)
						: t(
								'keepiq',
								'Move everything in this vault to another vault.',
							)
				}}
			</p>

			<NcSelect
				v-model="targetVaultId"
				:options="targetOptions"
				:reduce="(opt) => opt.value"
				:inputLabel="t('keepiq', 'Target vault')"
				:disabled="isEmpty"
				:clearable="false">
				<!-- Each vault shows its OWN glyph — icon in its color on the
				     tinted circle, exactly as the rail renders it — so the
				     target reads by identity, not just by name. -->
				<template #option="option">
					<span class="folder-move__option">
						<span
							class="folder-move__glyph"
							:style="optionGlyphStyle(option)">
							<component
								:is="optionIcon(option)"
								:size="16"
								:fillColor="optionColor(option)" />
						</span>
						{{ option.label }}
					</span>
				</template>
				<template #selected-option="option">
					<span class="folder-move__option">
						<span
							class="folder-move__glyph"
							:style="optionGlyphStyle(option)">
							<component
								:is="optionIcon(option)"
								:size="16"
								:fillColor="optionColor(option)" />
						</span>
						{{ option.label }}
					</span>
				</template>
			</NcSelect>
		</div>

		<template #actions>
			<NcButton variant="tertiary" @click="onUpdateOpen(false)">
				{{ t('keepiq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="!canSubmit"
				data-testid="folder-move-save"
				@click="submit">
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
	currentTheme,
	folderColorTint,
	resolveFolderColor,
	resolveFolderIcon,
} from '@conduction/nextcloud-vue'
import {
	NcButton,
	NcDialog,
	NcLoadingIcon,
	NcNoteCard,
	NcSelect,
} from '@nextcloud/vue'
import FolderMove from 'vue-material-design-icons/FolderMove.vue'
import Safe from 'vue-material-design-icons/Safe.vue'
import { useFolderStore } from '../store/modules/folder.js'
import { useSecretStore } from '../store/modules/secret.js'

/**
 * Move a vault's contents into another vault. Emits `saved` on success and
 * `close` on dismiss.
 */
export default {
	name: 'FolderMoveDialog',

	components: {
		FolderMove,
		NcButton,
		NcDialog,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
	},

	props: {
		/** The vault whose contents move ({id, name}). */
		folder: {
			type: Object,
			required: true,
		},
	},

	emits: ['saved', 'close'],

	data() {
		return {
			open: true,
			targetVaultId: null,
			/** The source vault's children payload (emptiness check). */
			children: null,
			saving: false,
			error: '',
		}
	},

	computed: {
		/**
		 * Whether the source vault holds neither secrets nor subfolders —
		 * an empty vault has nothing to transfer, so the dialog says that
		 * instead of offering a no-op move. False while the payload is
		 * still loading, so the controls never flash disabled.
		 *
		 * @return {boolean}
		 * @spec openspec/specs/secrets/spec.md#requirement-list-folder-children
		 */
		isEmpty() {
			if (this.children === null) {
				return false
			}
			return (
				(this.children.directSecretCount || 0) === 0
				&& (this.children.subfolders || []).length === 0
			)
		},

		/**
		 * The target picker: every OTHER vault (top-level folder). A vault
		 * cannot be its own target, and nested folders are not offered —
		 * contents move vault-to-vault (team decision).
		 *
		 * @return {Array<{value: string, label: string}>}
		 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
		 */
		targetOptions() {
			return useFolderStore()
				.folders.filter(
					(candidate) =>
						!candidate.parentId && candidate.id !== this.folder.id,
				)
				.map((candidate) => ({
					value: candidate.id,
					label: candidate.name,
					customIcon: candidate.customIcon ?? null,
					customColor: candidate.customColor ?? null,
				}))
		},

		/**
		 * Whether Move may run: not already moving, the source holds
		 * something, and a target vault is chosen.
		 *
		 * @return {boolean}
		 * @spec exclude Form-enablement guard; no domain behaviour.
		 */
		canSubmit() {
			return !this.saving && !this.isEmpty && !!this.targetVaultId
		},
	},

	/**
	 * Load the source vault's children payload for the emptiness check.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/specs/secrets/spec.md#requirement-list-folder-children
	 */
	async mounted() {
		try {
			this.children = await useFolderStore().fetchChildren(this.folder.id)
		} catch {
			// No payload — keep the move offered; the transfer itself will
			// surface any real problem.
		}
	},

	methods: {
		t,

		/**
		 * The glyph a target option renders: the vault's picked icon, Safe
		 * for unset — and for unknown keys (forward compatibility).
		 *
		 * @param {object} option The picker option.
		 * @return {object} An icon component.
		 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
		 */
		optionIcon(option) {
			return resolveFolderIcon(option.customIcon) ?? Safe
		},

		/**
		 * The option glyph's fill for the ACTIVE theme. Always a string —
		 * an explicit null fill-color strips the SVG fill attribute and
		 * renders black.
		 *
		 * @param {object} option The picker option.
		 * @return {string} A hex color or 'currentColor'.
		 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
		 */
		optionColor(option) {
			return (
				resolveFolderColor(option.customColor, currentTheme())
				?? 'currentColor'
			)
		},

		/**
		 * The tinted circle behind the option glyph (same-hex tint, exactly
		 * as the rail renders vaults). Undefined keeps the neutral circle
		 * for uncolored vaults.
		 *
		 * @param {object} option The picker option.
		 * @return {object|undefined} A style object or undefined.
		 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
		 */
		optionGlyphStyle(option) {
			const tint = folderColorTint(option.customColor, currentTheme())
			return tint ? { backgroundColor: tint } : undefined
		},

		/**
		 * Forward the open-state change; emit `close` when dismissed.
		 *
		 * @param {boolean} value The new open state.
		 * @return {void}
		 * @spec exclude Dialog open-state plumbing; no domain behaviour.
		 */
		onUpdateOpen(value) {
			this.open = value
			if (!value) {
				this.$emit('close')
			}
		},

		/**
		 * Transfer the vault's contents: every direct secret via
		 * updateSecret (folderId only — no re-encryption), then every
		 * direct subfolder via updateFolder (each carries its subtree).
		 * Finally refresh the visible list, which may have been mutated by
		 * the full-vault fetch.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
		 */
		async submit() {
			if (!this.canSubmit) {
				return
			}
			this.saving = true
			this.error = ''
			const folderStore = useFolderStore()
			const secretStore = useSecretStore()
			try {
				const secrets = await secretStore.fetchAllSecrets({
					folderId: this.folder.id,
				})
				for (const secret of secrets) {
					await secretStore.updateSecret(secret.id, {
						folderId: this.targetVaultId,
					})
				}
				const subfolders = folderStore.folders.filter(
					(candidate) => candidate.parentId === this.folder.id,
				)
				for (const subfolder of subfolders) {
					await folderStore.updateFolder(subfolder.id, {
						parentId: this.targetVaultId,
						move: true,
					})
				}
				this.$emit('saved', this.folder)
				this.onUpdateOpen(false)
			} catch (e) {
				this.error =
					e?.response?.data?.message
					|| t(
						'keepiq',
						'Failed to move the vault contents — some items may have moved already.',
					)
			} finally {
				// The full-vault fetch replaced the shared list state;
				// restore the visible list with the store's active filters.
				secretStore.fetchSecrets().catch(() => {})
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

.folder-form__hint {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

/* Vault identity inside the target picker: glyph + name per option, the
   glyph on the same tinted circle the rail uses. */
.folder-move__option {
	display: flex;
	align-items: center;
	gap: 8px;
}

.folder-move__glyph {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 24px;
	height: 24px;
	border-radius: 50%;
	background-color: var(--color-background-hover);
	flex-shrink: 0;
}
</style>
