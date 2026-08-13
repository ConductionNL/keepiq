/**
 * CXP (FIDO Credential Exchange Protocol) transport layer for cxp-transfer.
 *
 * CXP is a *transport wrapper*, not a new format: it HPKE-seals the identical
 * CXF payload `cxf-import-export` assembles and unseals it back into what the
 * CXF import pipeline consumes, so credentials move provider-to-provider with no
 * plaintext file on disk (design). This module owns the request/response
 * handshake and the sealed-envelope framing; all crypto is in `./hpke.js`.
 *
 * Every version/suite specific lives here or in `./hpke.js` — a CXP spec
 * revision touches only these two files.
 */

import {
	seal,
	open,
	generateRecipientKeyPair,
	KEM_ID,
	KDF_ID,
	AEAD_ID,
} from './hpke.js'

/** Pinned CXP transport version, validated at open time (fail-fast on mismatch). */
export const CXP_VERSION = 'doriath-cxp-v1'
export const REQUESTED_FORMAT = 'CXF'

const PINNED_SUITE = { kem: KEM_ID, kdf: KDF_ID, aead: AEAD_ID }

// --- byte/base64 helpers (chunked so large CXF payloads don't overflow the stack) ---

function bytesToB64(bytes) {
	let s = ''
	const CHUNK = 0x8000
	for (let i = 0; i < bytes.length; i += CHUNK) {
		s += String.fromCharCode.apply(null, bytes.subarray(i, i + CHUNK))
	}
	return btoa(s)
}

function b64ToBytes(b64) {
	return Uint8Array.from(atob(b64), (c) => c.charCodeAt(0))
}

async function fingerprint(bytes) {
	const digest = new Uint8Array(await crypto.subtle.digest('SHA-256', bytes))
	return Array.from(digest)
		.map((b) => b.toString(16).padStart(2, '0'))
		.join('')
}

function randomNonce() {
	return crypto.getRandomValues(new Uint8Array(16))
}

// The HPKE `info` binds the ciphertext to the CXP version + request nonce, so an
// envelope produced for one request cannot open under another (design: bind a
// sealed envelope to the request nonce/public-key that produced it).
function bindingInfo(nonceBytes) {
	const version = new TextEncoder().encode(CXP_VERSION + ':')
	const out = new Uint8Array(version.length + nonceBytes.length)
	out.set(version, 0)
	out.set(nonceBytes, version.length)
	return out
}

// AEAD additional data = the pinned suite triple, so a suite downgrade in the
// envelope header fails the AEAD tag rather than silently mis-decrypting.
function suiteAad(suite) {
	return new TextEncoder().encode(`${suite.kem}.${suite.kdf}.${suite.aead}`)
}

/**
 * IMPORTING provider — step 1: create a CXP request. Returns the serializable
 * `request` to hand to the exporting provider, and a `session` holding the
 * ephemeral private key that NEVER leaves the browser and is discarded after a
 * successful open.
 *
 * @return {Promise<{ request: object, session: object }>}
 */
export async function createImportRequest() {
	const { privateKey, publicKeyRaw } = await generateRecipientKeyPair()
	const nonce = randomNonce()
	const request = {
		v: CXP_VERSION,
		requestedFormat: REQUESTED_FORMAT,
		requesterPublicKey: bytesToB64(publicKeyRaw),
		nonce: bytesToB64(nonce),
	}
	const session = {
		privateKey,
		publicKeyRaw,
		nonce,
		recipientFp: await fingerprint(publicKeyRaw),
	}
	return { request, session }
}

/**
 * EXPORTING provider — seal an assembled CXF payload for a CXP request. Produces
 * only the sealed envelope; no plaintext is returned or written.
 *
 * @param {object} request     The peer's CXP request
 * @param {Uint8Array} cxfBytes The assembled CXF payload (UTF-8 JSON bytes)
 * @return {Promise<object>} the sealed envelope
 */
export async function sealForRequest(request, cxfBytes) {
	if (!request || request.v !== CXP_VERSION) {
		throw new Error(`cxp: unsupported request version ${request && request.v}`)
	}
	if (request.requestedFormat !== REQUESTED_FORMAT) {
		throw new Error(
			`cxp: unsupported requested format ${request.requestedFormat}`,
		)
	}
	const pkRraw = b64ToBytes(request.requesterPublicKey)
	const nonce = b64ToBytes(request.nonce)
	const info = bindingInfo(nonce)
	const aad = suiteAad(PINNED_SUITE)
	const { enc, ciphertext } = await seal(pkRraw, info, aad, cxfBytes)
	return {
		v: CXP_VERSION,
		suite: { ...PINNED_SUITE },
		nonce: request.nonce,
		recipientFp: await fingerprint(pkRraw),
		enc: bytesToB64(enc),
		ct: bytesToB64(ciphertext),
	}
}

function assertPinnedSuite(suite) {
	if (
		!suite
		|| suite.kem !== PINNED_SUITE.kem
		|| suite.kdf !== PINNED_SUITE.kdf
		|| suite.aead !== PINNED_SUITE.aead
	) {
		throw new Error(
			'cxp: unsupported HPKE suite — refusing to open (possible downgrade)',
		)
	}
}

/**
 * IMPORTING provider — step 2: open a sealed envelope with the request session.
 * Validates version + suite and binds the envelope to the request (nonce +
 * recipient fingerprint) BEFORE attempting a decrypt, so a misdirected envelope
 * is rejected without a decrypt attempt. Returns the CXF payload bytes.
 *
 * @param {object} session  The session from createImportRequest
 * @param {object} envelope The sealed envelope from the exporting provider
 * @return {Promise<Uint8Array>} the CXF payload bytes (never written to disk)
 */
export async function openEnvelope(session, envelope) {
	if (!envelope || envelope.v !== CXP_VERSION) {
		throw new Error(
			`cxp: unsupported envelope version ${envelope && envelope.v}`,
		)
	}
	assertPinnedSuite(envelope.suite)
	// Pre-open binding checks — reject a misdirected envelope before decrypting.
	if (envelope.nonce !== bytesToB64(session.nonce)) {
		throw new Error(
			'cxp: envelope nonce does not match the request — misdirected envelope',
		)
	}
	if (envelope.recipientFp !== session.recipientFp) {
		throw new Error(
			'cxp: envelope recipient fingerprint does not match this session',
		)
	}
	const info = bindingInfo(session.nonce)
	const aad = suiteAad(PINNED_SUITE)
	const plaintext = await open(
		session.privateKey,
		session.publicKeyRaw,
		b64ToBytes(envelope.enc),
		info,
		aad,
		b64ToBytes(envelope.ct),
	)
	return plaintext
}

export const _internals = {
	bytesToB64,
	b64ToBytes,
	fingerprint,
	bindingInfo,
	suiteAad,
	PINNED_SUITE,
}
