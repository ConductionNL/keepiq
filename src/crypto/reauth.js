/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Client-side master-password re-authentication (secret-export-gdpr D2).
 *
 * Under the always-E2E model (ADR-003) the server never sees the master
 * password and cannot verify it, so a "re-auth" gate is a CLIENT-SIDE proof of
 * knowledge: the entered password is run through the normal AES key-derivation
 * and used to attempt decryption of the stored private-key blob (re-fetched from
 * the API). Only if that succeeds did the user know the master password.
 *
 * Critically, this NEVER replaces the session CryptoKey and discards every
 * derived key immediately. It blocks the unattended-unlocked-session ("lunch
 * break") attack without weakening any E2E guarantee. The control is advisory
 * against a tampered client — true of every client-side control under E2E,
 * stated openly in the spec.
 */

import { decryptPrivateKey } from './aes.js'

/**
 * Verify the master password by attempting to decrypt the private-key blob.
 *
 * The freshly derived key is discarded the moment decryption resolves/rejects;
 * the caller's session CryptoKey is untouched. The password is never sent to
 * the server.
 *
 * @param {string} encryptedPrivateKey The stored AES envelope (private-key blob)
 * @param {string} masterPassword The freshly entered master password
 * @return {Promise<boolean>} True if the password decrypts the blob, else false
 */
export async function verifyMasterPassword(encryptedPrivateKey, masterPassword) {
	if (
		!encryptedPrivateKey
		|| typeof masterPassword !== 'string'
		|| masterPassword.length === 0
	) {
		return false
	}
	try {
		// A successful decrypt (AES-GCM tag verifies) proves knowledge of the
		// password. The returned PEM is intentionally not retained or imported.
		await decryptPrivateKey(encryptedPrivateKey, masterPassword)
		return true
	} catch {
		// Wrong password => AES-GCM authentication-tag mismatch => throw.
		return false
	}
}
