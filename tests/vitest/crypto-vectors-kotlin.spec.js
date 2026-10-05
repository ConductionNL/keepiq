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
import { getAssertion } from '../../browser-extension/src/passkey/webauthn.js'
import { decryptPrivateKey } from '../../src/crypto/aes.js'
import { importPrivateKey, rsaDecrypt } from '../../src/crypto/rsa.js'
import { parsePasskey, serializePasskey } from '../../src/passkey/passkey.js'
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
const passkeyVector = JSON.parse(readFileSync(join(VECTORS, 'passkey.json'), 'utf8'))

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

describe('passkeys between the phone and the browser extension (task 5.3)', () => {
	it("signs in with a passkey the core created, using the extension's own signing code", async () => {
		const r = kotlin.passkeyRegistration
		const record = parsePasskey(r.itemJson)
		expect(record).not.toBeNull()
		expect(serializePasskey(record)).toBe(r.itemJson)
		expect(record.counter).toBe(0)
		expect(fromBase64Url(record.credentialId)).toHaveLength(16)

		const clientData = JSON.parse(
			new TextDecoder().decode(fromBase64(r.clientDataJSON)),
		)
		expect(clientData).toEqual({
			type: 'webauthn.create',
			challenge: r.challengeBase64Url,
			origin: r.origin,
			crossOrigin: false,
		})
		const { authData, publicKey } = await readAttestation(
			fromBase64(r.attestationObject),
		)
		expect(authData[32]).toBe(0x45)
		expect(Array.from(authData.slice(37, 53))).toEqual(new Array(16).fill(0))
		expect(Array.from(authData.slice(55, 71))).toEqual(
			Array.from(fromBase64Url(record.credentialId)),
		)

		// The extension imports the phone's PKCS#8 key and signs; the public key from the attestation verifies it.
		const { assertion } = await getAssertion(
			{ challenge: r.challengeBase64Url, rpId: record.rpId },
			r.origin,
			record,
		)
		expect(
			await verify(
				publicKey,
				Uint8Array.from(assertion.response.authenticatorData),
				Uint8Array.from(assertion.response.clientDataJSON),
				Uint8Array.from(assertion.response.signature),
			),
		).toBe(true)
	})

	it('verifies what the core signed with a passkey the extension created', async () => {
		const { publicKey } = await readAttestation(
			fromBase64(passkeyVector.registration.attestationObject),
		)
		const a = kotlin.passkeyFromExtension
		expect(
			await verify(
				publicKey,
				fromBase64(a.authenticatorData),
				fromBase64(a.clientDataJSON),
				fromBase64(a.signatureDer),
			),
		).toBe(true)
	})
})

/**
 * The authenticator data and the public key of a `none` attestation object
 * in the shape webauthn.js createCredential writes (cbor.js key order).
 *
 * @param {Uint8Array} att The attestation object.
 * @return {Promise<{authData: Uint8Array, publicKey: CryptoKey}>}
 * @spec openspec/changes/clients-mobile-apps/tasks.md#5.3
 */
async function readAttestation(att) {
	const head = '\xa3\x63fmt\x64none\x67attStmt\xa0\x68authData\x58'
	expect(String.fromCharCode(...att.slice(0, head.length))).toBe(head)
	const authData = att.slice(head.length + 1)
	expect(authData).toHaveLength(att[head.length])
	const idLength = (authData[53] << 8) | authData[54]
	const cose = authData.slice(55 + idLength)
	// {1: 2, 3: -7, -1: 1, -2: x, -3: y}
	expect(Array.from(cose.slice(0, 10))).toEqual([
		0xa5, 0x01, 0x02, 0x03, 0x26, 0x20, 0x01, 0x21, 0x58, 0x20,
	])
	expect(Array.from(cose.slice(42, 45))).toEqual([0x22, 0x58, 0x20])
	const raw = new Uint8Array(65)
	raw[0] = 0x04
	raw.set(cose.slice(10, 42), 1)
	raw.set(cose.slice(45, 77), 33)
	const publicKey = await crypto.subtle.importKey(
		'raw',
		raw,
		{ name: 'ECDSA', namedCurve: 'P-256' },
		false,
		['verify'],
	)
	return { authData, publicKey }
}

/**
 * Verify a DER ES256 signature over authenticatorData + SHA-256(clientDataJSON).
 *
 * @param {CryptoKey} key The public key.
 * @param {Uint8Array} authData The authenticator data.
 * @param {Uint8Array} clientDataJSON The client data.
 * @param {Uint8Array} signatureDer The DER signature.
 * @return {Promise<boolean>}
 * @spec openspec/changes/clients-mobile-apps/tasks.md#5.3
 */
async function verify(key, authData, clientDataJSON, signatureDer) {
	const hash = new Uint8Array(
		await crypto.subtle.digest('SHA-256', clientDataJSON),
	)
	const signed = new Uint8Array(authData.length + hash.length)
	signed.set(authData, 0)
	signed.set(hash, authData.length)
	return crypto.subtle.verify(
		{ name: 'ECDSA', hash: 'SHA-256' },
		key,
		derToRaw(signatureDer),
		signed,
	)
}

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
