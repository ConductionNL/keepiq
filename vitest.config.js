/**
 * SPDX-FileCopyrightText: 2026 Conduction / Doriath Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest configuration for Doriath frontend unit tests.
 *
 * The current suite targets PURE logic — the security-critical browser
 * crypto in `src/crypto/rsa.js` (RSA-OAEP-SHA256 + X.509 SPKI extraction).
 * These tests need no DOM, so the default environment is `node`, which
 * exposes WebCrypto via `globalThis.crypto.subtle` (Node >= 20) plus the
 * `btoa` / `atob` globals the module relies on.
 *
 * To add component (`.vue`) tests later, switch the relevant files to the
 * jsdom environment with a per-file `// @vitest-environment jsdom` comment
 * and add `@vitejs/plugin-vue2` (see openbuild/vitest.config.js for the
 * full component harness pattern).
 */

const path = require('path')

module.exports = {
	test: {
		environment: 'node',
		globals: false,
		include: ['tests/vitest/**/*.spec.{js,ts}'],
		exclude: ['tests/e2e/**', 'tests/integration/**', 'tests/Unit/**', 'tests/unit/**', 'node_modules/**'],
	},
	resolve: {
		alias: [
			{ find: '@', replacement: path.resolve(__dirname, 'src') },
		],
	},
}
