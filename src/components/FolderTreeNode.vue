<template>
	<NcAppNavigationItem
		:name="folder.name"
		class="folder-tree-node"
		:class="{ 'folder-tree-node--active': isCurrentFolder }"
		:style="tintStyle"
		@click="handleClick"
		@contextmenu.native.prevent="onContextMenu">
		<template #icon>
			<component
				:is="displayedIconComponent"
				:size="20"
				:fill-color="resolvedColor || undefined" />
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
import { resolveFolderIcon } from '../utils/folderIcons.js'
import { resolveFolderColor } from '../utils/folderColors.js'
import { currentTheme } from '../utils/theme.js'

function hexToRgba(hex, alpha) {
	const h = String(hex).replace('#', '')
	if (h.length !== 3 && h.length !== 6) return null
	const full = h.length === 3 ? h.split('').map(c => c + c).join('') : h
	const r = parseInt(full.slice(0, 2), 16)
	const g = parseInt(full.slice(2, 4), 16)
	const b = parseInt(full.slice(4, 6), 16)
	if (Number.isNaN(r) || Number.isNaN(g) || Number.isNaN(b)) return null
	return `rgba(${r}, ${g}, ${b}, ${alpha})`
}

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
		displayedIconComponent() {
			const custom = resolveFolderIcon(this.folder.customIcon)
			if (custom) return custom
			return this.isOpen ? FolderOpenIcon : FolderIcon
		},
		resolvedColor() {
			return resolveFolderColor(this.folder.customColor, currentTheme())
		},
		tintStyle() {
			const hex = this.resolvedColor
			if (!hex) return null
			const tint = hexToRgba(hex, 0.12)
			const tintActive = hexToRgba(hex, 0.28)
			if (!tint || !tintActive) return null
			return {
				'--folder-tint': tint,
				'--folder-tint-active': tintActive,
			}
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
			event.stopPropagation()
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
/* css code to give the background color when NOT selected */
/* .folder-tree-node :deep(> .app-navigation-entry) {
	background: var(--folder-tint, transparent);
} */

.folder-tree-node--active :deep(> .app-navigation-entry) {
	background: var(--folder-tint-active, var(--color-background-hover));
}

/* Stop the tint custom properties from cascading into the children list,
   so subfolders do not inherit the parent folder's color. */
.folder-tree-node :deep(> .app-navigation-entry__children) {
	--folder-tint: initial;
	--folder-tint-active: initial;
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
