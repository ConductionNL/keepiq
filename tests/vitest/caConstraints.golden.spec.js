/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for how verifyFederatedCertificate reads the extensions of
 * each certificate in a chain (whether it may sign certificates, whether it
 * is marked CA, its pathLen). Chains are signed here with one throwaway key;
 * a seeded generator gives the root, the intermediate and the leaf extension
 * blocks built from basicConstraints, keyUsage and unknown extensions, well
 * formed and not: duplicates, critical flags, wrong tags, bad integers and
 * booleans, padding bits. Each case records the verification's outcome,
 * compared with golden/ca-constraints.json.
 *
 * Each case is its own test. Set RECORD_GOLDEN=1 to rewrite the fixture.
 *
 * @spec openspec/specs/federated-sharing/spec.md#requirement-certificate-lookup-is-signed-allowlisted-and-verified-in-the-browser
 */

import fs from 'node:fs'
import path from 'node:path'
import { beforeAll, describe, expect, it } from 'vitest'
import {
	FederatedCertificateError,
	verifyFederatedCertificate,
} from '../../src/crypto/federatedCertificate.js'
import { derElement, toBase64 } from './fixtures/pem-extra-text.js'

const GOLDEN = path.join(__dirname, 'golden', 'ca-constraints.json')
const RECORD = process.env.RECORD_GOLDEN === '1'
const recorded = RECORD ? {} : JSON.parse(fs.readFileSync(GOLDEN, 'utf8'))

const LEAF_NAME = 'mallory@cloud.partner.example'
const OID = {
	sha256Rsa: [0x2a, 0x86, 0x48, 0x86, 0xf7, 0x0d, 0x01, 0x01, 0x0b],
	commonName: [0x55, 0x04, 0x03],
	basicConstraints: [0x55, 0x1d, 0x13],
	keyUsage: [0x55, 0x1d, 0x0f],
	subjectKeyId: [0x55, 0x1d, 0x0e],
	unknown: [0x2b, 0x06, 0x01, 0x04, 0x01, 0x82, 0x37, 0x15, 0x07],
}

/**
 * @param {string} text Text.
 * @return {number[]} Its UTF-8 bytes.
 */
function ascii(text) {
	return Array.from(new TextEncoder().encode(text))
}

/**
 * @param {string} text A common name.
 * @return {Uint8Array} A Name with only that CN.
 */
function name(text) {
	return derElement(
		0x30,
		derElement(
			0x31,
			derElement(
				0x30,
				derElement(0x06, OID.commonName),
				derElement(0x0c, ascii(text)),
			),
		),
	)
}
const ROOT = name('Golden Root CA')
const INTERMEDIATE = name('Golden Intermediate CA')
const PKCS1 = derElement(0x30, derElement(0x06, OID.sha256Rsa), derElement(0x05))

/**
 * A seeded random source (mulberry32).
 *
 * @param {number} seed The seed.
 * @return {() => number}
 */
function random(seed) {
	let a = seed >>> 0
	return () => {
		a = (a + 0x6d2b79f5) >>> 0
		let t = a
		t = Math.imul(t ^ (t >>> 15), t | 1)
		t ^= t + Math.imul(t ^ (t >>> 7), t | 61)
		return ((t ^ (t >>> 14)) >>> 0) / 4294967296
	}
}

const BOOL_TRUE = derElement(0x01, [0xff])
const BOOL_FALSE = derElement(0x01, [0x00])
const CRITICAL = [
	['absent', []],
	['absent', []],
	['absent', []],
	['true', BOOL_TRUE],
	['true', BOOL_TRUE],
	['false', BOOL_FALSE],
	['bool of two bytes', derElement(0x01, [0xff, 0xff])],
	['bool twice', [...BOOL_TRUE, ...BOOL_TRUE]],
]
const BASIC_FIELDS = [
	['empty', []],
	['ca', [BOOL_TRUE]],
	['ca false', [BOOL_FALSE]],
	['ca pathLen 0', [BOOL_TRUE, derElement(0x02, [0x00])]],
	['ca pathLen 1', [BOOL_TRUE, derElement(0x02, [0x01])]],
	['ca pathLen 300', [BOOL_TRUE, derElement(0x02, [0x01, 0x2c])]],
	['pathLen only', [derElement(0x02, [0x03])]],
	['pathLen then ca', [derElement(0x02, [0x00]), BOOL_TRUE]],
	['ca twice', [BOOL_TRUE, BOOL_TRUE]],
	['negative pathLen', [BOOL_TRUE, derElement(0x02, [0x80])]],
	['empty pathLen', [BOOL_TRUE, derElement(0x02, [])]],
	['pathLen of 5 bytes', [BOOL_TRUE, derElement(0x02, [1, 0, 0, 0, 0])]],
	['bool of two bytes', [derElement(0x01, [0xff, 0x00])]],
	['a null', [derElement(0x05)]],
]
const KEY_USAGE_BITS = [
	['empty', []],
	['no bits', [0x00]],
	['one unused, no bits', [0x01]],
	['certSign and cRLSign', [0x01, 0x06]],
	['certSign only', [0x02, 0x04]],
	['certSign in padding', [0x03, 0x04]],
	['digitalSignature only', [0x07, 0x80]],
	['eight unused', [0x08, 0x04]],
	['three bytes, certSign', [0x00, 0x04, 0x00]],
	['three bytes, none', [0x00, 0x80, 0x01]],
	['two bytes, no unused', [0x00, 0x04]],
]

/**
 * One extension, as a choice of parts.
 *
 * @param {() => number} r The random source.
 * @return {{label: string, bytes: Uint8Array}}
 */
function anExtension(r) {
	const pick = (list) => list[Math.floor(r() * list.length)]
	const [criticalLabel, critical] = pick(CRITICAL)
	const kind = pick([
		'basic',
		'basic',
		'basic',
		'keyUsage',
		'keyId',
		'keyUsage',
		'keyUsage',
		'unknown',
		'keyId',
		'odd',
	])
	let id
	let value
	let label
	if (kind === 'basic') {
		const [fieldsLabel, fields] = pick(BASIC_FIELDS)
		id = OID.basicConstraints
		value = r() < 0.9 ? derElement(0x30, ...fields) : derElement(0x31, ...fields)
		label = `basic(${fieldsLabel})`
	} else if (kind === 'keyUsage') {
		const [bitsLabel, bits] = pick(KEY_USAGE_BITS)
		id = OID.keyUsage
		value = r() < 0.9 ? derElement(0x03, bits) : derElement(0x04, bits)
		label = `keyUsage(${bitsLabel})`
	} else if (kind === 'unknown') {
		id = OID.unknown
		value = derElement(0x05)
		label = 'unknown'
	} else if (kind === 'keyId') {
		id = OID.subjectKeyId
		value = derElement(0x04, new Uint8Array(4).fill(7))
		label = 'keyId'
	} else {
		const shape = pick([
			'set',
			'no value',
			'value not octets',
			'id not oid',
			'two flags',
		])
		label = `odd(${shape})`
		const octets = derElement(0x04, derElement(0x05))
		const oid = derElement(0x06, OID.unknown)
		const parts = {
			set: [0x31, oid, octets],
			'no value': [0x30, oid],
			'value not octets': [0x30, oid, derElement(0x03, [0x00])],
			'id not oid': [0x30, derElement(0x02, [0x01]), octets],
			'two flags': [0x30, oid, BOOL_TRUE, BOOL_FALSE, octets],
		}[shape]
		return { label, bytes: derElement(parts[0], ...parts.slice(1)) }
	}
	return {
		label: `${label} critical=${criticalLabel}`,
		bytes: derElement(
			0x30,
			derElement(0x06, id),
			critical,
			derElement(0x04, value),
		),
	}
}

/**
 * An extensions block, or none, or a malformed wrapper.
 *
 * @param {() => number} r The random source.
 * @return {{label: string, block: Array<Uint8Array>|null, raw: Uint8Array|null}}
 */
function aBlock(r) {
	const roll = r()
	if (roll < 0.1) {
		return { label: 'none', block: null, raw: null }
	}
	const count = Math.floor(r() * 4)
	const extensions = []
	for (let i = 0; i < count; i++) {
		extensions.push(anExtension(r))
	}
	if (r() < 0.15 && extensions.length > 0) {
		extensions.push(extensions[0])
	}
	const label = extensions.map((e) => e.label).join(' + ') || 'empty'
	if (roll > 0.95) {
		return {
			label: `two sequences: ${label}`,
			block: null,
			raw: derElement(
				0xa3,
				derElement(0x30),
				derElement(0x30, ...extensions.map((e) => e.bytes)),
			),
		}
	}
	return { label, block: extensions.map((e) => e.bytes), raw: null }
}

const CA_BLOCK = {
	label: 'ca',
	block: [
		derElement(
			0x30,
			derElement(0x06, OID.basicConstraints),
			BOOL_TRUE,
			derElement(0x04, derElement(0x30, BOOL_TRUE)),
		),
	],
	raw: null,
}

/**
 * A chain scenario: one of the three certificates gets a generated block,
 * sometimes two do.
 *
 * @param {number} seed The seed.
 * @return {{root: object, intermediate: object, leaf: object}}
 */
function scenario(seed) {
	const r = random(seed)
	const out = {
		root: CA_BLOCK,
		intermediate: CA_BLOCK,
		leaf: { label: 'none', block: null, raw: null },
	}
	const roles = ['root', 'intermediate', 'leaf']
	out[roles[seed % 3]] = aBlock(r)
	if (r() < 0.25) {
		out[roles[(seed + 1) % 3]] = aBlock(r)
	}
	return out
}

let keys

/**
 * A certificate signed with the throwaway key.
 *
 * @param {Uint8Array} issuer The issuer Name.
 * @param {object} spec The subject Name and the extensions block.
 * @return {Promise<string>} PEM.
 */
async function certificate(issuer, spec) {
	const time = (text) => derElement(0x17, ascii(text))
	let extensions = []
	if (spec.ext.raw) {
		extensions = spec.ext.raw
	} else if (spec.ext.block) {
		extensions = derElement(0xa3, derElement(0x30, ...spec.ext.block))
	}
	const tbs = derElement(
		0x30,
		derElement(0xa0, derElement(0x02, [2])),
		derElement(0x02, [1]),
		PKCS1,
		issuer,
		derElement(0x30, time('200101000000Z'), time('491231235959Z')),
		spec.subject,
		keys.spki,
		extensions,
	)
	const signature = await crypto.subtle.sign(
		'RSASSA-PKCS1-v1_5',
		keys.privateKey,
		tbs,
	)
	const der = derElement(
		0x30,
		tbs,
		PKCS1,
		derElement(0x03, [0x00], new Uint8Array(signature)),
	)
	return `-----BEGIN CERTIFICATE-----\n${toBase64(der)}\n-----END CERTIFICATE-----`
}

/**
 * @param {string} pem A PEM certificate.
 * @return {Promise<string>} Its SHA-256 fingerprint in hex.
 */
async function fingerprintOf(pem) {
	const b64 = pem.replace(/-----[^-]+-----/g, '').replace(/\s/g, '')
	const der = Uint8Array.from(atob(b64), (c) => c.charCodeAt(0))
	const digest = new Uint8Array(await crypto.subtle.digest('SHA-256', der))
	return Array.from(digest, (b) => b.toString(16).padStart(2, '0')).join('')
}

/**
 * A chain where one certificate carries the given extensions.
 *
 * @param {string} role root, intermediate or leaf.
 * @param {{label: string, bytes: Uint8Array}[]} extensions Its extensions.
 * @return {{root: object, intermediate: object, leaf: object}}
 */
function chainWith(role, extensions) {
	return {
		root: CA_BLOCK,
		intermediate: CA_BLOCK,
		leaf: { label: 'none', block: null, raw: null },
		[role]: {
			label: extensions.map((e) => e.label).join(' + '),
			block: extensions.map((e) => e.bytes),
			raw: null,
		},
	}
}

/**
 * @param {number[]} oidBytes The extnID.
 * @param {Uint8Array} value The extnValue contents.
 * @param {string} label Its label.
 * @return {{label: string, bytes: Uint8Array}}
 */
function criticalExtension(oidBytes, value, label) {
	return {
		label,
		bytes: derElement(
			0x30,
			derElement(0x06, oidBytes),
			BOOL_TRUE,
			derElement(0x04, value),
		),
	}
}

const CASES = {}
for (let seed = 1; seed <= 900; seed++) {
	CASES[`seed ${seed}`] = () => scenario(seed)
}
const CA_EXTENSION = { label: 'ca', bytes: CA_BLOCK.block[0] }
for (const role of ['root', 'intermediate', 'leaf']) {
	for (const [bitsLabel, bits] of KEY_USAGE_BITS) {
		const keyUsage = criticalExtension(
			OID.keyUsage,
			derElement(0x03, bits),
			`keyUsage(${bitsLabel})`,
		)
		CASES[`${role}: ca + keyUsage(${bitsLabel})`] = () =>
			chainWith(role, [CA_EXTENSION, keyUsage])
		CASES[`${role}: keyUsage(${bitsLabel}) + ca`] = () =>
			chainWith(role, [keyUsage, CA_EXTENSION])
	}
	for (const [fieldsLabel, fields] of BASIC_FIELDS) {
		CASES[`${role}: basic(${fieldsLabel})`] = () =>
			chainWith(role, [
				criticalExtension(
					OID.basicConstraints,
					derElement(0x30, ...fields),
					`basic(${fieldsLabel})`,
				),
			])
	}
}

describe('certificate extension constraints (golden)', () => {
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
		keys = {
			privateKey: pair.privateKey,
			spki: new Uint8Array(
				await crypto.subtle.exportKey('spki', pair.publicKey),
			),
		}
	})

	it.each(Object.keys(CASES))('%s', async (key) => {
		const s = CASES[key]()
		const root = await certificate(ROOT, { subject: ROOT, ext: s.root })
		const intermediate = await certificate(ROOT, {
			subject: INTERMEDIATE,
			ext: s.intermediate,
		})
		const leaf = await certificate(INTERMEDIATE, {
			subject: name(LEAF_NAME),
			ext: s.leaf,
		})
		let result
		try {
			const verified = await verifyFederatedCertificate({
				certificate: leaf,
				chain: [intermediate, root],
				partnerRootFingerprint: await fingerprintOf(root),
				cloudId: LEAF_NAME,
			})
			result = { verified: verified.cloudId ?? true }
		} catch (e) {
			result = {
				refused:
					e instanceof FederatedCertificateError
						? e.reason
						: `other: ${e.message}`,
			}
		}
		const actual = {
			root: s.root.label,
			intermediate: s.intermediate.label,
			leaf: s.leaf.label,
			result,
		}
		if (RECORD) {
			recorded[key] = actual
			fs.mkdirSync(path.dirname(GOLDEN), { recursive: true })
			fs.writeFileSync(
				GOLDEN,
				'{\n'
					+ Object.entries(recorded)
						.map(
							([k, v]) => `${JSON.stringify(k)}: ${JSON.stringify(v)}`,
						)
						.join(',\n')
					+ '\n}\n',
			)
		}
		expect(actual).toEqual(recorded[key])
	})
})
