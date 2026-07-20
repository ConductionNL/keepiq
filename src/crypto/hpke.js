/**
 * HPKE (RFC 9180) base-mode seal/open for the FIDO Credential Exchange Protocol
 * (CXP, cxp-transfer). This is the transport crypto that seals the CXF payload
 * `cxf-import-export` assembles, so credentials travel provider-to-provider with
 * no plaintext file on disk (ADR-003 — all key material stays in the browser).
 *
 * Cipher suite (pinned, matching the shipping CXP ecosystem / Bitwarden):
 *   KEM  = DHKEM(X25519, HKDF-SHA256)  = 0x0020
 *   KDF  = HKDF-SHA256                 = 0x0001
 *   AEAD = AES-256-GCM                 = 0x0002
 *
 * Only single-shot Seal/Open in base mode (no PSK, no auth) is implemented —
 * that is all CXP needs, and keeping the surface tiny keeps it auditable. Every
 * suite/version constant is isolated in this one module so a CXP spec revision
 * touches only this file (design: "isolate all CXP-version specifics").
 *
 * @packageDocumentation
 */

// --- pinned suite identifiers (RFC 9180 §7) ---
export const KEM_ID = 0x0020 // DHKEM(X25519, HKDF-SHA256)
export const KDF_ID = 0x0001 // HKDF-SHA256
export const AEAD_ID = 0x0002 // AES-256-GCM

const NSECRET = 32 // KEM shared secret length
const NH = 32 // HKDF-SHA256 output length
const NK = 32 // AES-256 key length
const NN = 12 // AES-GCM nonce length

const HPKE_V1 = strToBytes('HPKE-v1')

/**
 * I2OSP(n, len) — big-endian encode n into len bytes.
 * @param n
 * @param len
 */
function i2osp(n, len) {
	const out = new Uint8Array(len)
	for (let i = len - 1; i >= 0; i--) {
		out[i] = n & 0xff
		n >>>= 8
	}
	return out
}

function strToBytes(s) {
	return new TextEncoder().encode(s)
}

function concat(...chunks) {
	let total = 0
	for (const c of chunks) total += c.length
	const out = new Uint8Array(total)
	let off = 0
	for (const c of chunks) {
		out.set(c, off)
		off += c.length
	}
	return out
}

// KEM suite_id = "KEM" || I2OSP(kem_id, 2)
const KEM_SUITE_ID = concat(strToBytes('KEM'), i2osp(KEM_ID, 2))
// HPKE suite_id = "HPKE" || I2OSP(kem_id,2) || I2OSP(kdf_id,2) || I2OSP(aead_id,2)
const HPKE_SUITE_ID = concat(
	strToBytes('HPKE'),
	i2osp(KEM_ID, 2),
	i2osp(KDF_ID, 2),
	i2osp(AEAD_ID, 2),
)

// --- HKDF primitives (RFC 5869) over WebCrypto HMAC ---

async function hmacSha256(key, data) {
	// An empty HMAC key is padded to the block size with zeros; a 32-zero-byte
	// key hashes identically, so we substitute it when salt is empty (WebCrypto
	// rejects a zero-length key).
	const keyBytes = key.length === 0 ? new Uint8Array(NH) : key
	const k = await crypto.subtle.importKey('raw', keyBytes, { name: 'HMAC', hash: 'SHA-256' }, false, ['sign'])
	return new Uint8Array(await crypto.subtle.sign('HMAC', k, data))
}

/**
 * HKDF-Extract(salt, ikm) = HMAC-SHA256(salt, ikm).
 * @param salt
 * @param ikm
 */
function extract(salt, ikm) {
	return hmacSha256(salt, ikm)
}

/**
 * HKDF-Expand(prk, info, L) for L <= NH (single block — all CXP outputs fit).
 * @param prk
 * @param info
 * @param length
 */
async function expand(prk, info, length) {
	if (length > NH) throw new Error('hpke: expand length exceeds one block')
	const t = await hmacSha256(prk, concat(info, Uint8Array.of(1)))
	return t.slice(0, length)
}

function labeledExtract(salt, label, ikm) {
	return extract(salt, concat(HPKE_V1, KEM_SUITE_ID, strToBytes(label), ikm))
}

function labeledExpand(prk, label, info, length) {
	const labeledInfo = concat(i2osp(length, 2), HPKE_V1, KEM_SUITE_ID, strToBytes(label), info)
	return expand(prk, labeledInfo, length)
}

// The HPKE key schedule uses the HPKE suite_id (not the KEM one).
function labeledExtractHpke(salt, label, ikm) {
	return extract(salt, concat(HPKE_V1, HPKE_SUITE_ID, strToBytes(label), ikm))
}

function labeledExpandHpke(prk, label, info, length) {
	const labeledInfo = concat(i2osp(length, 2), HPKE_V1, HPKE_SUITE_ID, strToBytes(label), info)
	return expand(prk, labeledInfo, length)
}

// --- DHKEM(X25519, HKDF-SHA256) ---

async function extractAndExpand(dh, kemContext) {
	const eaePrk = await labeledExtract(new Uint8Array(0), 'eae_prk', dh)
	return labeledExpand(eaePrk, 'shared_secret', kemContext, NSECRET)
}

/**
 * Generate an ephemeral X25519 recipient keypair.
 * @return {Promise<{ publicKey: CryptoKey, privateKey: CryptoKey, publicKeyRaw: Uint8Array }>}
 */
export async function generateRecipientKeyPair() {
	const kp = await crypto.subtle.generateKey({ name: 'X25519' }, true, ['deriveBits'])
	const publicKeyRaw = new Uint8Array(await crypto.subtle.exportKey('raw', kp.publicKey))
	return { publicKey: kp.publicKey, privateKey: kp.privateKey, publicKeyRaw }
}

async function importPublicKeyRaw(raw) {
	return crypto.subtle.importKey('raw', raw, { name: 'X25519' }, true, [])
}

async function dh(privateKey, publicKey) {
	return new Uint8Array(await crypto.subtle.deriveBits({ name: 'X25519', public: publicKey }, privateKey, 256))
}

// DHKEM.Encap(pkR) -> { sharedSecret, enc }
async function encap(pkRraw) {
	const eph = await crypto.subtle.generateKey({ name: 'X25519' }, true, ['deriveBits'])
	const pkR = await importPublicKeyRaw(pkRraw)
	const dhBytes = await dh(eph.privateKey, pkR)
	const enc = new Uint8Array(await crypto.subtle.exportKey('raw', eph.publicKey))
	const kemContext = concat(enc, pkRraw)
	const sharedSecret = await extractAndExpand(dhBytes, kemContext)
	return { sharedSecret, enc }
}

// DHKEM.Decap(enc, skR, pkRraw) -> sharedSecret
async function decap(enc, skR, pkRraw) {
	const pkE = await importPublicKeyRaw(enc)
	const dhBytes = await dh(skR, pkE)
	const kemContext = concat(enc, pkRraw)
	return extractAndExpand(dhBytes, kemContext)
}

// --- HPKE base-mode key schedule (RFC 9180 §5.1, mode_base = 0x00) ---

async function keySchedule(sharedSecret, info) {
	const pskIdHash = await labeledExtractHpke(new Uint8Array(0), 'psk_id_hash', new Uint8Array(0))
	const infoHash = await labeledExtractHpke(new Uint8Array(0), 'info_hash', info)
	const keyScheduleContext = concat(Uint8Array.of(0x00), pskIdHash, infoHash)
	const secret = await labeledExtractHpke(sharedSecret, 'secret', new Uint8Array(0))
	const key = await labeledExpandHpke(secret, 'key', keyScheduleContext, NK)
	const baseNonce = await labeledExpandHpke(secret, 'base_nonce', keyScheduleContext, NN)
	return { key, baseNonce }
}

async function aeadKey(keyBytes) {
	return crypto.subtle.importKey('raw', keyBytes, { name: 'AES-GCM' }, false, ['encrypt', 'decrypt'])
}

/**
 * Single-shot HPKE Seal: seal `plaintext` to the recipient public key `pkRraw`.
 * Returns the encapsulated key and ciphertext (base nonce, seq=0).
 *
 * @param {Uint8Array} pkRraw Recipient X25519 public key (raw 32 bytes)
 * @param {Uint8Array} info   Context info (binds the ciphertext — CXP uses the request nonce)
 * @param {Uint8Array} aad    Additional authenticated data
 * @param {Uint8Array} plaintext
 * @return {Promise<{ enc: Uint8Array, ciphertext: Uint8Array }>}
 */
export async function seal(pkRraw, info, aad, plaintext) {
	const { sharedSecret, enc } = await encap(pkRraw)
	const { key, baseNonce } = await keySchedule(sharedSecret, info)
	const aeadK = await aeadKey(key)
	const ct = new Uint8Array(await crypto.subtle.encrypt({ name: 'AES-GCM', iv: baseNonce, additionalData: aad }, aeadK, plaintext))
	return { enc, ciphertext: ct }
}

/**
 * Single-shot HPKE Open: recover the plaintext from `enc`+`ciphertext` using the
 * recipient private key. The recipient public key `pkRraw` must be supplied so
 * the KEM context matches Encap.
 *
 * @param {CryptoKey} skR     Recipient X25519 private key
 * @param {Uint8Array} pkRraw Recipient X25519 public key (raw 32 bytes)
 * @param {Uint8Array} enc    Encapsulated key
 * @param {Uint8Array} info   Context info (must equal the seal-time info)
 * @param {Uint8Array} aad    Additional authenticated data
 * @param {Uint8Array} ciphertext
 * @return {Promise<Uint8Array>} plaintext
 */
export async function open(skR, pkRraw, enc, info, aad, ciphertext) {
	const sharedSecret = await decap(enc, skR, pkRraw)
	const { key, baseNonce } = await keySchedule(sharedSecret, info)
	const aeadK = await aeadKey(key)
	return new Uint8Array(await crypto.subtle.decrypt({ name: 'AES-GCM', iv: baseNonce, additionalData: aad }, aeadK, ciphertext))
}

// deriveSharedSecret exposes DHKEM's ExtractAndExpand for the RFC 9180 A.1
// known-answer test (interop anchor): shared_secret = ExtractAndExpand(dh, enc||pkRm).
async function deriveSharedSecret(dh, enc, pkRraw) {
	return extractAndExpand(dh, concat(enc, pkRraw))
}

export const _internals = { i2osp, concat, extract, expand, labeledExtract, labeledExpand, deriveSharedSecret, KEM_SUITE_ID, HPKE_SUITE_ID }
