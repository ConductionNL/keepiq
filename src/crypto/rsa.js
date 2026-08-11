/**
 * RSA-OAEP-SHA256 encryption/decryption using WebCrypto.
 *
 * Handles chunking for payloads > 446 bytes (RSA-4096 OAEP-SHA256 limit).
 * Format: [4-byte chunk count (big-endian)][512-byte encrypted blocks...]
 */

const RSA_KEY_BITS = 4096
const RSA_BLOCK_SIZE = 512
const RSA_CHUNK_SIZE = 446

/**
 * Generate an RSA-4096 key pair.
 *
 * @return {Promise<{ publicKey: CryptoKey, privateKey: CryptoKey, publicKeyPem: string }>}
 */
export async function generateKeyPair() {
	const keyPair = await crypto.subtle.generateKey(
		{
			name: 'RSA-OAEP',
			modulusLength: RSA_KEY_BITS,
			publicExponent: new Uint8Array([1, 0, 1]),
			hash: 'SHA-256',
		},
		true,
		['encrypt', 'decrypt'],
	)

	// Export public key as SPKI PEM.
	const spki = await crypto.subtle.exportKey('spki', keyPair.publicKey)
	const publicKeyPem = '-----BEGIN PUBLIC KEY-----\n'
		+ btoa(String.fromCharCode(...new Uint8Array(spki))).match(/.{1,64}/g).join('\n')
		+ '\n-----END PUBLIC KEY-----'

	return {
		publicKey: keyPair.publicKey,
		privateKey: keyPair.privateKey,
		publicKeyPem,
	}
}

/**
 * Import a PEM private key as a non-extractable CryptoKey.
 *
 * @param {string} pem PEM-encoded PKCS#8 private key
 * @return {Promise<CryptoKey>} Non-extractable RSA-OAEP private key
 */
export async function importPrivateKey(pem) {
	const pemBody = pem
		.replace(/-----BEGIN PRIVATE KEY-----/, '')
		.replace(/-----END PRIVATE KEY-----/, '')
		.replace(/-----BEGIN RSA PRIVATE KEY-----/, '')
		.replace(/-----END RSA PRIVATE KEY-----/, '')
		.replace(/\s/g, '')

	const binary = Uint8Array.from(atob(pemBody), c => c.charCodeAt(0))

	return crypto.subtle.importKey(
		'pkcs8',
		binary,
		{ name: 'RSA-OAEP', hash: 'SHA-256' },
		false, // extractable = false — security critical
		['decrypt'],
	)
}

/**
 * Read a single ASN.1 DER length field.
 *
 * @param {Uint8Array} der The DER bytes
 * @param {number} offset Offset of the length byte (immediately after the tag)
 * @return {{ length: number, headerEnd: number }} The content length and the
 *   offset of the first content byte.
 */
function readDerLength(der, offset) {
	const first = der[offset]
	if ((first & 0x80) === 0) {
		// Short form.
		return { length: first, headerEnd: offset + 1 }
	}

	// Long form: low 7 bits give the number of subsequent length bytes.
	const numBytes = first & 0x7f
	let length = 0
	for (let i = 0; i < numBytes; i++) {
		length = (length << 8) | der[offset + 1 + i]
	}

	return { length, headerEnd: offset + 1 + numBytes }
}

/**
 * Extract the DER-encoded SubjectPublicKeyInfo (SPKI) from an X.509 certificate.
 *
 * X.509: Certificate ::= SEQUENCE {
 *   tbsCertificate       TBSCertificate,   -- SEQUENCE
 *   signatureAlgorithm   AlgorithmIdentifier,
 *   signatureValue       BIT STRING }
 *
 * TBSCertificate ::= SEQUENCE {
 *   [0] version, serialNumber, signature, issuer, validity, subject,
 *   subjectPublicKeyInfo SubjectPublicKeyInfo, ... }
 *
 * The SPKI is itself a SEQUENCE. We walk the TBSCertificate's children and
 * return the AlgorithmIdentifier+BIT STRING SEQUENCE that follows the `subject`
 * Name (the 6th top-level TBS element, after the optional [0] version tag).
 *
 * @param {Uint8Array} certDer The full certificate DER bytes
 * @return {Uint8Array} The DER bytes of the SubjectPublicKeyInfo SEQUENCE
 */
function extractSpkiFromCertificate(certDer) {
	const SEQUENCE = 0x30
	const CONTEXT_0 = 0xa0 // [0] EXPLICIT version

	// Outer Certificate SEQUENCE.
	if (certDer[0] !== SEQUENCE) {
		throw new Error('Not a DER SEQUENCE (certificate expected)')
	}
	const { headerEnd } = readDerLength(certDer, 1)

	// tbsCertificate SEQUENCE.
	if (certDer[headerEnd] !== SEQUENCE) {
		throw new Error('Malformed certificate: tbsCertificate not a SEQUENCE')
	}
	const tbs = readDerLength(certDer, headerEnd + 1)
	let pos = tbs.headerEnd
	const tbsEnd = tbs.headerEnd + tbs.length

	// Skip the optional [0] version, then walk fields by index. The 6th field
	// (index 5, 0-based) after an explicit version — or 5th without one — is the
	// SubjectPublicKeyInfo. Robust approach: the SPKI is the first SEQUENCE whose
	// content begins with an AlgorithmIdentifier SEQUENCE followed by a BIT STRING.
	// We index the TBS children and pick the one at the SPKI position.
	const fields = []
	while (pos < tbsEnd) {
		const tag = certDer[pos]
		const { length, headerEnd: contentStart } = readDerLength(certDer, pos + 1)
		const fieldEnd = contentStart + length
		fields.push({ tag, start: pos, contentStart, end: fieldEnd })
		pos = fieldEnd
	}

	// Determine the SPKI index: skip a leading [0] version if present.
	const hasVersion = fields.length > 0 && fields[0].tag === CONTEXT_0
	// Order: (version?) serialNumber, signature, issuer, validity, subject, SPKI.
	const spkiIndex = hasVersion ? 6 : 5
	const spki = fields[spkiIndex]
	if (spki === undefined || certDer[spki.start] !== SEQUENCE) {
		throw new Error('Could not locate SubjectPublicKeyInfo in certificate')
	}

	return certDer.slice(spki.start, spki.end)
}

/**
 * Import a PEM public key or X.509 certificate for encryption.
 *
 * Accepts either a `SubjectPublicKeyInfo` (`-----BEGIN PUBLIC KEY-----`) or a
 * full X.509 certificate (`-----BEGIN CERTIFICATE-----`). For a certificate the
 * embedded SubjectPublicKeyInfo is extracted before importKey('spki', …), which
 * rejects a raw certificate DER with DataError.
 *
 * @param {string} pem PEM-encoded SPKI public key or X.509 certificate
 * @return {Promise<CryptoKey>}
 */
export async function importPublicKey(pem) {
	const isCertificate = /-----BEGIN CERTIFICATE-----/.test(pem)

	const pemBody = pem
		.replace(/-----BEGIN PUBLIC KEY-----/, '')
		.replace(/-----END PUBLIC KEY-----/, '')
		.replace(/-----BEGIN CERTIFICATE-----/, '')
		.replace(/-----END CERTIFICATE-----/, '')
		.replace(/\s/g, '')

	let spki = Uint8Array.from(atob(pemBody), c => c.charCodeAt(0))
	if (isCertificate === true) {
		spki = extractSpkiFromCertificate(spki)
	}

	return crypto.subtle.importKey(
		'spki',
		spki,
		{ name: 'RSA-OAEP', hash: 'SHA-256' },
		false,
		['encrypt'],
	)
}

/**
 * Encrypt plaintext with RSA-OAEP-SHA256, chunking if needed.
 *
 * @param {string} plaintext
 * @param {CryptoKey} publicKey
 * @return {Promise<string>} Base64-encoded chunked ciphertext
 */
export async function rsaEncrypt(plaintext, publicKey) {
	const encoder = new TextEncoder()
	const data = encoder.encode(plaintext)

	const chunks = []
	for (let i = 0; i < data.length; i += RSA_CHUNK_SIZE) {
		chunks.push(data.slice(i, i + RSA_CHUNK_SIZE))
	}
	if (chunks.length === 0) {
		chunks.push(new Uint8Array(0))
	}

	const result = new Uint8Array(4 + chunks.length * RSA_BLOCK_SIZE)
	const view = new DataView(result.buffer)
	view.setUint32(0, chunks.length, false) // big-endian

	for (let i = 0; i < chunks.length; i++) {
		const encrypted = new Uint8Array(
			await crypto.subtle.encrypt(
				{ name: 'RSA-OAEP' },
				publicKey,
				chunks[i],
			),
		)
		result.set(encrypted, 4 + i * RSA_BLOCK_SIZE)
	}

	return btoa(String.fromCharCode(...result))
}

/**
 * Decrypt RSA-OAEP-SHA256 chunked ciphertext.
 *
 * @param {string} ciphertext Base64-encoded chunked ciphertext
 * @param {CryptoKey} privateKey
 * @return {Promise<string>} Decrypted plaintext
 */
export async function rsaDecrypt(ciphertext, privateKey) {
	const raw = Uint8Array.from(atob(ciphertext), c => c.charCodeAt(0))
	const view = new DataView(raw.buffer, raw.byteOffset, raw.byteLength)
	const chunkCount = view.getUint32(0, false)

	// Collect the decrypted chunk bytes and decode ONCE at the end. Decoding
	// per chunk tears any multi-byte UTF-8 character that straddles a
	// RSA_CHUNK_SIZE boundary: TextDecoder.decode() without { stream: true }
	// treats every call as a complete input and emits U+FFFD for the trailing
	// partial sequence, so 'é' split across two blocks came back as two
	// replacement characters. Encryption chunks by BYTES, so a boundary lands
	// mid-character whenever the plaintext is non-ASCII and longer than one
	// chunk. Compromise-recovery migration is the first code to round-trip
	// every value in a vault, and a torn decrypt there fails the round-trip
	// compare forever, leaving the record unmigratable and the migration
	// unable to reach its completion gate.
	const parts = []
	let total = 0

	for (let i = 0; i < chunkCount; i++) {
		const block = raw.slice(4 + i * RSA_BLOCK_SIZE, 4 + (i + 1) * RSA_BLOCK_SIZE)
		const decrypted = new Uint8Array(
			await crypto.subtle.decrypt(
				{ name: 'RSA-OAEP' },
				privateKey,
				block,
			),
		)
		parts.push(decrypted)
		total += decrypted.length
	}

	const joined = new Uint8Array(total)
	let offset = 0
	for (const part of parts) {
		joined.set(part, offset)
		offset += part.length
	}

	return new TextDecoder().decode(joined)
}
