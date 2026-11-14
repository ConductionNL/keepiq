/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * SSH key handling for the SSH Key item type, entirely in the browser.
 *
 * The private key is the secret's value and is encrypted with the item; the
 * public key and its fingerprint ride in the same encrypted additional-fields
 * blob. Nothing here talks to the server: a generated private key exists in
 * plain text only inside this tab until the store encrypts it (design D2).
 *
 * The fingerprint is always DERIVED from the public key, never typed, so it
 * cannot disagree with the key (design D3). It is the OpenSSH format,
 * `SHA256:` followed by the unpadded base64 of the SHA-256 digest of the
 * public key blob — what `ssh-keygen -lf` prints.
 *
 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-ssh-key-item/spec.md#requirement-ssh-key-item-form
 */

/** The additional-fields member holding the OpenSSH public key line. */
export const SSH_PUBLIC_KEY_MEMBER = 'publicKey'

/** The additional-fields member holding the derived SHA256 fingerprint. */
export const SSH_FINGERPRINT_MEMBER = 'fingerprint'

/** The seeded system type name of an SSH key (lib/Repair/SeedSecretTypes.php). */
export const SSH_KEY_TYPE_NAME = 'ssh_key'

/** The public key algorithms an OpenSSH public key line may name. */
const PUBLIC_KEY_TYPES = new Set([
	'ssh-ed25519',
	'ssh-rsa',
	'ssh-dss',
	'ecdsa-sha2-nistp256',
	'ecdsa-sha2-nistp384',
	'ecdsa-sha2-nistp521',
	'sk-ssh-ed25519@openssh.com',
	'sk-ecdsa-sha2-nistp256@openssh.com',
])

/** The magic that opens an `openssh-key-v1` private key. */
const OPENSSH_MAGIC = 'openssh-key-v1\0'

/** PEM labels of private keys whose public half cannot be read without a passphrase or a curve table. */
const OPAQUE_PEM_LABELS = new Set([
	'ENCRYPTED PRIVATE KEY',
	'EC PRIVATE KEY',
	'DSA PRIVATE KEY',
])

/** The ECDSA curves WebCrypto knows, by JWK `crv`, with their SSH names. */
const EC_CURVES = {
	'P-256': 'nistp256',
	'P-384': 'nistp384',
	'P-521': 'nistp521',
}

/** Raised for text that is not a usable key. */
export class SshKeyError extends Error {}

// ---------------------------------------------------------------------------
// Bytes, base64 and the SSH wire format.
// ---------------------------------------------------------------------------

const encoder = new TextEncoder()
const decoder = new TextDecoder()

/**
 * Standard base64 of bytes.
 *
 * @param {Uint8Array} bytes The bytes.
 * @return {string}
 */
function toBase64(bytes) {
	let binary = ''
	for (const byte of bytes) {
		binary += String.fromCharCode(byte)
	}
	return btoa(binary)
}

/**
 * Bytes of strict standard base64; throws on anything else.
 *
 * @param {string} text The base64 text, whitespace already removed.
 * @return {Uint8Array}
 * @throws {SshKeyError} When the text is not base64.
 */
function fromBase64(text) {
	if (
		text === ''
		|| !/^[A-Za-z0-9+/]+={0,2}$/.test(text)
		|| text.length % 4 !== 0
	) {
		throw new SshKeyError('not base64')
	}
	const binary = atob(text)
	const bytes = new Uint8Array(binary.length)
	for (let i = 0; i < binary.length; i++) {
		bytes[i] = binary.charCodeAt(i)
	}
	return bytes
}

/**
 * Bytes of unpadded base64url (a JWK member).
 *
 * @param {string} text The base64url text.
 * @return {Uint8Array}
 */
function fromBase64Url(text) {
	const b64 = text.replace(/-/g, '+').replace(/_/g, '/')
	return fromBase64(b64 + '='.repeat((4 - (b64.length % 4)) % 4))
}

/**
 * Join byte arrays.
 *
 * @param {Array<Uint8Array>} parts The parts.
 * @return {Uint8Array}
 */
function concat(parts) {
	const out = new Uint8Array(parts.reduce((sum, part) => sum + part.length, 0))
	let offset = 0
	for (const part of parts) {
		out.set(part, offset)
		offset += part.length
	}
	return out
}

/**
 * A big-endian uint32.
 *
 * @param {number} value The number.
 * @return {Uint8Array}
 */
function uint32(value) {
	return new Uint8Array([
		(value >>> 24) & 0xff,
		(value >>> 16) & 0xff,
		(value >>> 8) & 0xff,
		value & 0xff,
	])
}

/**
 * An SSH `string`: a uint32 length and the bytes.
 *
 * @param {Uint8Array|string} value The bytes, or text to UTF-8 encode.
 * @return {Uint8Array}
 */
function sshString(value) {
	const bytes = typeof value === 'string' ? encoder.encode(value) : value
	return concat([uint32(bytes.length), bytes])
}

/**
 * An SSH `mpint` of an unsigned big-endian magnitude.
 *
 * @param {Uint8Array} magnitude The magnitude.
 * @return {Uint8Array}
 */
function sshMpint(magnitude) {
	let start = 0
	while (start < magnitude.length && magnitude[start] === 0) {
		start++
	}
	const trimmed = magnitude.subarray(start)
	if (trimmed.length > 0 && (trimmed[0] & 0x80) !== 0) {
		return sshString(concat([new Uint8Array([0]), trimmed]))
	}
	return sshString(trimmed)
}

/**
 * A reader over SSH wire-format bytes. Every read past the end throws, so a
 * truncated key is refused rather than read as zeros.
 *
 * @param {Uint8Array} bytes The bytes.
 * @return {{uint32: function(): number, string: function(): Uint8Array, text: function(): string, raw: function(number): Uint8Array, done: function(): boolean}}
 */
function reader(bytes) {
	let offset = 0
	const take = (length) => {
		if (length < 0 || offset + length > bytes.length) {
			throw new SshKeyError('truncated')
		}
		const out = bytes.subarray(offset, offset + length)
		offset += length
		return out
	}
	const readUint32 = () => {
		const b = take(4)
		return ((b[0] << 24) | (b[1] << 16) | (b[2] << 8) | b[3]) >>> 0
	}
	return {
		uint32: readUint32,
		string: () => take(readUint32()),
		text: () => decoder.decode(take(readUint32())),
		raw: take,
		done: () => offset === bytes.length,
	}
}

// ---------------------------------------------------------------------------
// Public keys and fingerprints.
// ---------------------------------------------------------------------------

/**
 * Check a public key blob's inner structure for the algorithms whose shape
 * is fixed, so a line with a valid header but a damaged body is refused.
 *
 * @param {string} type The key type the blob names.
 * @param {object} blobReader A reader positioned after the type string.
 * @return {void}
 * @throws {SshKeyError} When the blob does not hold that key type.
 */
function checkBlobBody(type, blobReader) {
	if (type === 'ssh-ed25519') {
		if (blobReader.string().length !== 32) {
			throw new SshKeyError('bad ed25519 key')
		}
	} else if (type === 'ssh-rsa') {
		blobReader.string()
		if (blobReader.string().length < 64) {
			throw new SshKeyError('bad rsa modulus')
		}
	} else if (type.startsWith('ecdsa-sha2-')) {
		if (blobReader.text() !== type.slice('ecdsa-sha2-'.length)) {
			throw new SshKeyError('curve mismatch')
		}
		const point = blobReader.string()
		if (point.length === 0 || point[0] !== 0x04) {
			throw new SshKeyError('bad ec point')
		}
	} else {
		return
	}
	if (!blobReader.done()) {
		throw new SshKeyError('trailing bytes')
	}
}

/**
 * Parse an OpenSSH public key line (`<type> <base64> [comment]`).
 *
 * @param {string} line The public key line.
 * @return {{type: string, blob: Uint8Array, comment: string}}
 * @throws {SshKeyError} When the line is not an OpenSSH public key.
 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-ssh-key-item/spec.md#requirement-ssh-key-item-form
 */
export function parsePublicKey(line) {
	const parts = String(line ?? '')
		.trim()
		.split(/\s+/)
	if (parts.length < 2 || !PUBLIC_KEY_TYPES.has(parts[0])) {
		throw new SshKeyError('not an OpenSSH public key')
	}
	const blob = fromBase64(parts[1])
	const blobReader = reader(blob)
	if (blobReader.text() !== parts[0]) {
		throw new SshKeyError('key type mismatch')
	}
	checkBlobBody(parts[0], blobReader)
	return { type: parts[0], blob, comment: parts.slice(2).join(' ') }
}

/**
 * Whether text is an OpenSSH public key line.
 *
 * @param {string} line The text.
 * @return {boolean}
 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-ssh-key-item/spec.md#requirement-ssh-key-item-form
 */
export function isPublicKey(line) {
	try {
		parsePublicKey(line)
		return true
	} catch {
		return false
	}
}

/**
 * Whether two public key lines hold the same key, whatever their comments.
 *
 * @param {string} a A public key line.
 * @param {string} b Another public key line.
 * @return {boolean}
 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-ssh-key-item/spec.md#requirement-ssh-key-item-form
 */
export function samePublicKey(a, b) {
	try {
		return toBase64(parsePublicKey(a).blob) === toBase64(parsePublicKey(b).blob)
	} catch {
		return false
	}
}

/**
 * The OpenSSH public key line of a blob.
 *
 * @param {Uint8Array} blob The public key blob.
 * @param {string} [comment] An optional comment.
 * @return {string}
 */
function publicKeyLine(blob, comment = '') {
	const type = reader(blob).text()
	const line = `${type} ${toBase64(blob)}`
	return comment ? `${line} ${comment}` : line
}

/**
 * The OpenSSH SHA256 fingerprint of a public key: `SHA256:` and the
 * unpadded base64 of the SHA-256 digest of the key blob, as
 * `ssh-keygen -lf` prints it.
 *
 * @param {string} line An OpenSSH public key line.
 * @return {Promise<string>}
 * @throws {SshKeyError} When the line is not an OpenSSH public key.
 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-ssh-key-item/spec.md#requirement-ssh-key-item-form
 */
export async function fingerprintOf(line) {
	const { blob } = parsePublicKey(line)
	const digest = new Uint8Array(await crypto.subtle.digest('SHA-256', blob))
	return 'SHA256:' + toBase64(digest).replace(/=+$/, '')
}

// ---------------------------------------------------------------------------
// Private keys.
// ---------------------------------------------------------------------------

/**
 * Split PEM armour into its label and DER bytes.
 *
 * @param {string} text The armoured text.
 * @return {{label: string, der: Uint8Array, encrypted: boolean}}
 * @throws {SshKeyError} When the text is not one PEM block.
 */
function unarmour(text) {
	const match =
		/^-----BEGIN ([A-Z0-9 ]+)-----\r?\n([\s\S]*?)\r?\n-----END \1-----$/.exec(
			String(text ?? '').trim(),
		)
	if (match === null) {
		throw new SshKeyError('not PEM')
	}
	const lines = match[2].split(/\r?\n/)
	const headers = lines.filter((line) => line.includes(':'))
	const body = lines
		.filter((line) => !line.includes(':'))
		.join('')
		.replace(/\s+/g, '')
	return {
		label: match[1],
		der: fromBase64(body),
		encrypted: headers.some((line) => /^Proc-Type:.*ENCRYPTED/.test(line)),
	}
}

/**
 * The public key blob in an `openssh-key-v1` private key. It sits outside
 * the (possibly encrypted) private section, so it is read without the
 * passphrase. An unencrypted key's private section is checked too.
 *
 * @param {Uint8Array} bytes The decoded key.
 * @return {Uint8Array} The public key blob.
 * @throws {SshKeyError} When the structure is not an OpenSSH private key.
 */
function opensshPublicBlob(bytes) {
	const keyReader = reader(bytes)
	if (decoder.decode(keyReader.raw(OPENSSH_MAGIC.length)) !== OPENSSH_MAGIC) {
		throw new SshKeyError('bad magic')
	}
	const cipher = keyReader.text()
	keyReader.string()
	keyReader.string()
	if (keyReader.uint32() < 1) {
		throw new SshKeyError('no keys')
	}
	const blob = keyReader.string()
	const type = reader(blob).text()
	if (!PUBLIC_KEY_TYPES.has(type)) {
		throw new SshKeyError('unknown key type')
	}
	const privateSection = keyReader.string()
	if (cipher === 'none') {
		const privateReader = reader(privateSection)
		if (
			privateReader.uint32() !== privateReader.uint32()
			|| privateReader.text() !== type
		) {
			throw new SshKeyError('damaged private section')
		}
	}
	return blob
}

/**
 * Wrap a PKCS #1 RSA private key in a PKCS #8 envelope WebCrypto can import.
 *
 * @param {Uint8Array} pkcs1 The PKCS #1 DER.
 * @return {Uint8Array} The PKCS #8 DER.
 */
function pkcs1ToPkcs8(pkcs1) {
	const length = (n) => {
		if (n < 0x80) {
			return new Uint8Array([n])
		}
		const bytes = []
		for (let rest = n; rest > 0; rest = Math.floor(rest / 256)) {
			bytes.unshift(rest & 0xff)
		}
		return new Uint8Array([0x80 | bytes.length, ...bytes])
	}
	const tlv = (tag, content) =>
		concat([new Uint8Array([tag]), length(content.length), content])
	// AlgorithmIdentifier { rsaEncryption, NULL }
	const algorithm = new Uint8Array([
		0x30, 0x0d, 0x06, 0x09, 0x2a, 0x86, 0x48, 0x86, 0xf7, 0x0d, 0x01, 0x01, 0x01,
		0x05, 0x00,
	])
	return tlv(
		0x30,
		concat([new Uint8Array([0x02, 0x01, 0x00]), algorithm, tlv(0x04, pkcs1)]),
	)
}

/** The import parameters tried, in order, for a PKCS #8 key. */
const PKCS8_ALGORITHMS = [
	{ name: 'Ed25519' },
	{ name: 'RSASSA-PKCS1-v1_5', hash: 'SHA-256' },
	{ name: 'ECDSA', namedCurve: 'P-256' },
	{ name: 'ECDSA', namedCurve: 'P-384' },
	{ name: 'ECDSA', namedCurve: 'P-521' },
]

/**
 * The public key blob of a JWK private (or public) key.
 *
 * @param {object} jwk The JWK.
 * @return {Uint8Array}
 * @throws {SshKeyError} For a key type SSH has no blob for.
 */
function blobOfJwk(jwk) {
	if (jwk.kty === 'OKP' && jwk.crv === 'Ed25519') {
		return concat([sshString('ssh-ed25519'), sshString(fromBase64Url(jwk.x))])
	}
	if (jwk.kty === 'RSA') {
		return concat([
			sshString('ssh-rsa'),
			sshMpint(fromBase64Url(jwk.e)),
			sshMpint(fromBase64Url(jwk.n)),
		])
	}
	if (jwk.kty === 'EC' && EC_CURVES[jwk.crv]) {
		const curve = EC_CURVES[jwk.crv]
		return concat([
			sshString(`ecdsa-sha2-${curve}`),
			sshString(curve),
			sshString(
				concat([
					new Uint8Array([4]),
					fromBase64Url(jwk.x),
					fromBase64Url(jwk.y),
				]),
			),
		])
	}
	throw new SshKeyError('unsupported key type')
}

/**
 * The public key blob of a PKCS #8 private key, trying each algorithm
 * WebCrypto can import it as.
 *
 * @param {Uint8Array} der The PKCS #8 DER.
 * @return {Promise<Uint8Array>}
 * @throws {SshKeyError} When no algorithm imports it.
 */
async function pkcs8PublicBlob(der) {
	for (const algorithm of PKCS8_ALGORITHMS) {
		try {
			const key = await crypto.subtle.importKey(
				'pkcs8',
				der,
				algorithm,
				true,
				['sign'],
			)
			return blobOfJwk(await crypto.subtle.exportKey('jwk', key))
		} catch {
			// Not this algorithm; try the next.
		}
	}
	throw new SshKeyError('unreadable PKCS #8 key')
}

/**
 * Read a private key and derive its OpenSSH public key where that is
 * possible without a passphrase.
 *
 * Accepted: OpenSSH (`OPENSSH PRIVATE KEY`, encrypted or not — its public
 * key is stored in clear), PKCS #8 (`PRIVATE KEY`: Ed25519, RSA, ECDSA) and
 * PKCS #1 (`RSA PRIVATE KEY`). An encrypted PKCS #8 key, a legacy encrypted
 * PEM, a SEC1 `EC PRIVATE KEY` and a `DSA PRIVATE KEY` are accepted as keys
 * but give no public key; the user then pastes it. Anything else is refused.
 *
 * @param {string} text The private key text.
 * @return {Promise<{publicKey: (string|null)}>} The derived public key line, or null.
 * @throws {SshKeyError} When the text is not a private key.
 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-ssh-key-item/spec.md#requirement-ssh-key-item-form
 */
export async function parsePrivateKey(text) {
	const { label, der, encrypted } = unarmour(text)
	if (label === 'OPENSSH PRIVATE KEY') {
		return { publicKey: publicKeyLine(opensshPublicBlob(der)) }
	}
	if (encrypted || OPAQUE_PEM_LABELS.has(label)) {
		return { publicKey: null }
	}
	if (label === 'PRIVATE KEY') {
		return { publicKey: publicKeyLine(await pkcs8PublicBlob(der)) }
	}
	if (label === 'RSA PRIVATE KEY') {
		return { publicKey: publicKeyLine(await pkcs8PublicBlob(pkcs1ToPkcs8(der))) }
	}
	throw new SshKeyError('not a private key')
}

// ---------------------------------------------------------------------------
// Generation.
// ---------------------------------------------------------------------------

/**
 * Armour bytes as PEM, wrapped at 70 columns as OpenSSH writes it.
 *
 * @param {string} label The PEM label.
 * @param {Uint8Array} bytes The bytes.
 * @return {string}
 */
function armour(label, bytes) {
	const body = toBase64(bytes)
		.match(/.{1,70}/g)
		.join('\n')
	return `-----BEGIN ${label}-----\n${body}\n-----END ${label}-----\n`
}

/**
 * The private fields of an `openssh-key-v1` private section for a JWK.
 *
 * @param {object} jwk The private JWK.
 * @return {Uint8Array}
 */
function opensshPrivateFields(jwk) {
	if (jwk.kty === 'OKP') {
		const publicBytes = fromBase64Url(jwk.x)
		return concat([
			sshString(publicBytes),
			sshString(concat([fromBase64Url(jwk.d), publicBytes])),
		])
	}
	return concat(
		['n', 'e', 'd', 'qi', 'p', 'q'].map((member) =>
			sshMpint(fromBase64Url(jwk[member])),
		),
	)
}

/**
 * Serialise a WebCrypto private key as an unencrypted OpenSSH private key.
 * The vault's own encryption protects it; a passphrase on top would have to
 * be stored next to it.
 *
 * @param {object} jwk The private JWK (Ed25519 or RSA).
 * @param {string} comment The key comment.
 * @return {string} The armoured `OPENSSH PRIVATE KEY`.
 */
function opensshPrivateKey(jwk, comment) {
	const blob = blobOfJwk(jwk)
	const check = crypto.getRandomValues(new Uint32Array(1))[0]
	let section = concat([
		uint32(check),
		uint32(check),
		sshString(reader(blob).text()),
		opensshPrivateFields(jwk),
		sshString(comment),
	])
	const padding = []
	for (let i = 1; (section.length + padding.length) % 8 !== 0; i++) {
		padding.push(i)
	}
	section = concat([section, new Uint8Array(padding)])
	return armour(
		'OPENSSH PRIVATE KEY',
		concat([
			encoder.encode(OPENSSH_MAGIC),
			sshString('none'),
			sshString('none'),
			sshString(''),
			uint32(1),
			sshString(blob),
			sshString(section),
		]),
	)
}

/**
 * Generate a key pair with WebCrypto: Ed25519 where the browser supports
 * it, otherwise RSA 4096. The private key never leaves this function other
 * than as the returned text, which the caller hands to the store to encrypt.
 *
 * @param {object} [options] Options.
 * @param {string} [options.comment] The key comment.
 * @return {Promise<{privateKey: string, publicKey: string, fingerprint: string, algorithm: string}>}
 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-ssh-key-item/spec.md#requirement-ssh-key-item-form
 */
export async function generateKeyPair(options = {}) {
	const comment = options.comment ?? ''
	let pair
	let algorithm = 'ssh-ed25519'
	try {
		pair = await crypto.subtle.generateKey({ name: 'Ed25519' }, true, [
			'sign',
			'verify',
		])
	} catch {
		algorithm = 'ssh-rsa'
		pair = await crypto.subtle.generateKey(
			{
				name: 'RSASSA-PKCS1-v1_5',
				modulusLength: 4096,
				publicExponent: new Uint8Array([1, 0, 1]),
				hash: 'SHA-256',
			},
			true,
			['sign', 'verify'],
		)
	}
	const jwk = await crypto.subtle.exportKey('jwk', pair.privateKey)
	const publicKey = publicKeyLine(blobOfJwk(jwk), comment)
	return {
		privateKey: opensshPrivateKey(jwk, comment),
		publicKey,
		fingerprint: await fingerprintOf(publicKey),
		algorithm,
	}
}

/**
 * The additional fields of an SSH key with the fingerprint derived from
 * its public key, replacing any fingerprint that came with it (an import
 * file's own is never trusted). Without a usable public key no
 * fingerprint is kept. Other types' fields are returned as they are.
 *
 * @param {string} typeName The secret's type name.
 * @param {object|string|null|undefined} fields The additional fields.
 * @return {Promise<object|string|null|undefined>}
 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-ssh-key-item/spec.md#requirement-ssh-key-item-form
 */
export async function withDerivedFingerprint(typeName, fields) {
	if (typeName !== SSH_KEY_TYPE_NAME || !fields || typeof fields !== 'object') {
		return fields
	}
	const rest = { ...fields }
	delete rest[SSH_FINGERPRINT_MEMBER]
	const publicKey = rest[SSH_PUBLIC_KEY_MEMBER]
	if (typeof publicKey !== 'string' || !isPublicKey(publicKey)) {
		return rest
	}
	return { ...rest, [SSH_FINGERPRINT_MEMBER]: await fingerprintOf(publicKey) }
}
