/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The owner's browser verifies a federated recipient's certificate before
 * encrypting for it (keepiq#789, sharing-federated-recipients task 2.3).
 * Fixtures are real openssl certificates from
 * tests/fixtures/generate-federation-chain.sh: two CAs with identical
 * names, as every Keepiq instance names its CA alike, so only the pinned
 * fingerprint and the signatures tell them apart.
 *
 * @spec openspec/specs/federated-sharing/spec.md#requirement-certificate-lookup-is-signed-allowlisted-and-verified-in-the-browser
 */

import { createPublicKey } from 'node:crypto'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import {
	FederatedCertificateError,
	verifyFederatedCertificate,
} from '../../src/crypto/federatedCertificate.js'
import { derElement, otherKeyText, toBase64 } from './fixtures/pem-extra-text.js'
import { pemToDer } from './fixtures/rsa-fixtures.js'

const F = JSON.parse(
	readFileSync(resolve(__dirname, '../fixtures/federation-chain.json'), 'utf8'),
)

const BOB = 'bob@cloud.partner.example'

/**
 * Verify with the partner A chain and pin unless overridden.
 *
 * @param {object} overrides Fields to replace.
 * @return {Promise<object>}
 */
function verify(overrides = {}) {
	return verifyFederatedCertificate({
		certificate: F.bob,
		chain: [F.intermediateA, F.rootA],
		partnerRootFingerprint: F.rootAFingerprint,
		cloudId: BOB,
		...overrides,
	})
}

/**
 * The refusal reason of a verification.
 *
 * @param {Promise<object>} pending The verification.
 * @return {Promise<string>}
 */
async function reasonOf(pending) {
	try {
		await pending
	} catch (e) {
		expect(e).toBeInstanceOf(FederatedCertificateError)
		return e.reason
	}
	throw new Error('the certificate was accepted')
}

describe('verifyFederatedCertificate', () => {
	it('accepts a certificate that chains to the pinned root and names the recipient', async () => {
		const { fingerprint } = await verify()

		expect(fingerprint).toMatch(/^([0-9A-F]{2}:){31}[0-9A-F]{2}$/)
	})

	it('accepts a renewal signed with RSASSA-PSS, as phpseclib 3 signed them', async () => {
		await expect(verify({ certificate: F.bobPss })).resolves.toHaveProperty(
			'fingerprint',
		)
	})

	it('refuses a chain that ends at another root', async () => {
		expect(
			await reasonOf(
				verify({
					certificate: F.bobOtherRoot,
					chain: [F.intermediateB, F.rootB],
				}),
			),
		).toBe('untrusted_root')
	})

	it('refuses a certificate the pinned chain did not sign, even with the same issuer name', async () => {
		// Partner B's intermediate carries the same name as A's: only the
		// signature check can tell that A's chain never signed this one.
		expect(await reasonOf(verify({ certificate: F.bobOtherRoot }))).toBe(
			'bad_chain',
		)
	})

	it('refuses another common name than the cloud id the owner entered', async () => {
		expect(await reasonOf(verify({ certificate: F.mallory }))).toBe(
			'name_mismatch',
		)
		expect(
			await reasonOf(verify({ cloudId: 'bob@cloud.elsewhere.example' })),
		).toBe('name_mismatch')
	})

	it('matches the common name without the scheme an http instance writes', async () => {
		// Nextcloud names a user of an http instance bob@http://host; the owner
		// types bob@host. Same user, same instance.
		await expect(
			verify({ cloudId: 'bob@http://cloud.partner.example' }),
		).resolves.toHaveProperty('fingerprint')
		await expect(
			verify({ cloudId: 'BOB@cloud.partner.example' }).catch((e) => e.reason),
		).resolves.toBe('name_mismatch')
		expect(
			await reasonOf(verify({ cloudId: 'bob@cloud.partner.example.evil' })),
		).toBe('name_mismatch')
		expect(
			await reasonOf(verify({ cloudId: 'bob@http://cloud.other.example' })),
		).toBe('name_mismatch')
	})

	it('refuses a certificate outside its validity', async () => {
		expect(await reasonOf(verify({ now: Date.UTC(2300, 0, 1) }))).toBe(
			'not_valid_now',
		)
	})

	it('refuses without a pin, without a chain, or with garbage', async () => {
		expect(await reasonOf(verify({ partnerRootFingerprint: '' }))).toBe(
			'untrusted_root',
		)
		expect(await reasonOf(verify({ chain: [] }))).toBe('untrusted_root')
		expect(await reasonOf(verify({ certificate: 'not a certificate' }))).toBe(
			'malformed',
		)
	})

	it('compares the pin without regard to case', async () => {
		await expect(
			verify({ partnerRootFingerprint: F.rootAFingerprint.toUpperCase() }),
		).resolves.toHaveProperty('fingerprint')
	})

	it('returns the public key of the certificate it verified', async () => {
		const { spki } = await verify()

		expect(spki).toEqual(
			new Uint8Array(
				createPublicKey(F.bob).export({ type: 'spki', format: 'der' }),
			),
		)
	})

	it('refuses base64 text before the certificate block', async () => {
		const { text } = await otherKeyText()

		expect(await reasonOf(verify({ certificate: `${text}\n${F.bob}` }))).toBe(
			'malformed',
		)
	})

	it('refuses a certificate text that is not exactly one PEM block', async () => {
		expect(
			await reasonOf(verify({ certificate: `${F.bob}\n${F.mallory}` })),
		).toBe('malformed')
		expect(await reasonOf(verify({ certificate: `junk\n${F.bob}` }))).toBe(
			'malformed',
		)
		expect(await reasonOf(verify({ certificate: `${F.bob}\njunk` }))).toBe(
			'malformed',
		)
		expect(
			await reasonOf(
				verify({ chain: [`${F.intermediateA}\n${F.bob}`, F.rootA] }),
			),
		).toBe('malformed')
	})

	it('refuses bytes after the certificate', async () => {
		const padded =
			'-----BEGIN CERTIFICATE-----\n'
			+ toBase64(Uint8Array.of(...pemToDer(F.bob), 0x05, 0x00))
			+ '\n-----END CERTIFICATE-----'

		expect(await reasonOf(verify({ certificate: padded }))).toBe('malformed')
	})

	it('refuses a certificate with more than three parts', async () => {
		const der = pemToDer(F.bob)
		// Bob's certificate has a two-byte long-form length: 30 82 hi lo.
		const fourParts = derElement(0x30, der.subarray(4), derElement(0x05))

		expect(await reasonOf(verify({ certificate: pemOf(fourParts) }))).toBe(
			'malformed',
		)
	})

	it('refuses an element that runs past the end of its parent', async () => {
		const der = pemToDer(F.bob)
		const sha256Rsa = [
			0x06, 0x09, 0x2a, 0x86, 0x48, 0x86, 0xf7, 0x0d, 0x01, 0x01, 0x0b,
		]
		// The outer signature algorithm, after the signed part: its OID now
		// runs one byte past the end of the algorithm SEQUENCE.
		const at = lastIndexOf(der, sha256Rsa)
		const overrun = Uint8Array.from(der)
		overrun[at + 1] = 0x0c

		expect(await reasonOf(verify({ certificate: pemOf(overrun) }))).toBe(
			'malformed',
		)
	})
})

/**
 * @param {Uint8Array} der DER bytes.
 * @return {string} One CERTIFICATE PEM block.
 */
function pemOf(der) {
	return `-----BEGIN CERTIFICATE-----\n${toBase64(der)}\n-----END CERTIFICATE-----`
}

/**
 * @param {Uint8Array} bytes Where to look.
 * @param {number[]} pattern What to find.
 * @return {number} The last offset of `pattern` in `bytes`.
 */
function lastIndexOf(bytes, pattern) {
	for (let i = bytes.length - pattern.length; i >= 0; i--) {
		if (pattern.every((byte, j) => bytes[i + j] === byte)) {
			return i
		}
	}
	throw new Error('pattern not found')
}
