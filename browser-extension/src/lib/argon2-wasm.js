/**
 * Argon2id for the extension: the web app's KDF (`src/crypto/argon2.js`)
 * with its WebAssembly bundled in (esbuild's binary loader), so the worker
 * never fetches code. Password-protected sends need it.
 *
 * @spec openspec/changes/clients-extension-complete/specs/extension-send/spec.md#requirement-password-protected-sends
 */

import wasmBinary from 'argon2-browser/dist/argon2.wasm'

/**
 * Point argon2-browser at the bundled WebAssembly, once.
 *
 * @return {void}
 */
export function installArgon2Wasm() {
	if (!globalThis.loadArgon2WasmBinary) {
		globalThis.loadArgon2WasmBinary = async () => wasmBinary
	}
}
