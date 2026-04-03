<template>
	<div class="folder-tree">
		<NcAppNavigationItem
			:name="t('doriath', 'All secrets')"
			:class="{ 'folder-tree__item--active': currentFolderId === null }"
			@click="navigate(null)">
			<template #icon>
				<KeyVariantIcon :size="20" />
			</template>
		</NcAppNavigationItem>

		<NcAppNavigationItem
			:name="t('doriath', 'Secrets')"
			:class="{ 'folder-tree__item--active': currentFolderId === 'root' }"
			@click="navigate('root')">
			<template #icon>
				<InboxIcon :size="20" />
			</template>
		</NcAppNavigationItem>

		<FolderTreeNode
			v-for="folder in folderTree"
			:key="folder.id"
			:folder="folder"
			:current-folder-id="currentFolderId"
			@navigate="navigate"
			@rename="startRename"
			@delete="handleDelete" />

		<NcAppNavigationItem
			:name="t('doriath', 'New folder')"
			class="folder-tree__new"
			@click="showNewFolder = true">
			<template #icon>
				<FolderPlusIcon :size="20" />
			</template>
		</NcAppNavigationItem>

		<!-- New folder inline input -->
		<div v-if="showNewFolder" class="folder-tree__input">
			<NcInputField
				v-model="newFolderName"
				:label="t('doriath', 'Folder name')"
				:disabled="creating"
				@keyup.enter="createFolder"
				@keyup.escape="showNewFolder = false" />
		</div>

		<!-- Rename dialog -->
		<NcDialog
			:open.sync="showRenameDialog"
			:name="t('doriath', 'Rename folder')">
			<NcInputField
				v-model="renameName"
				:label="t('doriath', 'New name')" />
			<template #actions>
				<NcButton @click="showRenameDialog = false">
					{{ t('doriath', 'Cancel') }}
				</NcButton>
				<NcButton type="primary" :disabled="!renameName" @click="confirmRename">
					{{ t('doriath', 'Rename') }}
				</NcButton>
			</template>
		</NcDialog>
	</div>
</template>

<script>
import { NcAppNavigationItem, NcButton, NcDialog, NcInputField } from '@nextcloud/vue'
import FolderPlusIcon from 'vue-material-design-icons/FolderPlus.vue'
import InboxIcon from 'vue-material-design-icons/Inbox.vue'
import KeyVariantIcon from 'vue-material-design-icons/KeyVariant.vue'
import FolderTreeNode from './FolderTreeNode.vue'
import { useFolderStore } from '../store/modules/folder.js'

export default {
	name: 'FolderTree',
	components: {
		NcAppNavigationItem,
		NcButton,
		NcDialog,
		NcInputField,
		FolderPlusIcon,
		InboxIcon,
		KeyVariantIcon,
		FolderTreeNode,
	},
	props: {
		currentFolderId: {
			type: String,
			default: null,
		},
	},
	data() {
		return {
			showNewFolder: false,
			newFolderName: '',
			creating: false,
			showRenameDialog: false,
			renameFolderId: null,
			renameName: '',
		}
	},
	computed: {
		folderStore() {
			return useFolderStore()
		},
		folderTree() {
			return this.folderStore.folderTree
		},
	},
	methods: {
		navigate(folderId) {
			if (folderId === 'root') {
				this.$router.push({ path: '/folders/root' })
			} else if (folderId) {
				this.$router.push({ path: `/folders/${folderId}` })
			} else {
				this.$router.push({ path: '/secrets' })
			}
		},
		async createFolder() {
			if (!this.newFolderName.trim()) return
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
			this.renameFolderId = folder.id
			this.renameName = folder.name
			this.showRenameDialog = true
		},
		async confirmRename() {
			if (!this.renameName.trim() || !this.renameFolderId) return
			try {
				await this.folderStore.updateFolder(this.renameFolderId, this.renameName.trim())
				await this.folderStore.fetchFolders()
			} catch {
				// Silently handled.
			}
			this.showRenameDialog = false
		},
		async handleDelete(folder) {
			try {
				await this.folderStore.deleteFolder(folder.id, 'move')
				await this.folderStore.fetchFolders()
				if (this.currentFolderId === folder.id) {
					this.$router.push({ path: '/secrets' })
				}
			} catch {
				// Silently handled.
			}
		},
	},
}
</script>

<style scoped>
.folder-tree__item--active :deep(.app-navigation-entry) {
	background: var(--color-background-hover);
}

.folder-tree__new :deep(.app-navigation-entry__name) {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.folder-tree__input {
	padding: 0 8px 8px;
}
</style>
