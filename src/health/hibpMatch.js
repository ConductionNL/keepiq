/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The local half of the k-anonymity breach check: hash the value and match
 * the suffix list. No network and no Nextcloud imports, so the browser
 * extension can share it with the web app (keepiq#746). The range fetch
 * stays with each caller.
 *
 * @spec openspec/changes/password-health/specs/password-health/spec.md#requirement-opt-in-breach-checking-via-k-anonymity
 */

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
 * Check a single value against the HIBP corpus via an injected range fetcher.
 *
 * Only the 5-character prefix is handed to `fetchRange`. Any failure reads as
 * `unavailable`, which callers must never treat as a block.
 *
 * @param {string} value The decrypted value (never transmitted).
 * @param {Function} fetchRange Resolves a 5-char prefix to the suffix list.
 * @return {Promise<{status: string, count: number}>}
 */
export async function checkValueWith(value, fetchRange) {
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
