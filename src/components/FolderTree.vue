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

		<FolderTreeNode
			v-for="folder in folderTree"
			:key="folder.id"
			:folder="folder"
			:current-folder-id="currentFolderId"
			@navigate="navigate" />
	</div>
</template>

<script>
import { NcAppNavigationItem } from '@nextcloud/vue'
import KeyVariantIcon from 'vue-material-design-icons/KeyVariant.vue'
import FolderTreeNode from './FolderTreeNode.vue'
import { useFolderStore } from '../store/modules/folder.js'

export default {
	name: 'FolderTree',
	components: {
		NcAppNavigationItem,
		KeyVariantIcon,
		FolderTreeNode,
	},
	props: {
		folders: {
			type: Array,
			default: () => [],
		},
		currentFolderId: {
			type: String,
			default: null,
		},
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
			if (folderId) {
				this.$router.push({ path: `/folders/${folderId}` })
			} else {
				this.$router.push({ path: '/secrets' })
			}
		},
	},
}
</script>

<style scoped>
.folder-tree__item--active :deep(.app-navigation-entry) {
	background: var(--color-background-hover);
}
</style>
