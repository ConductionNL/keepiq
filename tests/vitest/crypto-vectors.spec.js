/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Re-reads the shared crypto vectors in tests/vectors/crypto/ with the web
 * app's own modules. The native apps' Kotlin core reads the same files
 * (mobile/shared/src/commonTest), so a format change in src/crypto that is
 * not mirrored there fails one of the two suites.
 *
 * Argon2id: the vitest config aliases `argon2-browser` to a SHA-512 stub
 * for speed. That stub cannot open a vector written with the real KDF, so
 * this file swaps the real library back in for `argon2-browser` and runs
 * src/crypto/argon2.js on top of it.
 *
 * @spec openspec/changes/clients-mobile-apps/specs/mobile-shared-core/spec.md#requirement-crypto-byte-compatible-with-the-web-app
 */

import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { beforeAll, describe, expect, it, vi } from 'vitest'
import {
	decryptPrivateKey,
	decryptPrivateKeyWithRawKey,
	deriveUnlockKeyRaw,
} from '../../src/crypto/aes.js'
import { decodeEnvelope } from '../../src/crypto/envelope.js'
import {
	importPrivateKey,
	importPublicKey,
	rsaDecrypt,
	rsaEncrypt,
} from '../../src/crypto/rsa.js'
import { parsePasskey, serializePasskey } from '../../src/passkey/passkey.js'
import {
	aesDecrypt,
	fromBase64,
	fromBase64Url,
	sendLink,
	toBase64Url,
} from '../../src/send/sendCrypto.js'
import { generateTotp, parseOtpauth } from '../../src/totp/totp.js'

vi.mock('argon2-browser', async () => {
	const real = (await import('argon2-browser/lib/argon2.js')).default
	return { ...real, default: real }
})
// The emscripten loader prefers fetch() for the .wasm path, which node refuses
// for a bare file path; without fetch it reads the file from disk.
vi.stubGlobal('fetch', undefined)

const VECTORS = join(
	dirname(fileURLToPath(import.meta.url)),
	'..',
	'vectors',
	'crypto',
)

/**
 * Read one vector file.
 *
 * @param {string} name The file name.
 * @return {object}
 * @spec openspec/changes/clients-mobile-apps/tasks.md#1.2
 */
function vector(name) {
	return JSON.parse(readFileSync(join(VECTORS, name), 'utf8'))
}

const envelope = vector('envelope.json')
const fields = vector('fields.json')
const send = vector('send.json')
const totp = vector('totp.json')
const passkey = vector('passkey.json')

describe('crypto vectors: envelope', () => {
	it('opens with its password to the stored private key', async () => {
		expect(await decryptPrivateKey(envelope.envelope, envelope.password)).toBe(
			envelope.privateKeyPem,
		)
	})

	it('derives the recorded unlock key and opens with it', async () => {
		const { salt } = decodeEnvelope(envelope.envelope)
		const raw = await deriveUnlockKeyRaw(envelope.password, salt)
		expect(Buffer.from(raw).toString('hex')).toBe(envelope.unlockKeyHex)
		expect(await decryptPrivateKeyWithRawKey(envelope.envelope, raw)).toBe(
			envelope.privateKeyPem,
		)
	})

	it('refuses the wrong password', async () => {
		await expect(
			decryptPrivateKey(envelope.envelope, envelope.wrongPassword),
		).rejects.toThrow()
	})

	it('refuses an envelope whose version is not 1', () => {
		expect(() => decodeEnvelope(envelope.unsupportedVersionEnvelope)).toThrow(
			'Unsupported envelope version: 2',
		)
	})
})

describe('crypto vectors: field ciphertexts', () => {
	let privateKey

	beforeAll(async () => {
		privateKey = await importPrivateKey(envelope.privateKeyPem)
	})

	for (const c of fields.cases) {
		it(`decrypts "${c.name}"`, async () => {
			const raw = fromBase64(c.ciphertext)
			expect(new DataView(raw.buffer).getUint32(0, false)).toBe(c.chunkCount)
			expect(raw.length).toBe(4 + 512 * c.chunkCount)
			expect(await rsaDecrypt(c.ciphertext, privateKey)).toBe(c.decryptsTo)
		})
	}

	it('encrypts to the certificate and to the bare key alike', async () => {
		for (const pem of [envelope.publicKeyPem, envelope.certificatePem]) {
			const key = await importPublicKey(pem)
			expect(await rsaDecrypt(await rsaEncrypt('both', key), privateKey)).toBe(
				'both',
			)
		}
	})
})

describe('crypto vectors: Send', () => {
	it('opens a Send without a password from its link key', async () => {
		const v = send.withoutPassword
		const key = await crypto.subtle.importKey(
			'raw',
			fromBase64Url(v.rawKeyBase64Url),
			'AES-GCM',
			false,
			['decrypt'],
		)
		expect(
			new TextDecoder().decode(await aesDecrypt(key, v.encryptedPayload)),
		).toBe(v.payload)
		expect(
			sendLink(v.publicBase, v.token, fromBase64Url(v.rawKeyBase64Url)),
		).toBe(v.link)
		expect(sendLink(v.publicBase, v.token, null)).toBe(v.linkWithoutKey)
	})

	it('unwraps a password Send with the real Argon2id and opens it', async () => {
		const { deriveAesKeyArgon2id } = await import('../../src/crypto/argon2.js')
		const v = send.withPassword
		const kek = await deriveAesKeyArgon2id(
			v.password,
			fromBase64(v.argon2idSalt),
		)
		const rawKey = await aesDecrypt(kek, v.wrappedKey)
		expect(toBase64Url(rawKey)).toBe(v.rawKeyBase64Url)
		const key = await crypto.subtle.importKey('raw', rawKey, 'AES-GCM', false, [
			'decrypt',
		])
		expect(
			new TextDecoder().decode(await aesDecrypt(key, v.encryptedPayload)),
		).toBe(v.payload)
	})
})

describe('crypto vectors: TOTP', () => {
	for (const c of totp.cases) {
		it(`${c.algorithm} ${c.digits} digits at ${c.epochMs} for ${c.uri.slice(0, 40)}`, async () => {
			const params = parseOtpauth(c.uri)
			expect([params.algorithm, params.digits, params.period]).toEqual([
				c.algorithm,
				c.digits,
				c.period,
			])
			expect(await generateTotp(params, c.epochMs)).toBe(c.code)
		})
	}

	for (const r of totp.refused) {
		it(`refuses ${r.uri}`, () => {
			expect(() => parseOtpauth(r.uri)).toThrow(r.webError)
		})
	}
})

describe('crypto vectors: passkey', () => {
	it('keeps the item JSON stable through parse and serialise', () => {
		expect(serializePasskey(parsePasskey(passkey.itemJson))).toBe(
			passkey.itemJson,
		)
	})

	for (const a of passkey.assertions) {
		it(`verifies the ES256 assertion for stored counter ${a.storedCounter}`, async () => {
			const authData = fromBase64(a.authenticatorData)
			const clientData = fromBase64(a.clientDataJSON)
			const counter = new DataView(authData.buffer).getUint32(33, false)
			expect(counter).toBe(a.nextCounter)
			const key = await crypto.subtle.importKey(
				'spki',
				fromBase64(passkey.publicKeySpki),
				{ name: 'ECDSA', namedCurve: 'P-256' },
				false,
				['verify'],
			)
			const hash = new Uint8Array(
				await crypto.subtle.digest('SHA-256', clientData),
			)
			const signed = new Uint8Array(authData.length + hash.length)
			signed.set(authData, 0)
			signed.set(hash, authData.length)
			const ok = await crypto.subtle.verify(
				{ name: 'ECDSA', hash: 'SHA-256' },
				key,
				derToRaw(fromBase64(a.signatureDer)),
				signed,
			)
			expect(ok).toBe(true)
		})
	}
})

/**
 * Convert a DER ECDSA signature to the raw r||s form WebCrypto verifies.
 *
 * @param {Uint8Array} der SEQUENCE(INTEGER r, INTEGER s).
 * @return {Uint8Array} 64 bytes.
 * @spec openspec/changes/clients-mobile-apps/tasks.md#1.2
 */
function derToRaw(der) {
	const out = new Uint8Array(64)
	let pos = 2
	for (let i = 0; i < 2; i++) {
		const len = der[pos + 1]
		let int = der.slice(pos + 2, pos + 2 + len)
		while (int.length > 32 && int[0] === 0) {
			int = int.slice(1)
		}
		out.set(int, 32 * (i + 1) - int.length)
		pos += 2 + len
	}
	return out
}
