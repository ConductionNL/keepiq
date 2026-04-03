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
 * Import a PEM public key or certificate for encryption.
 *
 * @param {string} pem PEM-encoded public key (SPKI format)
 * @return {Promise<CryptoKey>}
 */
export async function importPublicKey(pem) {
	const pemBody = pem
		.replace(/-----BEGIN PUBLIC KEY-----/, '')
		.replace(/-----END PUBLIC KEY-----/, '')
		.replace(/-----BEGIN CERTIFICATE-----/, '')
		.replace(/-----END CERTIFICATE-----/, '')
		.replace(/\s/g, '')

	const binary = Uint8Array.from(atob(pemBody), c => c.charCodeAt(0))

	return crypto.subtle.importKey(
		'spki',
		binary,
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

	let plaintext = ''
	const decoder = new TextDecoder()

	for (let i = 0; i < chunkCount; i++) {
		const block = raw.slice(4 + i * RSA_BLOCK_SIZE, 4 + (i + 1) * RSA_BLOCK_SIZE)
		const decrypted = await crypto.subtle.decrypt(
			{ name: 'RSA-OAEP' },
			privateKey,
			block,
		)
		plaintext += decoder.decode(decrypted)
	}

	return plaintext
}

/**
 * Import a PEM private key for signing (RSASSA-PKCS1-v1_5).
 * Separate from importPrivateKey which uses RSA-OAEP for decryption.
 *
 * @param {string} pem PEM-encoded PKCS#8 private key
 * @return {Promise<CryptoKey>}
 */
export async function importPrivateKeyForSigning(pem) {
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
		{ name: 'RSASSA-PKCS1-v1_5', hash: 'SHA-256' },
		false,
		['sign'],
	)
}

/**
 * Sign data with an RSA private key (RSASSA-PKCS1-v1_5 + SHA-256).
 * Compatible with PHP's openssl_verify($data, $sig, $pubKey, OPENSSL_ALGO_SHA256).
 *
 * @param {string} data The data to sign
 * @param {CryptoKey} privateKey RSASSA-PKCS1-v1_5 signing key
 * @return {Promise<string>} Base64-encoded signature
 */
export async function rsaSign(data, privateKey) {
	const encoder = new TextEncoder()
	const signature = await crypto.subtle.sign(
		'RSASSA-PKCS1-v1_5',
		privateKey,
		encoder.encode(data),
	)
	return btoa(String.fromCharCode(...new Uint8Array(signature)))
}
