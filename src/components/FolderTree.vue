<template>
	<ul class="folder-tree">
		<li v-for="folder in folders" :key="folder.id" class="folder-tree__node">
			<button type="button"
				class="folder-tree__item"
				:class="{ 'folder-tree__item--active': folder.id === selectedId }"
				@click="$emit('select', folder.id)">
				<FolderIcon :size="18" />
				<span class="folder-tree__name">{{ folder.name }}</span>
			</button>
			<FolderTree v-if="folder.children && folder.children.length"
				:folders="folder.children"
				:selected-id="selectedId"
				class="folder-tree__children"
				@select="$emit('select', $event)" />
		</li>
	</ul>
</template>

<script>
import FolderIcon from 'vue-material-design-icons/Folder.vue'

/**
 * A recursive folder tree. Clicking a folder emits `select` with its ID so
 * the parent view can filter the secret list to that folder.
 */
export default {
	name: 'FolderTree',

	components: {
		FolderIcon,
	},

	props: {
		/** The folders at this level, each with an optional `children` array. */
		folders: {
			type: Array,
			default: () => [],
		},
		/** The currently selected folder ID. */
		selectedId: {
			type: String,
			default: null,
		},
	},
}
</script>

<style scoped>
.folder-tree {
	list-style: none;
	margin: 0;
	padding: 0;
}

.folder-tree__children {
	padding-inline-start: 16px;
}

.folder-tree__item {
	display: flex;
	align-items: center;
	gap: 8px;
	width: 100%;
	padding: 6px 8px;
	border: none;
	background: transparent;
	border-radius: var(--border-radius);
	cursor: pointer;
	text-align: start;
}

.folder-tree__item:hover {
	background-color: var(--color-background-hover);
}

.folder-tree__item--active {
	background-color: var(--color-primary-element-light);
	font-weight: bold;
}
</style>
