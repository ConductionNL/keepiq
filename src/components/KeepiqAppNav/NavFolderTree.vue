<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  NavFolderTree — recursive folder tree for the left rail (restyle Stage 7).

  Renders folderStore.folderTree nodes as NcAppNavigationItem rows, each
  navigating to its folder's list page. DISPLAY CAP (Option C): folders
  render to depth 5; the next hidden level shows as ONE child node named
  "…" standing in the chain. Clicking "…" navigates INTO the folder it
  represents when the deepest rendered folder has exactly one hidden
  child; with more than one hidden child it opens the deepest rendered
  folder's own page instead — its subfolder rows (Stage 6) list all
  children, and rows + breadcrumbs take over from there.
-->
<template>
	<ul
		class="keepiq-nav-tree"
		:data-testid="depth === 0 ? 'nav-folder-tree' : undefined">
		<NcAppNavigationItem
			v-for="node in folders"
			:key="node.id"
			:name="node.name"
			:to="{ name: 'SecretListFolder', params: { folderId: node.id } }"
			:active="node.id === highlightId"
			:allow-collapse="hasVisibleChildren(node)"
			:open="openState[node.id] ?? true"
			:data-testid="`nav-folder-${node.id}`"
			@update:open="openState[node.id] = $event">
			<!-- Root-level entries ARE the vaults (Stage 5 terminology), so
			     they carry the safe glyph; only nested entries are plain
			     folders. -->
			<template #icon>
				<Safe v-if="depth === 0" :size="18" />
				<FolderOutline v-else :size="18" />
			</template>
			<NavFolderTree
				v-if="hasVisibleChildren(node)"
				:folders="node.children"
				:depth="depth + 1"
				:highlight-id="highlightId" />
			<NcAppNavigationItem
				v-else-if="hasHiddenChildren(node)"
				:name="'…'"
				:to="ellipsisTarget(node)"
				:data-testid="`nav-folder-ellipsis-${node.id}`" />
		</NcAppNavigationItem>
	</ul>
</template>

<script>
import { NcAppNavigationItem } from '@nextcloud/vue'
import FolderOutline from 'vue-material-design-icons/FolderOutline.vue'
import Safe from 'vue-material-design-icons/Safe.vue'

/**
 * Maximum folder depth the rail renders. Deeper levels are reachable
 * through the "…" stand-in node plus the list page's subfolder rows and
 * breadcrumbs (Stage 6) — the rail stays legible, nothing becomes
 * unreachable.
 *
 * @type {number}
 */
export const NAV_TREE_MAX_DEPTH = 5

/**
 * Recursive NcAppNavigationItem folder tree with a display cap.
 */
export default {
	name: 'NavFolderTree',

	components: {
		NcAppNavigationItem,
		FolderOutline,
		Safe,
	},

	props: {
		/** The folder nodes at this level (each with a `children` array). */
		folders: {
			type: Array,
			default: () => [],
		},
		/** This level's depth: 0 = the top-level vaults. */
		depth: {
			type: Number,
			default: 0,
		},
		/**
		 * The folder id to highlight. When the ACTIVE folder is deeper than
		 * the display cap, the caller passes its deepest visible ancestor
		 * instead, so the rail still shows where the user is.
		 */
		highlightId: {
			type: String,
			default: null,
		},
	},

	data() {
		return {
			/**
			 * Per-node collapse state (open by default so the whole tree is
			 * visible; a user's toggle is remembered for the component's
			 * lifetime).
			 */
			openState: {},
		}
	},

	methods: {
		/**
		 * Whether `node`'s children render as a nested tree (still within
		 * the display cap).
		 *
		 * @param {object} node The folder node.
		 * @return {boolean}
		 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
		 */
		hasVisibleChildren(node) {
			return (node.children || []).length > 0
				&& this.depth + 1 < NAV_TREE_MAX_DEPTH
		},

		/**
		 * Whether `node` sits at the cap with children the rail hides —
		 * the case the "…" stand-in node covers.
		 *
		 * @param {object} node The folder node.
		 * @return {boolean}
		 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
		 */
		hasHiddenChildren(node) {
			return (node.children || []).length > 0
				&& this.depth + 1 >= NAV_TREE_MAX_DEPTH
		},

		/**
		 * Where the "…" node navigates: INTO the single hidden child when
		 * there is exactly one (it stands in the chain), otherwise to the
		 * deepest RENDERED folder's own page, whose subfolder rows list all
		 * the hidden children.
		 *
		 * @param {object} node The capped folder node.
		 * @return {object} A SecretListFolder router target.
		 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
		 */
		ellipsisTarget(node) {
			const children = node.children || []
			const folderId = children.length === 1 ? children[0].id : node.id
			return { name: 'SecretListFolder', params: { folderId } }
		},
	},
}
</script>

<style scoped>
/* NcAppNavigationItem already wraps slotted children in its own
   `ul.app-navigation-entry__children` (which owns the per-level indent),
   so this wrapper exists only to carry the `nav-folder-tree` testid and
   the recursion — `display: contents` removes its box entirely and lets
   the NC list styling apply as if the items were direct children. */
.keepiq-nav-tree {
	display: contents;
}
</style>
