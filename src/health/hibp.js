/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Have I Been Pwned (HIBP) k-anonymity breach-check client.
 *
 * Computes SHA-1 of a decrypted value IN THE BROWSER, keeps the 35-character
 * suffix local, and sends ONLY the first 5 hash characters to the Doriath
 * server proxy (`GET /api/v1/breach-check/range/{prefix}`). The proxy forwards
 * the prefix to HIBP and returns the suffix list verbatim; the suffix match
 * happens here, locally. The full hash and the value never leave the browser
 * (password-health design D5). Runs only when both gates (admin setting +
 * per-user opt-in) are on; upstream failure soft-degrades to `unavailable`.
 *
 * @spec openspec/changes/password-health/specs/password-health/spec.md#requirement-opt-in-breach-checking-via-k-anonymity
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * Compute the uppercase hexadecimal SHA-1 of a string via WebCrypto.
 *
 * @param {string} value The plaintext value (stays in the browser).
 * @return {Promise<string>} The 40-character uppercase hex SHA-1.
 */
export async function sha1Hex(value) {
	const bytes = new TextEncoder().encode(value)
	const digest = await crypto.subtle.digest('SHA-1', bytes)
	return Array.from(new Uint8Array(digest))
		.map((b) => b.toString(16).padStart(2, '0'))
		.join('')
		.toUpperCase()
}

/**
 * Parse a HIBP range response body (lines of `SUFFIX:COUNT`) and look up the
 * occurrence count for the given suffix.
 *
 * @param {string} body   The verbatim HIBP range response.
 * @param {string} suffix The 35-character uppercase hash suffix to match.
 * @return {number} The breach occurrence count (0 when not present).
 */
export function matchSuffix(body, suffix) {
	if (typeof body !== 'string' || body.length === 0) {
		return 0
	}
	const target = suffix.toUpperCase()
	for (const line of body.split(/\r?\n/)) {
		const idx = line.indexOf(':')
		if (idx === -1) {
			continue
		}
		if (line.slice(0, idx).toUpperCase() === target) {
			const count = parseInt(line.slice(idx + 1), 10)
			return Number.isFinite(count) ? count : 0
		}
	}
	return 0
}

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
	let hash
	try {
		hash = await sha1Hex(value)
	} catch {
		return { status: 'unavailable', count: 0 }
	}
	const prefix = hash.slice(0, 5)
	const suffix = hash.slice(5)

	let body
	try {
		body = await fetchRange(prefix)
	} catch {
		return { status: 'unavailable', count: 0 }
	}
	if (typeof body !== 'string') {
		return { status: 'unavailable', count: 0 }
	}

	const count = matchSuffix(body, suffix)
	return count > 0 ? { status: 'breached', count } : { status: 'clean', count: 0 }
}

/**
 * Default range fetcher: calls the Doriath server proxy with the 5-char prefix.
 *
 * @param {string} prefix The 5-character SHA-1 prefix (the ONLY data sent).
 * @return {Promise<string>} The verbatim HIBP suffix list.
 */
async function defaultFetchRange(prefix) {
	const response = await axios.get(
		generateUrl('/apps/doriath/api/v1/breach-check/range/' + encodeURIComponent(prefix)),
	)
	return response?.data?.suffixes ?? ''
}
