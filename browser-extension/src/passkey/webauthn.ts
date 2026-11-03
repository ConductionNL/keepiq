/** WebAuthn Level 3 authenticator responses for ES256 passkeys; pure, every key lives only for the call. */
import { fromBase64Url, toBase64Url } from '@/src/crypto/base64'
import { pemToPkcs8 } from '@/src/crypto/rsa'
import { concat } from './bytes'
import { encodeCbor, type CborValue } from './cbor'
import { buildPasskeyCredential, type PasskeyCredential } from './credential'

export const ES256 = -7

/** Keepiq has no registered AAGUID (design.md, Open Questions). */
export const AAGUID = new Uint8Array(16)

export const FLAGS = { UP: 0x01, UV: 0x04, BE: 0x08, BS: 0x10, AT: 0x40 } as const

const ECDSA_P256 = { name: 'ECDSA', namedCurve: 'P-256' } as const
const ECDSA_SHA256 = { name: 'ECDSA', hash: 'SHA-256' } as const

/** BE and BS always, since a Keepiq passkey syncs with the vault; UV only when the user verified in this ceremony. */
export function authenticatorFlags(verified: boolean, attested: boolean): number {
	return FLAGS.UP | FLAGS.BE | FLAGS.BS | (verified ? FLAGS.UV : 0) | (attested ? FLAGS.AT : 0)
}

async function sha256(bytes: Uint8Array<ArrayBuffer>): Promise<Uint8Array> {
	return new Uint8Array(await crypto.subtle.digest('SHA-256', bytes))
}

/** Members in the order WebAuthn's serialisation fixes, which relying parties may compare as bytes. */
export function clientDataJSON(type: 'webauthn.create' | 'webauthn.get', challenge: Uint8Array, origin: string): Uint8Array<ArrayBuffer> {
	return new TextEncoder().encode(JSON.stringify({ type, challenge: toBase64Url(challenge), origin, crossOrigin: false }))
}

export function ec2CoseKey(publicKey: JsonWebKey): Uint8Array {
	const x = fromBase64Url(publicKey.x ?? '')
	const y = fromBase64Url(publicKey.y ?? '')
	if (publicKey.crv !== 'P-256' || x.length !== 32 || y.length !== 32) throw new Error('Not a P-256 public key')
	return encodeCbor(new Map<number, number | Uint8Array>([[1, 2], [3, ES256], [-1, 1], [-2, x], [-3, y]]))
}

export function attestedCredentialData(credentialId: Uint8Array, coseKey: Uint8Array): Uint8Array {
	if (credentialId.length > 1023) throw new RangeError('Credential id too long')
	return concat(AAGUID, new Uint8Array([credentialId.length >> 8, credentialId.length & 0xff]), credentialId, coseKey)
}

export async function authenticatorData(rpId: string, flags: number, counter: number, attested?: Uint8Array): Promise<Uint8Array<ArrayBuffer>> {
	if (!Number.isInteger(counter) || counter < 0 || counter > 0xffffffff) throw new RangeError(`Counter out of range: ${counter}`)
	const tail = new Uint8Array(5)
	tail[0] = flags
	new DataView(tail.buffer).setUint32(1, counter)
	return concat(await sha256(new TextEncoder().encode(rpId)), tail, attested ?? new Uint8Array())
}

/** Format `none` whatever the page asked: Keepiq makes no attestation statement. */
export function noneAttestationObject(authData: Uint8Array): Uint8Array {
	return encodeCbor(new Map<string, CborValue>([['fmt', 'none'], ['attStmt', new Map()], ['authData', authData]]))
}

function derInteger(value: Uint8Array): Uint8Array {
	let start = 0
	while (start < value.length - 1 && value[start] === 0) start++
	const bytes = value.subarray(start)
	const padded = bytes[0]! & 0x80 ? concat(new Uint8Array([0]), bytes) : bytes
	return concat(new Uint8Array([0x02, padded.length]), padded)
}

/** WebCrypto signs as r‖s (IEEE P1363); WebAuthn wants an ASN.1 DER `Ecdsa-Sig-Value`. */
export function p1363ToDer(signature: Uint8Array): Uint8Array {
	if (signature.length !== 64) throw new Error('Not a P-256 signature')
	const body = concat(derInteger(signature.subarray(0, 32)), derInteger(signature.subarray(32)))
	return concat(new Uint8Array([0x30, body.length]), body)
}

/** A stored `privateKey` is base64url PKCS#8 (CXF), or PEM as the mobile app writes it. */
export function importSigningKey(privateKey: string): Promise<CryptoKey> {
	const pkcs8 = privateKey.trimStart().startsWith('-----BEGIN') ? pemToPkcs8(privateKey) : fromBase64Url(privateKey)
	return crypto.subtle.importKey('pkcs8', pkcs8, ECDSA_P256, false, ['sign'])
}

export interface CreateInput {
	origin: string
	rpId: string
	rpName: string
	user: { id: Uint8Array; name: string; displayName: string }
	challenge: Uint8Array
	verified: boolean
}

export interface Registration {
	/** Canonical, holding the private key: encrypt it, never send it on. */
	credential: PasskeyCredential
	rawId: Uint8Array
	clientDataJSON: Uint8Array
	authenticatorData: Uint8Array
	attestationObject: Uint8Array
	/** SubjectPublicKeyInfo, for the page's `getPublicKey()`. */
	publicKey: Uint8Array
}

export async function createCredential(input: CreateInput): Promise<Registration> {
	const pair = await crypto.subtle.generateKey(ECDSA_P256, true, ['sign', 'verify'])
	const rawId = crypto.getRandomValues(new Uint8Array(16))
	const pkcs8 = new Uint8Array(await crypto.subtle.exportKey('pkcs8', pair.privateKey))
	const coseKey = ec2CoseKey(await crypto.subtle.exportKey('jwk', pair.publicKey))
	const credential = buildPasskeyCredential({
		credentialId: toBase64Url(rawId),
		rpId: input.rpId,
		rpName: input.rpName,
		userName: input.user.name,
		userDisplayName: input.user.displayName,
		userHandle: toBase64Url(input.user.id),
		privateKey: toBase64Url(pkcs8),
		algorithm: ES256,
		counter: 0,
		transports: ['internal'],
		createdAt: new Date().toISOString(),
	})!
	const authData = await authenticatorData(input.rpId, authenticatorFlags(input.verified, true), 0, attestedCredentialData(rawId, coseKey))
	return {
		credential,
		rawId,
		clientDataJSON: clientDataJSON('webauthn.create', input.challenge, input.origin),
		authenticatorData: authData,
		attestationObject: noneAttestationObject(authData),
		publicKey: new Uint8Array(await crypto.subtle.exportKey('spki', pair.publicKey)),
	}
}

export interface AssertInput {
	credential: PasskeyCredential
	/** The credential's `privateKey`, imported with `importSigningKey`. */
	key: CryptoKey
	origin: string
	challenge: Uint8Array
	/** The value to sign with: the stored one, or the saved increment (design.md § Writes). */
	counter: number
	verified: boolean
}

export interface Assertion {
	rawId: Uint8Array
	clientDataJSON: Uint8Array
	authenticatorData: Uint8Array
	signature: Uint8Array
	userHandle: Uint8Array | null
}

export async function getAssertion(input: AssertInput): Promise<Assertion> {
	const { credential, key } = input
	if (credential.algorithm !== ES256) throw new Error(`Unsupported passkey algorithm ${credential.algorithm}`)
	const clientData = clientDataJSON('webauthn.get', input.challenge, input.origin)
	const authData = await authenticatorData(credential.rpId, authenticatorFlags(input.verified, false), input.counter)
	const signature = new Uint8Array(await crypto.subtle.sign(ECDSA_SHA256, key, concat(authData, await sha256(clientData))))
	return {
		rawId: fromBase64Url(credential.credentialId),
		clientDataJSON: clientData,
		authenticatorData: authData,
		signature: p1363ToDer(signature),
		userHandle: credential.userHandle === '' ? null : fromBase64Url(credential.userHandle),
	}
}
