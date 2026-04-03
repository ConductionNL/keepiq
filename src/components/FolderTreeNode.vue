<template>
	<NcAppNavigationItem
		:name="folder.name"
		:class="{ 'folder-tree-node--active': isCurrentFolder }"
		@click="handleClick">
		<template #icon>
			<FolderOpenIcon v-if="isOpen" :size="20" />
			<FolderIcon v-else :size="20" />
		</template>
		<template #actions>
			<NcActionButton @click="$emit('rename', folder)">
				<template #icon>
					<PencilIcon :size="20" />
				</template>
				{{ t('doriath', 'Rename') }}
			</NcActionButton>
			<NcActionButton @click="$emit('delete', folder)">
				<template #icon>
					<DeleteIcon :size="20" />
				</template>
				{{ t('doriath', 'Delete') }}
			</NcActionButton>
		</template>
		<template v-if="isOpen" #default>
			<FolderTreeNode
				v-for="child in folder.children"
				:key="child.id"
				:folder="child"
				:current-folder-id="currentFolderId"
				@navigate="$emit('navigate', $event)"
				@rename="$emit('rename', $event)"
				@delete="$emit('delete', $event)" />

			<NcAppNavigationItem
				v-if="isCurrentFolder && !showNewSubfolder"
				:name="t('doriath', 'New folder')"
				class="folder-tree-node__new"
				@click.stop="startNewSubfolder">
				<template #icon>
					<FolderPlusIcon :size="20" />
				</template>
			</NcAppNavigationItem>

			<div v-if="isCurrentFolder && showNewSubfolder" class="folder-tree-node__inline-input" @click.stop>
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
import { NcActionButton, NcAppNavigationItem, NcInputField } from '@nextcloud/vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import FolderIcon from 'vue-material-design-icons/Folder.vue'
import FolderOpenIcon from 'vue-material-design-icons/FolderOpen.vue'
import FolderPlusIcon from 'vue-material-design-icons/FolderPlus.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import { useFolderStore } from '../store/modules/folder.js'

export default {
	name: 'FolderTreeNode',
	components: {
		NcActionButton,
		NcAppNavigationItem,
		NcInputField,
		DeleteIcon,
		FolderIcon,
		FolderOpenIcon,
		FolderPlusIcon,
		PencilIcon,
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
	},
	emits: ['navigate', 'rename', 'delete'],
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
	},
	methods: {
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

.folder-tree-node__new :deep(.app-navigation-entry__name) {
	color: var(--color-text-maxcontrast);
	font-style: italic;
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
