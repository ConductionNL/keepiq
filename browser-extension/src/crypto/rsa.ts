import { fromBase64, toBase64 } from './base64'

const RSA_BLOCK_SIZE = 512
const RSA_CHUNK_SIZE = 446
const RSA_OAEP = { name: 'RSA-OAEP', hash: 'SHA-256' }

/** Exactly one block and nothing around it, so no second key rides along with the one a caller checked. */
export function decodePem(pem: string): { label: string; der: Uint8Array<ArrayBuffer> } {
	const match = /^\s*-----BEGIN ([A-Z ]+)-----([A-Za-z0-9+/=\s]+)-----END \1-----\s*$/.exec(pem)
	if (!match) throw new Error('Malformed PEM: expected exactly one block')
	return { label: match[1]!, der: fromBase64(match[2]!.replace(/\s/g, '')) }
}

export function pemToPkcs8(pem: string): Uint8Array<ArrayBuffer> {
	return single(decodePem(pem).der)
}

export function importPrivateKey(pkcs8: Uint8Array<ArrayBuffer>): Promise<CryptoKey> {
	return crypto.subtle.importKey('pkcs8', pkcs8, RSA_OAEP, false, ['decrypt'])
}

/** A byte that must exist; a short certificate is malformed, not zero. */
function byteAt(der: Uint8Array, offset: number): number {
	const byte = der[offset]
	if (byte === undefined) throw new Error('Malformed certificate: truncated')
	return byte
}

/** A length that fits in the buffer, so a hostile certificate can't loop or read out of bounds. */
function readDerLength(der: Uint8Array, offset: number): { length: number; contentStart: number } {
	const first = byteAt(der, offset)
	let length = first
	let contentStart = offset + 1
	if (first & 0x80) {
		const count = first & 0x7f
		if (count === 0 || count > 4) throw new Error('Malformed certificate: bad length')
		length = 0
		for (let i = 0; i < count; i++) length = length * 256 + byteAt(der, offset + 1 + i)
		contentStart += count
	}
	if (contentStart + length > der.length) throw new Error('Malformed certificate: length past the end')
	return { length, contentStart }
}

const SEQUENCE = 0x30

/** One SEQUENCE and nothing after it: trailing bytes would be a second, unchecked value. */
function single(der: Uint8Array<ArrayBuffer>): Uint8Array<ArrayBuffer> {
	if (der[0] !== SEQUENCE) throw new Error('Malformed DER: not a SEQUENCE')
	const { length, contentStart } = readDerLength(der, 1)
	if (contentStart + length !== der.length) throw new Error('Malformed DER: bytes after the end')
	return der
}

/** The SubjectPublicKeyInfo of an X.509 certificate: the TBS field after `subject`. */
export function extractSpki(certDer: Uint8Array<ArrayBuffer>): Uint8Array<ArrayBuffer> {
	single(certDer)
	const outer = readDerLength(certDer, 1)
	if (certDer[outer.contentStart] !== SEQUENCE) throw new Error('Malformed certificate: tbsCertificate not a SEQUENCE')
	const tbs = readDerLength(certDer, outer.contentStart + 1)
	const tbsEnd = tbs.contentStart + tbs.length
	const fields: { tag: number; start: number; end: number }[] = []
	for (let pos = tbs.contentStart; pos < tbsEnd;) {
		const { length, contentStart } = readDerLength(certDer, pos + 1)
		if (contentStart + length > tbsEnd) throw new Error('Malformed certificate: field past the tbsCertificate')
		fields.push({ tag: byteAt(certDer, pos), start: pos, end: contentStart + length })
		pos = contentStart + length
	}
	// (version?) serialNumber, signature, issuer, validity, subject, SPKI; version is [0].
	const spki = fields[fields[0]?.tag === 0xa0 ? 6 : 5]
	if (!spki || spki.tag !== SEQUENCE) throw new Error('Could not locate SubjectPublicKeyInfo in certificate')
	return certDer.slice(spki.start, spki.end)
}

/** Async, so a key that isn't one DER SEQUENCE rejects like any other that doesn't import. */
export async function importSpki(spki: Uint8Array<ArrayBuffer>): Promise<CryptoKey> {
	return crypto.subtle.importKey('spki', single(spki), RSA_OAEP, false, ['encrypt'])
}

/** Accepts an X.509 certificate PEM (the suite's `certificate`) or an SPKI PEM; async, so a malformed one rejects too. */
export async function importPublicKey(pem: string): Promise<CryptoKey> {
	const { label, der } = decodePem(pem)
	if (label === 'CERTIFICATE') return importSpki(extractSpki(der))
	if (label === 'PUBLIC KEY') return importSpki(der)
	throw new Error('Malformed PEM: expected a certificate or a public key')
}

/** `[uint32 BE chunk count][512-byte block] * count`, base64; 446-byte UTF-8 chunks (ADR-003). */
export async function rsaEncrypt(plaintext: string, publicKey: CryptoKey): Promise<string> {
	const data = new TextEncoder().encode(plaintext)
	const count = Math.max(1, Math.ceil(data.length / RSA_CHUNK_SIZE))
	const result = new Uint8Array(4 + count * RSA_BLOCK_SIZE)
	new DataView(result.buffer).setUint32(0, count, false)
	for (let i = 0; i < count; i++) {
		const chunk = data.slice(i * RSA_CHUNK_SIZE, (i + 1) * RSA_CHUNK_SIZE)
		result.set(new Uint8Array(await crypto.subtle.encrypt(RSA_OAEP, publicKey, chunk)), 4 + i * RSA_BLOCK_SIZE)
	}
	return toBase64(result)
}

export async function rsaDecrypt(ciphertext: string, privateKey: CryptoKey): Promise<string> {
	const raw = fromBase64(ciphertext)
	if (raw.length < 4) throw new Error('RSA ciphertext too short')
	const count = new DataView(raw.buffer).getUint32(0, false)
	if (count === 0 || raw.length !== 4 + count * RSA_BLOCK_SIZE) throw new Error('RSA ciphertext length does not match its block count')
	const parts: Uint8Array[] = []
	for (let i = 0; i < count; i++) {
		const block = raw.slice(4 + i * RSA_BLOCK_SIZE, 4 + (i + 1) * RSA_BLOCK_SIZE)
		parts.push(new Uint8Array(await crypto.subtle.decrypt(RSA_OAEP, privateKey, block)))
	}
	const joined = new Uint8Array(parts.reduce((n, p) => n + p.length, 0))
	let offset = 0
	for (const part of parts) {
		joined.set(part, offset)
		offset += part.length
	}
	// Decoded once: a multi-byte character can straddle a chunk boundary.
	return new TextDecoder().decode(joined)
}
