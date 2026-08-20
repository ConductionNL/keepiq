/**
 * WebAuthn ceremony — the extension acting as a credential provider
 * (extension-passkey-provider). All signing happens client-side in the service
 * worker with the decrypted passkey private key; the server never sees the key,
 * the challenge, or the assertion (ADR-003).
 *
 * v1 supports ES256 (COSE alg -7, ECDSA P-256) — the WebAuthn default and the
 * only algorithm most relying parties require. Attestation is `none`.
 *
 * These functions are PURE ceremony logic: they take/return key material as PEM
 * strings and structured results. The service worker owns decrypt/encrypt and
 * the vault read/write.
 */

import { cborEncode, rawEcdsaToDer } from './cbor.js'

const ES256 = -7
const AAGUID = new Uint8Array(16) // all-zero: a software authenticator

// --- byte helpers ---

function b64urlEncode(bytes) {
	let s = ''
	for (const b of bytes) s += String.fromCharCode(b)
	return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')
}

function b64urlDecode(str) {
	const pad = str.length % 4 === 0 ? '' : '='.repeat(4 - (str.length % 4))
	const b64 = str.replace(/-/g, '+').replace(/_/g, '/') + pad
	return Uint8Array.from(atob(b64), (c) => c.charCodeAt(0))
}

function toBytes(value) {
	if (value instanceof Uint8Array) return value
	if (value instanceof ArrayBuffer) return new Uint8Array(value)
	if (Array.isArray(value)) return Uint8Array.from(value)
	if (typeof value === 'string') return b64urlDecode(value)
	return new Uint8Array(0)
}

function concat(chunks) {
	let len = 0
	for (const c of chunks) len += c.length
	const out = new Uint8Array(len)
	let off = 0
	for (const c of chunks) {
		out.set(c, off)
		off += c.length
	}
	return out
}

async function sha256(bytes) {
	return new Uint8Array(await crypto.subtle.digest('SHA-256', bytes))
}

function pemFrom(b64, label) {
	return `-----BEGIN ${label}-----\n${b64.match(/.{1,64}/g).join('\n')}\n-----END ${label}-----\n`
}
function b64(buf) {
	let s = ''
	for (const b of new Uint8Array(buf)) s += String.fromCharCode(b)
	return btoa(s)
}

// --- authenticator data ---

function u32be(n) {
	return Uint8Array.of(
		(n >>> 24) & 0xff,
		(n >>> 16) & 0xff,
		(n >>> 8) & 0xff,
		n & 0xff,
	)
}

/**
 * COSE EC2 public key (alg ES256, P-256) from a raw 65-byte uncompressed point.
 * @param raw
 */
function coseKeyFromRawPoint(raw) {
	// raw = 0x04 ‖ x(32) ‖ y(32)
	const x = raw.slice(1, 33)
	const y = raw.slice(33, 65)
	const m = new Map()
	m.set(1, 2) // kty: EC2
	m.set(3, ES256) // alg
	m.set(-1, 1) // crv: P-256
	m.set(-2, x)
	m.set(-3, y)
	return cborEncode(m)
}

async function buildAuthData(rpId, flags, counter, attestedCredentialData) {
	const rpIdHash = await sha256(new TextEncoder().encode(rpId))
	const parts = [rpIdHash, Uint8Array.of(flags), u32be(counter)]
	if (attestedCredentialData) parts.push(attestedCredentialData)
	return concat(parts)
}

const FLAG_UP = 0x01
const FLAG_UV = 0x04
const FLAG_AT = 0x40

/**
 * create(): register a new ES256 passkey. Returns the record to save in the
 * vault and the PublicKeyCredential to return to the page.
 *
 * @param {object} options The `publicKey` of navigator.credentials.create
 * @param {string} origin The page origin
 * @return {Promise<{ record: object, credential: object }>}
 */
export async function createCredential(options, origin) {
	const rpId = (options.rp && options.rp.id) || new URL(origin).hostname
	const params = options.pubKeyCredParams || []
	if (params.length && !params.some((p) => p.alg === ES256)) {
		throw new Error('unsupported-algorithm') // caller falls through to platform
	}

	const keyPair = await crypto.subtle.generateKey(
		{ name: 'ECDSA', namedCurve: 'P-256' },
		true,
		['sign', 'verify'],
	)
	const rawPoint = new Uint8Array(
		await crypto.subtle.exportKey('raw', keyPair.publicKey),
	)
	const pkcs8 = new Uint8Array(
		await crypto.subtle.exportKey('pkcs8', keyPair.privateKey),
	)
	const privateKeyPem = pemFrom(b64(pkcs8), 'PRIVATE KEY')

	const credentialId = crypto.getRandomValues(new Uint8Array(16))

	const clientData = {
		type: 'webauthn.create',
		challenge: b64urlEncode(toBytes(options.challenge)),
		origin,
		crossOrigin: false,
	}
	const clientDataJSON = new TextEncoder().encode(JSON.stringify(clientData))

	const cosePub = coseKeyFromRawPoint(rawPoint)
	const attestedCredentialData = concat([
		AAGUID,
		Uint8Array.of((credentialId.length >> 8) & 0xff, credentialId.length & 0xff),
		credentialId,
		cosePub,
	])
	const authData = await buildAuthData(
		rpId,
		FLAG_UP | FLAG_UV | FLAG_AT,
		0,
		attestedCredentialData,
	)

	const attObj = new Map()
	attObj.set('fmt', 'none')
	attObj.set('attStmt', new Map())
	attObj.set('authData', authData)
	const attestationObject = cborEncode(attObj)

	const record = {
		credentialId: b64urlEncode(credentialId),
		rpId,
		rpName: (options.rp && options.rp.name) || rpId,
		userName: (options.user && options.user.name) || '',
		userDisplayName: (options.user && options.user.displayName) || '',
		userHandle: options.user ? b64urlEncode(toBytes(options.user.id)) : '',
		privateKey: privateKeyPem,
		algorithm: ES256,
		counter: 0,
	}
	const credential = {
		id: record.credentialId,
		rawId: Array.from(credentialId),
		type: 'public-key',
		response: {
			clientDataJSON: Array.from(clientDataJSON),
			attestationObject: Array.from(attestationObject),
		},
	}
	return { record, credential }
}

/**
 * get(): assert with a stored passkey. Signs client-side and returns the
 * assertion plus the counter to persist (unchanged when the stored counter is 0,
 * the synced-credential convention).
 *
 * @param {object} options The `publicKey` of navigator.credentials.get
 * @param {string} origin The page origin
 * @param {object} stored The stored passkey ({ credentialId, rpId, privateKey (PEM), counter, userHandle })
 * @return {Promise<{ assertion: object, counter: number }>}
 */
export async function getAssertion(options, origin, stored) {
	const rpId = options.rpId || new URL(origin).hostname
	const clientData = {
		type: 'webauthn.get',
		challenge: b64urlEncode(toBytes(options.challenge)),
		origin,
		crossOrigin: false,
	}
	const clientDataJSON = new TextEncoder().encode(JSON.stringify(clientData))
	const clientDataHash = await sha256(clientDataJSON)

	// Synced (counter 0) credentials keep 0; hardware-style counters increment.
	const nextCounter = stored.counter > 0 ? stored.counter + 1 : 0
	const authData = await buildAuthData(rpId, FLAG_UP | FLAG_UV, nextCounter, null)

	const privateKey = await crypto.subtle.importKey(
		'pkcs8',
		pemToPkcs8(stored.privateKey),
		{ name: 'ECDSA', namedCurve: 'P-256' },
		false,
		['sign'],
	)
	const signed = new Uint8Array(
		await crypto.subtle.sign(
			{ name: 'ECDSA', hash: 'SHA-256' },
			privateKey,
			concat([authData, clientDataHash]),
		),
	)
	const signature = rawEcdsaToDer(signed)

	const credIdBytes = b64urlDecode(stored.credentialId)
	const assertion = {
		id: stored.credentialId,
		rawId: Array.from(credIdBytes),
		type: 'public-key',
		response: {
			clientDataJSON: Array.from(clientDataJSON),
			authenticatorData: Array.from(authData),
			signature: Array.from(signature),
			userHandle: stored.userHandle
				? Array.from(b64urlDecode(stored.userHandle))
				: null,
		},
	}
	return { assertion, counter: nextCounter }
}

function pemToPkcs8(pem) {
	const b64body = pem
		.replace(/-----BEGIN [^-]+-----/, '')
		.replace(/-----END [^-]+-----/, '')
		.replace(/\s+/g, '')
	return Uint8Array.from(atob(b64body), (c) => c.charCodeAt(0))
}

export const _internals = {
	b64urlEncode,
	b64urlDecode,
	coseKeyFromRawPoint,
	buildAuthData,
	sha256,
	pemToPkcs8,
}
