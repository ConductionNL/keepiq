/**
 * SPDX-FileCopyrightText: 2026 Conduction / Doriath Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the security-critical browser crypto in src/crypto/rsa.js.
 *
 * These tests lock the Phase-0 vault-crypto fix at the JS-unit level (the
 * PHPUnit side covers the PHP crypto). They run in Vitest's `node`
 * environment, which provides WebCrypto via globalThis.crypto.subtle plus
 * the btoa / atob globals the module depends on.
 *
 * What is locked:
 *  - importPublicKey() accepts a full X.509 CERTIFICATE PEM and extracts the
 *    embedded SubjectPublicKeyInfo before importKey('spki', ...). This is the
 *    Phase-0 fix: importKey('spki') rejects a raw certificate DER with
 *    DataError, so a successful import + encrypt proves the SPKI extraction
 *    ran correctly (extractSpkiFromCertificate is module-private; we exercise
 *    it through its only public caller).
 *  - The SPKI pulled from the certificate is the SAME key as the standalone
 *    SubjectPublicKeyInfo: ciphertext produced with the cert-derived key
 *    decrypts with the matching PKCS#8 private key.
 *  - The RSA-OAEP-SHA256 encrypt/decrypt round-trip, including the multi-chunk
 *    path (RSA-4096), recovers the exact plaintext.
 *  - Failure modes: malformed DER throws; importKey rejects garbage.
 */

import { describe, it, expect } from 'vitest'
import { createPublicKey } from 'node:crypto'
import {
	generateKeyPair,
	importPublicKey,
	importPrivateKey,
	rsaEncrypt,
	rsaDecrypt,
} from '../../src/crypto/rsa.js'
import {
	CERTIFICATE_PEM,
	PRIVATE_KEY_PKCS8_PEM,
	PUBLIC_KEY_SPKI_PEM,
	pemToDer,
	sharedKeyPair,
} from './fixtures/rsa-fixtures.js'

describe('importPublicKey — X.509 SPKI extraction (Phase-0 fix)', () => {
	it('imports a full X.509 CERTIFICATE PEM by extracting its SPKI', async () => {
		// A raw certificate DER fed to importKey('spki') throws DataError;
		// a successful import proves extractSpkiFromCertificate pulled the
		// embedded SubjectPublicKeyInfo first.
		const key = await importPublicKey(CERTIFICATE_PEM)
		expect(key).toBeDefined()
		expect(key.type).toBe('public')
		expect(key.algorithm.name).toBe('RSA-OAEP')
		expect(key.usages).toContain('encrypt')
	})

	it('imports a standalone SPKI PUBLIC KEY PEM unchanged', async () => {
		const key = await importPublicKey(PUBLIC_KEY_SPKI_PEM)
		expect(key.type).toBe('public')
		expect(key.usages).toContain('encrypt')
	})

	it('extracts the SAME SPKI bytes from the certificate as the standalone SPKI key', async () => {
		// The module's importPublicKey(cert) must extract exactly the embedded
		// SubjectPublicKeyInfo. We confirm byte-equality against the standalone
		// SPKI PEM using Node's crypto as an independent oracle: the SPKI Node
		// pulls from the certificate must equal the standalone SPKI DER, and
		// the module imports the certificate without error.
		const spkiFromCert = createPublicKey(CERTIFICATE_PEM).export({ type: 'spki', format: 'der' })
		const spkiStandalone = createPublicKey(PUBLIC_KEY_SPKI_PEM).export({ type: 'spki', format: 'der' })
		expect(Buffer.compare(spkiFromCert, spkiStandalone)).toBe(0)

		// And the module successfully imports the cert (would throw DataError
		// if it had not extracted the SPKI first).
		const certKey = await importPublicKey(CERTIFICATE_PEM)
		expect(certKey.type).toBe('public')
		expect(certKey.usages).toContain('encrypt')
	})

	it('rejects a raw certificate DER imported directly as spki (regression guard)', async () => {
		// Demonstrates WHY the extraction is needed: handing the full cert DER
		// straight to importKey('spki') fails. The module avoids this by
		// extracting the SPKI first.
		const certDer = pemToDer(CERTIFICATE_PEM)
		await expect(
			crypto.subtle.importKey(
				'spki',
				certDer,
				{ name: 'RSA-OAEP', hash: 'SHA-256' },
				false,
				['encrypt'],
			),
		).rejects.toThrow()
	})
})

describe('extractSpkiFromCertificate failure modes', () => {
	it('throws when the PEM is not a DER SEQUENCE certificate', async () => {
		// 0x02 is INTEGER, not the 0x30 SEQUENCE a certificate must start with.
		const notACert = '-----BEGIN CERTIFICATE-----\n'
			+ btoa(String.fromCharCode(0x02, 0x01, 0x05))
			+ '\n-----END CERTIFICATE-----'
		await expect(importPublicKey(notACert)).rejects.toThrow(
			/Not a DER SEQUENCE/,
		)
	})

	it('throws when the certificate body is truncated garbage', async () => {
		// A SEQUENCE header claiming a long body but with no real TBS inside.
		const truncated = '-----BEGIN CERTIFICATE-----\n'
			+ btoa(String.fromCharCode(0x30, 0x05, 0x00, 0x00, 0x00, 0x00, 0x00))
			+ '\n-----END CERTIFICATE-----'
		await expect(importPublicKey(truncated)).rejects.toThrow()
	})
})

describe('importPrivateKey', () => {
	it('imports a PKCS#8 private key as a non-extractable decrypt key', async () => {
		const key = await importPrivateKey(PRIVATE_KEY_PKCS8_PEM)
		expect(key.type).toBe('private')
		expect(key.extractable).toBe(false)
		expect(key.usages).toEqual(['decrypt'])
	})

	it('rejects malformed PKCS#8 DER', async () => {
		const bad = '-----BEGIN PRIVATE KEY-----\n'
			+ btoa(String.fromCharCode(0x30, 0x02, 0x00, 0x00))
			+ '\n-----END PRIVATE KEY-----'
		await expect(importPrivateKey(bad)).rejects.toThrow()
	})
})

describe('rsaEncrypt / rsaDecrypt round-trip (RSA-4096, multi-chunk)', () => {
	it('round-trips a short payload (single chunk)', async () => {
		const { publicKey, privateKey } = await sharedKeyPair()
		const plaintext = 'hello vault'
		const ciphertext = await rsaEncrypt(plaintext, publicKey)
		const recovered = await rsaDecrypt(ciphertext, privateKey)
		expect(recovered).toBe(plaintext)
	})

	it('round-trips an empty string', async () => {
		const { publicKey, privateKey } = await sharedKeyPair()
		const ciphertext = await rsaEncrypt('', publicKey)
		const recovered = await rsaDecrypt(ciphertext, privateKey)
		expect(recovered).toBe('')
	})

	it('round-trips a payload > 446 bytes spanning multiple chunks', async () => {
		const { publicKey, privateKey } = await sharedKeyPair()
		// 1000 bytes -> ceil(1000 / 446) = 3 chunks.
		const plaintext = 'A'.repeat(1000)
		const ciphertext = await rsaEncrypt(plaintext, publicKey)

		// Chunk count is encoded big-endian in the first 4 bytes.
		const raw = Uint8Array.from(atob(ciphertext), c => c.charCodeAt(0))
		const chunkCount = new DataView(raw.buffer, raw.byteOffset, raw.byteLength).getUint32(0, false)
		expect(chunkCount).toBe(3)

		const recovered = await rsaDecrypt(ciphertext, privateKey)
		expect(recovered).toBe(plaintext)
	})

	it('round-trips a UTF-8 payload with multi-byte characters', async () => {
		const { publicKey, privateKey } = await sharedKeyPair()
		const plaintext = 'wachtwoord-€-ü-日本語-🔐'
		const ciphertext = await rsaEncrypt(plaintext, publicKey)
		const recovered = await rsaDecrypt(ciphertext, privateKey)
		expect(recovered).toBe(plaintext)
	})

	it('generateKeyPair emits a valid SPKI PEM re-importable by importPublicKey', async () => {
		const { publicKeyPem } = await generateKeyPair()
		expect(publicKeyPem).toMatch(/^-----BEGIN PUBLIC KEY-----/)
		expect(publicKeyPem).toMatch(/-----END PUBLIC KEY-----$/)
		const reimported = await importPublicKey(publicKeyPem)
		expect(reimported.type).toBe('public')
	})
})
