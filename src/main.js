/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Doriath Tier-4 bootstrap (hydra ADR-024 / ADR-036).
 *
 * Builds the vue-router from the bundled manifest, registers lib
 * icons + translations, and mounts the CnAppRoot-driven shell at
 * `#doriath-app`.
 *
 * ⚠️ The host element is `#doriath-app`, NOT `#content`. Under Vue 2,
 * `$mount('#content')` REPLACED the matched element, so the template's
 * `<div id="content">` (a duplicate of core's `layout.user.php` wrapper)
 * was swallowed. Vue 3's `mount()` renders INSIDE the match instead, so
 * mounting on `#content` would nest the app in core's own wrapper. The
 * host element is renamed rather than reasoning about which div wins.
 */

import { createApp, h } from 'vue'
import { createRouter, createWebHashHistory } from 'vue-router'
import { translate as t, translatePlural as n, loadTranslations } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import {
	// eslint-disable-next-line import/named
	buildManifest,
	// eslint-disable-next-line import/named
	CnPageRenderer,
	// eslint-disable-next-line import/named
	defaultPageTypes,
	// eslint-disable-next-line import/named
	registerIcons,
	// eslint-disable-next-line import/named
	registerBuiltinDashboardWidgets,
	// eslint-disable-next-line import/named
	registerTranslations,
} from '@conduction/nextcloud-vue'
import pinia from './pinia.js'
import App from './App.vue'
import bundledManifest from './manifest.json'
import menuLayout from './menu-layout.json'
import registry from './registry.js'
import appIcons from './icons.js'

// Library CSS — must be explicit import (webpack tree-shakes side-effect imports from aliased packages)
import '@conduction/nextcloud-vue/css/index.css'

// Global (unscoped) app styles
import './assets/app.css'

// Register library-side icon set + lib translations once at bootstrap.
registerIcons(appIcons)

// nc-vue declares `sideEffects: ["**/*.css"]`, which lets webpack drop the
// bare imports that register the built-in `stat` / `object-table` dashboard
// widgets. Without this explicit call those widgets render "Widget not
// available" while `chart` (registered inline) keeps working — the asymmetry
// that identified the bug on larpingapp.
registerBuiltinDashboardWidgets()

try {
	registerTranslations()
} catch (e) {
	// Non-fatal — lib translations fall back to English source.
	// eslint-disable-next-line no-console
	console.warn('[doriath] registerTranslations failed; falling back to English', e)
}

// Fire-and-forget translation load. Some Nextcloud installs only allow
// the JS/CSS allowlist through Apache; /custom_apps/<app>/l10n/<locale>.json
// may 404. Strings fall back to English source on miss; boot must not
// depend on this resolving.
function tryLoadTranslations() {
	try {
		const result = loadTranslations('doriath', () => {})
		if (result && typeof result.then === 'function') {
			result.then(() => {}, () => {})
		}
	} catch {
		// no-op
	}
}

// Shallow-clone CnPageRenderer because the lib's barrel exports are
// frozen / non-extensible (webpack ESM module records) and vue-router
// attaches bookkeeping to the component options it is handed. Cloning
// gives the router an extensible options object without altering the
// lib's internals.
const RoutePageRenderer = { ...CnPageRenderer }

const fragmentCtx = require.context('./manifest.d/', false, /\.json$/)
const fragments = fragmentCtx.keys().sort().map((key) => fragmentCtx(key))
const mergedManifest = buildManifest(bundledManifest, fragments, menuLayout)

/**
 * Build the vue-router config from the manifest. Each manifest page
 * becomes one route; the route's `name` IS `page.id` (per the lib's
 * manifest contract). Routes whose path declares a `:` parameter pass
 * `props: true` so the renderer receives params as props.
 *
 * @param {object} manifest The bundled manifest (with `pages[]`).
 * @return {Array<object>} vue-router 4 routes config.
 */
function routesFromManifest(manifest) {
	const routes = manifest.pages.map((page) => ({
		name: page.id,
		path: page.route,
		component: RoutePageRenderer,
		props: page.route.includes(':'),
	}))
	// Catch-all redirect to dashboard. vue-router 4 REMOVED the bare `*`
	// path — it matches nothing and silently leaves `<router-view>` empty
	// with no error, so the named-param form is mandatory.
	routes.push({ path: '/:pathMatch(.*)*', redirect: '/' })
	return routes
}

const router = createRouter({
	history: createWebHashHistory(generateUrl('/apps/doriath')),
	routes: routesFromManifest(mergedManifest),
})

tryLoadTranslations()

// Pass shallow copies of the registry maps to CnAppRoot. The lib
// exports `defaultPageTypes` (and the app's component maps) FROZEN, so
// any consumer that writes to them throws. Cloning here yields
// extensible objects.
//
// `customComponents` is derived from the v2 registry's `kind:"page"`
// entries because CnPageRenderer's `type:"custom"` dispatch path still
// resolves through `customComponents` (see ADR-036 transition notes).
// `kind:"widget"` entries in `registry.js` are consumed directly from
// the `registry` prop by CnAppRoot → CnWidgetGrid.
const pageTypesProp = { ...defaultPageTypes }
const registryProp = { ...registry }
const customComponentsProp = Object.fromEntries(
	Object.entries(registryProp)
		.filter(([, entry]) => entry && entry.kind === 'page')
		.map(([key, entry]) => [key, entry.component]),
)

// Create and mount the app immediately so the shell renders.
const app = createApp({
	render: () => h(App, {
		manifest: mergedManifest,
		customComponents: customComponentsProp,
		pageTypes: pageTypesProp,
		registry: registryProp,
	}),
})

// `t` / `n` were provided by a global `Vue.mixin` under Vue 2. An app-level
// mixin is the direct Vue 3 equivalent and keeps `t(...)` available to every
// template without touching 86 components.
app.mixin({ methods: { t, n } })
app.use(pinia)
app.use(router)
app.mount('#doriath-app')
