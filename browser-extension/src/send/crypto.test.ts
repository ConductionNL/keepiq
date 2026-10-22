import { describe, expect, it } from 'vitest'
import { fromBase64, fromBase64Url, toBase64Url } from '@/src/crypto/base64'
import { vectors } from '@/src/testing/vectors'
import { argon2idHash } from './argon2'
import { sealPayload, wrapContentKey } from './crypto'

const { withoutPassword, withPassword } = vectors('send')

/** The web app's `aesDecrypt`: the first 12 bytes are the IV. */
async function open(key: CryptoKey, blob: string): Promise<Uint8Array> {
	const bytes = fromBase64(blob)
	return new Uint8Array(await crypto.subtle.decrypt({ name: 'AES-GCM', iv: bytes.slice(0, 12) }, key, bytes.slice(12)))
}
const aesKey = (raw: Uint8Array<ArrayBuffer>) => crypto.subtle.importKey('raw', raw, 'AES-GCM', false, ['decrypt'])
/** What the recipient page derives to unwrap. */
const unwrapKey = async (password: string, salt: Uint8Array) => aesKey(await argon2idHash(password, salt) as Uint8Array<ArrayBuffer>)
const text = (bytes: Uint8Array) => new TextDecoder().decode(bytes)

describe('send crypto', () => {
	it('opens the web app\'s send without a password with the key from its link', async () => {
		expect(text(await open(await aesKey(fromBase64Url(withoutPassword.rawKeyBase64Url)), withoutPassword.encryptedPayload))).toBe(withoutPassword.payload)
	})

	it('unwraps the web app\'s password send with the Argon2id key, password in UTF-8', async () => {
		const kek = await unwrapKey(withPassword.password, fromBase64(withPassword.argon2idSalt))
		const raw = await open(kek, withPassword.wrappedKey)
		expect(toBase64Url(raw)).toBe(withPassword.rawKeyBase64Url)
		expect(text(await open(await aesKey(raw as Uint8Array<ArrayBuffer>), withPassword.encryptedPayload))).toBe(withPassword.payload)
	}, 30_000)

	it('seals a payload as base64 of IV, ciphertext and tag under a fresh 256-bit key', async () => {
		const payload = 'Wi-Fi: zomer☀2026'
		const first = await sealPayload(payload)
		const second = await sealPayload(payload)
		expect(first.rawKey).toHaveLength(32)
		expect(toBase64Url(first.rawKey)).not.toBe(toBase64Url(second.rawKey))
		expect(fromBase64(first.encryptedPayload)).toHaveLength(12 + new TextEncoder().encode(payload).length + 16)
		expect(text(await open(await aesKey(first.rawKey), first.encryptedPayload))).toBe(payload)
	})

	it('wraps the content key under a fresh 16-byte salt the recipient page derives from', async () => {
		const { rawKey } = await sealPayload('x')
		const { wrappedKey, argon2idSalt } = await wrapContentKey(rawKey, 'vier woorden in één regel')
		expect(fromBase64(argon2idSalt)).toHaveLength(16)
		expect(fromBase64(wrappedKey)).toHaveLength(12 + 32 + 16)
		const kek = await unwrapKey('vier woorden in één regel', fromBase64(argon2idSalt))
		expect(await open(kek, wrappedKey)).toEqual(rawKey)
	}, 30_000)

	it('round-trips base64url without padding', () => {
		const bytes = Uint8Array.from([251, 255, 191, 0, 1])
		expect(toBase64Url(bytes)).toBe('-_-_AAE')
		expect(fromBase64Url('-_-_AAE')).toEqual(bytes)
	})
})
