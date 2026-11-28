import { decodeEnvelope } from './envelope'

const PBKDF2_ITERATIONS = 600_000

export async function deriveUnlockKey(password: string, salt: Uint8Array<ArrayBuffer>): Promise<CryptoKey> {
	const material = await crypto.subtle.importKey('raw', new TextEncoder().encode(password), 'PBKDF2', false, ['deriveKey'])
	return crypto.subtle.deriveKey(
		{ name: 'PBKDF2', salt, iterations: PBKDF2_ITERATIONS, hash: 'SHA-256' },
		material,
		{ name: 'AES-GCM', length: 256 },
		false,
		['encrypt', 'decrypt'],
	)
}

/** The same key as raw bytes, which a passkey wraps (extension-biometric-unlock). */
export async function deriveUnlockKeyBits(password: string, salt: Uint8Array<ArrayBuffer>): Promise<Uint8Array<ArrayBuffer>> {
	const material = await crypto.subtle.importKey('raw', new TextEncoder().encode(password), 'PBKDF2', false, ['deriveBits'])
	return new Uint8Array(await crypto.subtle.deriveBits({ name: 'PBKDF2', salt, iterations: PBKDF2_ITERATIONS, hash: 'SHA-256' }, material, 256))
}

/** Thrown when the GCM tag does not verify, which means a wrong master password. */
export class InvalidMasterPassword extends Error {}

/** Decrypts the suite's private-key envelope to its PKCS#8 PEM. */
export async function decryptPrivateKeyPem(envelope: string, password: string): Promise<string> {
	return decryptPrivateKeyPemWith(envelope, await deriveUnlockKey(password, decodeEnvelope(envelope).salt))
}

/** As `decryptPrivateKeyPem`, with the unlock key already derived. */
export async function decryptPrivateKeyPemWith(envelope: string, key: CryptoKey): Promise<string> {
	const { iv, ciphertextWithTag } = decodeEnvelope(envelope)
	try {
		return new TextDecoder().decode(await crypto.subtle.decrypt({ name: 'AES-GCM', iv }, key, ciphertextWithTag))
	} catch {
		throw new InvalidMasterPassword()
	}
}
