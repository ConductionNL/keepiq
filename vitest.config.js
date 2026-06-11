/**
 * SPDX-FileCopyrightText: 2026 Conduction / Doriath Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest configuration for Doriath frontend unit tests.
 *
 * Doriath ships TWO kinds of vitest specs and the harness picks the runtime
 * environment per file via `environmentMatchGlobs`:
 *
 *   1. PURE LOGIC SPECS  -> `node` env (default)
 *      - `tests/vitest/**` (crypto round-trips: rsa.spec.js, argon2.spec.js,
 *        envelope, etc.). These use WebCrypto from `globalThis.crypto.subtle`
 *        (Node >= 20) plus the `btoa` / `atob` globals; jsdom would only slow
 *        them down.
 *
 *   2. COMPONENT / DOM SPECS -> `jsdom` env
 *      - `tests/components/**`, `tests/views/**`, `tests/dialogs/**`,
 *        `tests/store/**`. These mount `.vue` SFCs through
 *        `@vitejs/plugin-vue2` + `@vue/test-utils` (Vue 2.7) and assert on
 *        the rendered DOM. Reference pattern adapted from
 *        `apps-extra/openbuild/vitest.config.js`.
 *
 * Stubs:
 *   - `cssNoop` swallows `*.css` side-effect imports shipped by published
 *     `@nextcloud/vue` / `@conduction/nextcloud-vue` builds (the `.css`
 *     files do not exist on disk in unit-test mode — they come from a
 *     parallel Vite build the publishing pipeline runs).
 *   - `@conduction/nextcloud-vue` is aliased to a lightweight stub because
 *     its CJS bundle uses `require('*.vue')` which Vite's transform cannot
 *     consume. The doriath stub re-exports the dialog/modal primitives the
 *     tests need as plain Vue 2 components.
 *
 * @spec openspec/changes/implement-link-sharing/tasks.md#13.1
 * @spec openspec/changes/implement-secrets/tasks.md#13
 */

const path = require('path')
const vue2 = require('@vitejs/plugin-vue2')

const cssNoop = {
	name: 'doriath-css-noop',
	enforce: 'pre',
	resolveId(id) {
		if (typeof id === 'string' && /\.css(\?.*)?$/.test(id)) {
			return '\0virtual:css-noop'
		}
		return null
	},
	load(id) {
		if (id === '\0virtual:css-noop') {
			return 'export default {}'
		}
		return null
	},
}

module.exports = {
	plugins: [
		cssNoop,
		vue2.default ? vue2.default() : vue2(),
	],
	test: {
		// Default to node so the existing pure-crypto specs keep running fast.
		environment: 'node',
		environmentMatchGlobs: [
			['tests/components/**', 'jsdom'],
			['tests/views/**', 'jsdom'],
			['tests/dialogs/**', 'jsdom'],
			['tests/store/**', 'jsdom'],
		],
		globals: false,
		include: [
			'tests/vitest/**/*.spec.{js,ts}',
			'tests/components/**/*.spec.{js,ts}',
			'tests/views/**/*.spec.{js,ts}',
			'tests/dialogs/**/*.spec.{js,ts}',
			'tests/store/**/*.spec.{js,ts}',
		],
		exclude: ['tests/e2e/**', 'tests/integration/**', 'tests/Unit/**', 'tests/unit/**', 'node_modules/**'],
		setupFiles: [path.resolve(__dirname, 'tests/vitest/setup.js')],
		server: {
			deps: {
				inline: [
					/@nextcloud\/vue/,
					/@nextcloud\/axios/,
					/@nextcloud\/dialogs/,
					/@nextcloud\/initial-state/,
					/@conduction\/nextcloud-vue/,
					/vue-material-design-icons/,
				],
			},
		},
	},
	resolve: {
		alias: [
			{ find: '@', replacement: path.resolve(__dirname, 'src') },
			{
				find: /^@conduction\/nextcloud-vue$/,
				replacement: path.resolve(__dirname, 'tests/vitest/stubs/conduction-nextcloud-vue.js'),
			},
			{
				find: /^@nextcloud\/axios$/,
				replacement: path.resolve(__dirname, 'tests/vitest/stubs/nextcloud-axios.js'),
			},
			{
				find: /^@nextcloud\/router$/,
				replacement: path.resolve(__dirname, 'tests/vitest/stubs/nextcloud-router.js'),
			},
			// The full `@nextcloud/vue` design-system package needs the NC
			// window globals (`imagePath`, `appName`, capabilities initial
			// state) that the jsdom env does not have. Component tests
			// substitute lightweight render-function stubs for the design
			// primitives they touch (NcDialog, NcButton, NcSelect, ...).
			{
				find: /^@nextcloud\/vue$/,
				replacement: path.resolve(__dirname, 'tests/vitest/stubs/nextcloud-vue.js'),
			},
			// `argon2-browser` is an emscripten WASM module built for the
			// browser; under Node 22's global `fetch` the wasm-loader path
			// chokes on the absolute file path. The unit-test stub returns
			// a deterministic SHA-512-derived key so `encryptSnapshot` /
			// `decryptSnapshot` round-trip cleanly without the real KDF.
			// The production WASM is exercised by the Playwright e2e suite.
			{
				find: /^argon2-browser$/,
				replacement: path.resolve(__dirname, 'tests/vitest/stubs/argon2-browser.js'),
			},
			// The doriath shim does `await import('argon2-browser/dist/argon2.wasm')`
			// in the browser to get the webpack-emitted URL; in node we
			// short-circuit the import to a no-op (the stubbed argon2 module
			// above ignores the URL anyway).
			{
				find: /^argon2-browser\/dist\/argon2\.wasm$/,
				replacement: path.resolve(__dirname, 'tests/vitest/stubs/wasm-noop.js'),
			},
		],
	},
}
