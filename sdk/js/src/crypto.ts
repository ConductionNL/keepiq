/**
 * The Keepiq field recipe on WebCrypto, byte for byte the same as the server
 * (lib/Service/EncryptService.php), the browser app and sdk/go/crypto.
 *
 * rsa-oaep-sha256-chunked-v1: the UTF-8 value is split into chunks of the key
 * size minus 66 bytes (446 for RSA-4096), each chunk is RSA-OAEP-SHA256
 * encrypted, and the field is base64 of
 * [4-byte big-endian chunk count][one key-size block per chunk].
 *
 * No runtime dependency: globalThis.crypto.subtle (Node 20 and later, browsers).
 */

export const SCHEME = 'rsa-oaep-sha256-chunked-v1'
const OAEP_OVERHEAD = 2 * 32 + 2

const subtle = (): SubtleCrypto => {
	const s = globalThis.crypto?.subtle
	if (!s) {
		throw new Error('WebCrypto (globalThis.crypto.subtle) is not available: use Node 20 or later, or a browser')
	}
	return s
}

export function base64ToBytes(b64: string): Uint8Array {
	const bin = atob(b64)
	const out = new Uint8Array(bin.length)
	for (let i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i)
	return out
}

export function bytesToBase64(bytes: Uint8Array): string {
	let bin = ''
	for (let i = 0; i < bytes.length; i += 0x8000) {
		bin += String.fromCharCode(...bytes.subarray(i, i + 0x8000))
	}
	return btoa(bin)
}

export function base64Url(bytes: Uint8Array): string {
	return bytesToBase64(bytes).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')
}

function pemBody(pem: string, label: string): Uint8Array | null {
	const m = pem.match(new RegExp(`-----BEGIN ${label}-----([\\s\\S]+?)-----END ${label}-----`))
	return m ? base64ToBytes(m[1].replace(/\s+/g, '')) : null
}

// --- minimal DER, enough to wrap PKCS#1 and to find a certificate's SPKI ---

function derLength(len: number): number[] {
	if (len < 0x80) return [len]
	const bytes: number[] = []
	while (len > 0) {
		bytes.unshift(len & 0xff)
		len >>= 8
	}
	return [0x80 | bytes.length, ...bytes]
}

function der(tag: number, content: Uint8Array): Uint8Array {
	const head = [tag, ...derLength(content.length)]
	const out = new Uint8Array(head.length + content.length)
	out.set(head, 0)
	out.set(content, head.length)
	return out
}

function concat(...parts: Uint8Array[]): Uint8Array {
	const out = new Uint8Array(parts.reduce((n, p) => n + p.length, 0))
	let o = 0
	for (const p of parts) {
		out.set(p, o)
		o += p.length
	}
	return out
}

/** Read one TLV at offset: [tag, start of content, end of content]. */
function readTlv(buf: Uint8Array, offset: number): [number, number, number] {
	const tag = buf[offset]
	let len = buf[offset + 1]
	let start = offset + 2
	if (len & 0x80) {
		const n = len & 0x7f
		len = 0
		for (let i = 0; i < n; i++) len = (len << 8) | buf[start + i]
		start += n
	}
	return [tag, start, start + len]
}

// rsaEncryption OID 1.2.840.113549.1.1.1 with NULL parameters.
const RSA_ALGORITHM = new Uint8Array([0x30, 0x0d, 0x06, 0x09, 0x2a, 0x86, 0x48, 0x86, 0xf7, 0x0d, 0x01, 0x01, 0x01, 0x05, 0x00])

/** PKCS#8 DER from a PEM that is PKCS#8 or PKCS#1. */
export function privateKeyDer(pem: string): Uint8Array {
	const pkcs8 = pemBody(pem, 'PRIVATE KEY')
	if (pkcs8) return pkcs8
	const pkcs1 = pemBody(pem, 'RSA PRIVATE KEY')
	if (pkcs1) {
		return der(0x30, concat(new Uint8Array([0x02, 0x01, 0x00]), RSA_ALGORITHM, der(0x04, pkcs1)))
	}
	throw new Error('no RSA private key PEM (PKCS#8 or PKCS#1) found')
}

/** DER of the SubjectPublicKeyInfo inside a PEM certificate. */
export function certificateSpki(certPem: string): Uint8Array {
	const cert = pemBody(certPem, 'CERTIFICATE')
	if (!cert) throw new Error('no PEM certificate found')
	const [, certStart] = readTlv(cert, 0)
	const [, tbsStart, tbsEnd] = readTlv(cert, certStart)
	let off = tbsStart
	// [0] version (optional), serial, signature, issuer, validity, subject, spki
	if (cert[off] === 0xa0) off = readTlv(cert, off)[2]
	for (let i = 0; i < 5; i++) off = readTlv(cert, off)[2]
	if (off >= tbsEnd) throw new Error('certificate has no public key')
	const [, , end] = readTlv(cert, off)
	return cert.slice(off, end)
}

export async function certificateFingerprint(certPem: string): Promise<string> {
	const cert = pemBody(certPem, 'CERTIFICATE')
	if (!cert) throw new Error('no PEM certificate found')
	const sum = new Uint8Array(await subtle().digest('SHA-256', cert as BufferSource))
	return 'sha256:' + Array.from(sum, (b) => b.toString(16).padStart(2, '0')).join('')
}

export interface KeySet {
	decrypt: CryptoKey
	encrypt: CryptoKey
	sign: CryptoKey
	modulus: string
}

/** Import an application private key for decrypt, sign and (its public half) encrypt. */
export async function importKeySet(privateKeyPem: string): Promise<KeySet> {
	const pkcs8 = privateKeyDer(privateKeyPem) as BufferSource
	const oaep = { name: 'RSA-OAEP', hash: 'SHA-256' }
	const decrypt = await subtle().importKey('pkcs8', pkcs8, oaep, true, ['decrypt'])
	const sign = await subtle().importKey('pkcs8', pkcs8, { name: 'RSASSA-PKCS1-v1_5', hash: 'SHA-256' }, false, ['sign'])
	const jwk = await subtle().exportKey('jwk', decrypt)
	const encrypt = await subtle().importKey('jwk', { kty: 'RSA', n: jwk.n, e: jwk.e, alg: 'RSA-OAEP-256', ext: true }, oaep, true, ['encrypt'])
	return { decrypt, encrypt, sign, modulus: jwk.n as string }
}

/** Does the certificate carry the public half of this key? */
export async function certificateMatches(certPem: string, keys: KeySet): Promise<boolean> {
	const pub = await subtle().importKey('spki', certificateSpki(certPem) as BufferSource, { name: 'RSA-OAEP', hash: 'SHA-256' }, true, ['encrypt'])
	const jwk = await subtle().exportKey('jwk', pub)
	return jwk.n === keys.modulus
}

export async function encryptField(plaintext: string, publicKey: CryptoKey): Promise<string> {
	const size = (publicKey.algorithm as RsaHashedKeyAlgorithm).modulusLength / 8
	const chunkSize = size - OAEP_OVERHEAD
	const data = new TextEncoder().encode(plaintext)
	const chunks: Uint8Array[] = []
	for (let i = 0; i < data.length; i += chunkSize) chunks.push(data.subarray(i, i + chunkSize))
	if (chunks.length === 0) chunks.push(new Uint8Array(0))
	const out = new Uint8Array(4 + chunks.length * size)
	new DataView(out.buffer).setUint32(0, chunks.length, false)
	for (let i = 0; i < chunks.length; i++) {
		const block = new Uint8Array(await subtle().encrypt({ name: 'RSA-OAEP' }, publicKey, chunks[i] as BufferSource))
		out.set(block, 4 + i * size)
	}
	return bytesToBase64(out)
}

export async function decryptField(ciphertext: string, privateKey: CryptoKey): Promise<string> {
	const raw = base64ToBytes(ciphertext)
	if (raw.length < 4) throw new Error('field ciphertext too short')
	const count = new DataView(raw.buffer, raw.byteOffset).getUint32(0, false)
	const size = (privateKey.algorithm as RsaHashedKeyAlgorithm).modulusLength / 8
	if (count * size !== raw.length - 4) {
		throw new Error(`field length ${raw.length} inconsistent with chunk count ${count}`)
	}
	const parts: Uint8Array[] = []
	for (let i = 0; i < count; i++) {
		const block = raw.subarray(4 + i * size, 4 + (i + 1) * size)
		parts.push(new Uint8Array(await subtle().decrypt({ name: 'RSA-OAEP' }, privateKey, block as BufferSource)))
	}
	return new TextDecoder('utf-8', { fatal: true }).decode(concat(...parts))
}

export async function signRS256(signingInput: string, key: CryptoKey): Promise<string> {
	const sig = await subtle().sign('RSASSA-PKCS1-v1_5', key, new TextEncoder().encode(signingInput) as BufferSource)
	return base64Url(new Uint8Array(sig))
}

/**
 * Open the browser's private-key blob (human unlock): [4-byte version][16-byte
 * salt][12-byte IV][AES-256-GCM ciphertext], key from PBKDF2-HMAC-SHA256(master
 * password, salt, 600000). The library uses application keys, not this; it is
 * here so the library proves it reads the browser's own vector in sdk/testdata.
 */
export async function unwrapPrivateKey(blobB64: string, masterPassword: string): Promise<string> {
	const raw = base64ToBytes(blobB64)
	const version = new DataView(raw.buffer, raw.byteOffset).getUint32(0, false)
	if (version !== 1) throw new Error(`unsupported envelope version ${version}`)
	const salt = raw.subarray(4, 20)
	const iv = raw.subarray(20, 32)
	const ct = raw.subarray(32)
	const base = await subtle().importKey('raw', new TextEncoder().encode(masterPassword) as BufferSource, 'PBKDF2', false, ['deriveKey'])
	const aes = await subtle().deriveKey(
		{ name: 'PBKDF2', hash: 'SHA-256', salt: salt as BufferSource, iterations: 600000 },
		base,
		{ name: 'AES-GCM', length: 256 },
		false,
		['decrypt'],
	)
	const pt = await subtle().decrypt({ name: 'AES-GCM', iv: iv as BufferSource }, aes, ct as BufferSource)
	return new TextDecoder().decode(pt)
}
