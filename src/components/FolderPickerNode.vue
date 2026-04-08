<template>
	<div class="folder-picker-node">
		<button
			class="folder-picker-node__row"
			:class="{ 'folder-picker-node__row--selected': isSelected }"
			:style="{ paddingLeft: (depth * 24 + 8) + 'px' }"
			@click="onSelect">
			<ChevronDownIcon v-if="hasChildren && isOpen" :size="16" class="folder-picker-node__chevron" @click.stop="toggle" />
			<ChevronRightIcon v-else-if="hasChildren" :size="16" class="folder-picker-node__chevron" @click.stop="toggle" />
			<span v-else class="folder-picker-node__spacer" />
			<FolderOpenIcon v-if="isOpen && hasChildren" :size="20" />
			<FolderIcon v-else :size="20" />
			<span class="folder-picker-node__name">{{ folder.name }}</span>
		</button>
		<template v-if="isOpen">
			<FolderPickerNode
				v-for="child in folder.children"
				:key="child.id"
				:folder="child"
				:selected-folder-id="selectedFolderId"
				:depth="depth + 1"
				@select="$emit('select', $event)" />
		</template>
	</div>
</template>

<script>
import ChevronDownIcon from 'vue-material-design-icons/ChevronDown.vue'
import ChevronRightIcon from 'vue-material-design-icons/ChevronRight.vue'
import FolderIcon from 'vue-material-design-icons/Folder.vue'
import FolderOpenIcon from 'vue-material-design-icons/FolderOpen.vue'

export default {
	name: 'FolderPickerNode',
	components: {
		ChevronDownIcon,
		ChevronRightIcon,
		FolderIcon,
		FolderOpenIcon,
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
	},
	emits: ['select'],
	data() {
		return {
			isOpen: false,
		}
	},
	computed: {
		isSelected() {
			return this.selectedFolderId === this.folder.id
		},
		hasChildren() {
			return this.folder.children && this.folder.children.length > 0
		},
	},
	methods: {
		toggle() {
			this.isOpen = !this.isOpen
		},
		onSelect() {
			this.$emit('select', this.folder.id)
			if (this.hasChildren && !this.isOpen) {
				this.isOpen = true
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
</style>
