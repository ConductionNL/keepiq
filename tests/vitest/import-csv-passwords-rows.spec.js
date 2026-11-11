/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The column mapping the CSV parser detects and the rows it and the
 * Nextcloud Passwords parser make of edge-case input: duplicate and unknown
 * headers, empty and ignored cells, `additional:` targets, one-time codes,
 * nested, unnamed, cyclic and missing folders, and custom fields as text or
 * list. Recorded once from the parsers as they stood.
 *
 * @spec openspec/specs/secret-import/spec.md#requirement-supported-import-formats
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { applyMapping, detectMapping } from '../../src/import/parsers/csv.js'
import { parseNcPasswords } from '../../src/import/parsers/ncPasswords.js'

beforeEach(() => {
	vi.useFakeTimers()
	vi.setSystemTime(new Date('2026-01-02T03:04:05.000Z'))
})

afterEach(() => {
	vi.useRealTimers()
})

describe('CSV mapping and rows', () => {
	it('detects the first header of each kind, notes only when nothing else took it', () => {
		expect(
			detectMapping([' Title ', 'name', 'URL', 'Notes', 'comment', 'email', 'username', 'otp', 'Key', null, 'type', 'something']),
		).toMatchSnapshot()
	})

	it('applies a mapping to every kind of cell', () => {
		const mapping = [
			{ column: 'n', target: 'name' },
			{ column: 'u', target: 'url' },
			{ column: 'p', target: 'password' },
			{ column: 'x', target: 'ignore' },
			{ column: 'note', target: 'notes' },
			{ column: 'pin', target: 'additional:PIN' },
			{ column: 'code', target: 'totp' },
			{ column: 'f', target: 'folder' },
		]
		expect([
			applyMapping({ n: 'Mail', u: '', p: 'pw', x: 'dropped', note: 'hi', pin: '1234', code: 'JBSWY3DPEHPK3PXP', f: 'A/B' }, mapping, 1),
			applyMapping({ n: 'Steam', p: 'pw', code: 'steam://ABC' }, mapping, 2),
			applyMapping({ n: '', p: null }, mapping, 3),
			applyMapping({}, [], 4),
		]).toMatchSnapshot()
	})
})

describe('Nextcloud Passwords rows', () => {
	it('resolves every folder shape and custom field shape', () => {
		const backup = {
			folders: [
				{ id: 'f1', label: 'Work', parent: '00000000-0000-0000-0000-000000000000' },
				{ id: 'f2', label: 'Dev', parent: 'f1' },
				{ id: 'f3', label: '', parent: 'f2' },
				{ id: 'loop-a', label: 'A', parent: 'loop-b' },
				{ id: 'loop-b', label: 'B', parent: 'loop-a' },
				{ id: 'orphan', label: 'Orphan', parent: 'gone' },
				null,
				{ label: 'no id' },
			],
			passwords: [
				{ label: 'One', username: 'u', password: 'p', url: 'https://one', folder: 'f3', notes: 'n', customFields: '[{"label":"PIN","value":"1"},{"label":null,"value":"x"}]' },
				{ label: 'Two', folder: 'orphan', customFields: [{ label: 'A', value: 0 }, { label: 'B', value: null }, null] },
				{ label: 'Three', folder: 'loop-a', customFields: '{not json' },
				{ folder: 'unknown', customFields: 'null' },
				{ label: 'Five' },
			],
		}
		expect(parseNcPasswords(backup)).toMatchSnapshot()
		expect(parseNcPasswords(JSON.stringify({ passwords: [] }))).toEqual([])
		expect(() => parseNcPasswords({ folders: [] })).toThrow('Not a Nextcloud Passwords JSON backup')
	})
})
