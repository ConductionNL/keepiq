/** Send encryption as the web app's `src/send/sendCrypto.js` and `ephemeralSend.js`, so its recipient page opens what this makes. */
import { toBase64 } from '@/src/crypto/base64'
import { deriveSendKey } from './argon2'

const IV_LENGTH = 12
const SALT_LENGTH = 16

/** base64 of `[IV][ciphertext‖tag]`. */
async function aesEncrypt(key: CryptoKey, plaintext: Uint8Array<ArrayBuffer>): Promise<string> {
	const iv = crypto.getRandomValues(new Uint8Array(IV_LENGTH))
	const ciphertext = new Uint8Array(await crypto.subtle.encrypt({ name: 'AES-GCM', iv }, key, plaintext))
	const combined = new Uint8Array(IV_LENGTH + ciphertext.length)
	combined.set(iv, 0)
	combined.set(ciphertext, IV_LENGTH)
	return toBase64(combined)
}

/** The ciphertext for the server and the raw content key, which never goes there; the caller zeroes it. */
export async function sealPayload(payload: string): Promise<{ encryptedPayload: string; rawKey: Uint8Array<ArrayBuffer> }> {
	const key = await crypto.subtle.generateKey({ name: 'AES-GCM', length: 256 }, true, ['encrypt'])
	const rawKey = new Uint8Array(await crypto.subtle.exportKey('raw', key))
	const plaintext = new TextEncoder().encode(payload)
	try {
		return { encryptedPayload: await aesEncrypt(key, plaintext), rawKey }
	} finally {
		plaintext.fill(0)
	}
}

/** The content key under an Argon2id key from the send password, for a link that carries no key. */
export async function wrapContentKey(rawKey: Uint8Array<ArrayBuffer>, password: string): Promise<{ wrappedKey: string; argon2idSalt: string }> {
	const salt = crypto.getRandomValues(new Uint8Array(SALT_LENGTH))
	return { wrappedKey: await aesEncrypt(await deriveSendKey(password, salt), rawKey), argon2idSalt: toBase64(salt) }
}
