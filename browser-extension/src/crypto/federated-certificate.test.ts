import { createPublicKey, X509Certificate } from 'node:crypto'
import { beforeAll, describe, expect, it } from 'vitest'
import { caConstraintsChain, FEDERATION_NAMES, federationChain } from '@/src/testing/vectors'
import { fromBase64, toBase64 } from './base64'
import { canonicalCloudId, FederatedCertificateError, verifyFederatedCertificate, type FederatedCertificateInput } from './federated-certificate'
import { extractSpki, importPrivateKey, importSpki, pemToPkcs8, rsaDecrypt, rsaEncrypt } from './rsa'

// The web app's own fixture: two CAs with the same names, so only the pin and the signatures tell them apart.
const F = federationChain()
const C = caConstraintsChain()
const BOB = 'bob@cloud.partner.example'
const CAROL = 'carol@cloud.partner.example'
const MALLORY = 'mallory@cloud.partner.example'
const FINGERPRINT = expect.stringMatching(/^[0-9a-f]{64}$/)

const verify = (overrides: Partial<FederatedCertificateInput> = {}) => verifyFederatedCertificate({
	certificate: F.bob, chain: [F.intermediateA, F.rootA], partnerRootFingerprint: F.rootAFingerprint, cloudId: BOB, ...overrides,
})

const verifyMallory = (overrides: Partial<FederatedCertificateInput> = {}) => verifyFederatedCertificate({
	certificate: C.mallory, chain: [C.intermediate, C.root], partnerRootFingerprint: C.rootFingerprint, cloudId: MALLORY, ...overrides,
})

const derOf = (pem: string) => fromBase64(pem.replace(/-----[A-Z ]+-----|\s/g, ''))
const pemOf = (der: ArrayLike<number>) => `-----BEGIN CERTIFICATE-----\n${toBase64(Uint8Array.from(der))}\n-----END CERTIFICATE-----`

/** A DER element of up to 64 KiB. */
function der(tag: number, ...parts: ArrayLike<number>[]): number[] {
	const body = parts.flatMap((part) => Array.from(part))
	const n = body.length
	return [tag, ...(n < 0x80 ? [n] : n < 0x100 ? [0x81, n] : [0x82, n >> 8, n & 0xff]), ...body]
}

/** Where an element's value lies. */
function element(bytes: Uint8Array, offset: number): { start: number; end: number } {
	let length = bytes[offset + 1]!
	let start = offset + 2
	if (length & 0x80) {
		const count = length & 0x7f
		length = 0
		for (let i = 0; i < count; i++) length = length * 256 + bytes[start + i]!
		start += count
	}
	return { start, end: start + length }
}

function indexOf(bytes: Uint8Array, pattern: number[], last = false): number {
	const at = (i: number) => pattern.every((byte, j) => bytes[i + j] === byte)
	for (let n = 0; n <= bytes.length - pattern.length; n++) {
		const i = last ? bytes.length - pattern.length - n : n
		if (at(i)) return i
	}
	throw new Error('pattern not found')
}

/** A certificate with its TBS fields changed and the signature left as it was, since these are refused before it is checked. */
function withTbsFields(pem: string, edit: (fields: Uint8Array[]) => ArrayLike<number>[]): string {
	const bytes = derOf(pem)
	const certificate = element(bytes, 0)
	const tbs = element(bytes, certificate.start)
	const fields: Uint8Array[] = []
	for (let at = tbs.start; at < tbs.end; at = element(bytes, at).end) fields.push(bytes.subarray(at, element(bytes, at).end))
	return pemOf(der(0x30, der(0x30, ...edit(fields)), bytes.subarray(tbs.end, certificate.end)))
}

async function reasonOf(pending: Promise<unknown>): Promise<string> {
	try {
		await pending
	} catch (error) {
		expect(error).toBeInstanceOf(FederatedCertificateError)
		return (error as FederatedCertificateError).reason
	}
	throw new Error('the certificate was accepted')
}

async function sha256Hex(pem: string): Promise<string> {
	return Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256', derOf(pem))), (b) => b.toString(16).padStart(2, '0')).join('')
}

describe('verifyFederatedCertificate', () => {
	it("accepts the pinned chain and returns the leaf's SHA-256 as lowercase hex", async () => {
		const { fingerprint } = await verify()
		expect(fingerprint).toMatch(/^[0-9a-f]{64}$/)
		expect(fingerprint).toBe(await sha256Hex(F.bob))
	})

	it('accepts a renewal signed with RSASSA-PSS', async () => {
		expect((await verify({ certificate: F.bobPss })).fingerprint).toBe(await sha256Hex(F.bobPss))
	})

	it('returns the key of the leaf it checked, which the recipient can open', async () => {
		expect((await verify()).spki).toEqual(new Uint8Array(createPublicKey(F.bob).export({ type: 'spki', format: 'der' })))
		const { spki } = await verifyMallory()
		expect(spki).toEqual(extractSpki(derOf(C.mallory)))
		const sealed = await rsaEncrypt('hunter2', await importSpki(spki))
		expect(await rsaDecrypt(sealed, await importPrivateKey(pemToPkcs8(C.malloryKey)))).toBe('hunter2')
	})

	it('refuses another certificate smuggled around the checked one', async () => {
		// The leaf base64 in front of a real PEM: a lenient parser checks one and encrypts to the other.
		expect(await reasonOf(verify({ certificate: `${toBase64(derOf(C.forgedAlice))}\n${F.bob}` }))).toBe('malformed')
		expect(await reasonOf(verify({ certificate: `${F.bob}\n${toBase64(derOf(C.forgedAlice))}` }))).toBe('malformed')
		expect(await reasonOf(verify({ certificate: `${F.bob}${C.forgedAlice}` }))).toBe('malformed')
		expect(await reasonOf(verify({ certificate: `${F.bob}\n${F.mallory}` }))).toBe('malformed')
		expect(await reasonOf(verify({ chain: [`${F.intermediateA}\n${F.bob}`, F.rootA] }))).toBe('malformed')
		const trailing = new Uint8Array([...derOf(F.bob), ...derOf(C.forgedAlice)])
		expect(await reasonOf(verify({ certificate: pemOf(trailing) }))).toBe('malformed')
		expect(await reasonOf(verify({ certificate: F.bob.replace('-----END CERTIFICATE-----', '-----END PUBLIC KEY-----') }))).toBe('malformed')
	})

	it('refuses a PEM block that is not labelled CERTIFICATE', async () => {
		expect(await reasonOf(verify({ certificate: F.bob.replace(/CERTIFICATE/g, 'PUBLIC KEY') }))).toBe('malformed')
	})

	it('refuses a certificate with more than three parts', async () => {
		// Bob's certificate has a two-byte long-form length: 30 82 hi lo.
		expect(await reasonOf(verify({ certificate: pemOf(der(0x30, derOf(F.bob).subarray(4), [0x05, 0x00])) }))).toBe('malformed')
	})

	it('refuses a signature whose BIT STRING claims unused bits, though its bytes still verify', async () => {
		const bytes = derOf(F.bob)
		// A 2048-bit signature: 03 82 01 01, then the unused-bits count and 256 bytes.
		expect(Array.from(bytes.subarray(-261, -256))).toEqual([0x03, 0x82, 0x01, 0x01, 0x00])
		bytes[bytes.length - 257] = 0x01
		expect(await reasonOf(verify({ certificate: pemOf(bytes) }))).toBe('malformed')
	})

	it('refuses an element that runs past the end of its parent', async () => {
		const bytes = derOf(F.bob)
		// The outer signature algorithm's OID now runs one byte past the end of its SEQUENCE.
		bytes[indexOf(bytes, [0x06, 0x09, 0x2a, 0x86, 0x48, 0x86, 0xf7, 0x0d, 0x01, 0x01, 0x0b], true) + 1] = 0x0c
		expect(await reasonOf(verify({ certificate: pemOf(bytes) }))).toBe('malformed')
		// The common name, Mallory's last attribute, now one byte past its attribute but inside the certificate.
		const mallory = derOf(C.mallory)
		mallory[indexOf(mallory, [0x06, 0x03, 0x55, 0x04, 0x03, 0x0c, 0x1d, 0x6d]) + 6] = 0x1e
		expect(await reasonOf(verifyMallory({ certificate: pemOf(mallory) }))).toBe('malformed')
	})

	it('refuses a DER length that runs past the buffer, without reading out of bounds', async () => {
		expect(await reasonOf(verify({ certificate: pemOf(derOf(F.bob).slice(0, 200)) }))).toBe('malformed')
		expect(await reasonOf(verify({ certificate: pemOf([0x30, 0x84, 0xff, 0xff, 0xff, 0xff, 0x30]) }))).toBe('malformed')
	})

	it('refuses a second extensions block rather than leave it unread', async () => {
		const twice = withTbsFields(F.intermediateA, (fields) => {
			expect(fields.at(-1)![0]).toBe(0xa3)
			return [...fields, fields.at(-1)!]
		})
		expect(await reasonOf(verify({ chain: [twice, F.rootA] }))).toBe('malformed')
	})

	it('refuses an extensions block that holds two lists', async () => {
		const doubled = withTbsFields(F.intermediateA, (fields) => {
			const block = fields.at(-1)!
			const list = block.subarray(element(block, 0).start)
			return [...fields.slice(0, -1), der(0xa3, list, list)]
		})
		expect(await reasonOf(verify({ chain: [doubled, F.rootA] }))).toBe('malformed')
	})

	it('refuses a chain that ends at another root', async () => {
		expect(await reasonOf(verify({ certificate: F.bobOtherRoot, chain: [F.intermediateB, F.rootB] }))).toBe('untrusted_root')
	})

	it('refuses a certificate the pinned chain did not sign, even with the same issuer name', async () => {
		expect(await reasonOf(verify({ certificate: F.bobOtherRoot }))).toBe('bad_chain')
	})

	it('refuses another common name than the share names', async () => {
		expect(await reasonOf(verify({ certificate: F.mallory }))).toBe('name_mismatch')
		expect(await reasonOf(verify({ cloudId: 'bob@cloud.elsewhere.example' }))).toBe('name_mismatch')
		expect(await reasonOf(verify({ cloudId: 'not a cloud id' }))).toBe('name_mismatch')
	})

	it('matches the common name without the scheme an http instance writes', async () => {
		await expect(verify({ cloudId: 'bob@http://cloud.partner.example' })).resolves.toMatchObject({ fingerprint: FINGERPRINT })
		expect(await reasonOf(verify({ cloudId: 'BOB@cloud.partner.example' }))).toBe('name_mismatch')
		expect(await reasonOf(verify({ cloudId: 'bob@cloud.partner.example.evil' }))).toBe('name_mismatch')
		expect(await reasonOf(verify({ cloudId: 'bob@http://cloud.other.example' }))).toBe('name_mismatch')
	})

	it('refuses a certificate outside its validity', async () => {
		expect(await reasonOf(verify({ now: Date.UTC(2300, 0, 1) }))).toBe('not_valid_now')
		expect(await reasonOf(verify({ now: Date.UTC(2000, 0, 1) }))).toBe('not_valid_now')
	})

	it('allows a few minutes of clock skew on notBefore, not more', async () => {
		// The chain was issued in one run, so its newest notBefore is the recipient's.
		const issued = Date.parse(new X509Certificate(F.bob).validFrom)
		const minutes = 60 * 1000
		await expect(verify({ now: issued - 2 * minutes })).resolves.toMatchObject({ fingerprint: FINGERPRINT })
		expect(await reasonOf(verify({ now: issued - 10 * minutes }))).toBe('not_valid_now')
	})

	it('refuses without a pin, without a chain, or with garbage', async () => {
		expect(await reasonOf(verify({ partnerRootFingerprint: '' }))).toBe('untrusted_root')
		expect(await reasonOf(verify({ partnerRootFingerprint: null }))).toBe('untrusted_root')
		expect(await reasonOf(verify({ chain: [] }))).toBe('untrusted_root')
		expect(await reasonOf(verify({ chain: 'not a list' }))).toBe('untrusted_root')
		expect(await reasonOf(verify({ certificate: 'not a certificate' }))).toBe('malformed')
		expect(await reasonOf(verify({ certificate: 42 }))).toBe('malformed')
	})

	it('compares the pin without regard to case', async () => {
		await expect(verify({ partnerRootFingerprint: F.rootAFingerprint.toUpperCase() })).resolves.toMatchObject({ fingerprint: FINGERPRINT })
	})
})

// The web app's own cases on its own fixture: re-issues of partner A's keys with other extensions or validity, so only those can refuse them.
describe("verifyFederatedCertificate on the web app's chain constraints", () => {
	it('reads every entry of the fixture, so a case added there is added here', () => {
		expect(Object.keys(F).sort()).toEqual([...FEDERATION_NAMES].sort())
	})

	it('accepts a CA with basicConstraints and no keyUsage, and a pathLen the chain fits', async () => {
		await expect(verify({ chain: [F.intermediateABasicConstraintsOnly, F.rootA] })).resolves.toMatchObject({ fingerprint: FINGERPRINT })
		await expect(verify({ chain: [F.intermediateAPathLen0, F.rootA] })).resolves.toMatchObject({ fingerprint: FINGERPRINT })
	})

	it('refuses a recipient certificate marked CA, though it chains and names the recipient', async () => {
		// OpenSSL agrees the intermediate signed it, so only the cA flag can refuse it.
		const [leaf, intermediate] = [F.leafMarkedCa, F.intermediateA].map((pem) => new X509Certificate(pem))
		expect(leaf!.checkIssued(intermediate!)).toBe(true)
		expect(leaf!.verify(intermediate!.publicKey)).toBe(true)
		expect(await reasonOf(verify({ certificate: F.leafMarkedCa, cloudId: MALLORY }))).toBe('bad_chain')
	})

	it('refuses a leaf signed with another leaf', async () => {
		expect(await reasonOf(verify({ certificate: F.leafSignedByLeaf, chain: [F.mallory, F.intermediateA, F.rootA], cloudId: CAROL }))).toBe('bad_chain')
		// Pinned at the intermediate, so only the issuing leaf's missing basicConstraints refuses it.
		expect(await reasonOf(verify({ certificate: F.leafSignedByLeaf, chain: [F.mallory, F.intermediateA], partnerRootFingerprint: await sha256Hex(F.intermediateA), cloudId: CAROL }))).toBe('bad_chain')
	})

	it('refuses a chain other than an intermediate and the root, even one that verifies', async () => {
		expect(await reasonOf(verify({ certificate: F.leafSignedByLeaf, chain: [F.leafMarkedCa, F.intermediateA, F.rootA], cloudId: CAROL }))).toBe('bad_chain')
		expect(await reasonOf(verify({ certificate: F.leafSignedByRoot, chain: [F.rootA], cloudId: MALLORY }))).toBe('bad_chain')
	})

	it('refuses the root in place of the intermediate, though it signed the leaf', async () => {
		expect(await reasonOf(verify({ certificate: F.leafSignedByRoot, chain: [F.rootA, F.rootA], cloudId: MALLORY }))).toBe('bad_chain')
	})

	it.each([
		['without basicConstraints', 'intermediateANoBasicConstraints'],
		['with CA:FALSE', 'intermediateANotCa'],
		['whose keyUsage lacks keyCertSign', 'intermediateANoCertSign'],
		['whose keyUsage sets keyCertSign only in its padding bits', 'intermediateAKeyCertSignInPadding'],
	] as const)('refuses an issuer %s', async (_, name) => {
		expect(await reasonOf(verify({ chain: [F[name], F.rootA] }))).toBe('bad_chain')
	})

	it('refuses a chain longer than a pathLen allows', async () => {
		expect(await reasonOf(verify({ chain: [F.intermediateA, F.rootAPathLen0], partnerRootFingerprint: F.rootAPathLen0Fingerprint }))).toBe('bad_chain')
	})

	it('accepts an unknown extension on an issuer that is not critical, and a critical keyUsage', async () => {
		await expect(verify({ chain: [F.intermediateAUnknownNonCritical, F.rootA] })).resolves.toMatchObject({ fingerprint: FINGERPRINT })
		await expect(verify()).resolves.toMatchObject({ fingerprint: FINGERPRINT })
	})

	it.each([
		['an unknown critical extension', 'intermediateAUnknownCritical'],
		['basicConstraints twice, CA:TRUE last', 'intermediateADuplicateBasicConstraints'],
		['an empty keyUsage that claims unused bits', 'intermediateAEmptyKeyUsageWithUnusedBits'],
	] as const)('refuses an issuer with %s', async (_, name) => {
		expect(await reasonOf(verify({ chain: [F[name], F.rootA] }))).toBe('malformed')
	})

	it('refuses an issuer whose basicConstraints does not decode', async () => {
		// The extnValue OCTET STRING of `critical, CA:TRUE`, retagged as a BIT STRING.
		const bytes = derOf(F.intermediateABasicConstraintsOnly)
		bytes[indexOf(bytes, [0x06, 0x03, 0x55, 0x1d, 0x13, 0x01, 0x01, 0xff, 0x04]) + 8] = 0x03
		expect(await reasonOf(verify({ chain: [pemOf(bytes), F.rootA] }))).toBe('malformed')
	})

	it.each<[string, Partial<FederatedCertificateInput>]>([
		['an expired intermediate', { chain: [F.intermediateAExpired, F.rootA] }],
		['an intermediate not yet valid', { chain: [F.intermediateANotYetValid, F.rootA] }],
		['an expired root', { chain: [F.intermediateA, F.rootAExpired], partnerRootFingerprint: F.rootAExpiredFingerprint }],
	])('refuses %s while the leaf is valid', async (_, overrides) => {
		expect(await reasonOf(verify(overrides))).toBe('not_valid_now')
	})
})

// Each bad certificate re-issues a good one's key and name, so only what it changes can refuse it.
describe('verifyFederatedCertificate chain constraints', () => {
	it('accepts CAs with basicConstraints and no keyUsage, and a pathLen the chain fits', async () => {
		await expect(verifyMallory()).resolves.toMatchObject({ fingerprint: await sha256Hex(C.mallory) })
		await expect(verifyMallory({ chain: [C.intermediatePathLen0, C.root] })).resolves.toMatchObject({ fingerprint: FINGERPRINT })
	})

	it('refuses a leaf signed with another leaf', async () => {
		const alice = { certificate: C.forgedAlice, cloudId: 'alice@cloud.partner.example' }
		expect(await reasonOf(verifyMallory({ ...alice, chain: [C.mallory, C.intermediate, C.root] }))).toBe('bad_chain')
		// Pinned at the intermediate, so only mallory's lack of basicConstraints refuses it.
		expect(await reasonOf(verifyMallory({ ...alice, chain: [C.mallory, C.intermediate], partnerRootFingerprint: await sha256Hex(C.intermediate) }))).toBe('bad_chain')
	})

	it('refuses a chain of other than an intermediate and the root, even one that verifies', async () => {
		expect(await reasonOf(verifyMallory({ certificate: C.malloryByRoot, chain: [C.root] }))).toBe('bad_chain')
		expect(await reasonOf(verifyMallory({ certificate: C.malloryByRoot, chain: [C.root, C.root] }))).toBe('bad_chain')
		expect(await reasonOf(verifyMallory({ certificate: C.malloryBySubIntermediate, chain: [C.subIntermediate, C.intermediate, C.root] }))).toBe('bad_chain')
	})

	it.each([
		['without basicConstraints', 'intermediateNoBasicConstraints'],
		['with CA:FALSE', 'intermediateNotCa'],
		['whose keyUsage lacks keyCertSign', 'intermediateNoCertSign'],
	] as const)('refuses an issuer %s', async (_, name) => {
		expect(await reasonOf(verifyMallory({ chain: [C[name], C.root] }))).toBe('bad_chain')
	})

	it('refuses a chain longer than a pathLen allows', async () => {
		expect(await reasonOf(verifyMallory({ chain: [C.intermediate, C.rootPathLen0], partnerRootFingerprint: C.rootPathLen0Fingerprint }))).toBe('bad_chain')
	})

	it('accepts an unknown extension that is not critical', async () => {
		await expect(verifyMallory({ certificate: C.malloryUnknownNonCritical })).resolves.toMatchObject({ fingerprint: FINGERPRINT })
	})

	it.each<[string, Partial<FederatedCertificateInput>]>([
		['a leaf with an unknown critical extension', { certificate: C.malloryUnknownCritical }],
		['an issuer with an unknown critical extension', { chain: [C.intermediateUnknownCritical, C.root] }],
		['a leaf with an extension twice', { certificate: C.malloryDuplicateExtension }],
		['an issuer with basicConstraints twice, CA:TRUE last', { chain: [C.intermediateDuplicateBasicConstraints, C.root] }],
	])('refuses %s', async (_, overrides) => {
		expect(await reasonOf(verifyMallory(overrides))).toBe('malformed')
	})

	it.each<[string, Partial<FederatedCertificateInput>]>([
		['an expired intermediate', { chain: [C.intermediateExpired, C.root] }],
		['an intermediate not yet valid', { chain: [C.intermediateNotYetValid, C.root] }],
		['an expired root', { chain: [C.intermediate, C.rootExpired], partnerRootFingerprint: C.rootExpiredFingerprint }],
	])('refuses %s while the leaf is valid', async (_, overrides) => {
		expect(await reasonOf(verifyMallory(overrides))).toBe('not_valid_now')
	})

	it('reads the fixture without its note', () => {
		expect(C).not.toHaveProperty('_note')
	})
})

// Signed here with one throwaway key, so each certificate differs from a good one only where a test says.
describe('verifyFederatedCertificate on chains built here', () => {
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
	const utf8 = (text: string) => der(0x0c, new TextEncoder().encode(text))
	const name = (...commonNames: number[][]) => der(0x30, ...commonNames.map((cn) => der(0x31, der(0x30, der(0x06, OID.commonName), cn))))
	const extension = (id: number[], value: number[], critical = false) => der(0x30, der(0x06, id), critical ? der(0x01, [0xff]) : [], der(0x04, value))
	const basicConstraints = (...fields: number[][]) => extension(OID.basicConstraints, der(0x30, ...fields), true)
	const CA = basicConstraints(der(0x01, [0xff]))
	const PKCS1 = der(0x30, der(0x06, OID.sha256Rsa), der(0x05))
	const pss = ({ hash = OID.sha256, mask = OID.mgf1, maskHash = OID.sha256 } = {}) => der(0x30, der(0x06, OID.pss), der(0x30,
		der(0xa0, der(0x30, der(0x06, hash))), der(0xa1, der(0x30, der(0x06, mask), der(0x30, der(0x06, maskHash)))), der(0xa2, der(0x02, [32]))))
	const ROOT = name(utf8('Built Root CA'))
	const INTERMEDIATE = name(utf8('Built Intermediate CA'))

	interface Spec {
		issuer: number[]
		subject: number[]
		extensions?: number[][]
		/** The algorithm in the TBS, and outside it unless `outer` says otherwise. */
		algorithm?: number[]
		outer?: number[]
		/** The throwaway key's SubjectPublicKeyInfo unless given. */
		spki?: ArrayLike<number>
	}

	let keys: { spki: Uint8Array; pkcs1: CryptoKey; pss: CryptoKey }

	beforeAll(async () => {
		const pair = await crypto.subtle.generateKey({ name: 'RSASSA-PKCS1-v1_5', modulusLength: 2048, publicExponent: new Uint8Array([1, 0, 1]), hash: 'SHA-256' }, true, ['sign', 'verify'])
		const pkcs8 = await crypto.subtle.exportKey('pkcs8', pair.privateKey)
		keys = {
			spki: new Uint8Array(await crypto.subtle.exportKey('spki', pair.publicKey)),
			pkcs1: pair.privateKey,
			pss: await crypto.subtle.importKey('pkcs8', pkcs8, { name: 'RSA-PSS', hash: 'SHA-256' }, false, ['sign']),
		}
	})

	/** Signed with SHA-256: PSS with MGF1-SHA-256 and a 32-byte salt under any PSS algorithm, whatever its parameters claim. */
	async function certificate({ issuer, subject, extensions, algorithm = PKCS1, outer = algorithm, spki = keys.spki }: Spec): Promise<string> {
		const validity = der(0x30, der(0x17, new TextEncoder().encode('200101000000Z')), der(0x17, new TextEncoder().encode('491231235959Z')))
		const tbs = der(0x30, der(0xa0, der(0x02, [2])), der(0x02, [1]), algorithm, issuer, validity, subject, spki, extensions ? der(0xa3, der(0x30, ...extensions)) : [])
		const signature = algorithm.some((_, i) => OID.pss.every((byte, j) => algorithm[i + j] === byte))
			? await crypto.subtle.sign({ name: 'RSA-PSS', saltLength: 32 }, keys.pss, Uint8Array.from(tbs))
			: await crypto.subtle.sign('RSASSA-PKCS1-v1_5', keys.pkcs1, Uint8Array.from(tbs))
		return pemOf(der(0x30, tbs, outer, der(0x03, [0x00], new Uint8Array(signature))))
	}

	type Parts = { root?: Partial<Spec>; intermediate?: Partial<Spec>; leaf?: Partial<Spec> }

	/** A root, an intermediate and Mallory's leaf, each replaceable. */
	async function build(parts: Parts = {}) {
		const root = await certificate({ issuer: ROOT, subject: ROOT, extensions: [CA], ...parts.root })
		const intermediate = await certificate({ issuer: ROOT, subject: INTERMEDIATE, extensions: [CA], ...parts.intermediate })
		const leaf = await certificate({ issuer: INTERMEDIATE, subject: name(utf8(MALLORY)), ...parts.leaf })
		return { root, intermediate, leaf }
	}

	async function verifyBuilt(parts: Parts = {}, cloudId = MALLORY) {
		const { root, intermediate, leaf } = await build(parts)
		return verifyFederatedCertificate({ certificate: leaf, chain: [intermediate, root], partnerRootFingerprint: await sha256Hex(root), cloudId })
	}

	it('accepts a chain of the profile Keepiq issues today, as lib/Service/certificate-extensions.cnf writes it', async () => {
		// keyCertSign and cRLSign, with one unused bit; the key identifiers are not critical and not read.
		const keyUsage = extension(OID.keyUsage, der(0x03, [0x01, 0x06]), true)
		const keyIds = [extension(OID.subjectKeyId, der(0x04, new Uint8Array(20).fill(7))), extension(OID.authorityKeyId, der(0x30, der(0x80, new Uint8Array(20).fill(7))))]
		const built = await build({
			root: { extensions: [CA, keyUsage, keyIds[0]!, keyIds[1]!] },
			intermediate: { extensions: [basicConstraints(der(0x01, [0xff]), der(0x02, [0x00])), keyUsage, ...keyIds] },
			// openssl writes CA:FALSE as an empty SEQUENCE.
			leaf: { extensions: [basicConstraints(), ...keyIds] },
		})
		await expect(verifyFederatedCertificate({ certificate: built.leaf, chain: [built.intermediate, built.root], partnerRootFingerprint: await sha256Hex(built.root), cloudId: MALLORY }))
			.resolves.toMatchObject({ fingerprint: FINGERPRINT })
		// OpenSSL agrees each link holds, so the builder and the parser don't share a wrong reading.
		const [root, intermediate, leaf] = [built.root, built.intermediate, built.leaf].map((pem) => new X509Certificate(pem))
		for (const [cert, issuer] of [[root, root], [intermediate, root], [leaf, intermediate]] as const) {
			expect(cert!.checkIssued(issuer!)).toBe(true)
			expect(cert!.verify(issuer!.publicKey)).toBe(true)
		}
	})

	it('accepts a leaf signed with RSASSA-PSS, SHA-256 and MGF1-SHA-256', async () => {
		await expect(verifyBuilt({ leaf: { algorithm: pss() } })).resolves.toMatchObject({ fingerprint: FINGERPRINT })
	})

	it.each([
		['a hash other than SHA-256', { hash: OID.sha384 }],
		['MGF1 with SHA-1', { maskHash: OID.sha1 }],
		['a mask generation function other than MGF1', { mask: [...OID.mgf1.slice(0, -1), 0x09] }],
	])('refuses a PSS signature whose parameters name %s, though it verifies with SHA-256', async (_, params) => {
		expect(await reasonOf(verifyBuilt({ leaf: { algorithm: pss(params) } }))).toBe('bad_chain')
	})

	it.each([
		['a hash that is no AlgorithmIdentifier', der(0x30, der(0x06, OID.pss), der(0x30, der(0xa0, der(0x02, [5]))))],
		['no SEQUENCE', der(0x30, der(0x06, OID.pss), der(0x05))],
		['a field it does not know', der(0x30, der(0x06, OID.pss), der(0x30, der(0xa4, der(0x02, [1]))))],
	])('refuses PSS parameters with %s as malformed', async (_, algorithm) => {
		expect(await reasonOf(verifyBuilt({ leaf: { algorithm } }))).toBe('malformed')
	})

	it('refuses a signature algorithm outside the TBS other than the one inside, though the signature verifies', async () => {
		expect(await reasonOf(verifyBuilt({ leaf: { outer: der(0x30, der(0x06, OID.sha256Rsa)) } }))).toBe('malformed')
	})

	it("refuses a leaf whose issuer name is not the intermediate's, though the intermediate's key signed it", async () => {
		expect(await reasonOf(verifyBuilt({ leaf: { issuer: name(utf8('Another CA')) } }))).toBe('bad_chain')
	})

	it('refuses an intermediate whose basicConstraints spells out cA FALSE', async () => {
		expect(await reasonOf(verifyBuilt({ intermediate: { extensions: [basicConstraints(der(0x01, [0x00]))] } }))).toBe('bad_chain')
	})

	it('refuses a leaf marked CA even when its keyUsage lacks keyCertSign', async () => {
		// digitalSignature only.
		const keyUsage = extension(OID.keyUsage, der(0x03, [0x07, 0x80]), true)
		expect(await reasonOf(verifyBuilt({ leaf: { extensions: [CA, keyUsage] } }))).toBe('bad_chain')
	})

	it('refuses a keyUsage that claims more than 7 unused bits', async () => {
		expect(await reasonOf(verifyBuilt({ intermediate: { extensions: [CA, extension(OID.keyUsage, der(0x03, [0x08, 0x04, 0x00]), true)] } }))).toBe('malformed')
	})

	const ascii = (text: string) => Array.from(new TextEncoder().encode(text))
	const bmp = (text: string) => der(0x1e, Array.from(text).flatMap((char) => [char.charCodeAt(0) >> 8, char.charCodeAt(0) & 0xff]))

	it.each<[string, number[]]>([
		['a second common name', name(utf8('alice@cloud.partner.example'), utf8(MALLORY))],
		['a common name as a BMPString', name(bmp(MALLORY))],
		['a common name that is no UTF-8', name(der(0x0c, [...ascii(MALLORY), 0xff]))],
		...[0x1f, 0x7f, 0xe9].map((byte): [string, number[]] => [`a T61String common name with byte 0x${byte.toString(16)}`, name(der(0x14, [...ascii(MALLORY), byte]))]),
		['an IA5String common name with a control character', name(der(0x16, [...ascii(MALLORY), 0x0a]))],
		['a PrintableString common name with UTF-8 é', name(der(0x13, [...ascii(MALLORY), 0xc3, 0xa9]))],
	])('refuses a leaf with %s', async (_, subject) => {
		expect(await reasonOf(verifyBuilt({ leaf: { subject } }))).toBe('malformed')
	})

	it.each([
		['a PrintableString', 0x13],
		['a T61String of printable ASCII, as OpenSSL may write it', 0x14],
		['an IA5String of printable ASCII', 0x16],
	])('accepts a common name as %s', async (_, tag) => {
		await expect(verifyBuilt({ leaf: { subject: name(der(tag, ascii(MALLORY))) } })).resolves.toMatchObject({ fingerprint: FINGERPRINT })
	})

	it('reads no common name of a CA, which only its name bytes and the pin identify', async () => {
		const root = name(utf8('Built Root CA'), utf8('Built Root CA again'))
		const intermediate = name(utf8('Built Intermediate CA'), bmp('Built Intermediate CA'))
		await expect(verifyBuilt({ root: { issuer: root, subject: root }, intermediate: { issuer: root, subject: intermediate }, leaf: { issuer: intermediate } }))
			.resolves.toMatchObject({ fingerprint: FINGERPRINT })
	})

	it('refuses a common name and a cloud id that are both no cloud id', async () => {
		expect(await reasonOf(verifyBuilt({ leaf: { subject: name(utf8('no cloud id')) } }, 'garbage'))).toBe('name_mismatch')
	})

	it('keeps a byte order mark in the common name, so it names no one', async () => {
		expect(await reasonOf(verifyBuilt({ leaf: { subject: name(der(0x0c, [0xef, 0xbb, 0xbf, ...ascii(MALLORY)])) } }))).toBe('name_mismatch')
	})

	it('refuses a leaf whose key is no SEQUENCE, though its chain verifies', async () => {
		expect(await reasonOf(verifyBuilt({ leaf: { spki: der(0x04, keys.spki) } }))).toBe('malformed')
	})
})

describe('canonicalCloudId', () => {
	it.each([
		['bob@http://Cloud.Partner.example/', 'bob@cloud.partner.example'],
		['Bob@https://cloud.partner.example:8443/nc', 'Bob@cloud.partner.example:8443/nc'],
		['bob@Cloud.Partner.example/NC/', 'bob@cloud.partner.example/NC'],
		['bob', ''],
		['bob@', ''],
		[null, ''],
	])('%s → %s', (input, expected) => {
		expect(canonicalCloudId(input)).toBe(expected)
	})
})
