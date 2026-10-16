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
import { beforeAll, describe, expect, it } from 'vitest'
import {
	canonicalCloudId,
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

	it('refuses a certificate cut short of its DER length', async () => {
		expect(
			await reasonOf(
				verify({ certificate: pemOf(pemToDer(F.bob).slice(0, 200)) }),
			),
		).toBe('malformed')
		expect(
			await reasonOf(
				verify({ certificate: pemOf(Uint8Array.of(0x30, 0x84, 0xff)) }),
			),
		).toBe('malformed')
	})

	it('refuses a certificate that is no SEQUENCE', async () => {
		const set = Uint8Array.from(pemToDer(F.bob))
		set[0] = 0x31

		expect(await reasonOf(verify({ certificate: pemOf(set) }))).toBe('malformed')
	})

	it('refuses a signature whose BIT STRING claims unused bits, though its bytes still verify', async () => {
		const der = Uint8Array.from(pemToDer(F.bob))
		// A 2048-bit signature: 03 82 01 01, then the unused-bits count and 256 bytes.
		expect(Array.from(der.subarray(-261, -256))).toEqual([
			0x03, 0x82, 0x01, 0x01, 0x00,
		])
		der[der.length - 257] = 0x01

		expect(await reasonOf(verify({ certificate: pemOf(der) }))).toBe('malformed')
	})

	it('refuses an empty signature BIT STRING', async () => {
		const der = pemToDer(F.bob)
		const signed = der.subarray(4, der.length - 261)
		const empty = encode(0x30, [signed, [0x03, 0x00]])

		expect(await reasonOf(verify({ certificate: pemOf(empty) }))).toBe(
			'malformed',
		)
	})

	it('refuses a validity without notAfter as malformed', async () => {
		const short = withTbsFields(F.bob, (fields) => {
			const validity = fields[4]
			const value = element(validity, 0)
			return fields.with(
				4,
				encode(0x30, [
					validity.subarray(
						value.start,
						element(validity, value.start).end,
					),
				]),
			)
		})

		expect(await reasonOf(verify({ certificate: short }))).toBe('malformed')
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

	it("accepts a CA with basicConstraints and no keyUsage, as a host's default v3_ca section issues it, and a pathLen the chain fits", async () => {
		await expect(
			verify({ chain: [F.intermediateABasicConstraintsOnly, F.rootA] }),
		).resolves.toHaveProperty('fingerprint')
		await expect(
			verify({ chain: [F.intermediateAPathLen0, F.rootA] }),
		).resolves.toHaveProperty('fingerprint')
	})

	it('refuses a recipient certificate marked CA, though it chains and names the recipient', async () => {
		expect(
			await reasonOf(
				verify({ certificate: F.leafMarkedCa, cloudId: MALLORY }),
			),
		).toBe('bad_chain')
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
		// The issuing leaf is marked CA, so only the chain's length refuses it.
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

	it('refuses the root in place of the intermediate, though it signed the leaf', async () => {
		expect(
			await reasonOf(
				verify({
					certificate: F.leafSignedByRoot,
					chain: [F.rootA, F.rootA],
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

// Signed here with one throwaway key, so each certificate differs from a good
// one only where a test says.
describe('verifyFederatedCertificate on chains built here', () => {
	const MALLORY = 'mallory@cloud.partner.example'
	const OID = {
		sha256Rsa: [0x2a, 0x86, 0x48, 0x86, 0xf7, 0x0d, 0x01, 0x01, 0x0b],
		pss: [0x2a, 0x86, 0x48, 0x86, 0xf7, 0x0d, 0x01, 0x01, 0x0a],
		mgf1: [0x2a, 0x86, 0x48, 0x86, 0xf7, 0x0d, 0x01, 0x01, 0x08],
		sha256: [0x60, 0x86, 0x48, 0x01, 0x65, 0x03, 0x04, 0x02, 0x01],
		sha384: [0x60, 0x86, 0x48, 0x01, 0x65, 0x03, 0x04, 0x02, 0x02],
		sha1: [0x2b, 0x0e, 0x03, 0x02, 0x1a],
		commonName: [0x55, 0x04, 0x03],
		basicConstraints: [0x55, 0x1d, 0x13],
		keyUsage: [0x55, 0x1d, 0x0f],
		subjectKeyId: [0x55, 0x1d, 0x0e],
		authorityKeyId: [0x55, 0x1d, 0x23],
	}
	const ascii = (text) => Array.from(new TextEncoder().encode(text))
	const utf8 = (text) => derElement(0x0c, ascii(text))
	const bmp = (text) =>
		derElement(
			0x1e,
			Array.from(text).flatMap((char) => [
				char.charCodeAt(0) >> 8,
				char.charCodeAt(0) & 0xff,
			]),
		)
	const name = (...commonNames) =>
		derElement(
			0x30,
			...commonNames.map((cn) =>
				derElement(
					0x31,
					derElement(0x30, derElement(0x06, OID.commonName), cn),
				),
			),
		)
	const extension = (id, value, critical = false) =>
		derElement(
			0x30,
			derElement(0x06, id),
			critical ? derElement(0x01, [0xff]) : [],
			derElement(0x04, value),
		)
	const basicConstraints = (...fields) =>
		extension(OID.basicConstraints, derElement(0x30, ...fields), true)
	const CA = basicConstraints(derElement(0x01, [0xff]))
	const PKCS1 = derElement(0x30, derElement(0x06, OID.sha256Rsa), derElement(0x05))
	const pss = ({
		hash = OID.sha256,
		mask = OID.mgf1,
		maskHash = OID.sha256,
		trailer = [],
	} = {}) =>
		derElement(
			0x30,
			derElement(0x06, OID.pss),
			derElement(
				0x30,
				derElement(0xa0, derElement(0x30, derElement(0x06, hash))),
				derElement(
					0xa1,
					derElement(
						0x30,
						derElement(0x06, mask),
						derElement(0x30, derElement(0x06, maskHash)),
					),
				),
				derElement(0xa2, derElement(0x02, [32])),
				...trailer,
			),
		)
	const ROOT = name(utf8('Built Root CA'))
	const INTERMEDIATE = name(utf8('Built Intermediate CA'))

	let keys

	beforeAll(async () => {
		const pair = await crypto.subtle.generateKey(
			{
				name: 'RSASSA-PKCS1-v1_5',
				modulusLength: 2048,
				publicExponent: new Uint8Array([1, 0, 1]),
				hash: 'SHA-256',
			},
			true,
			['sign', 'verify'],
		)
		const pkcs8 = await crypto.subtle.exportKey('pkcs8', pair.privateKey)
		keys = {
			spki: new Uint8Array(
				await crypto.subtle.exportKey('spki', pair.publicKey),
			),
			pkcs1: pair.privateKey,
			pss: await crypto.subtle.importKey(
				'pkcs8',
				pkcs8,
				{ name: 'RSA-PSS', hash: 'SHA-256' },
				false,
				['sign'],
			),
		}
	})

	/**
	 * Signed with SHA-256: PSS with MGF1-SHA-256 and a 32-byte salt under
	 * any PSS algorithm, whatever its parameters claim.
	 *
	 * @param {object} spec The certificate.
	 * @param {Uint8Array} spec.issuer The issuer Name.
	 * @param {Uint8Array} spec.subject The subject Name.
	 * @param {Uint8Array[]} [spec.extensions] Its extensions, if any.
	 * @param {Uint8Array} [spec.algorithm] The algorithm in the TBS.
	 * @param {Uint8Array} [spec.outer] The algorithm outside it, by default
	 *   the same.
	 * @param {Uint8Array} [spec.spki] The key, by default the throwaway one.
	 * @return {Promise<string>} The PEM certificate.
	 */
	async function certificate({
		issuer,
		subject,
		extensions,
		algorithm = PKCS1,
		outer = algorithm,
		spki = keys.spki,
	}) {
		const time = (text) => derElement(0x17, ascii(text))
		const tbs = derElement(
			0x30,
			derElement(0xa0, derElement(0x02, [2])),
			derElement(0x02, [1]),
			algorithm,
			issuer,
			derElement(0x30, time('200101000000Z'), time('491231235959Z')),
			subject,
			spki,
			extensions ? derElement(0xa3, derElement(0x30, ...extensions)) : [],
		)
		const signsPss = algorithm.some((_, i) =>
			OID.pss.every((byte, j) => algorithm[i + j] === byte),
		)
		const signature = signsPss
			? await crypto.subtle.sign(
					{ name: 'RSA-PSS', saltLength: 32 },
					keys.pss,
					tbs,
				)
			: await crypto.subtle.sign('RSASSA-PKCS1-v1_5', keys.pkcs1, tbs)
		return pemOf(
			derElement(
				0x30,
				tbs,
				outer,
				derElement(0x03, [0x00], new Uint8Array(signature)),
			),
		)
	}

	/**
	 * A root, an intermediate and Mallory's leaf, each replaceable.
	 *
	 * @param {object} parts Fields to replace, per certificate.
	 * @return {Promise<{root: string, intermediate: string, leaf: string}>}
	 */
	async function build(parts = {}) {
		const root = await certificate({
			issuer: ROOT,
			subject: ROOT,
			extensions: [CA],
			...parts.root,
		})
		const intermediate = await certificate({
			issuer: ROOT,
			subject: INTERMEDIATE,
			extensions: [CA],
			...parts.intermediate,
		})
		const leaf = await certificate({
			issuer: INTERMEDIATE,
			subject: name(utf8(MALLORY)),
			...parts.leaf,
		})
		return { root, intermediate, leaf }
	}

	/**
	 * @param {object} parts Fields to replace, per certificate.
	 * @param {string} cloudId The cloud id to verify for.
	 * @return {Promise<object>}
	 */
	async function verifyBuilt(parts = {}, cloudId = MALLORY) {
		const { root, intermediate, leaf } = await build(parts)
		return verifyFederatedCertificate({
			certificate: leaf,
			chain: [intermediate, root],
			partnerRootFingerprint: await fingerprintOf(root),
			cloudId,
		})
	}

	it('accepts a chain of the profile lib/Service/certificate-extensions.cnf issues', async () => {
		// keyCertSign and cRLSign, with one unused bit; the key identifiers are
		// not critical and not read.
		const keyUsage = extension(
			OID.keyUsage,
			derElement(0x03, [0x01, 0x06]),
			true,
		)
		const keyIds = [
			extension(
				OID.subjectKeyId,
				derElement(0x04, new Uint8Array(20).fill(7)),
			),
			extension(
				OID.authorityKeyId,
				derElement(0x30, derElement(0x80, new Uint8Array(20).fill(7))),
			),
		]
		const built = await build({
			root: { extensions: [CA, keyUsage, ...keyIds] },
			intermediate: {
				extensions: [
					basicConstraints(
						derElement(0x01, [0xff]),
						derElement(0x02, [0x00]),
					),
					keyUsage,
					...keyIds,
				],
			},
			// openssl writes CA:FALSE as an empty SEQUENCE.
			leaf: { extensions: [basicConstraints(), ...keyIds] },
		})

		await expect(
			verifyFederatedCertificate({
				certificate: built.leaf,
				chain: [built.intermediate, built.root],
				partnerRootFingerprint: await fingerprintOf(built.root),
				cloudId: MALLORY,
			}),
		).resolves.toHaveProperty('fingerprint')
		// OpenSSL agrees each link holds, so the builder and the parser don't
		// share a wrong reading.
		const [root, intermediate, leaf] = [
			built.root,
			built.intermediate,
			built.leaf,
		].map((pem) => new X509Certificate(pem))
		for (const [cert, issuer] of [
			[root, root],
			[intermediate, root],
			[leaf, intermediate],
		]) {
			expect(cert.checkIssued(issuer)).toBe(true)
			expect(cert.verify(issuer.publicKey)).toBe(true)
		}
	})

	it('refuses a leaf without a cloud id for a requested id without one', async () => {
		// Both canonicalise to ''.
		expect(
			await reasonOf(
				verifyBuilt(
					{ leaf: { subject: name(utf8('Built Leaf')) } },
					'garbage',
				),
			),
		).toBe('name_mismatch')
	})

	it('accepts a leaf signed with RSASSA-PSS, SHA-256, MGF1-SHA-256 and trailer field 1', async () => {
		await expect(
			verifyBuilt({ leaf: { algorithm: pss() } }),
		).resolves.toHaveProperty('fingerprint')
		await expect(
			verifyBuilt({
				leaf: {
					algorithm: pss({
						trailer: [derElement(0xa3, derElement(0x02, [1]))],
					}),
				},
			}),
		).resolves.toHaveProperty('fingerprint')
	})

	it.each([
		['a hash other than SHA-256', { hash: OID.sha384 }],
		['MGF1 with SHA-1', { maskHash: OID.sha1 }],
		[
			'a mask generation function other than MGF1',
			{ mask: [...OID.mgf1.slice(0, -1), 0x09] },
		],
	])(
		'refuses a PSS signature whose parameters name %s, though it verifies with SHA-256',
		async (_, params) => {
			expect(
				await reasonOf(verifyBuilt({ leaf: { algorithm: pss(params) } })),
			).toBe('bad_chain')
		},
	)

	const pssWith = (...params) =>
		derElement(0x30, derElement(0x06, OID.pss), ...params)
	const pssHash = (hash) =>
		derElement(0xa0, derElement(0x30, derElement(0x06, hash)))
	const PSS_MASK = derElement(
		0xa1,
		derElement(
			0x30,
			derElement(0x06, OID.mgf1),
			derElement(0x30, derElement(0x06, OID.sha256)),
		),
	)
	const PSS_SALT = derElement(0xa2, derElement(0x02, [32]))

	it.each([
		[
			'a hash that is no AlgorithmIdentifier',
			pssWith(derElement(0x30, derElement(0xa0, derElement(0x02, [5])))),
		],
		['no SEQUENCE', pssWith(derElement(0x05))],
		['an empty field', pssWith(derElement(0x30, derElement(0xa0)))],
		[
			'a field it does not know',
			pssWith(derElement(0x30, derElement(0xa4, derElement(0x02, [1])))),
		],
		[
			'a trailer field other than 1',
			pss({ trailer: [derElement(0xa3, derElement(0x02, [2]))] }),
		],
		[
			'a hash field twice, SHA-256 last',
			pssWith(
				derElement(
					0x30,
					pssHash(OID.sha384),
					pssHash(OID.sha256),
					PSS_MASK,
					PSS_SALT,
				),
			),
		],
		[
			'the mask before the hash',
			pssWith(derElement(0x30, PSS_MASK, pssHash(OID.sha256), PSS_SALT)),
		],
	])('refuses PSS parameters with %s as malformed', async (_, algorithm) => {
		expect(await reasonOf(verifyBuilt({ leaf: { algorithm } }))).toBe(
			'malformed',
		)
	})

	it('refuses a signature algorithm outside the TBS other than the one inside, though the signature verifies', async () => {
		expect(
			await reasonOf(
				verifyBuilt({
					leaf: {
						outer: derElement(0x30, derElement(0x06, OID.sha256Rsa)),
					},
				}),
			),
		).toBe('malformed')
	})

	it('refuses an algorithm identifier that is no OID, though its bytes spell one', async () => {
		const algorithm = derElement(
			0x30,
			derElement(0x04, OID.sha256Rsa),
			derElement(0x05),
		)

		expect(await reasonOf(verifyBuilt({ leaf: { algorithm } }))).toBe(
			'malformed',
		)
	})

	it('refuses a leaf whose subject ends in a length that runs past it', async () => {
		// The common name is read from the subject alone, so the last RDN's four
		// length bytes lie beyond the bytes it is read from.
		const rdn = derElement(
			0x31,
			derElement(0x30, derElement(0x06, OID.commonName), utf8(MALLORY)),
		)
		const subject = derElement(0x30, rdn, [0x31, 0x84])

		expect(await reasonOf(verifyBuilt({ leaf: { subject } }))).toBe('malformed')
	})

	it('refuses a leaf marked CA even when its keyUsage lacks keyCertSign', async () => {
		// digitalSignature only.
		const keyUsage = extension(
			OID.keyUsage,
			derElement(0x03, [0x07, 0x80]),
			true,
		)

		expect(
			await reasonOf(verifyBuilt({ leaf: { extensions: [CA, keyUsage] } })),
		).toBe('bad_chain')
	})

	it('refuses a leaf whose key is no SEQUENCE, though its chain verifies', async () => {
		expect(
			await reasonOf(
				verifyBuilt({ leaf: { spki: derElement(0x04, keys.spki) } }),
			),
		).toBe('malformed')
	})

	it.each([
		[
			'a second common name',
			name(utf8('alice@cloud.partner.example'), utf8(MALLORY)),
		],
		['a common name as a BMPString', name(bmp(MALLORY))],
		[
			'a common name that is no UTF-8',
			name(derElement(0x0c, [...ascii(MALLORY), 0xff])),
		],
		...[0x1f, 0x7f, 0xe9].map((byte) => [
			`a TeletexString common name with byte 0x${byte.toString(16)}`,
			name(derElement(0x14, [...ascii(MALLORY), byte])),
		]),
		[
			'an IA5String common name with a control character',
			name(derElement(0x16, [...ascii(MALLORY), 0x0a])),
		],
		[
			'a PrintableString common name with UTF-8 é',
			name(derElement(0x13, [...ascii(MALLORY), 0xc3, 0xa9])),
		],
	])('refuses a leaf with %s', async (_, subject) => {
		expect(await reasonOf(verifyBuilt({ leaf: { subject } }))).toBe('malformed')
	})

	it.each([
		['a PrintableString', 0x13],
		['a TeletexString of printable ASCII, as OpenSSL may write it', 0x14],
		['an IA5String of printable ASCII', 0x16],
	])('accepts a common name as %s', async (_, tag) => {
		await expect(
			verifyBuilt({
				leaf: { subject: name(derElement(tag, ascii(MALLORY))) },
			}),
		).resolves.toHaveProperty('fingerprint')
	})

	it('reads no common name of a CA, which only its name bytes and the pin identify', async () => {
		const root = name(utf8('Built Root CA'), utf8('Built Root CA again'))
		const intermediate = name(
			utf8('Built Intermediate CA'),
			bmp('Built Intermediate CA'),
		)

		await expect(
			verifyBuilt({
				root: { issuer: root, subject: root },
				intermediate: { issuer: root, subject: intermediate },
				leaf: { issuer: intermediate },
			}),
		).resolves.toHaveProperty('fingerprint')
	})

	it('keeps a byte order mark in the common name, so it names no one', async () => {
		const subject = name(derElement(0x0c, [0xef, 0xbb, 0xbf, ...ascii(MALLORY)]))

		expect(await reasonOf(verifyBuilt({ leaf: { subject } }))).toBe(
			'name_mismatch',
		)
	})
})

describe('canonicalCloudId', () => {
	it.each([
		['bob@http://Cloud.Partner.example/', 'bob@cloud.partner.example'],
		[
			'Bob@https://cloud.partner.example:8443/nc',
			'Bob@cloud.partner.example:8443/nc',
		],
		['bob@Cloud.Partner.example/NC/', 'bob@cloud.partner.example/NC'],
		['bob', ''],
		['bob@', ''],
		['bob@https://', ''],
		[null, ''],
	])('%s → %s', (input, expected) => {
		expect(canonicalCloudId(input)).toBe(expected)
	})
})
