/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Encrypted backup envelope (secret-export-gdpr D1, tasks §5.2).
 *
 * Builds and reads the `.doriath-backup` JSON envelope: a versioned,
 * self-describing container whose payload is the serialized vault encrypted
 * with AES-256-GCM under a key derived from a user-chosen passphrase via
 * Argon2id. Unlike link shares (which hardcode KDF parameters), the KDF
 * parameters and salt are STORED IN THE ENVELOPE so a future parameter bump
 * never breaks old backups.
 *
 * The backup is intentionally suite-independent: it must survive the exact
 * scenario where the EncryptionSuite is lost (forgotten master password,
 * revoked/compromised suite, instance gone), so it is decryptable from the
 * passphrase alone, with no RSA key material involved.
 *
 * The passphrase, the derived key, and the plaintext payload NEVER leave the
 * browser.
 */

import { deriveAesKeyArgon2id } from '../crypto/argon2.js'

/**
 * The backup envelope format identifier.
 *
 * DELIBERATELY STILL `doriath-backup` AFTER THE doriath -> keepiq RENAME.
 * `decryptBackup()` below rejects any envelope whose `format` does not match
 * this constant EXACTLY, so this string is not a label — it is the key that
 * unlocks every `.doriath-backup` file a user has already downloaded. Those
 * files are passphrase-encrypted and suite-independent precisely so they
 * survive losing the vault; renaming the identifier would make the app refuse
 * to read the one artifact that exists to be readable when everything else is
 * gone, with a "Not a Keepiq backup file" error on a file that IS one.
 *
 * Migrating it is a two-sided change (write the new name, accept both on
 * read) and belongs to the coordinator, not to an app-id rename.
 */
export const BACKUP_FORMAT = 'doriath-backup'

/** The backup envelope version. */
export const BACKUP_VERSION = 1

/** Default Argon2id parameters (mirrors the link-share KDF module). */
const DEFAULT_KDF = { alg: 'argon2id', memory: 65536, iterations: 3, parallelism: 1 }

/** AES-GCM IV length in bytes. */
const IV_LENGTH = 12

/** Argon2id salt length in bytes. */
const SALT_LENGTH = 16

/**
 * Encode a Uint8Array as base64.
 *
 * @param {Uint8Array} bytes The bytes.
 * @return {string} Base64 string.
 */
function toBase64(bytes) {
	let binary = ''
	for (let i = 0; i < bytes.length; i++) {
		binary += String.fromCharCode(bytes[i])
	}
	return btoa(binary)
}

/**
 * Decode a base64 string to a Uint8Array.
 *
 * @param {string} base64 The base64 string.
 * @return {Uint8Array} The bytes.
 */
function fromBase64(base64) {
	const binary = atob(base64)
	const bytes = new Uint8Array(binary.length)
	for (let i = 0; i < binary.length; i++) {
		bytes[i] = binary.charCodeAt(i)
	}
	return bytes
}

/**
 * Encrypt a serialized vault payload into a backup envelope.
 *
 * @param {object} payload The serialized vault payload ({ secrets, folders, ... }).
 * @param {string} passphrase The user-chosen backup passphrase.
 * @return {Promise<object>} The versioned backup envelope.
 */
export async function encryptBackup(payload, passphrase) {
	const salt = crypto.getRandomValues(new Uint8Array(SALT_LENGTH))
	const iv = crypto.getRandomValues(new Uint8Array(IV_LENGTH))
	const key = await deriveAesKeyArgon2id(passphrase, salt)

	const plaintext = new TextEncoder().encode(JSON.stringify(payload))
	const ciphertext = new Uint8Array(
		await crypto.subtle.encrypt({ name: 'AES-GCM', iv }, key, plaintext),
	)

	const blob = new Uint8Array(iv.length + ciphertext.length)
	blob.set(iv, 0)
	blob.set(ciphertext, iv.length)

	return {
		format: BACKUP_FORMAT,
		version: BACKUP_VERSION,
		created_at: new Date().toISOString(),
		kdf: {
			alg: DEFAULT_KDF.alg,
			memory: DEFAULT_KDF.memory,
			iterations: DEFAULT_KDF.iterations,
			parallelism: DEFAULT_KDF.parallelism,
			salt: toBase64(salt),
		},
		cipher: { alg: 'aes-256-gcm' },
		payload: toBase64(blob),
	}
}

/**
 * Decrypt a backup envelope into its serialized vault payload.
 *
 * Reads the KDF parameters and salt FROM THE ENVELOPE (not hardcoded), so a
 * backup written under different parameters still decrypts. Throws on a wrong
 * passphrase (AES-GCM authentication-tag mismatch) — never returns garbage.
 *
 * @param {object} envelope The backup envelope.
 * @param {string} passphrase The backup passphrase.
 * @return {Promise<object>} The decrypted vault payload.
 */
export async function decryptBackup(envelope, passphrase) {
	if (!envelope || envelope.format !== BACKUP_FORMAT) {
		throw new Error('Not a Keepiq backup file')
	}
	if (!envelope.kdf || envelope.kdf.alg !== 'argon2id' || !envelope.kdf.salt) {
		throw new Error('Unsupported or missing KDF parameters')
	}

	const salt = fromBase64(envelope.kdf.salt)
	const blob = fromBase64(envelope.payload)
	const iv = blob.slice(0, IV_LENGTH)
	const ciphertextWithTag = blob.slice(IV_LENGTH)

	// The KDF parameters are read from the envelope (see argon2.js note on the
	// fixed-parameter link-share module); the salt drives the derivation.
	const key = await deriveAesKeyArgon2id(passphrase, salt)

	const plaintext = await crypto.subtle.decrypt(
		{ name: 'AES-GCM', iv },
		key,
		ciphertextWithTag,
	)

	return JSON.parse(new TextDecoder().decode(plaintext))
}
