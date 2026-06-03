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
 * Widget metadata fields (ADR-036): every `kind:"widget"` entry
 * declares `defaultSize`, `minSize`, `maxSize`, `allowedSlots`, and
 * `propsSchema` so `CnAppRoot` can validate the manifest against the
 * widget's contract. Missing fields trigger console-warn noise.
 *
 * Current entries:
 *   - LockScreen (page) — master-password setup + unlock flow.
 *     `type:"custom"` because the lock owns its own full-page layout
 *     (no app nav / header / sidebar) and the existing lock-redirect
 *     watcher in App.vue must short-circuit normal navigation. No lib
 *     primitive matches yet.
 *   - stats-block (widget) — local placeholder KPI tile. Overrides the
 *     library's `CnStatsBlockWidget` (which requires a `dataSource`
 *     block pointing at an OR schema) until doriath registers its own
 *     OR-backed schemas; until then the manifest passes static
 *     `count` / `title` / `variant` props directly.
 *   - doriath-recent-activity (widget) — sample activity stream
 *     placeholder for the dashboard. Replace with a real feed widget
 *     once the underlying schema lands.
 *   - doriath-quick-actions (widget) — sample quick-actions tile for
 *     the dashboard. Placeholder until real actions wire through.
 *
 * @type {Record<string, { kind: string, component: object, defaultSize?: object, minSize?: object, maxSize?: object, allowedSlots?: string[], propsSchema?: object }>}
 */

import LockScreen from './views/LockScreen.vue'
import RecentActivityWidget from './widgets/RecentActivityWidget.vue'
import QuickActionsWidget from './widgets/QuickActionsWidget.vue'
import StatsBlockWidget from './widgets/StatsBlockWidget.vue'

export default {
	LockScreen: { kind: 'page', component: LockScreen },
	'stats-block': {
		kind: 'widget',
		component: StatsBlockWidget,
		defaultSize: { w: 3, h: 2 },
		minSize: { w: 2, h: 2 },
		maxSize: { w: 6, h: 4 },
		allowedSlots: ['body'],
		propsSchema: {},
	},
	'doriath-recent-activity': {
		kind: 'widget',
		component: RecentActivityWidget,
		defaultSize: { w: 6, h: 4 },
		minSize: { w: 4, h: 3 },
		maxSize: { w: 12, h: 8 },
		allowedSlots: ['body'],
		propsSchema: {},
	},
	'doriath-quick-actions': {
		kind: 'widget',
		component: QuickActionsWidget,
		defaultSize: { w: 6, h: 4 },
		minSize: { w: 3, h: 2 },
		maxSize: { w: 12, h: 8 },
		allowedSlots: ['body'],
		propsSchema: {},
	},
}
