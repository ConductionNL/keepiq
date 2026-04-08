<template>
	<div class="folder-picker-node">
		<button
			class="folder-picker-node__row"
			:class="{
				'folder-picker-node__row--selected': isSelected,
				'folder-picker-node__row--pending': folder.isPending,
			}"
			:style="{ paddingLeft: (depth * 24 + 8) + 'px' }"
			@click="onSelect">
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
			<span class="folder-picker-node__name">{{ folder.name }}{{ folder.isPending ? ' ✨' : '' }}</span>
		</button>
		<template v-if="isOpen">
			<FolderPickerNode
				v-for="child in folder.children"
				:key="child.id"
				:folder="child"
				:selected-folder-id="selectedFolderId"
				:depth="depth + 1"
				:is-duplicate-name="isDuplicateName"
				@select="$emit('select', $event)"
				@create-folder="$emit('create-folder', $event)" />

			<button
				v-if="isSelected && !showNewSubfolder"
				class="folder-picker-node__new-folder-btn"
				:style="{ paddingLeft: ((depth + 2) * 24 + 8) + 'px' }"
				@click.stop="startNewSubfolder">
				<FolderPlusIcon :size="20" />
				<span>{{ t('doriath', 'New folder') }}</span>
			</button>

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
	},
	emits: ['select', 'create-folder'],
	data() {
		return {
			isOpen: false,
			showNewSubfolder: false,
			newSubfolderName: '',
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
	},
	watch: {
		isSelected(val) {
			if (val) {
				this.isOpen = true
			}
		},
	},
	methods: {
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

.folder-picker-node__row--pending {
	font-style: italic;
}

.folder-picker-node__new-folder-btn {
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
	color: var(--color-text-maxcontrast);
	font-style: italic;
	font-size: inherit;
}

.folder-picker-node__new-folder-btn:hover {
	background: var(--color-background-hover);
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
