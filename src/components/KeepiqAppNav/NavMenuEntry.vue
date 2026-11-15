<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  NavMenuEntry — one manifest menu entry of the left rail, the same in the
  main list, the footer and the settings foldout: route entries render `:to`
  (so the `href$` e2e selectors keep working), external entries a real
  `href`, and `action: "user-settings"` opens the host's settings dialog.
-->
<template>
	<NcAppNavigationItem
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

<script>
import { CnIcon } from '@conduction/nextcloud-vue'
import { NcAppNavigationItem } from '@nextcloud/vue'
import { menuEntryTo } from '../../utils/navEntries.js'

/**
 * Render one manifest menu entry of keepiq's left rail.
 */
export default {
	name: 'NavMenuEntry',

	components: {
		CnIcon,
		NcAppNavigationItem,
	},

	inject: {
		/**
		 * Opens the host app's NcAppSettingsDialog (provided by CnAppRoot).
		 * Defaults to a no-op so the rail still mounts standalone (tests).
		 */
		cnOpenUserSettings: { default: () => () => {} },
	},

	props: {
		/** The manifest menu entry ({id, label, route, query, href, action, icon}). */
		item: {
			type: Object,
			required: true,
		},
	},

	methods: {
		/**
		 * Router target for a manifest entry (route entries only) — `:to`
		 * keeps the rendered anchors' `href$` shape the e2e suite selects on.
		 * An entry's `query` rides along, so the Integrations entry opens its
		 * page preset to `app=keepiq`.
		 *
		 * @param {object} item The menu entry.
		 * @return {object|null}
		 * @spec openspec/specs/menu-architecture/spec.md#app-navigation-renders
		 * @spec openspec/specs/admin-integrations/spec.md#requirement-req-keepiq-conn-004-an-admin-reads-the-connections-on-an-integrations-page
		 */
		itemTo(item) {
			return menuEntryTo(item)
		},

		/**
		 * Plain href for external entries (e.g. Documentation) — a real
		 * anchor, which NcAppNavigationItem opens in a new tab for
		 * scheme-prefixed URLs.
		 *
		 * @param {object} item The menu entry.
		 * @return {string|null}
		 * @spec openspec/specs/menu-architecture/spec.md#app-navigation-renders
		 */
		itemHref(item) {
			return item.href && !item.action ? item.href : null
		},

		/**
		 * Whether a route entry matches the current route.
		 *
		 * @param {object} item The menu entry.
		 * @return {boolean}
		 * @spec openspec/specs/menu-architecture/spec.md#app-navigation-renders
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
		 * @spec openspec/specs/menu-architecture/spec.md#app-navigation-renders
		 */
		onItemClick(item, event) {
			if (item.action === 'user-settings') {
				if (event && typeof event.preventDefault === 'function') {
					event.preventDefault()
				}
				this.cnOpenUserSettings()
			}
		},
	},
}
</script>
