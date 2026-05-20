<template>
	<div class="folder-tree">
		<NcAppNavigationItem
			:name="t('doriath', 'All secrets')"
			:active="isAllSecrets"
			@click="navigate(null)">
			<template #icon>
				<KeyVariantIcon :size="20" />
			</template>
		</NcAppNavigationItem>

		<div class="separator" />

		<NcAppNavigationItem
			:name="t('doriath', 'Main Folder')"
			:active="isRootFolder"
			@click="navigateRoot"
			@contextmenu.native.prevent="onRootContextMenu">
			<template #icon>
				<InboxIcon :size="20" />
			</template>
		</NcAppNavigationItem>

		<div class="folder-tree__children">
			<FolderTreeNode
				v-for="folder in folderTree"
				:key="folder.id"
				:folder="folder"
				:current-folder-id="currentFolderId"
				:trigger-action="triggerAction"
				@navigate="navigate"
				@context-menu="onFolderContextMenu" />
		</div>

		<div
			v-if="showNewFolder"
			class="folder-tree__inline-input"
			@click.stop>
			<FolderPlusIcon :size="20" class="folder-tree__inline-input-icon" />
			<NcInputField
				ref="newFolderInput"
				v-model="newFolderName"
				v-tooltip="isNewFolderDuplicate ? t('doriath', 'A folder with this name already exists in the same location') : ''"
				:label="t('doriath', 'Folder name')"
				:disabled="creating"
				:error="!!newFolderName && isNewFolderDuplicate"
				@keyup.enter="createFolder"
				@keyup.escape="cancelNewFolder"
				@blur="handleNewFolderBlur" />
		</div>

		<RenameFolderDialog
			:open.sync="showRenameDialog"
			:folder="renameFolder"
			@renamed="onRenamed" />

		<RemoveFolderDialog
			:open.sync="showDeleteDialog"
			:folder="folderToDelete"
			@deleted="onDeleted" />

		<CnContextMenu
			:open.sync="contextMenuOpen"
			:active-panel.sync="contextMenuPanel"
			@close="onContextMenuClose">
			<NcActionButton close-after-click @click="onContextNewSubfolder">
				<template #icon>
					<FolderPlusIcon :size="20" />
				</template>
				{{ t('doriath', 'New subfolder') }}
			</NcActionButton>
			<NcActionButton
				v-if="contextMenuFolderId && contextMenuFolderId !== '__root__'"
				close-after-click
				@click="onContextRename">
				<template #icon>
					<PencilIcon :size="20" />
				</template>
				{{ t('doriath', 'Rename') }}
			</NcActionButton>
			<NcActionButton
				v-if="contextMenuFolderId && contextMenuFolderId !== '__root__'"
				@click="contextMenuPanel = 'icon'">
				<template #icon>
					<PaletteSwatchIcon :size="20" />
				</template>
				{{ t('doriath', 'Change icon') }}
			</NcActionButton>
			<NcActionButton
				v-if="contextMenuFolderId && contextMenuFolderId !== '__root__'"
				@click="contextMenuPanel = 'color'">
				<template #icon>
					<PaletteIcon :size="20" />
				</template>
				{{ t('doriath', 'Change color') }}
			</NcActionButton>
			<NcActionButton
				v-if="contextMenuFolderId && contextMenuFolderId !== '__root__'"
				close-after-click
				@click="onContextDelete">
				<template #icon>
					<DeleteIcon :size="20" />
				</template>
				{{ t('doriath', 'Delete') }}
			</NcActionButton>

			<template #panel:color="{ back }">
				<div class="folder-customize__panel">
					<div class="folder-customize__panel-header">
						<button
							type="button"
							class="folder-customize__panel-back"
							@click="back">
							<ArrowLeftIcon :size="16" />
							{{ t('doriath', 'Back') }}
						</button>
						<button
							type="button"
							class="folder-customize__panel-reset"
							@click="applyColor(null)">
							{{ t('doriath', 'Reset') }}
						</button>
					</div>
					<div class="folder-customize__swatches">
						<button
							v-for="c in FOLDER_COLORS"
							:key="c.key"
							type="button"
							class="folder-customize__swatch"
							:style="{ backgroundColor: resolveFolderColor(c.key, theme) }"
							:title="t('doriath', c.label)"
							:aria-label="t('doriath', c.label)"
							@click="applyColor(c.key)" />
					</div>
				</div>
			</template>

			<template #panel:icon="{ back }">
				<div class="folder-customize__panel">
					<div class="folder-customize__panel-header">
						<button
							type="button"
							class="folder-customize__panel-back"
							@click="back">
							<ArrowLeftIcon :size="16" />
							{{ t('doriath', 'Back') }}
						</button>
						<button
							type="button"
							class="folder-customize__panel-reset"
							@click="applyIcon(null)">
							{{ t('doriath', 'Reset') }}
						</button>
					</div>
					<NcInputField
						v-model="iconSearchQuery"
						:label="t('doriath', 'Search icons')"
						class="folder-customize__icon-search" />
					<div class="folder-customize__icons">
						<button
							v-for="entry in filteredIcons"
							:key="entry.key"
							type="button"
							class="folder-customize__icon-btn"
							:title="t('doriath', entry.label)"
							:aria-label="t('doriath', entry.label)"
							@click="applyIcon(entry.key)">
							<component :is="entry.component" :size="20" />
						</button>
					</div>
				</div>
			</template>
		</CnContextMenu>
	</div>
</template>

<script>
import { NcActionButton, NcAppNavigationItem, NcInputField } from '@nextcloud/vue'
import { CnContextMenu, useContextMenu } from '@conduction/nextcloud-vue'
import ArrowLeftIcon from 'vue-material-design-icons/ArrowLeft.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import FolderPlusIcon from 'vue-material-design-icons/FolderPlus.vue'
import InboxIcon from 'vue-material-design-icons/Inbox.vue'
import KeyVariantIcon from 'vue-material-design-icons/KeyVariant.vue'
import PaletteIcon from 'vue-material-design-icons/Palette.vue'
import PaletteSwatchIcon from 'vue-material-design-icons/PaletteSwatch.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import FolderTreeNode from './FolderTreeNode.vue'
import RenameFolderDialog from '../dialog/RenameFolderDialog.vue'
import RemoveFolderDialog from '../dialog/RemoveFolderDialog.vue'
import { useFolderStore } from '../store/modules/folder.js'
import { folderDirQuery } from '../utils/folderPath.js'
import { FOLDER_COLORS, resolveFolderColor } from '../utils/folderColors.js'
import { FOLDER_ICONS, searchFolderIcons } from '../utils/folderIcons.js'
import { currentTheme } from '../utils/theme.js'

export default {
	name: 'FolderTree',
	components: {
		NcActionButton,
		NcAppNavigationItem,
		NcInputField,
		CnContextMenu,
		ArrowLeftIcon,
		DeleteIcon,
		FolderPlusIcon,
		InboxIcon,
		KeyVariantIcon,
		PaletteIcon,
		PaletteSwatchIcon,
		PencilIcon,
		FolderTreeNode,
		RenameFolderDialog,
		RemoveFolderDialog,
	},
	props: {
		currentFolderId: {
			type: String,
			default: null,
		},
		isAllSecrets: {
			type: Boolean,
			default: false,
		},
		isRootFolder: {
			type: Boolean,
			default: false,
		},
	},
	setup() {
		const {
			isOpen: contextMenuOpen,
			targetItem: contextMenuFolderId,
			open: openContextMenu,
			close: closeContextMenu,
		} = useContextMenu()

		return {
			contextMenuOpen,
			contextMenuFolderId,
			openContextMenu,
			closeContextMenu,
		}
	},
	data() {
		return {
			showNewFolder: false,
			newFolderName: '',
			creating: false,
			showRenameDialog: false,
			renameFolder: null,
			showDeleteDialog: false,
			folderToDelete: null,
			triggerAction: null,
			contextMenuPanel: null,
			iconSearchQuery: '',
		}
	},
	computed: {
		folderStore() {
			return useFolderStore()
		},
		folderTree() {
			return this.folderStore.folderTree
		},
		isNewFolderDuplicate() {
			if (!this.newFolderName.trim()) return false
			return this.folderStore.isDuplicateName(this.newFolderName, null)
		},
		FOLDER_COLORS() {
			return FOLDER_COLORS
		},
		theme() {
			return currentTheme()
		},
		filteredIcons() {
			const q = this.iconSearchQuery.trim()
			return q ? searchFolderIcons(q) : FOLDER_ICONS
		},
	},
	methods: {
		resolveFolderColor,
		onFolderContextMenu({ folderId, event }) {
			this.contextMenuPanel = null
			this.iconSearchQuery = ''
			this.openContextMenu({ item: folderId, event })
		},
		onRootContextMenu(event) {
			this.contextMenuPanel = null
			this.iconSearchQuery = ''
			this.openContextMenu({ item: '__root__', event })
		},
		onContextMenuClose() {
			this.contextMenuPanel = null
			this.iconSearchQuery = ''
			this.closeContextMenu()
		},
		async applyColor(colorKey) {
			const folder = this._findFolder(this.contextMenuFolderId)
			this.closeContextMenu()
			if (!folder) return
			try {
				await this.folderStore.updateFolder(
					folder.id,
					folder.name,
					folder.parentId ?? null,
					undefined,
					colorKey,
				)
			} catch {
				// Silently handled.
			}
		},
		async applyIcon(key) {
			const folder = this._findFolder(this.contextMenuFolderId)
			this.closeContextMenu()
			if (!folder) return
			try {
				await this.folderStore.updateFolder(
					folder.id,
					folder.name,
					folder.parentId ?? null,
					key,
					undefined,
				)
			} catch {
				// Silently handled.
			}
		},
		onContextNewSubfolder() {
			const folderId = this.contextMenuFolderId
			this.closeContextMenu()
			this.$nextTick(() => {
				if (folderId === '__root__') {
					this.startNewFolder()
				} else {
					this.triggerAction = { folderId, action: 'new-subfolder' }
					this.$nextTick(() => { this.triggerAction = null })
				}
			})
		},
		onContextRename() {
			const folderId = this.contextMenuFolderId
			this.closeContextMenu()
			const folder = this._findFolder(folderId)
			if (folder) {
				this.startRename(folder)
			}
		},
		onContextDelete() {
			const folderId = this.contextMenuFolderId
			this.closeContextMenu()
			const folder = this._findFolder(folderId)
			if (folder) {
				this.handleDelete(folder)
			}
		},
		_findFolder(folderId) {
			const search = (folders) => {
				for (const f of folders) {
					if (f.id === folderId) return f
					if (f.children) {
						const found = search(f.children)
						if (found) return found
					}
				}
				return null
			}
			return search(this.folderTree)
		},
		startNewFolder() {
			this.showNewFolder = true
			this.$nextTick(() => {
				this.$refs.newFolderInput?.$el?.querySelector('input')?.focus()
			})
		},
		cancelNewFolder() {
			this.newFolderName = ''
			this.showNewFolder = false
		},
		handleNewFolderBlur() {
			if (!this.newFolderName.trim()) {
				this.showNewFolder = false
			}
		},
		navigateRoot() {
			this.$router.push({ name: 'FolderView', query: { dir: '/' } })
		},
		navigate(folderId) {
			if (folderId) {
				this.$router.push({
					name: 'FolderView',
					query: folderDirQuery(folderId, this.folderStore.foldersById),
				})
			} else {
				this.$router.push({ path: '/secrets' })
			}
		},
		async createFolder() {
			if (!this.newFolderName.trim() || this.isNewFolderDuplicate) return
			this.creating = true
			try {
				await this.folderStore.createFolder(this.newFolderName.trim(), null)
				this.newFolderName = ''
				this.showNewFolder = false
				await this.folderStore.fetchFolders()
			} finally {
				this.creating = false
			}
		},
		startRename(folder) {
			this.renameFolder = folder
			this.showRenameDialog = true
		},
		onRenamed() {
			this.renameFolder = null
		},
		handleDelete(folder) {
			this.folderToDelete = folder
			this.showDeleteDialog = true
		},
		async onDeleted() {
			await this.folderStore.fetchFolders()
			if (this.currentFolderId === this.folderToDelete?.id) {
				this.$router.push({ path: '/secrets' })
			}
			this.folderToDelete = null
		},
	},
}
</script>

<style scoped>
.folder-tree__children {
	padding-inline-start: 16px;
}

.folder-tree__inline-input {
	display: flex;
	align-items: center;
	height: 44px;
	padding: 0 8px;
	gap: 8px;
}

.folder-tree__inline-input-icon {
	flex-shrink: 0;
	color: var(--color-text-maxcontrast);
}

.separator {
	height: 1px;
	background: var(--color-border);
	margin: 8px 0;
}

.folder-customize__panel {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 4px;
	min-width: 240px;
}

.folder-customize__panel-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 8px;
}

.folder-customize__panel-back,
.folder-customize__panel-reset {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	padding: 4px 8px;
	background: transparent;
	border: 1px solid transparent;
	border-radius: var(--border-radius);
	color: var(--color-main-text);
	cursor: pointer;
	font-size: 13px;
}

.folder-customize__panel-back:hover,
.folder-customize__panel-reset:hover {
	background: var(--color-background-hover);
}

.folder-customize__swatches {
	display: grid;
	grid-template-columns: repeat(8, 24px);
	gap: 6px;
	padding: 4px;
	justify-content: center;
}

.folder-customize__swatch {
	box-sizing: border-box;
	width: 24px;
	height: 24px;
	min-width: 24px;
	min-height: 24px;
	aspect-ratio: 1 / 1;
	border-radius: 50%;
	border: 1px solid var(--color-border);
	cursor: pointer;
	padding: 0;
	margin: 0;
	appearance: none;
}

.folder-customize__swatch:hover {
	transform: scale(1.1);
}

.folder-customize__icon-search {
	padding: 0 4px;
}

.folder-customize__icons {
	display: grid;
	grid-template-columns: repeat(6, 1fr);
	gap: 4px;
	max-height: 240px;
	overflow-y: auto;
	padding: 4px;
}

.folder-customize__icon-btn {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 32px;
	height: 32px;
	background: transparent;
	border: 1px solid transparent;
	border-radius: var(--border-radius);
	color: var(--color-main-text);
	cursor: pointer;
}

.folder-customize__icon-btn:hover {
	background: var(--color-background-hover);
}
</style>
