/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Have I Been Pwned (HIBP) k-anonymity breach-check client.
 *
 * Computes SHA-1 of a decrypted value IN THE BROWSER, keeps the 35-character
 * suffix local, and sends ONLY the first 5 hash characters to the Keepiq
 * server proxy (`POST /api/v1/breach-check/range`, prefix in the body). The proxy forwards
 * the prefix to HIBP and returns the suffix list verbatim; the suffix match
 * happens here, locally. The full hash and the value never leave the browser
 * (password-health design D5). Runs only when both gates (admin setting +
 * per-user opt-in) are on; upstream failure soft-degrades to `unavailable`.
 *
 * @spec openspec/changes/password-health/specs/password-health/spec.md#requirement-opt-in-breach-checking-via-k-anonymity
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { checkValueWith } from './hibpMatch.js'

export { matchSuffix, sha1Hex } from './hibpMatch.js'

/**
 * Check a single value against the HIBP corpus via the prefix-only proxy.
 *
 * The returned shape is `{ status, count }` where status is `breached`,
 * `clean`, or `unavailable`. Only the 5-char prefix is ever transmitted.
 *
 * @param {string} value     The decrypted value (never transmitted).
 * @param {Function} [fetchRange] Optional injected range fetcher (test seam).
 * @return {Promise<{status: string, count: number}>}
 */
export async function checkValue(value, fetchRange = defaultFetchRange) {
	return checkValueWith(value, fetchRange)
}

/**
 * Default range fetcher: calls the Keepiq server proxy with the 5-char prefix.
 *
 * @param {string} prefix The 5-character SHA-1 prefix (the ONLY data sent).
 * @return {Promise<string>} The verbatim HIBP suffix list.
 */
async function defaultFetchRange(prefix) {
	// The prefix goes in the body, never in the URL: the server's log lines
	// and access log carry the request URI next to the user (keepiq#866).
	const response = await axios.post(
		generateUrl('/apps/keepiq/api/v1/breach-check/range'),
		{ prefix },
	)
	return response?.data?.suffixes ?? ''
}
