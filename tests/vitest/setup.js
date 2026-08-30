/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Global setup for Keepiq Vitest unit tests.
 *
 * Stubs the Nextcloud `t()` and `n()` translation helpers so component
 * renders that call them resolve to the bare key string. Loaded
 * automatically via `test.setupFiles` in `vitest.config.js`.
 *
 * Two registration sites are required:
 *
 *   - `globalThis.t` / `globalThis.n` — for direct calls in store / util
 *     modules that use the helpers as free functions.
 *   - `config.global.mixins` — for `this.t(...)` in component options and
 *     `_ctx.t(...)` emitted by the template compiler. Under Vue 2 this was
 *     `Vue.mixin(...)` on the global constructor; Vue 3 has no global Vue,
 *     so the equivalent is Vue Test Utils' `config.global`, which VTU
 *     merges into every `mount()` in the run.
 *
 * ⚠️ The Vue 2 form (`import Vue from 'vue'; Vue.mixin(...)`) does not
 * survive the migration: `vue@3`'s default export has no `.mixin`, so it
 * throws at setup time — loud, not silent, which is the good case.
 *
 * @spec openspec/changes/implement-link-sharing/tasks.md#13
 */

import { config } from '@vue/test-utils'

const tStub = (_app, key, _vars) => key
const nStub = (_app, singular, plural, count) => (count === 1 ? singular : plural)

globalThis.t = tStub
globalThis.n = nStub

config.global.mixins = [
	...(config.global.mixins || []),
	{
		methods: {
			t: tStub,
			n: nStub,
		},
	},
]
