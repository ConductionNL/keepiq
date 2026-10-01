/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Tags in the form the server stores them (vault-favourites-tags-and-last-used D2).
 *
 * @spec openspec/changes/vault-favourites-tags-and-last-used/specs/vault-list-organisation/spec.md#requirement-tags-per-holder
 */

/** The most tags one secret may carry. */
export const MAX_TAGS = 20

/** The longest tag, in characters. */
export const MAX_LENGTH = 32

/**
 * Trim, lowercase, drop empties and duplicates, cut each tag to 32
 * characters and keep the first 20.
 *
 * @param {Array<string>|null} value The tags.
 * @return {Array<string>}
 * @spec openspec/changes/vault-favourites-tags-and-last-used/specs/vault-list-organisation/spec.md#requirement-tags-per-holder
 */
export function normaliseTags(value) {
	const seen = []
	for (const raw of value || []) {
		const tag = String(raw ?? '')
			.trim()
			.toLowerCase()
			.slice(0, MAX_LENGTH)
		if (tag && !seen.includes(tag)) seen.push(tag)
	}
	return seen.slice(0, MAX_TAGS)
}

/**
 * Whether two tag lists hold the same tags, in any order.
 *
 * @param {Array<string>} a One list.
 * @param {Array<string>} b The other.
 * @return {boolean}
 * @spec openspec/changes/vault-favourites-tags-and-last-used/specs/vault-list-organisation/spec.md#requirement-tags-per-holder
 */
export function sameTags(a, b) {
	const left = [...(a || [])].sort()
	const right = [...(b || [])].sort()
	return left.length === right.length && left.every((tag, i) => tag === right[i])
}
