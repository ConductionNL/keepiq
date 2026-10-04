/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Writes the crypto test vectors that the native apps' shared core must read
 * byte for byte (clients-mobile-apps design D2). Every ciphertext, code and
 * signature here is produced by the web app's OWN modules, not by a copy of
 * them, so a vector can only drift when the web app's format drifts:
 *
 *   src/crypto/rsa.js, src/crypto/aes.js, src/crypto/envelope.js,
 *   src/crypto/argon2.js, src/send/sendCrypto.js, src/totp/totp.js,
 *   src/passkey/passkey.js, browser-extension/src/passkey/webauthn.js
 *
 * RSA-OAEP, AES-GCM and ECDSA output is randomised, so a vector carries the
 * output produced once plus the key that opens or verifies it. Re-running the
 * script therefore rewrites every file with new bytes; that is expected and
 * the readers (tests/vitest/crypto-vectors.spec.js and the Kotlin commonTest)
 * must keep passing.
 *
 * Usage, from the repository root:
 *
 *   node tests/vectors/generate-crypto-vectors.mjs
 *
 * Needs `openssl` on the PATH for the X.509 certificate vector (the suite
 * public key reaches clients as a certificate).
 *
 * @spec openspec/changes/clients-mobile-apps/specs/mobile-shared-core/spec.md#requirement-crypto-byte-compatible-with-the-web-app
 */

import { execFileSync } from 'node:child_process'
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { register } from 'node:module'
import { tmpdir } from 'node:os'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'

const HERE = dirname(fileURLToPath(import.meta.url))
const ROOT = resolve(HERE, '..', '..')
const OUT = join(HERE, 'crypto')

// argon2-browser is a UMD bundle that webpack hands to src/crypto/argon2.js
// with named exports. Plain node only sees a default export, so a resolve
// hook maps the bare specifier to a tiny ESM shim around the same file.
const argonLib = pathToFileURL(
	join(ROOT, 'node_modules/argon2-browser/lib/argon2.js'),
).href
const argonShim =
	'data:text/javascript,'
	+ encodeURIComponent(
		`import a from ${JSON.stringify(argonLib)};`
			+ 'export const ArgonType = a.ArgonType; export const hash = a.hash; export default a;',
	)
register(
	'data:text/javascript,'
		+ encodeURIComponent(
			'export async function resolve(s, c, n) {'
				+ ` if (s === 'argon2-browser') return { url: ${JSON.stringify(argonShim)}, shortCircuit: true };`
				+ ' return n(s, c) }',
		),
)
// The emscripten loader prefers fetch() for the .wasm path, which node refuses
// for a bare file path; without fetch it reads the file from disk instead.
globalThis.fetch = undefined
// src/crypto/argon2.js only imports the webpack wasm URL when this is unset.
globalThis.loadArgon2WasmBinary = () => {
	throw new Error('not used under node')
}

const rsa = await import(join(ROOT, 'src/crypto/rsa.js'))
const aes = await import(join(ROOT, 'src/crypto/aes.js'))
const envelopeMod = await import(join(ROOT, 'src/crypto/envelope.js'))
const argon2 = await import(join(ROOT, 'src/crypto/argon2.js'))
const sendCrypto = await import(join(ROOT, 'src/send/sendCrypto.js'))
const totp = await import(join(ROOT, 'src/totp/totp.js'))
const passkey = await import(join(ROOT, 'src/passkey/passkey.js'))
const webauthn = await import(
	join(ROOT, 'browser-extension/src/passkey/webauthn.js')
)

/**
 * Base64 of bytes.
 *
 * @param {Uint8Array|ArrayBuffer} bytes The bytes.
 * @return {string}
 * @spec openspec/changes/clients-mobile-apps/tasks.md#1.2
 */
function b64(bytes) {
	return Buffer.from(new Uint8Array(bytes)).toString('base64')
}

/**
 * Wrap DER bytes as PEM with 64-character lines.
 *
 * @param {Uint8Array|ArrayBuffer} der The DER bytes.
 * @param {string} label The PEM label.
 * @return {string}
 * @spec openspec/changes/clients-mobile-apps/tasks.md#1.2
 */
function pem(der, label) {
	return `-----BEGIN ${label}-----\n${b64(der)
		.match(/.{1,64}/g)
		.join('\n')}\n-----END ${label}-----`
}

/**
 * Write one vector file, pretty-printed with a trailing newline.
 *
 * @param {string} name File name under tests/vectors/crypto/.
 * @param {object} data The vector document.
 * @spec openspec/changes/clients-mobile-apps/tasks.md#1.2
 */
function write(name, data) {
	writeFileSync(join(OUT, name), JSON.stringify(data, null, '\t') + '\n')
	console.log(`wrote tests/vectors/crypto/${name}`)
}

/**
 * Build an X.509 certificate around an RSA private key with openssl, the
 * shape in which a suite public key reaches clients.
 *
 * @param {string} privateKeyPem PKCS#8 PEM.
 * @return {string} The certificate PEM.
 * @spec openspec/changes/clients-mobile-apps/tasks.md#1.2
 */
function certificateFor(privateKeyPem) {
	const dir = mkdtempSync(join(tmpdir(), 'keepiq-vectors-'))
	try {
		writeFileSync(join(dir, 'key.pem'), privateKeyPem + '\n')
		execFileSync('openssl', [
			'req',
			'-x509',
			'-new',
			'-key',
			join(dir, 'key.pem'),
			'-sha256',
			'-days',
			'36500',
			'-subj',
			'/CN=keepiq test vectors',
			'-out',
			join(dir, 'cert.pem'),
		])
		return readFileSync(join(dir, 'cert.pem'), 'utf8').trim()
	} finally {
		rmSync(dir, { recursive: true, force: true })
	}
}

// --- RSA key, certificate and private-key envelope -------------------------

const pair = await rsa.generateKeyPair()
const privateKeyPem = pem(
	await crypto.subtle.exportKey('pkcs8', pair.privateKey),
	'PRIVATE KEY',
)
const certificatePem = certificateFor(privateKeyPem)
const masterPassword = 'correct horse – batterij stäple 🔑'
const envelope = await aes.encryptPrivateKey(privateKeyPem, masterPassword)
const { salt: envelopeSalt } = envelopeMod.decodeEnvelope(envelope)
const unlockKey = await aes.deriveUnlockKeyRaw(masterPassword, envelopeSalt)

// Same bytes with the version word set to 2: the readers must refuse it.
const v2 = Buffer.from(envelope, 'base64')
v2.writeUInt32BE(2, 0)

write('envelope.json', {
	description:
		'Private-key envelope (src/crypto/envelope.js, aes.js). privateKeyPem is what the envelope opens to with password; unlockKeyHex is deriveUnlockKeyRaw(password, envelope salt).',
	password: masterPassword,
	envelope,
	privateKeyPem,
	publicKeyPem: pair.publicKeyPem,
	certificatePem,
	unlockKeyHex: Buffer.from(unlockKey).toString('hex'),
	wrongPassword: 'correct horse – batterij stäple',
	unsupportedVersionEnvelope: v2.toString('base64'),
})

// --- field ciphertexts ------------------------------------------------------

const publicKey = await rsa.importPublicKey(pair.publicKeyPem)
const certificateKey = await rsa.importPublicKey(certificatePem)
const privateKey = await rsa.importPrivateKey(privateKeyPem)

const fieldCases = [
	{ name: 'empty', plaintext: '' },
	{ name: 'one chunk', plaintext: 'hunter2' },
	{ name: 'exactly one full chunk (446 bytes)', plaintext: 'x'.repeat(446) },
	{ name: 'one byte over a chunk (447 bytes)', plaintext: 'y'.repeat(447) },
	{
		name: 'several chunks',
		plaintext: JSON.stringify({
			notes: 'Lorem ipsum dolor sit amet. '.repeat(50),
		}),
	},
	{
		name: 'multibyte across a chunk boundary',
		plaintext:
			'a'.repeat(445) + 'é' + 'ñ'.repeat(300) + ' 🔐 漢字 ' + '€'.repeat(200),
	},
	{
		// The web decoder (TextDecoder, ignoreBOM false) drops a leading BOM.
		name: 'leading byte order mark',
		plaintext: '﻿bom',
	},
]

const fields = []
for (const c of fieldCases) {
	const ciphertext = await rsa.rsaEncrypt(c.plaintext, publicKey)
	fields.push({
		name: c.name,
		plaintext: c.plaintext,
		utf8Length: Buffer.byteLength(c.plaintext, 'utf8'),
		chunkCount: Buffer.from(ciphertext, 'base64').readUInt32BE(0),
		ciphertext,
		decryptsTo: await rsa.rsaDecrypt(ciphertext, privateKey),
		encryptedTo: 'publicKeyPem',
	})
}
const viaCertificate = 'encrypted to the certificate'
const certCiphertext = await rsa.rsaEncrypt(viaCertificate, certificateKey)
fields.push({
	name: 'encrypted to the X.509 certificate',
	plaintext: viaCertificate,
	utf8Length: Buffer.byteLength(viaCertificate, 'utf8'),
	chunkCount: 1,
	ciphertext: certCiphertext,
	decryptsTo: await rsa.rsaDecrypt(certCiphertext, privateKey),
	encryptedTo: 'certificatePem',
})

write('fields.json', {
	description:
		"Field ciphertexts from rsaEncrypt (src/crypto/rsa.js). Open with envelope.json privateKeyPem. decryptsTo is what the web app's rsaDecrypt returns, and is the value a reader must produce.",
	cases: fields,
})

// --- Send ---------------------------------------------------------------------

const sendPayload = 'Wi-Fi: Keepiq-Gast / wachtwoord: zomer☀2026'
const plain = await sendCrypto.sealPayload(sendPayload)
const publicBase = 'https://cloud.example.nl/index.php/apps/keepiq/public'
const token = 'Ab3_tOkEn-42'

const pwPayload = 'Pincode kluis: 0472'
const sendPassword = 'vier woorden in één regel'
const sealed = await sendCrypto.sealPayload(pwPayload)
// Mirrors src/store/modules/ephemeralSend.js createSend() with a password.
const argonSalt = crypto.getRandomValues(new Uint8Array(16))
const kek = await argon2.deriveAesKeyArgon2id(sendPassword, argonSalt)
const wrappedKey = await sendCrypto.aesEncrypt(kek, sealed.rawKey)

// A raw Argon2id answer for diagnosis, from the same library the web app loads.
const argonRaw = (await import('argon2-browser')).default
const katSalt = new Uint8Array(16).fill(1)
const kat = await argonRaw.hash({
	pass: 'password',
	salt: katSalt,
	type: argonRaw.ArgonType.Argon2id,
	mem: 65536,
	time: 3,
	parallelism: 1,
	hashLen: 32,
})

write('send.json', {
	description:
		'Send payloads (src/send/sendCrypto.js) and the password wrap from src/store/modules/ephemeralSend.js with Argon2id from src/crypto/argon2.js.',
	withoutPassword: {
		payload: sendPayload,
		encryptedPayload: plain.encryptedPayload,
		rawKeyBase64Url: sendCrypto.toBase64Url(plain.rawKey),
		publicBase,
		token,
		link: sendCrypto.sendLink(publicBase, token, plain.rawKey),
		linkWithoutKey: sendCrypto.sendLink(publicBase, token, null),
	},
	withPassword: {
		payload: pwPayload,
		password: sendPassword,
		encryptedPayload: sealed.encryptedPayload,
		wrappedKey,
		argon2idSalt: sendCrypto.toBase64(argonSalt),
		rawKeyBase64Url: sendCrypto.toBase64Url(sealed.rawKey),
	},
	argon2idKnownAnswer: {
		password: 'password',
		saltHex: Buffer.from(katSalt).toString('hex'),
		memoryKiB: 65536,
		iterations: 3,
		parallelism: 1,
		keyHex: Buffer.from(kat.hash).toString('hex'),
	},
})

// --- TOTP -----------------------------------------------------------------

// RFC 6238 appendix B seeds, as base32.
const seeds = {
	SHA1: 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ',
	SHA256: 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQGEZA',
	SHA512: 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQGEZDGNA',
}
const times = [59, 1111111109, 1234567890, 2000000000, 20000000000]
const totpCases = []
for (const [algorithm, secret] of Object.entries(seeds)) {
	for (const digits of [6, 8]) {
		const uri = `otpauth://totp/Keepiq:alice%40example.nl?secret=${secret}&issuer=Keepiq&algorithm=${algorithm}&digits=${digits}&period=30`
		const params = totp.parseOtpauth(uri)
		for (const seconds of times) {
			totpCases.push({
				uri,
				epochMs: seconds * 1000,
				algorithm: params.algorithm,
				digits: params.digits,
				period: params.period,
				code: await totp.generateTotp(params, seconds * 1000),
			})
		}
	}
}
for (const [uri, seconds] of [
	[
		seeds.SHA1.toLowerCase()
			.replace(/(.{4})/g, '$1 ')
			.trim(),
		1111111109,
	],
	[`otpauth://totp/Example?secret=${seeds.SHA1}&period=60`, 1111111109],
	[`otpauth://totp/Example?secret=${seeds.SHA1}&digits=7`, 1111111109],
]) {
	const params = totp.parseOtpauth(uri)
	totpCases.push({
		uri,
		epochMs: seconds * 1000,
		algorithm: params.algorithm,
		digits: params.digits,
		period: params.period,
		code: await totp.generateTotp(params, seconds * 1000),
	})
}
const refused = []
for (const uri of [
	`otpauth://hotp/Example?secret=${seeds.SHA1}&counter=1`,
	`otpauth://totp/Example?secret=${seeds.SHA1}&algorithm=MD5`,
	'otpauth://totp/Example?issuer=NoSecret',
	'not base32 !',
]) {
	try {
		totp.parseOtpauth(uri)
		throw new Error(`web app accepted ${uri}; the vector expects a refusal`)
	} catch (e) {
		if (String(e.message).startsWith('web app accepted')) throw e
		refused.push({ uri, webError: e.message })
	}
}
write('totp.json', {
	description:
		'TOTP codes from parseOtpauth + generateTotp (src/totp/totp.js). algorithm/digits/period are what the parser resolved; refused lists inputs the parser rejects.',
	cases: totpCases,
	refused,
})

// --- passkey assertion ------------------------------------------------------

const ecPair = await crypto.subtle.generateKey(
	{ name: 'ECDSA', namedCurve: 'P-256' },
	true,
	['sign', 'verify'],
)
const userHandle = new TextEncoder().encode('user-7f3a')
const credentialIdBytes = Uint8Array.from({ length: 16 }, (_, i) => 0xa0 + i)
const record = passkey.buildPasskeyCredential({
	credentialId: webauthn._internals.b64urlEncode(credentialIdBytes),
	rpId: 'login.example.nl',
	rpName: 'Example Login',
	userName: 'alice@example.nl',
	userDisplayName: 'Alice de Vries',
	userHandle: webauthn._internals.b64urlEncode(userHandle),
	privateKey:
		pem(await crypto.subtle.exportKey('pkcs8', ecPair.privateKey), 'PRIVATE KEY')
		+ '\n',
	algorithm: -7,
	counter: 41,
	transports: ['internal', 'hybrid'],
	createdAt: '2026-10-04T12:00:00.000Z',
})
const challenge = Uint8Array.from({ length: 32 }, (_, i) => (i * 7) & 0xff)
const origin = 'https://login.example.nl'
const passkeyCases = []
for (const counter of [41, 0]) {
	const stored = { ...record, counter }
	const { assertion, counter: next } = await webauthn.getAssertion(
		{
			challenge: webauthn._internals.b64urlEncode(challenge),
			rpId: record.rpId,
		},
		origin,
		stored,
	)
	passkeyCases.push({
		storedCounter: counter,
		nextCounter: next,
		clientDataJSON: b64(Uint8Array.from(assertion.response.clientDataJSON)),
		authenticatorData: b64(
			Uint8Array.from(assertion.response.authenticatorData),
		),
		signatureDer: b64(Uint8Array.from(assertion.response.signature)),
		userHandle: b64(Uint8Array.from(assertion.response.userHandle)),
		rawId: b64(Uint8Array.from(assertion.rawId)),
	})
}
write('passkey.json', {
	description:
		'Passkey item JSON (src/passkey/passkey.js serializePasskey) and ES256 assertions from browser-extension/src/passkey/webauthn.js getAssertion. Signatures are DER and verify with publicKeySpki.',
	itemJson: passkey.serializePasskey(record),
	publicKeySpki: b64(await crypto.subtle.exportKey('spki', ecPair.publicKey)),
	challengeBase64Url: webauthn._internals.b64urlEncode(challenge),
	origin,
	assertions: passkeyCases,
})
