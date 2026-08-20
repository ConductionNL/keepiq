/**
 * SPDX-FileCopyrightText: 2026 Conduction / Doriath Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Test-only stub for the `argon2-browser` WASM module.
 *
 * The real argon2-browser ships an emscripten-compiled WASM that is
 * built for the browser (`fetch`-based loader, anonymous `self.Module`
 * globals) and refuses to load cleanly under vitest's node env (Node
 * 22's global `fetch` reads the wasm path as a URL and throws
 * `Failed to parse URL`).
 *
 * This stub returns a deterministic SHA-512-derived 32-byte hash from
 * (password, salt) so the AES-256-GCM round-trip in
 * `src/crypto/argon2.js` can be unit-tested end-to-end. The KDF is the
 * one thing this stub is NOT trying to exercise — Argon2id's strength
 * vs SHA-512 is irrelevant to the round-trip + tag-mismatch + per-call
 * randomisation invariants the unit tests lock down. The real WASM is
 * exercised in the browser via the existing Playwright e2e suite.
 *
 * The shape mirrors `argon2-browser`'s public surface:
 *  - `ArgonType.Argon2id` constant
 *  - `hash({ pass, salt, ... })` returning `{ hash: Uint8Array }`
 *
 * @spec openspec/changes/implement-link-sharing/tasks.md#13.1
 */

import { createHash } from 'node:crypto'

export const ArgonType = {
	Argon2d: 0,
	Argon2i: 1,
	Argon2id: 2,
}

export async function hash({ pass, salt, hashLen = 32 }) {
	// SHA-512 over (password || salt), truncated/wrapped to the requested
	// length. Deterministic in (pass, salt) — which is what the round-trip
	// test relies on.
	const passBytes =
		typeof pass === 'string' ? new TextEncoder().encode(pass) : pass
	const saltBytes =
		typeof salt === 'string' ? new TextEncoder().encode(salt) : salt
	const combined = new Uint8Array(passBytes.length + saltBytes.length)
	combined.set(passBytes, 0)
	combined.set(saltBytes, passBytes.length)

	const digest = createHash('sha512').update(Buffer.from(combined)).digest()
	const out = new Uint8Array(hashLen)
	for (let i = 0; i < hashLen; i++) {
		out[i] = digest[i % digest.length]
	}
	return { hash: out }
}

export default { ArgonType, hash }
