<template>
	<NcAppNavigationItem
		:name="folder.name"
		:class="{ 'folder-tree-node--active': currentFolderId === folder.id }"
		@click="$emit('navigate', folder.id)">
		<template #icon>
			<FolderOpenIcon v-if="currentFolderId === folder.id" :size="20" />
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
		<template v-if="folder.children && folder.children.length" #default>
			<FolderTreeNode
				v-for="child in folder.children"
				:key="child.id"
				:folder="child"
				:current-folder-id="currentFolderId"
				@navigate="$emit('navigate', $event)"
				@rename="$emit('rename', $event)"
				@delete="$emit('delete', $event)" />
		</template>
	</NcAppNavigationItem>
</template>

<script>
import { NcActionButton, NcAppNavigationItem } from '@nextcloud/vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import FolderIcon from 'vue-material-design-icons/Folder.vue'
import FolderOpenIcon from 'vue-material-design-icons/FolderOpen.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'

export default {
	name: 'FolderTreeNode',
	components: {
		NcActionButton,
		NcAppNavigationItem,
		DeleteIcon,
		FolderIcon,
		FolderOpenIcon,
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
}
</script>

<style scoped>
.folder-tree-node--active :deep(> .app-navigation-entry) {
	background: var(--color-background-hover);
}
</style>
