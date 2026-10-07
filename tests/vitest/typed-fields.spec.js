/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The typed values of an item type live in the additional-fields blob under
 * their field label (admin-18).
 *
 * @spec openspec/specs/admin-secret-types/spec.md#requirement-typed-fields-storage
 */

import { describe, expect, it } from 'vitest'
import {
	fieldKeyFor,
	fieldListError,
	mergeTypedValues,
	missingRequired,
	splitTypedValues,
	typedFieldsOf,
} from '../../src/utils/typedFields.js'

function t(_app, text, vars = {}) {
	return text.replace(/{(\w+)}/g, (_m, k) => String(vars[k] ?? ''))
}

const FIELDS = [
	{ key: 'host', label: 'Host', kind: 'url', required: true },
	{
		key: 'root-password',
		label: 'Root password',
		kind: 'hidden',
		required: false,
	},
]

describe('typedFields', () => {
	it('reads no fields from a built-in type', () => {
		expect(typedFieldsOf({ name: 'login' })).toEqual([])
		expect(typedFieldsOf(null)).toEqual([])
	})

	it('splits a blob into typed values and free members, and merges it back', () => {
		const blob = { Host: 'https://db', 'Root password': 'pw', Notes: 'x' }
		const { values, rest } = splitTypedValues(blob, FIELDS)
		expect(values).toEqual({ host: 'https://db', 'root-password': 'pw' })
		expect(rest).toEqual({ Notes: 'x' })
		expect(mergeTypedValues(rest, FIELDS, values)).toEqual(blob)
	})

	it('leaves empty typed values out of the blob', () => {
		expect(
			mergeTypedValues({}, FIELDS, { host: 'h', 'root-password': '' }),
		).toEqual({
			Host: 'h',
		})
	})

	it('names the required fields still empty', () => {
		expect(missingRequired(FIELDS, { host: '  ' })).toEqual(['host'])
		expect(missingRequired(FIELDS, { host: 'h' })).toEqual([])
	})

	it('derives unique keys from labels', () => {
		expect(fieldKeyFor('Root password')).toBe('root-password')
		expect(fieldKeyFor('Host', ['host'])).toBe('host-2')
		expect(fieldKeyFor('***')).toBe('field')
	})

	it('refuses an empty, reserved or duplicate label', () => {
		expect(fieldListError([{ label: '', kind: 'text' }], t)).not.toBe('')
		expect(fieldListError([{ label: 'URL', kind: 'url' }], t)).not.toBe('')
		expect(
			fieldListError(
				[
					{ label: 'Host', kind: 'text' },
					{ label: 'host', kind: 'url' },
				],
				t,
			),
		).not.toBe('')
		expect(fieldListError(FIELDS, t)).toBe('')
	})
})
