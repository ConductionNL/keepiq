<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  KeepiqAppNav — keepiq's own left rail (restyle Stage 7).

  The ONE justified custom shell component: CnAppNav verifiably cannot
  render trees, and the vault rail needs the folder tree. Every STATIC
  entry stays manifest-driven — this component renders `manifest.menu` by
  section/order exactly like CnAppNav does (captions →
  NcAppNavigationCaption; route items → NcAppNavigationItem `:to` so the
  `href$` e2e selectors keep working; `action: "user-settings"` → the
  cnOpenUserSettings inject; external `href` → a real anchor) and adds,
  after the FoldersCaption, the "All vaults" item hosting NavFolderTree.

  E2E-PARITY CONTRACT (documented, do not rename): the
  `cn-app-nav__footer-list` / `cn-app-nav__settings-list` class names and
  the `cn-nav-entry-<id>` / `cn-nav-settings` / `cn-nav-admin-settings`
  data-testids are CnAppNav's — navigation.spec.ts and
  page-surfaces.spec.ts (and the workflow specs touching UserSettings)
  pass unchanged against this component because of them. The few scoped
  list styles are copied locally for the same reason.

  Feature parity notes: the isAdmin-gated "Admin settings" link-out
  (absolute same-origin URL so it opens in a NEW tab, with an open-in-new
  marker) is replicated from CnAppNav — a custom #menu slot would
  otherwise silently drop it. Personal settings is NOT auto-prepended:
  keepiq opts out (nav.includePersonalSettings: false) and ships its own
  manifest entry with action "user-settings".

  The `.keepiq-shell--locked :deep(.app-navigation)` rule in App.vue keeps
  hiding this rail on the lock screen — it targets NcAppNavigation's root,
  which this component still renders.
-->
<template>
	<NcAppNavigation :aria-label="t('keepiq', 'Keepiq navigation')" data-testid="cn-nav">
		<template #list>
			<template v-for="item in mainItems" :key="item.id">
				<NcAppNavigationCaption
					v-if="item.type === 'caption'"
					:name="t('keepiq', item.label)"
					:data-testid="`cn-nav-caption-${item.id}`" />
				<NcAppNavigationItem
					v-else
					:name="t('keepiq', item.label)"
					:to="itemTo(item)"
					:href="itemHref(item)"
					:active="isActive(item)"
					:data-testid="`cn-nav-entry-${item.id}`"
					:data-cn-route="item.route"
					@click="onItemClick(item, $event)">
					<template v-if="item.icon" #icon>
						<CnIcon :name="item.icon" :size="20" />
					</template>
				</NcAppNavigationItem>
			</template>
			<!-- The Vaults caption + tree host are OWNED BY THIS COMPONENT,
			     not the manifest: buildManifest drops menu entries without
			     route/href/action/children ("empty group shells"), which
			     eats a `type: "caption"` declaration before it ever renders.
			     Since the tree itself can only live here anyway, the caption
			     rides along. Revisit if the lib's filter learns to spare
			     captions. -->
			<NcAppNavigationCaption
				:name="t('keepiq', 'Vaults')"
				data-testid="cn-nav-caption-FoldersCaption" />
			<NcAppNavigationItem
				:name="t('keepiq', 'All vaults')"
				:to="{ name: 'SecretList' }"
				:allow-collapse="folderTree.length > 0"
				:open="treeOpen"
				data-testid="cn-nav-entry-VaultTree"
				@update:open="treeOpen = $event">
				<template #icon>
					<Inbox :size="20" />
				</template>
				<NavFolderTree
					v-if="folderTree.length > 0"
					:folders="folderTree"
					:highlight-id="highlightFolderId" />
			</NcAppNavigationItem>
		</template>
		<template #footer>
			<!-- Footer-section entries live in NcAppNavigation's #footer slot —
			     OUTSIDE the scrollable list — so they stay visible above the
			     settings foldout no matter how long the main menu is (same
			     rationale as CnAppNav). -->
			<ul v-if="footerItems.length > 0" class="cn-app-nav__footer-list">
				<NcAppNavigationItem
					v-for="item in footerItems"
					:key="item.id"
					:name="t('keepiq', item.label)"
					:to="itemTo(item)"
					:href="itemHref(item)"
					:active="isActive(item)"
					:data-testid="`cn-nav-entry-${item.id}`"
					:data-cn-route="item.route"
					@click="onItemClick(item, $event)">
					<template v-if="item.icon" #icon>
						<CnIcon :name="item.icon" :size="20" />
					</template>
				</NcAppNavigationItem>
			</ul>
			<NcAppNavigationSettings
				:name="t('keepiq', 'Settings')"
				data-testid="cn-nav-settings">
				<ul class="cn-app-nav__settings-list">
					<!-- Admin settings link-out, replicated from CnAppNav for
					     instance admins: the destination is Nextcloud's own
					     settings area, so it opens in a NEW tab (the href is
					     ABSOLUTE on purpose — NcAppNavigationItem only renders
					     target="_blank" for scheme-prefixed hrefs) and carries
					     an open-in-new marker. Visibility-only gate; the
					     server authorizes the settings page itself. -->
					<NcAppNavigationItem
						v-if="isAdmin"
						:name="t('keepiq', 'Admin settings')"
						:href="adminSettingsHref"
						data-testid="cn-nav-admin-settings">
						<template #icon>
							<ShieldAccountOutline :size="20" />
						</template>
						<template #counter>
							<OpenInNew :size="16" :title="t('keepiq', 'Opens in a new tab')" />
						</template>
					</NcAppNavigationItem>
					<NcAppNavigationItem
						v-for="item in settingsItems"
						:key="item.id"
						:name="t('keepiq', item.label)"
						:to="itemTo(item)"
						:href="itemHref(item)"
						:active="isActive(item)"
						:data-testid="`cn-nav-entry-${item.id}`"
						:data-cn-route="item.route"
						@click="onItemClick(item, $event)">
						<template v-if="item.icon" #icon>
							<CnIcon :name="item.icon" :size="20" />
						</template>
					</NcAppNavigationItem>
				</ul>
			</NcAppNavigationSettings>
		</template>
	</NcAppNavigation>
</template>

<script>
import { CnIcon } from '@conduction/nextcloud-vue'
import { getCurrentUser } from '@nextcloud/auth'
import { generateUrl } from '@nextcloud/router'
import {
	NcAppNavigation,
	NcAppNavigationCaption,
	NcAppNavigationItem,
	NcAppNavigationSettings,
} from '@nextcloud/vue'
import Inbox from 'vue-material-design-icons/Inbox.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import ShieldAccountOutline from 'vue-material-design-icons/ShieldAccountOutline.vue'
import { useFolderStore } from '../../store/modules/folder.js'
import { useSessionStore } from '../../store/modules/session.js'
import NavFolderTree, { NAV_TREE_MAX_DEPTH } from './NavFolderTree.vue'

/**
 * Keepiq's manifest-driven left rail with the recursive vault/folder tree.
 */
export default {
	name: 'KeepiqAppNav',

	components: {
		CnIcon,
		Inbox,
		NavFolderTree,
		NcAppNavigation,
		NcAppNavigationCaption,
		NcAppNavigationItem,
		NcAppNavigationSettings,
		OpenInNew,
		ShieldAccountOutline,
	},

	inject: {
		/**
		 * Opens the host app's NcAppSettingsDialog (provided by CnAppRoot).
		 * Defaults to a no-op so the rail still mounts standalone (tests).
		 */
		cnOpenUserSettings: { default: () => () => {} },
	},

	props: {
		/** The merged app manifest whose `menu[]` this rail renders. */
		manifest: {
			type: Object,
			required: true,
		},
	},

	data() {
		return {
			/** Collapse state of the vault-tree host item. */
			treeOpen: true,
		}
	},

	computed: {
		folderStore() {
			return useFolderStore()
		},

		sessionStore() {
			return useSessionStore()
		},

		/** The nested vault/folder tree feeding NavFolderTree. */
		folderTree() {
			return this.folderStore.folderTree
		},

		/** The manifest menu, order-sorted (entries without order last). */
		sortedMenu() {
			return [...(this.manifest?.menu || [])].sort(
				(a, b) => (a.order ?? Number.MAX_SAFE_INTEGER)
					- (b.order ?? Number.MAX_SAFE_INTEGER),
			)
		},

		/** Main-section entries (no `section`, or section "main"). */
		mainItems() {
			return this.sortedMenu.filter(
				(item) => !item.section || item.section === 'main',
			)
		},

		/** Footer-section entries (Documentation, roadmap, activity, …). */
		footerItems() {
			return this.sortedMenu.filter((item) => item.section === 'footer')
		},

		/** Settings-foldout entries (Applications, Lock vault, …). */
		settingsItems() {
			return this.sortedMenu.filter((item) => item.section === 'settings')
		},

		/**
		 * Whether the current user administers the INSTANCE — gates the
		 * visibility of the Admin-settings link only; the settings page
		 * itself is authorized server-side.
		 *
		 * @return {boolean}
		 */
		isAdmin() {
			return getCurrentUser()?.isAdmin === true
		},

		/**
		 * Absolute same-origin URL of keepiq's Nextcloud admin settings.
		 * Absolute on purpose: NcAppNavigationItem opens only
		 * scheme-prefixed hrefs in a new tab.
		 *
		 * @return {string}
		 */
		adminSettingsHref() {
			const origin = (typeof window !== 'undefined'
				&& window.location && window.location.origin) || ''
			return origin + generateUrl('/settings/admin/keepiq')
		},

		/**
		 * The active folder id from the route.
		 *
		 * @return {string|null}
		 */
		activeFolderId() {
			return this.$route?.params?.folderId || null
		},

		/**
		 * The folder id the tree highlights: the active folder itself while
		 * it is within the display cap, otherwise its DEEPEST VISIBLE
		 * ancestor — so the rail still shows where the user is when the
		 * route points below the cap. Cycle/depth guarded like every other
		 * parentId walk.
		 *
		 * @return {string|null}
		 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
		 */
		highlightFolderId() {
			const active = this.activeFolderId
			if (!active) {
				return null
			}
			const byId = new Map(this.folderStore.folders.map((f) => [f.id, f]))
			const trail = []
			const seen = new Set()
			let current = byId.get(active)
			while (current && !seen.has(current.id) && trail.length < 64) {
				seen.add(current.id)
				trail.unshift(current.id)
				current = current.parentId ? byId.get(current.parentId) : null
			}
			if (trail.length === 0) {
				return null
			}
			return trail.length <= NAV_TREE_MAX_DEPTH
				? active
				: trail[NAV_TREE_MAX_DEPTH - 1]
		},
	},

	watch: {
		/**
		 * Refetch the tree when the vault unlocks — the locked shell hides
		 * the rail, and the folder list may have been unavailable before.
		 *
		 * @param {boolean} locked The new lock state.
		 */
		'sessionStore.isLocked'(locked) {
			if (!locked) {
				this.fetchFoldersSafe()
			}
		},
	},

	/**
	 * Load the folder tree once at mount when the vault is unlocked.
	 *
	 * @return {void}
	 * @spec openspec/specs/dashboard/spec.md#app-navigation-renders
	 */
	mounted() {
		if (!this.sessionStore.isLocked) {
			this.fetchFoldersSafe()
		}
	},

	methods: {
		/**
		 * Router target for a manifest entry (route entries only) — `:to`
		 * keeps the rendered anchors' `href$` shape the e2e suite selects on.
		 *
		 * @param {object} item The menu entry.
		 * @return {object|null}
		 * @spec openspec/specs/dashboard/spec.md#app-navigation-renders
		 */
		itemTo(item) {
			return item.route && !item.action ? { name: item.route } : null
		},

		/**
		 * Plain href for external entries (e.g. Documentation) — a real
		 * anchor, which NcAppNavigationItem opens in a new tab for
		 * scheme-prefixed URLs.
		 *
		 * @param {object} item The menu entry.
		 * @return {string|null}
		 * @spec openspec/specs/dashboard/spec.md#app-navigation-renders
		 */
		itemHref(item) {
			return item.href && !item.action ? item.href : null
		},

		/**
		 * Whether a route entry matches the current route.
		 *
		 * @param {object} item The menu entry.
		 * @return {boolean}
		 * @spec openspec/specs/dashboard/spec.md#app-navigation-renders
		 */
		isActive(item) {
			return Boolean(item.route) && this.$route?.name === item.route
		},

		/**
		 * Click dispatch: `action: "user-settings"` opens the host's
		 * settings dialog via the CnAppRoot inject and prevents default;
		 * everything else navigates natively through `:to`/`href`.
		 *
		 * @param {object} item The clicked entry.
		 * @param {Event} event The click event.
		 * @return {void}
		 * @spec openspec/specs/dashboard/spec.md#app-navigation-renders
		 */
		onItemClick(item, event) {
			if (item.action === 'user-settings') {
				if (event && typeof event.preventDefault === 'function') {
					event.preventDefault()
				}
				this.cnOpenUserSettings()
			}
		},

		/**
		 * Fetch the folder list without ever throwing into the rail — a
		 * failed fetch costs the tree, never the navigation.
		 *
		 * @return {void}
		 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
		 */
		fetchFoldersSafe() {
			this.folderStore.fetchFolders().catch(() => {})
		},
	},
}
</script>

<style scoped>
/* Copied from CnAppNav (e2e-parity contract, see the header comment). */
.cn-app-nav__footer-list {
	list-style: none;
	margin: 0;
	padding: 0;
	flex-shrink: 0 !important;
	overflow: visible !important;
}

.cn-app-nav__settings-list {
	list-style: none;
	margin: 0;
	padding-inline-start: 5px;
}
</style>
