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

/** Thrown when the GCM tag does not verify, which means a wrong master password. */
export class InvalidMasterPassword extends Error {}

/** Decrypts the suite's private-key envelope to its PKCS#8 PEM. */
export async function decryptPrivateKeyPem(envelope: string, password: string): Promise<string> {
	const { salt, iv, ciphertextWithTag } = decodeEnvelope(envelope)
	const key = await deriveUnlockKey(password, salt)
	try {
		return new TextDecoder().decode(await crypto.subtle.decrypt({ name: 'AES-GCM', iv }, key, ciphertextWithTag))
	} catch {
		throw new InvalidMasterPassword()
	}
}
