/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Keepiq admin-settings bootstrap.
 *
 * ⚠️ The mount is deliberately NOT inside the `loadTranslations` callback.
 * That scaffold pattern (inherited from nextcloud-app-template) renders a
 * BLANK admin panel whenever `/custom_apps/keepiq/l10n/<locale>.json` 404s —
 * which it does on installs whose Apache allowlist only passes JS/CSS.
 * Translation loading is fire-and-forget; the panel must mount regardless.
 */

import { loadState } from '@nextcloud/initial-state'
import {
	loadTranslations,
	translatePlural as n,
	translate as t,
} from '@nextcloud/l10n'
import { createApp, h } from 'vue'
import AdminRoot from './views/settings/AdminRoot.vue'
import pinia from './pinia.js'
import { mountAdminAreas } from './views/settings/adminAreas.js'

try {
	const result = loadTranslations('keepiq', () => {})
	if (result && typeof result.then === 'function') {
		result.then(
			() => {},
			() => {},
		)
	}
} catch {
	// no-op — English source strings are the fallback.
}

// One mount per admin area the viewer holds (admin-scoped-roles D3).
// Nextcloud renders the admin template once per area and provides
// `area-<key>` for each; the area comes from initial state, never from the
// DOM (ADR-004).
mountAdminAreas({
	loadState,
	mount: (area, selector) => {
		const app = createApp({ render: () => h(AdminRoot, { area }) })
		app.mixin({ methods: { t, n } })
		app.use(pinia)
		app.mount(selector)
	},
})
