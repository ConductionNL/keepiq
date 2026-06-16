/**
 * SPDX-FileCopyrightText: 2026 Conduction / Doriath Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Global setup for Doriath Vitest unit tests.
 *
 * Stubs the Nextcloud `t()` and `n()` translation helpers so component
 * renders that call them resolve to the bare key string. Loaded
 * automatically via `test.setupFiles` in `vitest.config.js`.
 *
 * Two registration sites are required because Vue 2's template compiler
 * emits `_vm.t(...)` (instance lookup) while plain `<script>` code calls
 * `t(...)` (global lookup):
 *
 *   - `globalThis.t` / `globalThis.n` — for direct calls in store / util
 *     modules and inline template expressions that bypass the instance.
 *   - `Vue.mixin({ methods: { t, n } })` — for `_vm.t(...)` emitted by
 *     the template compiler. Registered as a global mixin so every
 *     mounted component has them on `this`.
 *
 * @spec openspec/changes/implement-link-sharing/tasks.md#13
 */

import Vue from 'vue'

const tStub = (_app, key, _vars) => key
const nStub = (_app, singular, plural, count) => (count === 1 ? singular : plural)

globalThis.t = tStub
globalThis.n = nStub

Vue.mixin({
	methods: {
		t: tStub,
		n: nStub,
	},
})
