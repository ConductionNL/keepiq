import { describe, expect, it } from 'vitest'
import { decodeEnvelope, encodeEnvelope, IV_LENGTH, SALT_LENGTH } from './envelope'
import { decryptPrivateKeyPem, deriveUnlockKey, InvalidMasterPassword } from './kdf'
import { fromBase64, toBase64 } from './base64'
import { extractSpki, importPrivateKey, importPublicKey, importSpki, pemToPkcs8, rsaDecrypt, rsaEncrypt } from './rsa'
import { envelope, federationChain, vectors } from '@/src/testing/vectors'

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

	it('imports a SubjectPublicKeyInfo as an encrypt-only key', async () => {
		const publicKey = await importSpki(extractSpki(derOf(envelope.certificatePem)))
		expect(publicKey.usages).toEqual(['encrypt'])
		expect(publicKey.extractable).toBe(false)
		expect(await rsaDecrypt(await rsaEncrypt('x', publicKey), privateKey)).toBe('x')
	})

	it('rejects a SubjectPublicKeyInfo that is no SEQUENCE rather than throwing', async () => {
		let pending: Promise<CryptoKey> | undefined
		expect(() => (pending = importSpki(new Uint8Array([0x04, 0x00])))).not.toThrow()
		await expect(pending).rejects.toThrow(/Malformed/)
	})
})

const derOf = (pem: string) => fromBase64(pem.replace(/-----[A-Z ]+-----|\s/g, ''))
const wrap = (label: string, der: Uint8Array) => `-----BEGIN ${label}-----\n${toBase64(der)}\n-----END ${label}-----`

describe('PEM', () => {
	const cert: string = envelope.certificatePem
	const spki: string = envelope.publicKeyPem
	// Another certificate, so a parser that takes the wrong one is caught.
	const other = federationChain().mallory

	it.each([
		['text before the block', `${toBase64(derOf(other))}\n${cert}`],
		['text after the block', `${cert}\n${toBase64(derOf(other))}`],
		['two blocks', `${cert}\n${other}`],
		['DER after the certificate', wrap('CERTIFICATE', new Uint8Array([...derOf(cert), ...derOf(other)]))],
		['DER after the public key', wrap('PUBLIC KEY', new Uint8Array([...derOf(spki), 0x30, 0x00]))],
		['mismatched labels', cert.replace('END CERTIFICATE', 'END PUBLIC KEY')],
		['another label', wrap('PRIVATE KEY', derOf(cert))],
		['characters outside base64', cert.replace('\n', '\n!')],
	])('refuses a public key with %s by rejecting', async (_, pem) => {
		await expect(importPublicKey(pem)).rejects.toThrow(/Malformed/)
	})

	it('refuses a private key with text around it or DER after it', () => {
		const pem: string = envelope.privateKeyPem
		expect(() => pemToPkcs8(`${pem}\n${pem}`)).toThrow(/Malformed/)
		expect(() => pemToPkcs8(wrap('PRIVATE KEY', new Uint8Array([...derOf(pem), 0x30, 0x00])))).toThrow(/Malformed/)
	})

	it('allows whitespace around the block', async () => {
		await expect(importPublicKey(`\r\n  ${cert.replace(/\n/g, '\r\n')}\n\n`)).resolves.toBeDefined()
		expect(pemToPkcs8(`${envelope.privateKeyPem}\n`)).toEqual(pemToPkcs8(envelope.privateKeyPem))
	})
})

describe('malformed input', () => {
	it.each([
		['more than 4 length bytes', [0x30, 0x85, 1, 0, 0, 0, 0]],
		['an indefinite length', [0x30, 0x80, 0x30, 0x00]],
		['a length past the end', [0x30, 0x82, 0xff, 0xff, 0x30, 0x00]],
		['a field length past the end', [0x30, 0x06, 0x30, 0x04, 0x02, 0x7f, 0x00, 0x00]],
	])('rejects a certificate with %s', (_, bytes) => {
		expect(() => extractSpki(new Uint8Array(bytes))).toThrow(/Malformed/)
	})

	it('rejects RSA ciphertext whose block count does not match its length', async () => {
		const privateKey = await importPrivateKey(pemToPkcs8(envelope.privateKeyPem))
		const header = new Uint8Array([0xff, 0xff, 0xff, 0xff])
		await expect(rsaDecrypt(btoa(String.fromCharCode(...header)), privateKey)).rejects.toThrow(/block count/)
		await expect(rsaDecrypt('', privateKey)).rejects.toThrow(/too short/)
	})
})
