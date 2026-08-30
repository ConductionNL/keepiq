/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest configuration for Keepiq frontend unit tests.
 *
 * Keepiq ships TWO kinds of vitest specs and the harness picks the runtime
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
 *        `@vitejs/plugin-vue` + `@vue/test-utils` v2 (Vue 3) and assert on
 *        the rendered DOM.
 *
 * Stubs:
 *   - `cssNoop` swallows `*.css` side-effect imports shipped by published
 *     `@nextcloud/vue` / `@conduction/nextcloud-vue` builds (the `.css`
 *     files do not exist on disk in unit-test mode — they come from a
 *     parallel Vite build the publishing pipeline runs).
 *   - `@conduction/nextcloud-vue` is aliased to a lightweight stub because
 *     its bundle drags in apexcharts / leaflet / codemirror, none of which
 *     render under jsdom. The keepiq stub re-exports the dialog/modal
 *     primitives the tests need as plain Vue 3 components.
 *
 * @spec openspec/changes/implement-link-sharing/tasks.md#13.1
 * @spec openspec/changes/implement-secrets/tasks.md#13
 */

const path = require('path')
const vue = require('@vitejs/plugin-vue')

const cssNoop = {
	name: 'keepiq-css-noop',
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
	plugins: [cssNoop, vue.default ? vue.default() : vue()],
	test: {
		// Vitest's default `testTimeout` is 5000ms, and several specs here do
		// real WebCrypto work — RSA keygen, envelope wrap/unwrap, backup
		// round-trips — rather than mocking it. On a shared 2-core CI runner
		// those sit close enough to 5s that they tip over, and WHICH one tips
		// over moves from run to run, which is the signature of a marginal
		// timeout rather than a broken test.
		//
		// This was already happening on `development` at vitest 1.6.1: run
		// 31083918823 failed `emergencyEnvelope.spec.js > builds an envelope
		// the grantee can open` with "Test timed out in 5000ms" while the run
		// before and after it passed. So the headroom was already gone.
		//
		// Upgrading vitest 1.6.1 -> 3.2.7 then removed what was left. Measured
		// on the CI runner, same repo, same job:
		//
		//                            vitest 1.6.1     vitest 3.2.7
		//   attachment-crypto.spec       2604ms           5391ms
		//   import.spec                  3937ms           TIMED OUT
		//   total `tests` time           29.08s           35.66s
		//
		// The likely mechanism is vitest 2's change of the default worker pool
		// from `threads` to `forks`: more per-worker overhead and more
		// contention for two cores on CPU-bound crypto.
		//
		// 20s is chosen to be well clear of the slowest observed spec while
		// still failing fast on a genuine hang — the whole suite runs in ~30s.
		// This changes no assertion; it only stops a slow runner from being
		// scored as a test failure.
		testTimeout: 20000,
		hookTimeout: 20000,
		// vitest 4 removed `environmentMatchGlobs`, which is how this app used to
		// give its DOM specs jsdom while the crypto specs stayed on node. Without a
		// replacement every DOM spec dies with `ReferenceError: window is not
		// defined`, so the split is expressed as two projects instead.
		//
		// `extends: true` means each project inherits everything configured at
		// this level (timeouts, setupFiles, stubs, inlined deps). Only the name,
		// the environment and the files differ, which is the whole of the old
		// `environmentMatchGlobs` intent.
		projects: [
			{
				extends: true,
				test: {
					name: 'node',
					environment: 'node',
					// Crypto round-trips use WebCrypto from `globalThis.crypto`
					// (Node >= 20); jsdom would only slow them down. The router
					// guards are pure logic and belong here for the same reason.
					include: [
						'tests/vitest/**/*.spec.{js,ts}',
						'tests/router/**/*.spec.{js,ts}',
					],
				},
			},
			{
				extends: true,
				test: {
					name: 'jsdom',
					environment: 'jsdom',
					// Everything that mounts an SFC or touches the document.
					include: [
						'tests/bootstrap/**/*.spec.{js,ts}',
						'tests/components/**/*.spec.{js,ts}',
						'tests/views/**/*.spec.{js,ts}',
						'tests/dialogs/**/*.spec.{js,ts}',
						'tests/modals/**/*.spec.{js,ts}',
						'tests/store/**/*.spec.{js,ts}',
						'tests/extension/**/*.spec.{js,ts}',
					],
				},
			},
		],
		globals: false,
		exclude: [
			'tests/e2e/**',
			'tests/integration/**',
			'tests/Unit/**',
			'tests/unit/**',
			'node_modules/**',
		],
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
				replacement: path.resolve(
					__dirname,
					'tests/vitest/stubs/conduction-nextcloud-vue.js',
				),
			},
			{
				find: /^@nextcloud\/axios$/,
				replacement: path.resolve(
					__dirname,
					'tests/vitest/stubs/nextcloud-axios.js',
				),
			},
			{
				find: /^@nextcloud\/router$/,
				replacement: path.resolve(
					__dirname,
					'tests/vitest/stubs/nextcloud-router.js',
				),
			},
			// The full `@nextcloud/vue` design-system package needs the NC
			// window globals (`imagePath`, `appName`, capabilities initial
			// state) that the jsdom env does not have. Component tests
			// substitute lightweight render-function stubs for the design
			// primitives they touch (NcDialog, NcButton, NcSelect, ...).
			{
				find: /^@nextcloud\/vue$/,
				replacement: path.resolve(
					__dirname,
					'tests/vitest/stubs/nextcloud-vue.js',
				),
			},
			// `argon2-browser` is an emscripten WASM module built for the
			// browser; under Node 22's global `fetch` the wasm-loader path
			// chokes on the absolute file path. The unit-test stub returns
			// a deterministic SHA-512-derived key so `encryptSnapshot` /
			// `decryptSnapshot` round-trip cleanly without the real KDF.
			// The production WASM is exercised by the Playwright e2e suite.
			{
				find: /^argon2-browser$/,
				replacement: path.resolve(
					__dirname,
					'tests/vitest/stubs/argon2-browser.js',
				),
			},
			// The keepiq shim does `await import('argon2-browser/dist/argon2.wasm')`
			// in the browser to get the webpack-emitted URL; in node we
			// short-circuit the import to a no-op (the stubbed argon2 module
			// above ignores the URL anyway).
			{
				find: /^argon2-browser\/dist\/argon2\.wasm$/,
				replacement: path.resolve(
					__dirname,
					'tests/vitest/stubs/wasm-noop.js',
				),
			},
		],
	},
}
