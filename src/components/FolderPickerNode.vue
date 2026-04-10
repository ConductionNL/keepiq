<template>
	<div class="folder-picker-node">
		<button
			v-if="!isEditing"
			class="folder-picker-node__row"
			:class="{
				'folder-picker-node__row--selected': isSelected,
				'folder-picker-node__row--pending': folder.isPending,
				'folder-picker-node__row--renamed': folder.isRenamed,
			}"
			:style="{ paddingLeft: (depth * 24 + 8) + 'px' }"
			@click="onSelect"
			@contextmenu.prevent="onContextMenu">
			<ChevronDownIcon v-if="hasChildren && isOpen"
				:size="16"
				class="folder-picker-node__chevron"
				@click.stop="toggle" />
			<ChevronRightIcon v-else-if="hasChildren"
				:size="16"
				class="folder-picker-node__chevron"
				@click.stop="toggle" />
			<span v-else class="folder-picker-node__spacer" />
			<FolderOpenIcon v-if="isOpen && hasChildren" :size="20" />
			<FolderIcon v-else :size="20" />
			<span class="folder-picker-node__name">
				{{ folder.name }}{{ folder.isPending ? ' ✨' : '' }}{{ folder.isRenamed ? ' ✏️' : '' }}
			</span>
		</button>
		<div
			v-else
			class="folder-picker-node__rename-input"
			:style="{ paddingLeft: (depth * 24 + 8) + 'px' }"
			@click.stop>
			<FolderIcon :size="20" class="folder-picker-node__inline-input-icon" />
			<NcInputField
				ref="renameInput"
				v-model="editName"
				v-tooltip="isEditNameDuplicate ? t('doriath', 'A folder with this name already exists in the same location') : ''"
				:label="t('doriath', 'Folder name')"
				:error="!!editName && isEditNameDuplicate"
				@keyup.enter="confirmRename"
				@keyup.escape="cancelRename"
				@blur="handleRenameBlur" />
		</div>
		<template v-if="isOpen">
			<FolderPickerNode
				v-for="child in folder.children"
				:key="child.id"
				:folder="child"
				:selected-folder-id="selectedFolderId"
				:depth="depth + 1"
				:is-duplicate-name="isDuplicateName"
				:trigger-action="triggerAction"
				@select="$emit('select', $event)"
				@create-folder="$emit('create-folder', $event)"
				@rename-folder="$emit('rename-folder', $event)"
				@revert-rename="$emit('revert-rename', $event)"
				@remove-folder="$emit('remove-folder', $event)"
				@context-menu="$emit('context-menu', $event)" />

			<div
				v-if="isSelected && showNewSubfolder"
				class="folder-picker-node__inline-input"
				:style="{ paddingLeft: ((depth + 1) * 24 + 8) + 'px' }"
				@click.stop>
				<FolderPlusIcon :size="20" class="folder-picker-node__inline-input-icon" />
				<NcInputField
					ref="newSubfolderInput"
					v-model="newSubfolderName"
					v-tooltip="isNewSubfolderDuplicate ? t('doriath', 'A folder with this name already exists in the same location') : ''"
					:label="t('doriath', 'Folder name')"
					:error="!!newSubfolderName && isNewSubfolderDuplicate"
					@keyup.enter="confirmNewSubfolder"
					@keyup.escape="cancelNewSubfolder"
					@blur="handleSubfolderBlur" />
			</div>
		</template>
	</div>
</template>

<script>
import { NcInputField } from '@nextcloud/vue'
import ChevronDownIcon from 'vue-material-design-icons/ChevronDown.vue'
import ChevronRightIcon from 'vue-material-design-icons/ChevronRight.vue'
import FolderIcon from 'vue-material-design-icons/Folder.vue'
import FolderOpenIcon from 'vue-material-design-icons/FolderOpen.vue'
import FolderPlusIcon from 'vue-material-design-icons/FolderPlus.vue'

export default {
	name: 'FolderPickerNode',
	components: {
		NcInputField,
		ChevronDownIcon,
		ChevronRightIcon,
		FolderIcon,
		FolderOpenIcon,
		FolderPlusIcon,
	},
	props: {
		folder: {
			type: Object,
			required: true,
		},
		selectedFolderId: {
			type: String,
			default: null,
		},
		depth: {
			type: Number,
			default: 0,
		},
		isDuplicateName: {
			type: Function,
			default: () => false,
		},
		triggerAction: {
			type: Object,
			default: null,
		},
	},
	emits: ['select', 'create-folder', 'rename-folder', 'revert-rename', 'remove-folder', 'context-menu'],
	data() {
		return {
			isOpen: false,
			showNewSubfolder: false,
			newSubfolderName: '',
			isEditing: false,
			editName: '',
		}
	},
	computed: {
		isSelected() {
			return this.selectedFolderId === this.folder.id
		},
		hasChildren() {
			return this.folder.children && this.folder.children.length > 0
		},
		isNewSubfolderDuplicate() {
			if (!this.newSubfolderName.trim()) return false
			return this.isDuplicateName(this.newSubfolderName, this.folder.id)
		},
		isEditNameDuplicate() {
			if (!this.editName.trim()) return false
			return this.isDuplicateName(this.editName, this.folder.parentId ?? null, this.folder.id)
		},
	},
	watch: {
		isSelected(val) {
			if (val) {
				this.isOpen = true
			}
		},
		triggerAction(val) {
			if (!val || val.folderId !== this.folder.id) return
			if (val.action === 'rename') {
				this.startEditing()
			} else if (val.action === 'new-subfolder') {
				if (!this.isOpen) this.isOpen = true
				this.$nextTick(() => this.startNewSubfolder())
			}
		},
	},
	methods: {
		onContextMenu(event) {
			this.$emit('context-menu', { folderId: this.folder.id, event })
		},
		toggle() {
			this.isOpen = !this.isOpen
		},
		onSelect() {
			this.$emit('select', this.folder.id)
			if (!this.isOpen) {
				this.isOpen = true
			}
		},
		startNewSubfolder() {
			this.showNewSubfolder = true
			this.$nextTick(() => {
				this.$refs.newSubfolderInput?.$el?.querySelector('input')?.focus()
			})
		},
		confirmNewSubfolder() {
			if (!this.newSubfolderName.trim() || this.isNewSubfolderDuplicate) return
			this.$emit('create-folder', {
				name: this.newSubfolderName.trim(),
				parentId: this.folder.id,
			})
			this.newSubfolderName = ''
			this.showNewSubfolder = false
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
		startEditing() {
			this.editName = this.folder.name
			this.isEditing = true
			this.$nextTick(() => {
				const input = this.$refs.renameInput?.$el?.querySelector('input')
				input?.focus()
				input?.select()
			})
		},
		confirmRename() {
			const trimmed = this.editName.trim()
			if (!trimmed || this.isEditNameDuplicate) return
			if (trimmed !== this.folder.name) {
				this.$emit('rename-folder', {
					folderId: this.folder.id,
					newName: trimmed,
				})
			}
			this.isEditing = false
			this.editName = ''
		},
		cancelRename() {
			this.isEditing = false
			this.editName = ''
		},
		handleRenameBlur() {
			if (this.editName.trim() && !this.isEditNameDuplicate) {
				this.confirmRename()
			} else {
				this.cancelRename()
			}
		},
	},
}
</script>

<style scoped>
.folder-picker-node__row {
	display: flex;
	align-items: center;
	gap: 8px;
	height: 36px;
	width: 100%;
	padding-right: 8px;
	border: none;
	background: transparent;
	cursor: pointer;
	border-radius: var(--border-radius);
	color: var(--color-main-text);
	font-size: inherit;
}

.folder-picker-node__row:hover {
	background: var(--color-background-hover);
}

.folder-picker-node__row--selected {
	background: var(--color-primary-element-light);
	font-weight: 600;
}

.folder-picker-node__row--selected:hover {
	background: var(--color-primary-element-light);
}

.folder-picker-node__chevron {
	flex-shrink: 0;
	cursor: pointer;
}

.folder-picker-node__spacer {
	width: 16px;
	flex-shrink: 0;
}

.folder-picker-node__name {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.folder-picker-node__rename-input {
	display: flex;
	align-items: center;
	height: 36px;
	padding-right: 8px;
	gap: 8px;
}

.folder-picker-node__row--pending,
.folder-picker-node__row--renamed {
	font-style: italic;
}

.folder-picker-node__inline-input {
	display: flex;
	align-items: center;
	height: 36px;
	padding-right: 8px;
	gap: 8px;
}

.folder-picker-node__inline-input-icon {
	flex-shrink: 0;
	color: var(--color-text-maxcontrast);
}
</style>
