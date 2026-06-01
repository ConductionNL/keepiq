<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Folder tree navigation. Renders the user's folder hierarchy as nested
  NcAppNavigationItem entries; clicking a folder navigates to the folder-scoped
  secret list. Self-recursive via the `nodes` prop for nested levels.
-->
<template>
	<ul class="folder-tree">
		<NcAppNavigationItem
			v-for="node in nodes"
			:key="node.id"
			:name="node.name"
			:allow-collapse="node.children.length > 0"
			@click="navigate(node)">
			<template #icon>
				<FolderIcon :size="20" />
			</template>
			<template v-if="node.children.length" #default>
				<FolderTree :nodes="node.children" />
			</template>
		</NcAppNavigationItem>
	</ul>
</template>

<script>
import { NcAppNavigationItem } from '@nextcloud/vue'
import FolderIcon from 'vue-material-design-icons/Folder.vue'

export default {
	name: 'FolderTree',

	components: {
		NcAppNavigationItem,
		FolderIcon,
	},

	props: {
		/** The folder nodes to render at this level (each with a children array). */
		nodes: {
			type: Array,
			default: () => [],
		},
	},

	methods: {
		/**
		 * Navigate to a folder-scoped secret list.
		 *
		 * @param {object} node The folder node.
		 */
		navigate(node) {
			this.$router.push({ name: 'FolderView', params: { id: node.id } })
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
</style>
