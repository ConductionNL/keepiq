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

		<NcAppNavigationItem
			:name="t('doriath', 'Secrets')"
			:active="isRootFolder"
			@click="navigateRoot">
			<template #icon>
				<InboxIcon :size="20" />
			</template>
		</NcAppNavigationItem>

		<div class="separator" />

		<FolderTreeNode
			v-for="folder in folderTree"
			:key="folder.id"
			:folder="folder"
			:current-folder-id="currentFolderId"
			@navigate="navigate"
			@rename="startRename"
			@delete="handleDelete" />

		<NcAppNavigationItem
			v-if="!showNewFolder"
			:name="t('doriath', 'New folder')"
			class="folder-tree__new"
			@click="startNewFolder">
			<template #icon>
				<FolderPlusIcon :size="20" />
			</template>
		</NcAppNavigationItem>

		<div v-else class="folder-tree__inline-input">
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
	</div>
</template>

<script>
import { NcAppNavigationItem, NcInputField } from '@nextcloud/vue'
import FolderPlusIcon from 'vue-material-design-icons/FolderPlus.vue'
import InboxIcon from 'vue-material-design-icons/Inbox.vue'
import KeyVariantIcon from 'vue-material-design-icons/KeyVariant.vue'
import FolderTreeNode from './FolderTreeNode.vue'
import RenameFolderDialog from '../dialog/RenameFolderDialog.vue'
import RemoveFolderDialog from '../dialog/RemoveFolderDialog.vue'
import { useFolderStore } from '../store/modules/folder.js'

export default {
	name: 'FolderTree',
	components: {
		NcAppNavigationItem,
		NcInputField,
		FolderPlusIcon,
		InboxIcon,
		KeyVariantIcon,
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
	data() {
		return {
			showNewFolder: false,
			newFolderName: '',
			creating: false,
			showRenameDialog: false,
			renameFolder: null,
			showDeleteDialog: false,
			folderToDelete: null,
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
.folder-tree__new :deep(.app-navigation-entry__name) {
	color: var(--color-text-maxcontrast);
	font-style: italic;
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
