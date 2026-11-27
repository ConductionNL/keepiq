/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/** The window event the CA section sends after a root renewal; `detail.rootFingerprint` is the new root's. */
export const ROOT_RENEWED_EVENT = 'keepiq:root-renewed'

/**
 * @param {string} hex A lowercase hex SHA-256.
 * @return {string} Upper case, colon separated, as people read it aloud.
 * @spec exclude Display formatting only.
 */
export function formatFingerprint(hex) {
	return (String(hex || '').match(/.{2}/g) || []).join(':').toUpperCase()
}

/**
 * Tell the other admin sections that this server's root was renewed.
 *
 * @param {string} rootFingerprint The new root's fingerprint.
 * @return {void}
 * @spec openspec/changes/partner-root-renewal-announcement/specs/federated-sharing/spec.md#scenario-a-root-whose-key-cannot-be-read-is-renewed-unannounced
 */
export function announceRootRenewed(rootFingerprint) {
	window.dispatchEvent(
		new CustomEvent(ROOT_RENEWED_EVENT, { detail: { rootFingerprint } }),
	)
}
