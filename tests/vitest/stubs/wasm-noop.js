/**
 * SPDX-FileCopyrightText: 2026 Conduction / Doriath Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest no-op stub for `argon2-browser/dist/argon2.wasm`.
 *
 * `src/crypto/argon2.js` does `await import('argon2-browser/dist/argon2.wasm')`
 * to grab the webpack-emitted URL the browser then `fetch`es. Under vite
 * there is no asset loader for `.wasm`, so we alias the URL import to this
 * empty module — the stubbed argon2-browser (see ./argon2-browser.js) does
 * not consult the URL anyway.
 */

export default 'about:blank#test-wasm-noop'
