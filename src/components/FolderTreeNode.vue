<template>
	<NcAppNavigationItem
		:name="folder.name"
		:class="{ 'folder-tree-node--active': currentFolderId === folder.id }"
		@click="$emit('navigate', folder.id)">
		<template #icon>
			<FolderOpenIcon v-if="currentFolderId === folder.id" :size="20" />
			<FolderIcon v-else :size="20" />
		</template>
		<template v-if="folder.children && folder.children.length" #default>
			<FolderTreeNode
				v-for="child in folder.children"
				:key="child.id"
				:folder="child"
				:current-folder-id="currentFolderId"
				@navigate="$emit('navigate', $event)" />
		</template>
	</NcAppNavigationItem>
</template>

<script>
import { NcAppNavigationItem } from '@nextcloud/vue'
import FolderIcon from 'vue-material-design-icons/Folder.vue'
import FolderOpenIcon from 'vue-material-design-icons/FolderOpen.vue'

export default {
	name: 'FolderTreeNode',
	components: {
		NcAppNavigationItem,
		FolderIcon,
		FolderOpenIcon,
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
	emits: ['navigate'],
}
</script>

<style scoped>
.folder-tree-node--active :deep(> .app-navigation-entry) {
	background: var(--color-background-hover);
}
</style>
