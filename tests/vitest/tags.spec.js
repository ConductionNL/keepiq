/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Tags in stored form on the client (vault-favourites-tags-and-last-used D2):
 * the same rules the server applies, so the field never shows a tag the
 * server would store differently.
 *
 * @spec openspec/changes/vault-favourites-tags-and-last-used/specs/vault-list-organisation/spec.md#requirement-tags-per-holder
 */

import { describe, expect, it } from 'vitest'
import { normaliseTags, sameTags } from '../../src/utils/tags.js'

describe('normaliseTags', () => {
	it('trims, lowercases and drops empties and duplicates', () => {
		expect(normaliseTags([' On Call ', 'FINANCE', 'on call', '', null])).toEqual(
			['on call', 'finance'],
		)
	})

	it('cuts a tag to 32 characters and keeps at most 20', () => {
		expect(normaliseTags(['a'.repeat(40)])[0]).toHaveLength(32)
		const many = Array.from({ length: 25 }, (_, i) => `t${i}`)
		expect(normaliseTags(many)).toHaveLength(20)
	})
})

describe('sameTags', () => {
	it('ignores order and tells a change apart', () => {
		expect(sameTags(['a', 'b'], ['b', 'a'])).toBe(true)
		expect(sameTags(['a'], ['a', 'b'])).toBe(false)
		expect(sameTags([], undefined)).toBe(true)
	})
})
