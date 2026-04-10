<template>
	<NcAppNavigationItem
		:name="folder.name"
		:class="{ 'folder-tree-node--active': isCurrentFolder }"
		@click="handleClick"
		@contextmenu.native.prevent="onContextMenu">
		<template #icon>
			<FolderOpenIcon v-if="isOpen" :size="20" />
			<FolderIcon v-else :size="20" />
		</template>
		<template v-if="isOpen" #default>
			<FolderTreeNode
				v-for="child in folder.children"
				:key="child.id"
				:folder="child"
				:current-folder-id="currentFolderId"
				:trigger-action="triggerAction"
				@navigate="$emit('navigate', $event)"
				@context-menu="$emit('context-menu', $event)" />

			<div v-if="showNewSubfolder" class="folder-tree-node__inline-input" @click.stop>
				<FolderPlusIcon :size="20" class="folder-tree-node__inline-input-icon" />
				<NcInputField
					ref="newSubfolderInput"
					v-model="newSubfolderName"
					v-tooltip="isNewSubfolderDuplicate ? t('doriath', 'A folder with this name already exists in the same location') : ''"
					:label="t('doriath', 'Folder name')"
					:disabled="creating"
					:error="!!newSubfolderName && isNewSubfolderDuplicate"
					@keyup.enter="createSubfolder"
					@keyup.escape="cancelNewSubfolder"
					@blur="handleSubfolderBlur" />
			</div>
		</template>
	</NcAppNavigationItem>
</template>

<script>
import { NcAppNavigationItem, NcInputField } from '@nextcloud/vue'
import FolderIcon from 'vue-material-design-icons/Folder.vue'
import FolderOpenIcon from 'vue-material-design-icons/FolderOpen.vue'
import FolderPlusIcon from 'vue-material-design-icons/FolderPlus.vue'
import { useFolderStore } from '../store/modules/folder.js'

export default {
	name: 'FolderTreeNode',
	components: {
		NcAppNavigationItem,
		NcInputField,
		FolderIcon,
		FolderOpenIcon,
		FolderPlusIcon,
	},
	props: {
		folder: {
			type: Object,
			required: true,
		},
		currentFolderId: {
			type: String,
			default: null,
		},
		triggerAction: {
			type: Object,
			default: null,
		},
	},
	emits: ['navigate', 'context-menu'],
	data() {
		return {
			isOpen: false,
			showNewSubfolder: false,
			newSubfolderName: '',
			creating: false,
		}
	},
	computed: {
		folderStore() {
			return useFolderStore()
		},
		isCurrentFolder() {
			return this.currentFolderId === this.folder.id
		},
		isNewSubfolderDuplicate() {
			if (!this.newSubfolderName.trim()) return false
			return this.folderStore.isDuplicateName(this.newSubfolderName, this.folder.id)
		},
		containsCurrentFolder() {
			if (!this.currentFolderId) return false
			return this._subtreeContains(this.folder, this.currentFolderId)
		},
	},
	watch: {
		containsCurrentFolder: {
			immediate: true,
			handler(val) {
				if (val) {
					this.isOpen = true
				}
			},
		},
		triggerAction(val) {
			if (!val || val.folderId !== this.folder.id) return
			if (val.action === 'new-subfolder') {
				if (!this.isOpen) this.isOpen = true
				this.$nextTick(() => this.startNewSubfolder())
			}
		},
	},
	methods: {
		onContextMenu(event) {
			this.$emit('context-menu', { folderId: this.folder.id, event })
		},
		_subtreeContains(node, targetId) {
			if (node.id === targetId) return true
			return (node.children || []).some(child => this._subtreeContains(child, targetId))
		},
		handleClick() {
			if (this.isOpen && this.isCurrentFolder) {
				this.isOpen = false
			} else {
				this.isOpen = true
				this.$emit('navigate', this.folder.id)
			}
		},
		startNewSubfolder() {
			this.showNewSubfolder = true
			this.$nextTick(() => {
				this.$refs.newSubfolderInput?.$el?.querySelector('input')?.focus()
			})
		},
		cancelNewSubfolder() {
			this.newSubfolderName = ''
			this.showNewSubfolder = false
		},
		handleSubfolderBlur() {
			if (!this.newSubfolderName.trim()) {
				this.showNewSubfolder = false
			}
		},
		async createSubfolder() {
			if (!this.newSubfolderName.trim() || this.isNewSubfolderDuplicate) return
			this.creating = true
			try {
				const folderStore = useFolderStore()
				await folderStore.createFolder(this.newSubfolderName.trim(), this.folder.id)
				this.newSubfolderName = ''
				this.showNewSubfolder = false
				await folderStore.fetchFolders()
			} finally {
				this.creating = false
			}
		},
	},
}
</script>

<style scoped>
.folder-tree-node--active :deep(> .app-navigation-entry) {
	background: var(--color-background-hover);
}

:deep(.app-navigation-entry__children) {
	gap: 0;
}

.folder-tree-node__inline-input {
	display: flex;
	align-items: center;
	height: 44px;
	padding: 0 8px;
	gap: 8px;
}

.folder-tree-node__inline-input-icon {
	flex-shrink: 0;
	color: var(--color-text-maxcontrast);
}
</style>
