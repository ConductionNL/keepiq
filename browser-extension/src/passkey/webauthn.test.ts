import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'
import { fromBase64, fromBase64Url, toBase64 } from '@/src/crypto/base64'
import { parsePasskey, type PasskeyCredential } from './credential'
import {
	AAGUID,
	attestedCredentialData,
	authenticatorData,
	authenticatorFlags,
	clientDataJSON,
	createCredential,
	ec2CoseKey,
	FLAGS,
	getAssertion,
	importSigningKey,
	noneAttestationObject,
	p1363ToDer,
	type AssertInput,
} from './webauthn'

const assert = async (input: Omit<AssertInput, 'key'>) => getAssertion({ ...input, key: await importSigningKey(input.credential.privateKey) })

// Frozen output of the old extension's webauthn.js, kept in the app repo.
const vector = JSON.parse(readFileSync(new URL('../../../tests/vectors/crypto/passkey.json', import.meta.url), 'utf8'))

// What a relying party does, written apart from the module under test: its own CBOR and DER readers.
type Cbor = number | string | Uint8Array | Map<Cbor, Cbor> | Cbor[]

function decodeCbor(bytes: Uint8Array): Cbor {
	let at = 0
	const length = (info: number): number => {
		if (info < 24) return info
		const size = 2 ** (info - 24)
		let value = 0
		for (let i = 0; i < size; i++) value = value * 256 + bytes[at++]!
		return value
	}
	const item = (): Cbor => {
		const first = bytes[at++]!
		const major = first >> 5
		const n = length(first & 31)
		switch (major) {
			case 0: return n
			case 1: return -1 - n
			case 2: return bytes.slice(at, (at += n))
			case 3: return new TextDecoder().decode(bytes.slice(at, (at += n)))
			case 4: return Array.from({ length: n }, item)
			case 5: return new Map(Array.from({ length: n }, () => [item(), item()] as [Cbor, Cbor]))
			default: throw new Error(`major type ${major}`)
		}
	}
	const value = item()
	if (at !== bytes.length) throw new Error('trailing bytes')
	return value
}

function derToP1363(der: Uint8Array): Uint8Array<ArrayBuffer> {
	expect(der[0]).toBe(0x30)
	expect(der[1]).toBe(der.length - 2)
	const out = new Uint8Array(64)
	let at = 2
	for (const offset of [0, 32]) {
		expect(der[at]).toBe(0x02)
		const n = der[at + 1]!
		const value = der.slice(at + 2, at + 2 + n)
		// Minimal: no leading zero unless the next byte has its high bit set.
		if (value.length > 1) expect(value[0] === 0 && value[1]! < 0x80).toBe(false)
		const trimmed = value[0] === 0 ? value.slice(1) : value
		expect(trimmed.length).toBeLessThanOrEqual(32)
		out.set(trimmed, offset + 32 - trimmed.length)
		at += 2 + n
	}
	expect(at).toBe(der.length)
	return out
}

const sha256 = async (bytes: Uint8Array<ArrayBuffer>) => new Uint8Array(await crypto.subtle.digest('SHA-256', bytes))
const ab = (bytes: Uint8Array) => new Uint8Array(bytes)

interface ParsedAuthData {
	rpIdHash: Uint8Array
	flags: number
	counter: number
	aaguid?: Uint8Array
	credentialId?: Uint8Array
	publicKey?: Map<Cbor, Cbor>
}

function parseAuthData(data: Uint8Array): ParsedAuthData {
	const parsed: ParsedAuthData = { rpIdHash: data.slice(0, 32), flags: data[32]!, counter: new DataView(ab(data).buffer).getUint32(33) }
	if (!(parsed.flags & 0x40)) {
		expect(data.length).toBe(37)
		return parsed
	}
	const idLength = (data[53]! << 8) | data[54]!
	parsed.aaguid = data.slice(37, 53)
	parsed.credentialId = data.slice(55, 55 + idLength)
	parsed.publicKey = decodeCbor(data.slice(55 + idLength)) as Map<Cbor, Cbor>
	return parsed
}

async function coseToCryptoKey(cose: Map<Cbor, Cbor>): Promise<CryptoKey> {
	expect([cose.get(1), cose.get(3), cose.get(-1)]).toEqual([2, -7, 1])
	const b64u = (bytes: Cbor) => toBase64(bytes as Uint8Array).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')
	return crypto.subtle.importKey('jwk', { kty: 'EC', crv: 'P-256', x: b64u(cose.get(-2)!), y: b64u(cose.get(-3)!) }, { name: 'ECDSA', namedCurve: 'P-256' }, false, ['verify'])
}

async function verifySignature(key: CryptoKey, authData: Uint8Array, clientData: Uint8Array, der: Uint8Array): Promise<boolean> {
	const signed = new Uint8Array([...authData, ...(await sha256(ab(clientData)))])
	return crypto.subtle.verify({ name: 'ECDSA', hash: 'SHA-256' }, key, derToP1363(der), signed)
}

/** WebAuthn § 7.1 and § 7.2 as far as a `none` attestation and an assertion go. */
async function verifyRegistration(registration: { clientDataJSON: Uint8Array; attestationObject: Uint8Array; rawId: Uint8Array }, expected: { rpId: string; origin: string; challenge: Uint8Array }) {
	const clientData = JSON.parse(new TextDecoder().decode(registration.clientDataJSON))
	expect(clientData).toEqual({ type: 'webauthn.create', challenge: toBase64(expected.challenge).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, ''), origin: expected.origin, crossOrigin: false })
	const attestation = decodeCbor(registration.attestationObject) as Map<Cbor, Cbor>
	expect(attestation.get('fmt')).toBe('none')
	expect((attestation.get('attStmt') as Map<Cbor, Cbor>).size).toBe(0)
	const authData = parseAuthData(attestation.get('authData') as Uint8Array)
	expect(authData.rpIdHash).toEqual(await sha256(new TextEncoder().encode(expected.rpId)))
	expect(authData.flags & 0x41).toBe(0x41)
	expect(authData.credentialId).toEqual(registration.rawId)
	return { authData, publicKey: await coseToCryptoKey(authData.publicKey!) }
}

async function verifyAssertion(
	assertion: { clientDataJSON: Uint8Array; authenticatorData: Uint8Array; signature: Uint8Array },
	publicKey: CryptoKey,
	expected: { rpId: string; origin: string; challenge: Uint8Array },
) {
	const clientData = JSON.parse(new TextDecoder().decode(assertion.clientDataJSON))
	expect(clientData.type).toBe('webauthn.get')
	expect(fromBase64Url(clientData.challenge)).toEqual(expected.challenge)
	expect(clientData.origin).toBe(expected.origin)
	const authData = parseAuthData(assertion.authenticatorData)
	expect(authData.rpIdHash).toEqual(await sha256(new TextEncoder().encode(expected.rpId)))
	expect(authData.flags & 0x01).toBe(0x01)
	expect(await verifySignature(publicKey, assertion.authenticatorData, assertion.clientDataJSON, assertion.signature)).toBe(true)
	return authData
}

const challenge = crypto.getRandomValues(new Uint8Array(32))
const site = { rpId: 'bank.example', origin: 'https://login.bank.example', challenge }
const user = { id: new TextEncoder().encode('user-7f3a'), name: 'alice@bank.example', displayName: 'Alice' }

describe('a registration and an assertion', () => {
	it('verify with an independent relying party', async () => {
		const registration = await createCredential({ ...site, rpName: 'Bank', user, verified: true })
		const { authData, publicKey } = await verifyRegistration(registration, site)
		expect(authData.aaguid).toEqual(new Uint8Array(16))
		expect(authData.counter).toBe(0)

		// The page's getPublicKey() is the same key.
		const spki = await crypto.subtle.importKey('spki', ab(registration.publicKey), { name: 'ECDSA', namedCurve: 'P-256' }, true, ['verify'])
		const fromSpki = await crypto.subtle.exportKey('jwk', spki)
		expect([authData.publicKey!.get(-2), authData.publicKey!.get(-3)]).toEqual([fromBase64Url(fromSpki.x!), fromBase64Url(fromSpki.y!)])

		const stored = parsePasskey(JSON.stringify(registration.credential))!
		const signIn = crypto.getRandomValues(new Uint8Array(32))
		const assertion = await assert({ credential: stored, origin: site.origin, challenge: signIn, counter: 0, verified: false })
		await verifyAssertion(assertion, publicKey, { ...site, challenge: signIn })
		expect(assertion.rawId).toEqual(registration.rawId)
		expect(assertion.userHandle).toEqual(user.id)
	})

	it('stores the canonical credential the spec names', async () => {
		const { credential, rawId } = await createCredential({ ...site, rpName: 'Bank', user, verified: false })
		expect(Object.keys(credential)).toEqual([
			'credentialId', 'rpId', 'rpName', 'userName', 'userDisplayName', 'userHandle', 'privateKey', 'algorithm', 'counter', 'transports', 'createdAt',
		])
		expect(credential).toMatchObject({ rpId: 'bank.example', rpName: 'Bank', userName: user.name, userDisplayName: 'Alice', userHandle: 'dXNlci03ZjNh', algorithm: -7, counter: 0, transports: ['internal'] })
		expect(rawId).toHaveLength(16)
		expect(fromBase64Url(credential.credentialId)).toEqual(rawId)
		// Base64url PKCS#8, no padding or PEM armour.
		expect(credential.privateKey).toMatch(/^[A-Za-z0-9_-]+$/)
		expect(Date.parse(credential.createdAt)).not.toBeNaN()
	})

	it('signs with the counter it is given', async () => {
		const registration = await createCredential({ ...site, rpName: 'Bank', user, verified: false })
		const { publicKey } = await verifyRegistration(registration, site)
		const assertion = await assert({ credential: registration.credential, origin: site.origin, challenge, counter: 42, verified: false })
		expect((await verifyAssertion(assertion, publicKey, site)).counter).toBe(42)
	})

	it('has a null user handle when none is stored', async () => {
		const { credential } = await createCredential({ ...site, rpName: 'Bank', user, verified: false })
		const assertion = await assert({ credential: { ...credential, userHandle: '' }, origin: site.origin, challenge, counter: 0, verified: false })
		expect(assertion.userHandle).toBeNull()
	})

	it('refuses an algorithm other than ES256', async () => {
		const { credential } = await createCredential({ ...site, rpName: 'Bank', user, verified: false })
		await expect(assert({ credential: { ...credential, algorithm: -257 }, origin: site.origin, challenge, counter: 0, verified: false })).rejects.toThrow(/algorithm/)
	})
})

describe('flags', () => {
	it.each([
		['create, verified', true, true, 0x5d],
		['create, not verified', false, true, 0x59],
		['get, verified', true, false, 0x1d],
		['get, not verified', false, false, 0x19],
	])('%s', async (_name, verified, attested, flags) => {
		expect(authenticatorFlags(verified, attested)).toBe(flags)
		const data = attested
			? (await createCredential({ ...site, rpName: 'Bank', user, verified })).authenticatorData
			: (await assert({ credential: (await createCredential({ ...site, rpName: 'Bank', user, verified: false })).credential, origin: site.origin, challenge, counter: 0, verified })).authenticatorData
		const parsed = parseAuthData(data)
		expect(parsed.flags).toBe(flags)
		expect(Boolean(parsed.flags & FLAGS.UV)).toBe(verified)
		expect(parsed.flags & (FLAGS.UP | FLAGS.BE | FLAGS.BS)).toBe(FLAGS.UP | FLAGS.BE | FLAGS.BS)
	})
})

describe('the old extension vector', () => {
	const credential = parsePasskey(vector.itemJson) as PasskeyCredential
	const vectorChallenge = fromBase64Url(vector.challengeBase64Url)
	const vectorKey = () => crypto.subtle.importKey('spki', fromBase64(vector.publicKeySpki), { name: 'ECDSA', namedCurve: 'P-256' }, false, ['verify'])

	it('verifies with the independent verifier', async () => {
		const key = await vectorKey()
		for (const assertion of vector.assertions) {
			const authData = fromBase64(assertion.authenticatorData)
			expect(await verifySignature(key, authData, fromBase64Url(assertion.clientDataJSON), fromBase64(assertion.signatureDer))).toBe(true)
		}
	})

	it('builds the same client data and authenticator data', async () => {
		const [first] = vector.assertions
		expect(clientDataJSON('webauthn.get', vectorChallenge, vector.origin)).toEqual(fromBase64Url(first.clientDataJSON))
		expect(await authenticatorData(credential.rpId, FLAGS.UP | FLAGS.UV, first.nextCounter)).toEqual(fromBase64(first.authenticatorData))
	})

	it('signs with its PEM key so its public key verifies', async () => {
		const assertion = await assert({ credential, origin: vector.origin, challenge: vectorChallenge, counter: 7, verified: true })
		expect((await verifyAssertion(assertion, await vectorKey(), { rpId: credential.rpId, origin: vector.origin, challenge: vectorChallenge })).counter).toBe(7)
	})

	it('builds the same attestation object byte for byte', async () => {
		const registered = parsePasskey(vector.registration.itemJson)!
		const pem = registered.privateKey.replace(/-----[A-Z ]+-----|\s/g, '')
		const jwk = await crypto.subtle.exportKey('jwk', await crypto.subtle.importKey('pkcs8', fromBase64(pem), { name: 'ECDSA', namedCurve: 'P-256' }, true, ['sign']))
		const attested = attestedCredentialData(fromBase64Url(registered.credentialId), ec2CoseKey({ crv: jwk.crv, x: jwk.x, y: jwk.y }))
		const authData = await authenticatorData(registered.rpId, FLAGS.UP | FLAGS.UV | FLAGS.AT, 0, attested)
		expect(toBase64(noneAttestationObject(authData))).toBe(vector.registration.attestationObject)
		expect(clientDataJSON('webauthn.create', vectorChallenge, vector.registration.origin)).toEqual(fromBase64Url(vector.registration.clientDataJSON))
	})
})

describe('p1363ToDer', () => {
	const signature = (r: number[], s: number[]) => {
		const out = new Uint8Array(64)
		out.set(r, 32 - r.length)
		out.set(s, 64 - s.length)
		return out
	}

	it.each([
		['small values', signature([1], [2]), '3006020101020102'],
		['a high bit gets a zero byte', signature([0x80, ...new Array(31).fill(0)], [1]), '3026022100' + '80' + '00'.repeat(31) + '020101'],
		['leading zeros go', signature([0, 0, 0x7f, 1], [0, 0x80]), '30080202' + '7f01' + '02020080'],
		['zero stays one byte', signature([], [5]), '3006020100020105'],
	])('%s', (_name, input, expected) => {
		expect(Buffer.from(p1363ToDer(input)).toString('hex')).toBe(expected)
		expect(derToP1363(p1363ToDer(input))).toEqual(input)
	})

	it('refuses a signature that is not 64 bytes', () => {
		expect(() => p1363ToDer(new Uint8Array(63))).toThrow()
	})
})

describe('pieces', () => {
	it('has a zero AAGUID', () => {
		expect(AAGUID).toEqual(new Uint8Array(16))
	})

	it('puts the client data members in order', () => {
		expect(new TextDecoder().decode(clientDataJSON('webauthn.get', new Uint8Array([251, 255]), 'https://a.example'))).toBe(
			'{"type":"webauthn.get","challenge":"-_8","origin":"https://a.example","crossOrigin":false}',
		)
	})

	it.each([-1, 2 ** 32, 1.5])('refuses counter %d', async (counter) => {
		await expect(authenticatorData('a.example', 1, counter)).rejects.toThrow(RangeError)
	})
})
