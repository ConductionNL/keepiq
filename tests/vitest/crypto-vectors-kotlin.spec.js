/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The web app reads what the phone wrote: the native apps' Kotlin core
 * (mobile/shared, KotlinVectorsWriterTest) encrypts the vector inputs, and
 * this file opens the result with the web app's own modules.
 *
 * By default it reads the committed copy, tests/vectors/crypto/kotlin-output.json.
 * The mobile CI workflow sets KEEPIQ_KOTLIN_VECTORS to the file the Kotlin
 * tests wrote in that same run, so a format drift in the core fails here.
 *
 * @spec openspec/changes/clients-mobile-apps/specs/mobile-shared-core/spec.md#requirement-crypto-byte-compatible-with-the-web-app
 */

import { readFileSync } from 'node:fs'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it, vi } from 'vitest'
import { decryptPrivateKey } from '../../src/crypto/aes.js'
import { importPrivateKey, rsaDecrypt } from '../../src/crypto/rsa.js'
import { aesDecrypt, fromBase64, fromBase64Url } from '../../src/send/sendCrypto.js'

vi.mock('argon2-browser', async () => {
	const real = (await import('argon2-browser/lib/argon2.js')).default
	return { ...real, default: real }
})
// The emscripten loader prefers fetch() for the .wasm path, which node refuses
// for a bare file path; without fetch it reads the file from disk.
vi.stubGlobal('fetch', undefined)

const HERE = dirname(fileURLToPath(import.meta.url))
const VECTORS = join(HERE, '..', 'vectors', 'crypto')
const kotlinFile = process.env.KEEPIQ_KOTLIN_VECTORS
	? resolve(process.env.KEEPIQ_KOTLIN_VECTORS)
	: join(VECTORS, 'kotlin-output.json')
const kotlin = JSON.parse(readFileSync(kotlinFile, 'utf8'))
const envelope = JSON.parse(readFileSync(join(VECTORS, 'envelope.json'), 'utf8'))

/**
 * Import a raw AES-GCM key for decryption.
 *
 * @param {Uint8Array} raw The 32 key bytes.
 * @return {Promise<CryptoKey>}
 * @spec openspec/changes/clients-mobile-apps/tasks.md#1.4
 */
function aesKey(raw) {
	return crypto.subtle.importKey('raw', raw, 'AES-GCM', false, ['decrypt'])
}

describe('Kotlin core output opened by the web app', () => {
	it('decrypts every field the core encrypted, byte for byte', async () => {
		const privateKey = await importPrivateKey(envelope.privateKeyPem)
		expect(kotlin.fields.length).toBeGreaterThanOrEqual(6)
		const multibyte = kotlin.fields.find(
			(f) => f.name === 'multibyte across a chunk boundary',
		)
		expect(multibyte).toBeDefined()
		expect(
			new DataView(fromBase64(multibyte.ciphertext).buffer).getUint32(
				0,
				false,
			),
		).toBeGreaterThan(1)
		for (const f of kotlin.fields) {
			const plain = await rsaDecrypt(f.ciphertext, privateKey)
			expect(
				Buffer.from(plain, 'utf8').equals(Buffer.from(f.plaintext, 'utf8')),
			).toBe(true)
		}
	})

	it('opens the private-key envelope the core sealed', async () => {
		expect(
			await decryptPrivateKey(
				kotlin.envelope.envelope,
				kotlin.envelope.password,
			),
		).toBe(kotlin.envelope.privateKeyPem)
	})

	it('opens a Send without a password from the link the core built', async () => {
		const v = kotlin.sendWithoutPassword
		const key = await aesKey(fromBase64Url(v.link.split('#k=')[1]))
		expect(
			new TextDecoder().decode(await aesDecrypt(key, v.encryptedPayload)),
		).toBe(v.payload)
	})

	it('unwraps and opens a password Send the core made, with the real Argon2id', async () => {
		const { deriveAesKeyArgon2id } = await import('../../src/crypto/argon2.js')
		const v = kotlin.sendWithPassword
		const kek = await deriveAesKeyArgon2id(
			v.password,
			fromBase64(v.argon2idSalt),
		)
		const rawKey = await aesDecrypt(kek, v.wrappedKey)
		expect(
			new TextDecoder().decode(
				await aesDecrypt(await aesKey(rawKey), v.encryptedPayload),
			),
		).toBe(v.payload)
	})

	it('verifies the ES256 assertion the core signed', async () => {
		const a = kotlin.passkeyAssertion
		const key = await crypto.subtle.importKey(
			'spki',
			fromBase64(a.publicKeySpki),
			{ name: 'ECDSA', namedCurve: 'P-256' },
			false,
			['verify'],
		)
		const authData = fromBase64(a.authenticatorData)
		const hash = new Uint8Array(
			await crypto.subtle.digest('SHA-256', fromBase64(a.clientDataJSON)),
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
})

/**
 * Convert a DER ECDSA signature to the raw r||s form WebCrypto verifies.
 *
 * @param {Uint8Array} der SEQUENCE(INTEGER r, INTEGER s).
 * @return {Uint8Array} 64 bytes.
 * @spec openspec/changes/clients-mobile-apps/tasks.md#1.4
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
