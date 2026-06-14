/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * GDPR Art. 15 package assembly (secret-export-gdpr D3, tasks §5.4).
 *
 * Merges the server-side metadata half (GET /api/v1/gdpr/metadata) with the
 * client-decrypted vault half (serializeVault output) into one versioned,
 * self-describing `doriath-gdpr-export.json` package, assembled in the browser.
 *
 * When the vault is locked, the package is still produced with the metadata
 * half and a vault section that explicitly states the vault is end-to-end
 * encrypted and was not unlocked — which is itself the honest Art. 15 answer
 * under the always-E2E model (ADR-003). The package is downloaded locally and
 * is never stored server-side.
 */

/** The GDPR export package format identifier. */
export const GDPR_PACKAGE_FORMAT = 'doriath-gdpr-export'

/** The GDPR export package version. */
export const GDPR_PACKAGE_VERSION = 1

/** The explicit vault-unavailable explanation (honest Art. 15 posture). */
export const VAULT_UNAVAILABLE_REASON
	= 'vault is end-to-end encrypted and the data subject did not unlock it'

/**
 * Assemble the full or metadata-only GDPR export package.
 *
 * @param {object} metadata The server metadata document.
 * @param {object|null} vaultPayload The serialized decrypted vault payload, or
 *   null when the vault was not unlocked.
 * @return {object} The versioned, self-describing GDPR export package.
 */
export function assembleGdprPackage(metadata, vaultPayload) {
	const includesVault = vaultPayload != null

	const vaultSection = includesVault
		? {
			available: true,
			secrets: vaultPayload.secrets || [],
			folders: vaultPayload.folders || [],
		}
		: {
			available: false,
			unavailable: VAULT_UNAVAILABLE_REASON,
		}

	return {
		format: GDPR_PACKAGE_FORMAT,
		version: GDPR_PACKAGE_VERSION,
		generated: new Date().toISOString(),
		includesVault,
		documentation: {
			article: 'GDPR Article 15 (right of access)',
			metadata: 'Server-readable personal data Doriath stores about you.',
			vault: 'Your decrypted secrets, assembled in the browser; only present when you unlocked the vault.',
			privateKeyExcluded: 'Encrypted private-key blobs are excluded from suite records — they are unreadable without your master password and shipping them widens the attack surface.',
		},
		metadata,
		vault: vaultSection,
	}
}
