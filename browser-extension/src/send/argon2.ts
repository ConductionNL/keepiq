/** Argon2id for password sends, with the web app's parameters (`src/crypto/argon2.js`), so its recipient page derives the same key. */
import { argon2id } from 'hash-wasm'

const PARAMS = { memorySize: 65536, iterations: 3, parallelism: 1, hashLength: 32 } as const

// The smallest valid module. `typeof WebAssembly` says nothing: a CSP without 'wasm-unsafe-eval' refuses compiling even this.
const EMPTY_MODULE = new Uint8Array([0x00, 0x61, 0x73, 0x6d, 0x01, 0x00, 0x00, 0x00])

let available: Promise<boolean> | undefined

export function isArgon2Available(): Promise<boolean> {
	available ??= probe()
	return available
}

async function probe(): Promise<boolean> {
	try {
		await WebAssembly.compile(EMPTY_MODULE)
		return true
	} catch {
		return false
	}
}

/** The raw hash; the password goes in as UTF-8, as argon2-browser takes it. */
export function argon2idHash(password: string, salt: Uint8Array): Promise<Uint8Array> {
	return argon2id({ password, salt, ...PARAMS, outputType: 'binary' })
}

/** Wrap only: unwrapping is the recipient page's. */
export async function deriveSendKey(password: string, salt: Uint8Array): Promise<CryptoKey> {
	const bytes = await argon2idHash(password, salt)
	try {
		return await crypto.subtle.importKey('raw', bytes as Uint8Array<ArrayBuffer>, 'AES-GCM', false, ['encrypt'])
	} finally {
		bytes.fill(0)
	}
}
