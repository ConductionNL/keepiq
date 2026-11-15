/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The exported crypto parameters are the values the crypto functions actually
 * use. Each test spies on the boundary (WebCrypto / the Argon2 module) while
 * the real function runs, and compares what was passed with the exported
 * parameters — so a constant changed in the module changes `CRYPTO_PARAMETERS`
 * and therefore the encryption overview, and an exported value that drifts
 * from the code fails here.
 *
 * @spec openspec/changes/crypto-vault-encryption-details/specs/vault-encryption-details/spec.md#requirement-the-user-sees-what-protects-the-vault
 */

import { afterEach, describe, expect, it, vi } from 'vitest'
import {
	CONTENT_KEY_PARAMETERS,
	deriveAesKey,
	deriveUnlockKeyRaw,
	MASTER_KEY_PARAMETERS,
} from '../../src/crypto/aes.js'
import {
	ARGON2_PARAMETERS,
	deriveAesKeyArgon2id,
} from '../../src/crypto/argon2.js'
import { CRYPTO_PARAMETERS, technicalNames } from '../../src/crypto/parameters.js'
import { generateKeyPair, RSA_PARAMETERS } from '../../src/crypto/rsa.js'

const argon2Calls = vi.hoisted(() => [])

vi.mock('argon2-browser', async (importOriginal) => {
	const real = await importOriginal()
	return {
		...real,
		hash: (options) => {
			argon2Calls.push(options)
			return real.hash(options)
		},
	}
})

afterEach(() => {
	vi.restoreAllMocks()
	argon2Calls.length = 0
})

describe('RSA_PARAMETERS', () => {
	it('match the key pair generateKeyPair() asks WebCrypto for', async () => {
		const spy = vi.spyOn(crypto.subtle, 'generateKey')
		await generateKeyPair()
		const [algorithm] = spy.mock.calls[0]
		expect(algorithm.name).toBe(RSA_PARAMETERS.algorithm)
		expect(algorithm.hash).toBe(RSA_PARAMETERS.hash)
		expect(algorithm.modulusLength).toBe(RSA_PARAMETERS.keyBits)
		const exponent = [...algorithm.publicExponent].reduce(
			(acc, byte) => acc * 256 + byte,
			0,
		)
		expect(exponent).toBe(RSA_PARAMETERS.publicExponent)
	}, 60000)

	it('are frozen and describe RSA-OAEP with SHA-256 under 4096-bit keys', () => {
		expect(Object.isFrozen(RSA_PARAMETERS)).toBe(true)
		expect(RSA_PARAMETERS).toEqual({
			algorithm: 'RSA-OAEP',
			hash: 'SHA-256',
			keyBits: 4096,
			publicExponent: 65537,
		})
	})
})

describe('MASTER_KEY_PARAMETERS', () => {
	it('match the PBKDF2 derivation and AES key of deriveAesKey()', async () => {
		const spy = vi.spyOn(crypto.subtle, 'deriveKey')
		await deriveAesKey('correct horse', new Uint8Array(16))
		const [kdf, , derived] = spy.mock.calls[0]
		expect(kdf.name).toBe(MASTER_KEY_PARAMETERS.kdf)
		expect(kdf.hash).toBe(MASTER_KEY_PARAMETERS.kdfHash)
		expect(kdf.iterations).toBe(MASTER_KEY_PARAMETERS.iterations)
		expect(derived).toEqual({
			name: MASTER_KEY_PARAMETERS.cipher,
			length: MASTER_KEY_PARAMETERS.keyBits,
		})
	})

	it('match the PBKDF2 derivation of deriveUnlockKeyRaw()', async () => {
		const spy = vi.spyOn(crypto.subtle, 'deriveBits')
		await deriveUnlockKeyRaw('correct horse', new Uint8Array(16))
		const [kdf] = spy.mock.calls[0]
		expect(kdf.iterations).toBe(MASTER_KEY_PARAMETERS.iterations)
		expect(kdf.hash).toBe(MASTER_KEY_PARAMETERS.kdfHash)
	})

	it('describe AES-256-GCM under PBKDF2-SHA256 at 600,000 iterations', () => {
		expect(MASTER_KEY_PARAMETERS).toMatchObject({
			cipher: 'AES-GCM',
			keyBits: 256,
			kdf: 'PBKDF2',
			kdfHash: 'SHA-256',
			iterations: 600000,
		})
	})
})

describe('ARGON2_PARAMETERS', () => {
	it('match the Argon2id call and the AES key of deriveAesKeyArgon2id()', async () => {
		const spy = vi.spyOn(crypto.subtle, 'importKey')
		await deriveAesKeyArgon2id('link password', new Uint8Array(16))
		expect(argon2Calls).toHaveLength(1)
		const [options] = argon2Calls
		expect(options.mem).toBe(ARGON2_PARAMETERS.memoryKiB)
		expect(options.time).toBe(ARGON2_PARAMETERS.iterations)
		expect(options.parallelism).toBe(ARGON2_PARAMETERS.parallelism)
		expect(options.hashLen * 8).toBe(ARGON2_PARAMETERS.keyBits)
		const [, , algorithm] = spy.mock.calls.at(-1)
		expect(algorithm).toEqual({
			name: ARGON2_PARAMETERS.cipher,
			length: ARGON2_PARAMETERS.keyBits,
		})
	})

	it('describe Argon2id with 64 MiB, 3 iterations and parallelism 1', () => {
		expect(ARGON2_PARAMETERS).toMatchObject({
			kdf: 'Argon2id',
			memoryKiB: 65536,
			iterations: 3,
			parallelism: 1,
			keyBits: 256,
		})
	})
})

describe('CRYPTO_PARAMETERS', () => {
	it('assembles the module exports, not copies of them', () => {
		expect(CRYPTO_PARAMETERS.secretValues).toBe(RSA_PARAMETERS)
		expect(CRYPTO_PARAMETERS.privateKey).toBe(MASTER_KEY_PARAMETERS)
		expect(CRYPTO_PARAMETERS.passwordProtected).toBe(ARGON2_PARAMETERS)
		expect(CRYPTO_PARAMETERS.attachments).toEqual({
			cipher: CONTENT_KEY_PARAMETERS.name,
			keyBits: CONTENT_KEY_PARAMETERS.length,
			keyWrap: RSA_PARAMETERS.algorithm,
			keyWrapBits: RSA_PARAMETERS.keyBits,
		})
		expect(Object.isFrozen(CRYPTO_PARAMETERS)).toBe(true)
	})

	it('carries no key material', () => {
		const json = JSON.stringify(CRYPTO_PARAMETERS)
		expect(json).not.toMatch(/PRIVATE KEY|BEGIN|salt"|"iv"/i)
	})
})

describe('technicalNames()', () => {
	it('names each scheme from the code parameters', () => {
		expect(technicalNames()).toEqual({
			secretValues: 'RSA-OAEP, SHA-256, 4096-bit keys',
			privateKey: 'AES-256-GCM, PBKDF2-SHA256, 600,000 iterations',
			attachments: 'AES-256-GCM per file, key wrapped with RSA-OAEP 4096-bit',
			passwordProtected:
				'Argon2id (memory 64 MiB, 3 iterations, parallelism 1), AES-256-GCM',
		})
	})

	it('follows a changed parameter without other edits', () => {
		const raised = {
			...CRYPTO_PARAMETERS,
			privateKey: { ...MASTER_KEY_PARAMETERS, iterations: 1200000 },
		}
		expect(technicalNames(raised).privateKey).toBe(
			'AES-256-GCM, PBKDF2-SHA256, 1,200,000 iterations',
		)
	})
})
