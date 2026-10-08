import { describe, expect, it } from 'vitest'
import { decodeEnvelope, encodeEnvelope, IV_LENGTH, SALT_LENGTH } from './envelope'
import { decryptPrivateKeyPem, deriveUnlockKey, InvalidMasterPassword } from './kdf'
import { importPrivateKey, importPublicKey, pemToPkcs8, rsaDecrypt, rsaEncrypt } from './rsa'
import { envelope, vectors } from '@/src/testing/vectors'

// Written by the Keepiq web app, so passing them means byte compatibility.
const fields = vectors('fields')

describe('envelope', () => {
	it('round trips', () => {
		const parts = {
			salt: crypto.getRandomValues(new Uint8Array(SALT_LENGTH)),
			iv: crypto.getRandomValues(new Uint8Array(IV_LENGTH)),
			ciphertextWithTag: crypto.getRandomValues(new Uint8Array(40)),
		}
		expect(decodeEnvelope(encodeEnvelope(parts))).toEqual(parts)
	})

	it('rejects an unsupported version', () => {
		expect(() => decodeEnvelope(envelope.unsupportedVersionEnvelope)).toThrow(/version/)
	})
})

describe('unlock', () => {
	it('opens the web app envelope with the master password', async () => {
		expect(await decryptPrivateKeyPem(envelope.envelope, envelope.password)).toBe(envelope.privateKeyPem)
	})

	it('fails on the GCM tag with a wrong password', async () => {
		await expect(decryptPrivateKeyPem(envelope.envelope, envelope.wrongPassword)).rejects.toBeInstanceOf(InvalidMasterPassword)
	})

	it('derives a key that round trips AES-GCM', async () => {
		const key = await deriveUnlockKey('pw', new Uint8Array(SALT_LENGTH))
		const iv = new Uint8Array(IV_LENGTH)
		const sealed = await crypto.subtle.encrypt({ name: 'AES-GCM', iv }, key, new TextEncoder().encode('x'))
		expect(new TextDecoder().decode(await crypto.subtle.decrypt({ name: 'AES-GCM', iv }, key, sealed))).toBe('x')
	})
})

describe('rsa fields', async () => {
	const privateKey = await importPrivateKey(pemToPkcs8(envelope.privateKeyPem))

	for (const field of fields.cases) {
		it(`decrypts the web app ciphertext: ${field.name}`, async () => {
			expect(await rsaDecrypt(field.ciphertext, privateKey)).toBe(field.decryptsTo)
		})
	}

	for (const pem of ['publicKeyPem', 'certificatePem']) {
		it(`round trips through ${pem}, straddling a chunk boundary`, async () => {
			const publicKey = await importPublicKey(envelope[pem])
			for (const text of ['', 'a', 'é'.repeat(300), 'x'.repeat(445) + '🔑']) {
				expect(await rsaDecrypt(await rsaEncrypt(text, publicKey), privateKey)).toBe(text)
			}
		})
	}
})
