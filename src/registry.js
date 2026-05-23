/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * V2 component registry for doriath (hydra ADR-036).
 *
 * Recognised kinds: page, widget, modal, form-field, cell-renderer.
 *
 * Page resolution path at runtime:
 *   - `pages[].type === 'custom'` → CnPageRenderer resolves
 *     `page.component` against `customComponents` (derived from the
 *     `kind:"page"` entries of this registry in src/main.js).
 *   - Dashboard `widgets[]` entries whose `widgetKey` is not built-in
 *     are looked up against this registry via `CnWidgetGrid` (the
 *     `kind:"widget"` entries below).
 *
 * Current entries:
 *   - LockScreen (page) — master-password setup + unlock flow.
 *     `type:"custom"` because the lock owns its own full-page layout
 *     (no app nav / header / sidebar) and the existing lock-redirect
 *     watcher in App.vue must short-circuit normal navigation. No lib
 *     primitive matches yet.
 *   - doriath-recent-activity (widget) — sample activity stream
 *     placeholder for the dashboard. Replace with a real feed widget
 *     once the underlying schema lands.
 *   - doriath-quick-actions (widget) — sample quick-actions tile for
 *     the dashboard. Placeholder until real actions wire through.
 *
 * @type {Record<string, { kind: string, component: object }>}
 */

import LockScreen from './views/LockScreen.vue'
import RecentActivityWidget from './widgets/RecentActivityWidget.vue'
import QuickActionsWidget from './widgets/QuickActionsWidget.vue'

export default {
	LockScreen: { kind: 'page', component: LockScreen },
	'doriath-recent-activity': { kind: 'widget', component: RecentActivityWidget },
	'doriath-quick-actions': { kind: 'widget', component: QuickActionsWidget },
}
