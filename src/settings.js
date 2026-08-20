/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Doriath admin-settings bootstrap.
 *
 * ⚠️ The mount is deliberately NOT inside the `loadTranslations` callback.
 * That scaffold pattern (inherited from nextcloud-app-template) renders a
 * BLANK admin panel whenever `/custom_apps/doriath/l10n/<locale>.json` 404s —
 * which it does on installs whose Apache allowlist only passes JS/CSS.
 * Translation loading is fire-and-forget; the panel must mount regardless.
 */

import {
	loadTranslations,
	translatePlural as n,
	translate as t,
} from '@nextcloud/l10n'
import { createApp, h } from 'vue'
import AdminRoot from './views/settings/AdminRoot.vue'
import pinia from './pinia.js'

try {
	const result = loadTranslations('doriath', () => {})
	if (result && typeof result.then === 'function') {
		result.then(
			() => {},
			() => {},
		)
	}
} catch {
	// no-op — English source strings are the fallback.
}

const app = createApp({ render: () => h(AdminRoot) })
app.mixin({ methods: { t, n } })
app.use(pinia)
app.mount('#doriath-settings')
