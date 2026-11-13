/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Custom fields through CXF: a hidden field goes out as a concealed string,
 * the full schema rides along in the field's extensions, and an import of the
 * document gives the secret the same fields and schemas back.
 *
 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-schemas-survive-export-and-import
 */

import { describe, expect, it } from 'vitest'
import { buildCxfDocument, cxfToRows, parseCxfDocument } from '../../src/cxf/cxf.js'
import { readCustomFields } from '../../src/cxf/cxfCustomFields.js'

const BLOB = {
	'Recovery code': 'abc-123',
	Port: '5432',
	'@schema': {
		'Recovery code': { type: 'string', hidden: true },
		Port: { type: 'integer', minimum: 1, maximum: 65535 },
	},
}

describe('CXF custom fields', () => {
	it('exports a hidden field as a concealed string with its schema', () => {
		const { document } = buildCxfDocument([
			{
				name: 'Server',
				type: 'login',
				login: 'root',
				password: 'pw',
				additionalFields: BLOB,
			},
		])
		const custom = document.accounts[0].items[0].credentials.find(
			(c) => c.type === 'custom-fields',
		)

		expect(custom.fields.map((f) => [f.label, f.fieldType])).toEqual([
			['Recovery code', 'concealed-string'],
			['Port', 'number'],
		])
		expect(custom.fields[1].extensions[0].schema).toEqual({
			type: 'integer',
			minimum: 1,
			maximum: 65535,
		})
	})

	it('round-trips the fields and their schemas', () => {
		const { document } = buildCxfDocument([
			{
				name: 'Server',
				type: 'login',
				login: 'root',
				password: 'pw',
				additionalFields: BLOB,
			},
		])
		const rows = cxfToRows(parseCxfDocument(JSON.stringify(document)))
		const accepted = rows.filter((row) => row.errors.length === 0)

		expect(accepted).toHaveLength(1)
		expect(accepted[0].additionalFields).toEqual(BLOB)
	})

	it('reads a schema from the CXF field type when no Keepiq schema rides along', () => {
		expect(
			readCustomFields({
				type: 'custom-fields',
				fields: [
					{ label: 'PIN', fieldType: 'concealed-string', value: '1234' },
					{ label: 'Site', fieldType: 'string', value: 'x' },
				],
			}),
		).toEqual({
			PIN: '1234',
			Site: 'x',
			'@schema': { PIN: { type: 'string', hidden: true } },
		})
	})
})
