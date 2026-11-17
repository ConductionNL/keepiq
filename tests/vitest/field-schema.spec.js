/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The custom-field schema helpers: presets, the value check per type and
 * format, the reserved `@schema` member in the blob, and what an import and a
 * request merge do with schemas.
 *
 * @spec openspec/specs/vault-custom-fields/spec.md#requirement-custom-fields-carry-a-schema
 * @spec openspec/specs/vault-custom-fields/spec.md#requirement-presets-pick-a-schema-advanced-shows-it
 * @spec openspec/specs/vault-custom-fields/spec.md#requirement-a-value-must-fit-its-schema
 * @spec openspec/specs/vault-custom-fields/spec.md#requirement-a-request-carries-the-schemas-of-its-fields
 */

import { describe, expect, it } from 'vitest'
import {
	memberNameError,
	membersToObject,
	objectToMembers,
} from '../../src/utils/additionalFields.js'
import { typeFieldsOf } from '../../src/utils/builtinTypeFields.js'
import {
	cleanSchema,
	fieldsOf,
	fitImportedBlob,
	inputOf,
	inputTypeFor,
	isHiddenSchema,
	mergeBlobs,
	mergeFilledBlob,
	presetOf,
	presetOptions,
	presetSchema,
	schemaConflicts,
	schemaError,
	schemasOf,
	valuesFit,
	withSchemas,
} from '../../src/utils/fieldSchema.js'

describe('field schemas', () => {
	it('reads a blob without schemas as plain text, exactly as before', () => {
		const blob = { 'client-id': 'abc', tenant: 'acme' }

		expect(schemasOf(blob)).toEqual({})
		expect(objectToMembers(blob)).toEqual([
			{ name: 'client-id', value: 'abc', schema: { type: 'string' } },
			{ name: 'tenant', value: 'acme', schema: { type: 'string' } },
		])
		// Plain-text members write no @schema member at all.
		expect(membersToObject(objectToMembers(blob))).toEqual(blob)
	})

	it('stores a schema under @schema and never lists it as a field', () => {
		const blob = membersToObject([
			{
				name: 'Port',
				value: '5432',
				schema: { type: 'integer', minimum: 1, maximum: 65535 },
			},
		])

		expect(blob).toEqual({
			Port: '5432',
			'@schema': { Port: { type: 'integer', minimum: 1, maximum: 65535 } },
		})
		expect(fieldsOf(blob)).toEqual({ Port: '5432' })
		expect(objectToMembers(blob).map((m) => m.name)).toEqual(['Port'])
	})

	it('refuses @schema as a field name', () => {
		expect(memberNameError('@schema')).not.toBe('')
		expect(memberNameError('schema')).toBe('')
	})

	it('maps every preset to a schema and back', () => {
		for (const { id } of presetOptions()) {
			expect(presetOf(presetSchema(id))).toBe(id)
		}
		expect(presetSchema('password')).toEqual({ type: 'string', hidden: true })
		expect(presetOf({ type: 'string', maxLength: 10 })).toBe('custom')
	})

	it('keeps only the keys that fit the type', () => {
		expect(
			cleanSchema({
				type: 'integer',
				minimum: '1',
				format: 'uri',
				pattern: 'x',
			}),
		).toEqual({ type: 'integer', minimum: 1 })
		expect(cleanSchema({ type: 'nonsense' })).toEqual({ type: 'string' })
		expect(cleanSchema({ type: 'object', hidden: true })).toEqual({
			type: 'object',
			hidden: true,
		})
	})

	it.each([
		[{ type: 'integer', minimum: 1, maximum: 65535 }, '5432', true],
		[{ type: 'integer', minimum: 1, maximum: 65535 }, '70000', false],
		[{ type: 'integer' }, '4.5', false],
		[{ type: 'number', multipleOf: 0.5 }, '4.5', true],
		[{ type: 'number', multipleOf: 0.5 }, '4.2', false],
		[{ type: 'string', minLength: 4, maxLength: 6 }, '123', false],
		[{ type: 'string', maxLength: 32 }, 'x'.repeat(40), false],
		[{ type: 'string', pattern: '^[0-9]{4,6}$' }, '12a4', false],
		[{ type: 'string', pattern: '^[0-9]{4,6}$' }, '1234', true],
		[{ type: 'string', format: 'uri' }, 'https://example.org/hook', true],
		[{ type: 'string', format: 'uri' }, 'example.org', false],
		[{ type: 'string', format: 'email' }, 'a@example.org', true],
		[{ type: 'string', format: 'email' }, 'not-an-address', false],
		[{ type: 'string', format: 'date' }, '2026-11-10', true],
		[{ type: 'string', format: 'ipv4' }, '10.0.0.300', false],
		[{ type: 'boolean' }, 'true', true],
		[{ type: 'boolean' }, 'yes', false],
		[{ type: 'object' }, '{"type":"service_account"}', true],
		[{ type: 'object' }, '[1, 2]', false],
		[{ type: 'object' }, '{broken', false],
	])('checks %j against %s', (schema, value, fits) => {
		expect(schemaError(value, schema) === '').toBe(fits)
	})

	it('lets an empty value fit any schema', () => {
		expect(schemaError('', { type: 'integer', minimum: 1 })).toBe('')
	})

	it('picks the input from the schema', () => {
		expect(inputOf({ type: 'object' })).toBe('json')
		expect(inputOf({ type: 'string', format: 'uri' })).toBe('url')
		expect(inputOf({ type: 'string', format: 'text' })).toBe('multiline')
		expect(isHiddenSchema({ type: 'object', hidden: true })).toBe(true)
		expect(isHiddenSchema({ type: 'string', format: 'password' })).toBe(true)
	})

	it('drops the schema of an imported value that does not fit, and keeps the value', () => {
		const blob = {
			Port: 'n/a',
			PIN: '1234',
			'@schema': {
				Port: { type: 'integer' },
				PIN: { type: 'string', hidden: true },
			},
		}

		expect(fitImportedBlob(blob)).toEqual({
			Port: 'n/a',
			PIN: '1234',
			'@schema': { PIN: { type: 'string', hidden: true } },
		})
	})

	it('merges an imported schema into the stored ones', () => {
		const own = {
			Host: 'db',
			'@schema': { Host: { type: 'string', format: 'hostname' } },
		}
		const filled = withSchemas(
			{ PIN: '1234' },
			{ PIN: { type: 'string', hidden: true } },
		)

		expect(mergeBlobs(own, filled)).toEqual({
			Host: 'db',
			PIN: '1234',
			'@schema': {
				Host: { type: 'string', format: 'hostname' },
				PIN: { type: 'string', hidden: true },
			},
		})
	})

	it('takes only the values from a fill: the stored schemas stay', () => {
		const own = {
			Host: 'db',
			PIN: '',
			'@schema': {
				Host: { type: 'string', hidden: true },
				PIN: { type: 'string', pattern: '^[0-9]{4}$' },
			},
		}
		// A crafted fill trying to unmask Host, retype PIN and add a pattern.
		const filled = {
			PIN: '1234',
			Note: 'hi',
			'@schema': {
				Host: { type: 'string' },
				PIN: { type: 'boolean' },
				Note: { type: 'string', pattern: '^(a+)+$' },
			},
		}

		expect(mergeFilledBlob(own, filled)).toEqual({
			Host: 'db',
			PIN: '1234',
			Note: 'hi',
			'@schema': {
				Host: { type: 'string', hidden: true },
				PIN: { type: 'string', pattern: '^[0-9]{4}$' },
			},
		})
	})

	it('gives a filled field without a stored schema its request’s, never the fill’s', () => {
		const own = {
			Host: 'db',
			'@schema': { Host: { type: 'string', format: 'hostname' } },
		}
		const filled = {
			Host: 'db2',
			PIN: '1234',
			'@schema': { PIN: { type: 'string' } },
		}
		const requested = {
			Host: { type: 'string' },
			PIN: { type: 'string', hidden: true },
			Other: { type: 'boolean' },
		}

		expect(mergeFilledBlob(own, filled, requested)).toEqual({
			Host: 'db2',
			PIN: '1234',
			'@schema': {
				Host: { type: 'string', format: 'hostname' },
				PIN: { type: 'string', hidden: true },
			},
		})
	})

	// openspec/specs/vault-custom-fields/spec.md#requirement-a-request-carries-the-schemas-of-its-fields
	it('finds the conflicts the server refuses, reading a schema as stored', () => {
		expect(schemaConflicts({ type: 'string', pattern: '(a+)+' })).toBe(true)
		expect(schemaConflicts({ type: 'integer', minimum: 9, maximum: 1 })).toBe(true)
		// An integer keeps no pattern, so a stray one is no conflict.
		expect(schemaConflicts({ type: 'integer', pattern: '(a+)+' })).toBe(false)
		expect(schemaConflicts({ type: 'string', minLength: 1, maxLength: 9 })).toBe(false)
	})

	// openspec/specs/vault-custom-fields/spec.md#requirement-each-schema-has-its-own-input-and-display
	it('shows a value a number or date input cannot hold in a text input', () => {
		expect(inputTypeFor('number', '5')).toBe('number')
		expect(inputTypeFor('number', '-2.5e3')).toBe('number')
		expect(inputTypeFor('number', 'default')).toBe('text')
		expect(inputTypeFor('number', '5.')).toBe('text')
		expect(inputTypeFor('number', '0x10')).toBe('text')
		expect(inputTypeFor('date', '31/03/2026')).toBe('text')
		expect(inputTypeFor('date', '2026-03-31')).toBe('date')
		expect(inputTypeFor('date', '')).toBe('date')
	})

	it('keeps a field a person named @schema, under a name of its own', () => {
		expect(fieldsOf({ '@schema': 'my value', a: '1' })).toEqual({
			a: '1',
			'@schema (imported)': 'my value',
		})
		expect(objectToMembers({ '@schema': 'v' })).toEqual([
			{ name: '@schema (imported)', value: 'v', schema: { type: 'string' } },
		])
		expect(fitImportedBlob({ '@schema': 'my value', a: '1' })).toEqual({
			a: '1',
			'@schema (imported)': 'my value',
		})
	})

	it('keeps an imported field hidden when its value does not fit the rest', () => {
		expect(
			fitImportedBlob({
				PIN: 'abcde',
				'@schema': {
					PIN: { type: 'string', hidden: true, pattern: '^\\d{4}$' },
				},
			}),
		).toEqual({
			PIN: 'abcde',
			'@schema': { PIN: { type: 'string', hidden: true } },
		})
	})

	it('refuses dates and times that do not exist, in plain words', () => {
		const date = { type: 'string', format: 'date' }
		expect(schemaError('2026-02-28', date)).toBe('')
		expect(schemaError('2026-02-31', date)).toBe(
			'Enter a real date, such as 2026-03-31.',
		)
		expect(schemaError('2026-13-45', date)).not.toBe('')
		expect(
			schemaError('2026-03-31T25:00', { type: 'string', format: 'date-time' }),
		).not.toBe('')
		expect(schemaError('99:99', { type: 'string', format: 'time' })).toBe(
			'Enter a time, such as 14:30.',
		)
		expect(schemaError('nope', { type: 'string', format: 'color-hex' })).toBe(
			'Enter a colour, such as #1a2b3c.',
		)
	})

	it('blocks a form while a value or a built-in field does not fit', () => {
		const database = typeFieldsOf({ name: 'database' })

		expect(valuesFit([], database, { port: '5432' })).toBe(true)
		expect(valuesFit([], database, { port: '70000' })).toBe(false)
		expect(
			valuesFit([{ value: 'abc', schema: { type: 'integer' } }], [], {}),
		).toBe(false)
	})
})
