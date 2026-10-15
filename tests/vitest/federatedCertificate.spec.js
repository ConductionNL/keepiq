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
import { X509Certificate } from 'node:crypto'
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

/**
 * @param {string} pem A PEM certificate.
 * @return {Uint8Array} Its DER bytes.
 */
function derOf(pem) {
	return Uint8Array.from(atob(pem.replace(/-----[A-Z ]+-----|\s/g, '')), (char) =>
		char.charCodeAt(0),
	)
}

/**
 * @param {Uint8Array} der DER bytes.
 * @return {string} A PEM certificate.
 */
function pemOf(der) {
	return `-----BEGIN CERTIFICATE-----\n${btoa(String.fromCharCode(...der))}\n-----END CERTIFICATE-----`
}

/**
 * @param {Uint8Array} der DER bytes.
 * @param {number} offset Where an element starts.
 * @return {{start: number, end: number}} Its value.
 */
function element(der, offset) {
	let length = der[offset + 1]
	let start = offset + 2
	if (length & 0x80) {
		const count = length & 0x7f
		length = 0
		for (let i = 0; i < count; i++) {
			length = length * 256 + der[start + i]
		}
		start += count
	}
	return { start, end: start + length }
}

/**
 * @param {number} tag The tag.
 * @param {Uint8Array[]} parts The content, in pieces.
 * @return {Uint8Array} The DER element.
 */
function encode(tag, parts) {
	const content = parts.flatMap((part) => [...part])
	const size = []
	for (let n = content.length; n > 0; n = Math.floor(n / 256)) {
		size.unshift(n % 256)
	}
	const header =
		content.length < 0x80 ? [content.length] : [0x80 | size.length, ...size]
	return Uint8Array.from([tag, ...header, ...content])
}

/**
 * A certificate with its TBS fields changed. The signature is left as it
 * was: these inputs are refused before it is checked.
 *
 * @param {string} pem A PEM certificate.
 * @param {function(Uint8Array[]): Uint8Array[]} edit Takes and returns the
 *   TBS fields, each a whole DER element.
 * @return {string} The changed certificate.
 */
function withTbsFields(pem, edit) {
	const der = derOf(pem)
	const certificate = element(der, 0)
	const tbs = element(der, certificate.start)
	const fields = []
	for (let at = tbs.start; at < tbs.end; at = element(der, at).end) {
		fields.push(der.subarray(at, element(der, at).end))
	}
	return pemOf(
		encode(0x30, [
			encode(0x30, edit(fields)),
			der.subarray(tbs.end, certificate.end),
		]),
	)
}

/**
 * @param {string} pem A PEM certificate.
 * @return {Promise<string>} Lowercase hex SHA-256 over its DER bytes.
 */
async function fingerprintOf(pem) {
	const digest = new Uint8Array(await crypto.subtle.digest('SHA-256', derOf(pem)))
	return Array.from(digest, (byte) => byte.toString(16).padStart(2, '0')).join('')
}

// The variants re-issue partner A's own keys with other extensions or
// validity, so only those can refuse them.
describe('verifyFederatedCertificate chain constraints', () => {
	const CAROL = 'carol@cloud.partner.example'
	const MALLORY = 'mallory@cloud.partner.example'

	it('accepts a CA with basicConstraints and no keyUsage, as Keepiq issues them, and a pathLen the chain fits', async () => {
		await expect(
			verify({ chain: [F.intermediateABasicConstraintsOnly, F.rootA] }),
		).resolves.toHaveProperty('fingerprint')
		await expect(
			verify({ chain: [F.intermediateAPathLen0, F.rootA] }),
		).resolves.toHaveProperty('fingerprint')
	})

	it('still accepts a recipient certificate marked CA, as openssl issued them before', async () => {
		await expect(
			verify({ certificate: F.leafMarkedCa, cloudId: MALLORY }),
		).resolves.toHaveProperty('fingerprint')
	})

	it('refuses a leaf signed with another leaf', async () => {
		expect(
			await reasonOf(
				verify({
					certificate: F.leafSignedByLeaf,
					chain: [F.mallory, F.intermediateA, F.rootA],
					cloudId: CAROL,
				}),
			),
		).toBe('bad_chain')
		// Pinned at the intermediate, so only the issuing leaf's missing basicConstraints refuses it.
		expect(
			await reasonOf(
				verify({
					certificate: F.leafSignedByLeaf,
					chain: [F.mallory, F.intermediateA],
					partnerRootFingerprint: await fingerprintOf(F.intermediateA),
					cloudId: CAROL,
				}),
			),
		).toBe('bad_chain')
	})

	it('refuses a chain other than an intermediate and the root, even one that verifies', async () => {
		// The issuing leaf is marked CA, as openssl issued user certificates before.
		expect(
			await reasonOf(
				verify({
					certificate: F.leafSignedByLeaf,
					chain: [F.leafMarkedCa, F.intermediateA, F.rootA],
					cloudId: CAROL,
				}),
			),
		).toBe('bad_chain')
		expect(
			await reasonOf(
				verify({
					certificate: F.leafSignedByRoot,
					chain: [F.rootA],
					cloudId: MALLORY,
				}),
			),
		).toBe('bad_chain')
	})

	it.each([
		['without basicConstraints', 'intermediateANoBasicConstraints'],
		['with CA:FALSE', 'intermediateANotCa'],
		['whose keyUsage lacks keyCertSign', 'intermediateANoCertSign'],
		[
			'whose keyUsage sets keyCertSign only in its padding bits',
			'intermediateAKeyCertSignInPadding',
		],
	])('refuses an issuer %s', async (_, name) => {
		expect(await reasonOf(verify({ chain: [F[name], F.rootA] }))).toBe(
			'bad_chain',
		)
	})

	it('refuses a chain longer than a pathLen allows', async () => {
		expect(
			await reasonOf(
				verify({
					chain: [F.intermediateA, F.rootAPathLen0],
					partnerRootFingerprint: F.rootAPathLen0Fingerprint,
				}),
			),
		).toBe('bad_chain')
	})

	it('accepts an unknown extension on an issuer that is not critical', async () => {
		await expect(
			verify({ chain: [F.intermediateAUnknownNonCritical, F.rootA] }),
		).resolves.toHaveProperty('fingerprint')
	})

	it.each([
		['an unknown critical extension', 'intermediateAUnknownCritical'],
		[
			'basicConstraints twice, CA:TRUE last',
			'intermediateADuplicateBasicConstraints',
		],
		[
			'an empty keyUsage that claims unused bits',
			'intermediateAEmptyKeyUsageWithUnusedBits',
		],
	])('refuses an issuer with %s', async (_, name) => {
		expect(await reasonOf(verify({ chain: [F[name], F.rootA] }))).toBe(
			'malformed',
		)
	})

	it('refuses an issuer with a second extensions block', async () => {
		const twice = withTbsFields(F.intermediateA, (fields) => {
			expect(fields.at(-1)[0]).toBe(0xa3)
			return [...fields, fields.at(-1)]
		})
		expect(await reasonOf(verify({ chain: [twice, F.rootA] }))).toBe('malformed')
	})

	it('refuses an issuer whose extensions block holds two lists', async () => {
		const doubled = withTbsFields(F.intermediateA, (fields) => {
			const block = fields.at(-1)
			const list = block.subarray(element(block, 0).start)
			return [...fields.slice(0, -1), encode(0xa3, [list, list])]
		})
		expect(await reasonOf(verify({ chain: [doubled, F.rootA] }))).toBe(
			'malformed',
		)
	})

	it('refuses an issuer whose basicConstraints does not decode', async () => {
		// The extnValue OCTET STRING of `critical, CA:TRUE`, retagged as a BIT STRING.
		const der = derOf(F.intermediateABasicConstraintsOnly)
		const extension = [0x06, 0x03, 0x55, 0x1d, 0x13, 0x01, 0x01, 0xff, 0x04]
		const at = der.findIndex((_, i) =>
			extension.every((byte, j) => der[i + j] === byte),
		)
		expect(at).toBeGreaterThan(0)
		der[at + 8] = 0x03
		expect(await reasonOf(verify({ chain: [pemOf(der), F.rootA] }))).toBe(
			'malformed',
		)
	})

	it.each([
		['an expired intermediate', { chain: [F.intermediateAExpired, F.rootA] }],
		[
			'an intermediate not yet valid',
			{ chain: [F.intermediateANotYetValid, F.rootA] },
		],
		[
			'an expired root',
			{
				chain: [F.intermediateA, F.rootAExpired],
				partnerRootFingerprint: F.rootAExpiredFingerprint,
			},
		],
	])(
		'refuses %s while the recipient certificate is valid',
		async (_, overrides) => {
			expect(await reasonOf(verify(overrides))).toBe('not_valid_now')
		},
	)

	it('allows a few minutes of clock skew on notBefore, not more', async () => {
		// The chain was issued in one run, so its newest notBefore is the recipient's.
		const issued = Date.parse(new X509Certificate(F.bob).validFrom)
		const minutes = 60 * 1000

		await expect(verify({ now: issued - 2 * minutes })).resolves.toHaveProperty(
			'fingerprint',
		)
		expect(await reasonOf(verify({ now: issued - 10 * minutes }))).toBe(
			'not_valid_now',
		)
	})
})
