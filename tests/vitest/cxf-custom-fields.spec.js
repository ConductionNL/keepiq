/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Custom fields through CXF: a hidden field goes out as a concealed string,
 * also a built-in one, the full schema rides along in the field's extensions,
 * and an import of the document gives the secret the same fields and schemas
 * back. An SSH key's public key keeps the built-in form's name both ways, and
 * an imported field never takes a reserved name.
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

	it('exports a built-in hidden field as a concealed string', () => {
		const { document } = buildCxfDocument([
			{
				name: 'Deploy key',
				type: 'ssh_key',
				password: 'PRIVATE',
				additionalFields: {
					Passphrase: 'pp',
					'Public key': 'ssh-ed25519 AAAA',
				},
			},
		])
		const [ssh, custom] = document.accounts[0].items[0].credentials

		expect(ssh.publicKey.value).toBe('ssh-ed25519 AAAA')
		// The public key leaves on the SSH key entity, not again as a field.
		expect(custom.fields.map((f) => [f.label, f.fieldType])).toEqual([
			['Passphrase', 'concealed-string'],
		])
	})

	it('imports an SSH public key under the name the SSH Key form shows', () => {
		const rows = cxfToRows(
			parseCxfDocument(
				JSON.stringify({
					version: { major: 1, minor: 0 },
					exporterRpId: 'example.test',
					exporterDisplayName: 'Example',
					timestamp: 0,
					accounts: [
						{
							id: 'a',
							username: 'u',
							email: 'u@example.test',
							items: [
								{
									id: 'i',
									title: 'Deploy key',
									credentials: [
										{
											type: 'ssh-key',
											privateKey: { value: 'PRIVATE' },
											publicKey: { value: 'ssh-ed25519 AAAA' },
										},
									],
								},
							],
						},
					],
				}),
			),
		)

		expect(rows[0].additionalFields).toEqual({
			'Public key': 'ssh-ed25519 AAAA',
		})
	})

	it('gives an imported field with a reserved name a name of its own', () => {
		expect(
			readCustomFields({
				type: 'custom-fields',
				fields: [
					{ label: 'URL', fieldType: 'string', value: 'https://a' },
					{ label: 'url (imported)', fieldType: 'string', value: 'x' },
					{ label: 'url', fieldType: 'string', value: 'https://b' },
					{ label: '@schema', fieldType: 'string', value: '{}' },
				],
			}),
		).toEqual({
			'URL (imported)': 'https://a',
			'url (imported)': 'x',
			'url (imported 2)': 'https://b',
			'@schema (imported)': '{}',
		})
	})
})
