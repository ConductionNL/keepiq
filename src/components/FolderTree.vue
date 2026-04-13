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
			@close="closeContextMenu">
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
				close-after-click
				@click="onContextDelete">
				<template #icon>
					<DeleteIcon :size="20" />
				</template>
				{{ t('doriath', 'Delete') }}
			</NcActionButton>
		</CnContextMenu>
	</div>
</template>

<script>
import { NcActionButton, NcAppNavigationItem, NcInputField } from '@nextcloud/vue'
import { CnContextMenu, useContextMenu } from '@conduction/nextcloud-vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import FolderPlusIcon from 'vue-material-design-icons/FolderPlus.vue'
import InboxIcon from 'vue-material-design-icons/Inbox.vue'
import KeyVariantIcon from 'vue-material-design-icons/KeyVariant.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import FolderTreeNode from './FolderTreeNode.vue'
import RenameFolderDialog from '../dialog/RenameFolderDialog.vue'
import RemoveFolderDialog from '../dialog/RemoveFolderDialog.vue'
import { useFolderStore } from '../store/modules/folder.js'

export default {
	name: 'FolderTree',
	components: {
		NcActionButton,
		NcAppNavigationItem,
		NcInputField,
		CnContextMenu,
		DeleteIcon,
		FolderPlusIcon,
		InboxIcon,
		KeyVariantIcon,
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
	},
	methods: {
		onFolderContextMenu({ folderId, event }) {
			this.openContextMenu({ item: folderId, event })
		},
		onRootContextMenu(event) {
			this.openContextMenu({ item: '__root__', event })
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
			this.$router.push({ name: 'RootFolder' })
		},
		navigate(folderId) {
			if (folderId) {
				this.$router.push({ path: `/folders/${folderId}` })
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
</style>
