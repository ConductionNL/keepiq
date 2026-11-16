/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for `src/ssh/sshKey.js`: the SSH key item's parsing, derived
 * fingerprint and in-browser generation.
 *
 * The fingerprint vectors come from phpseclib (an implementation independent
 * of the module under test). Private keys are generated at test time with
 * Node's OpenSSL-backed `crypto`, so no private key is committed.
 *
 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-ssh-key-item/spec.md#requirement-ssh-key-item-form
 */

import { generateKeyPairSync } from 'node:crypto'
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
	fingerprintOf,
	generateKeyPair,
	isPublicKey,
	parsePrivateKey,
	parsePublicKey,
	samePublicKey,
	SshKeyError,
} from '../../src/ssh/sshKey.js'
import vectors from '../fixtures/ssh-key-vectors.json'

/**
 * The OpenSSH ssh-ed25519 public key line built by hand from a raw key.
 *
 * @param {Buffer} raw The 32-byte public key.
 * @return {string}
 */
function ed25519Line(raw) {
	const type = Buffer.from('ssh-ed25519')
	const blob = Buffer.concat([
		Buffer.from([0, 0, 0, type.length]),
		type,
		Buffer.from([0, 0, 0, 32]),
		raw,
	])
	return `ssh-ed25519 ${blob.toString('base64')}`
}

afterEach(() => {
	vi.restoreAllMocks()
})

describe('fingerprintOf', () => {
	it.each(['ed25519', 'rsa', 'ec'])(
		'matches the independently computed SHA256 fingerprint of the %s vector',
		async (name) => {
			expect(await fingerprintOf(vectors[name].publicKey)).toBe(
				vectors[name].fingerprint,
			)
		},
	)

	it('ignores the comment', async () => {
		const bare = vectors.ed25519.publicKey.split(' ').slice(0, 2).join(' ')
		expect(await fingerprintOf(bare)).toBe(vectors.ed25519.fingerprint)
	})

	it('refuses text that is not a public key', async () => {
		await expect(fingerprintOf('hello world')).rejects.toBeInstanceOf(
			SshKeyError,
		)
	})
})

describe('parsePublicKey', () => {
	it('reads the type, blob and comment', () => {
		const parsed = parsePublicKey(vectors.ed25519.publicKey)
		expect(parsed.type).toBe('ssh-ed25519')
		expect(parsed.blob.length).toBe(51)
		expect(parsed.comment).toBe('vector@keepiq')
	})

	it('refuses a line whose blob names another type', () => {
		const body = vectors.ed25519.publicKey.split(' ')[1]
		expect(() => parsePublicKey(`ssh-rsa ${body}`)).toThrow(SshKeyError)
	})

	it('refuses a truncated blob', () => {
		const body = vectors.ed25519.publicKey.split(' ')[1]
		expect(isPublicKey(`ssh-ed25519 ${body.slice(0, 40)}`)).toBe(false)
	})

	it('compares keys by blob, not by comment', () => {
		const bare = vectors.rsa.publicKey.split(' ').slice(0, 2).join(' ')
		expect(samePublicKey(bare + ' other', vectors.rsa.publicKey)).toBe(true)
		expect(samePublicKey(vectors.ed25519.publicKey, vectors.rsa.publicKey)).toBe(
			false,
		)
	})
})

describe('parsePrivateKey', () => {
	it('derives the public key of a PKCS #8 Ed25519 key', async () => {
		const { privateKey, publicKey } = generateKeyPairSync('ed25519')
		const pem = privateKey.export({ type: 'pkcs8', format: 'pem' })
		const raw = publicKey.export({ type: 'spki', format: 'der' }).subarray(-32)

		expect((await parsePrivateKey(pem)).publicKey).toBe(ed25519Line(raw))
	})

	it('derives the same RSA public key from PKCS #1 and PKCS #8', async () => {
		const { privateKey, publicKey } = generateKeyPairSync('rsa', {
			modulusLength: 2048,
		})
		const pkcs1 = await parsePrivateKey(
			privateKey.export({ type: 'pkcs1', format: 'pem' }),
		)
		const pkcs8 = await parsePrivateKey(
			privateKey.export({ type: 'pkcs8', format: 'pem' }),
		)

		expect(pkcs1.publicKey).toBe(pkcs8.publicKey)
		expect(pkcs1.publicKey.startsWith('ssh-rsa AAAAB3NzaC1yc2E')).toBe(true)
		// The modulus in the blob is the key's own.
		const modulus = Buffer.from(
			publicKey.export({ format: 'jwk' }).n,
			'base64url',
		)
		const blob = Buffer.from(parsePublicKey(pkcs1.publicKey).blob)
		expect(blob.includes(modulus)).toBe(true)
	})

	it('derives an ECDSA P-256 public key', async () => {
		const { privateKey } = generateKeyPairSync('ec', { namedCurve: 'P-256' })
		const parsed = await parsePrivateKey(
			privateKey.export({ type: 'pkcs8', format: 'pem' }),
		)
		expect(parsePublicKey(parsed.publicKey).type).toBe('ecdsa-sha2-nistp256')
	})

	it('accepts an encrypted PKCS #8 key without deriving its public key', async () => {
		const { privateKey } = generateKeyPairSync('ed25519')
		const pem = privateKey.export({
			type: 'pkcs8',
			format: 'pem',
			cipher: 'aes-256-cbc',
			passphrase: 'test only',
		})
		expect(await parsePrivateKey(pem)).toEqual({ publicKey: null })
	})

	it.each([
		['plain text', 'not a key at all'],
		['a public key', vectors.ed25519.publicKey],
		[
			'a certificate',
			'-----BEGIN CERTIFICATE-----\nAAAA\n-----END CERTIFICATE-----',
		],
		[
			'armour around junk',
			'-----BEGIN PRIVATE KEY-----\nAAAAAAAA\n-----END PRIVATE KEY-----',
		],
		[
			'a broken OpenSSH key',
			'-----BEGIN OPENSSH PRIVATE KEY-----\nb3BlbnNzaC1rZXk=\n-----END OPENSSH PRIVATE KEY-----',
		],
	])('refuses %s', async (_label, text) => {
		await expect(parsePrivateKey(text)).rejects.toBeInstanceOf(SshKeyError)
	})
})

describe('generateKeyPair', () => {
	it('generates an Ed25519 pair whose OpenSSH private key gives back its public key', async () => {
		const pair = await generateKeyPair({ comment: 'me@keepiq' })

		expect(pair.algorithm).toBe('ssh-ed25519')
		expect(
			pair.privateKey.startsWith('-----BEGIN OPENSSH PRIVATE KEY-----\n'),
		).toBe(true)
		expect(parsePublicKey(pair.publicKey)).toMatchObject({
			type: 'ssh-ed25519',
			comment: 'me@keepiq',
		})
		expect(pair.fingerprint).toBe(await fingerprintOf(pair.publicKey))
		expect(pair.fingerprint).toMatch(/^SHA256:[A-Za-z0-9+/]{43}$/)
		const derived = await parsePrivateKey(pair.privateKey)
		expect(samePublicKey(derived.publicKey, pair.publicKey)).toBe(true)
	})

	it('falls back to RSA 4096 when the browser has no Ed25519', async () => {
		const original = crypto.subtle.generateKey.bind(crypto.subtle)
		vi.spyOn(crypto.subtle, 'generateKey').mockImplementation(
			(algorithm, ...rest) =>
				algorithm.name === 'Ed25519'
					? Promise.reject(
							new DOMException('Unsupported', 'NotSupportedError'),
						)
					: original(algorithm, ...rest),
		)

		const pair = await generateKeyPair()

		expect(pair.algorithm).toBe('ssh-rsa')
		// 4096-bit modulus: 512 bytes plus a leading zero in the mpint.
		const blob = parsePublicKey(pair.publicKey).blob
		expect(blob.length).toBeGreaterThan(530)
		const derived = await parsePrivateKey(pair.privateKey)
		expect(samePublicKey(derived.publicKey, pair.publicKey)).toBe(true)
	})
})
