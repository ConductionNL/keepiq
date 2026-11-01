/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test of parseBitwardenJson(): the rows for a set of exports with
 * every item type, folder and collection mapping, TOTP seeds and passkeys,
 * compared with golden/bitwarden-json.json. KEEPIQ_RECORD_GOLDEN=1 rewrites it.
 */
import fs from 'node:fs'
import path from 'node:path'
import { describe, expect, it } from 'vitest'
import { parseBitwardenJson } from '../../src/import/parsers/bitwarden.js'

const GOLDEN = path.join(__dirname, 'golden', 'bitwarden-json.json')

function passkey(id, extra = {}) {
	return {
		credentialId: id,
		rpId: 'example.com',
		keyValue: 'KEY-' + id,
		userName: 'u',
		creationDate: '2026-01-01T00:00:00Z',
		...extra,
	}
}

const EXPORTS = {
	'not an export': 'null',
	'no items': { folders: [] },
	'items not array': { items: {} },
	'json text': JSON.stringify({
		items: [{ type: 1, name: 'T', login: { username: 'a', password: 'b' } }],
	}),
	empty: { items: [] },
	mixed: {
		folders: [
			{ id: 'f1', name: 'Work/Dev' },
			{ id: 'f2' },
			null,
			{ name: 'no id' },
		],
		collections: [{ id: 'c1', name: 'Shared' }, { id: 'c2' }, null],
		items: [
			{
				type: 1,
				name: 'GitHub',
				folderId: 'f1',
				notes: 'n',
				fields: [
					{ name: 'pin', value: '1234' },
					{ name: null, value: 'x' },
					null,
					{ name: 7, value: 0 },
				],
				login: {
					uris: [{ uri: 'https://github.com' }],
					username: 'me',
					password: 'pw',
					totp: 'JBSWY3DPEHPK3PXP',
				},
			},
			{
				type: 1,
				name: 'Uri wins',
				login: { uri: 'https://direct', uris: [{ uri: 'https://list' }] },
			},
			{
				type: 1,
				name: 'Unknown folder',
				folderId: 'nope',
				collectionIds: ['zz', 'c2', 'c1'],
				login: {},
			},
			{
				type: 1,
				name: 'Collection',
				collectionIds: 'c1',
				login: { username: 'u' },
			},
			{ type: undefined, name: 'No type', login: { password: 'p' } },
			{ type: 1, login: null },
			{ type: 2, name: 'Note', secureNote: { type: 0 } },
			{ type: 5, name: 'SSH key' },
			{
				type: 3,
				name: 'Card',
				card: {
					cardholderName: 'A B',
					number: '4111111111111111',
					expMonth: '1',
					expYear: '2030',
					code: '123',
					brand: 'Visa',
				},
			},
			{
				type: 4,
				name: 'Identity',
				identity: { firstName: 'A', lastName: 'B', email: 'a@b' },
			},
			{ type: 1, name: 'Steam', login: { totp: 'steam://ABCDEFGHIJ' } },
			{
				type: 1,
				name: 'Bad seed',
				notes: 'keep',
				login: { totp: 'not a seed!' },
			},
		],
	},
	passkeys: {
		items: [
			{
				type: 1,
				name: 'Only passkey',
				login: { fido2Credentials: [passkey('p1')] },
			},
			{
				type: 1,
				name: 'Two passkeys',
				login: {
					uri: 'https://site',
					fido2Credentials: [passkey('p2'), passkey('p3')],
				},
			},
			{
				type: 1,
				name: 'Incomplete',
				login: {
					fido2Credentials: [
						{ credentialId: 'x' },
						passkey('p4', { rpId: '' }),
						passkey('p5'),
					],
				},
			},
			{
				type: 1,
				name: 'Mixed',
				login: {
					fido2Credentials: [{}, passkey('p6'), null, passkey('p7')],
				},
			},
			{
				type: 1,
				name: 'Not array',
				login: { fido2Credentials: passkey('p8') },
			},
			{ type: 1, name: 'After', login: { username: 'z' } },
		],
	},
}

describe('parseBitwardenJson', () => {
	it('matches the golden record', () => {
		const got = {}
		for (const [name, input] of Object.entries(EXPORTS)) {
			try {
				got[name] = parseBitwardenJson(input)
			} catch (e) {
				got[name] = `throws ${e.constructor.name}: ${e.message}`
			}
		}
		const json = JSON.stringify(got, null, 1) + '\n'
		if (process.env.KEEPIQ_RECORD_GOLDEN === '1') {
			fs.mkdirSync(path.dirname(GOLDEN), { recursive: true })
			fs.writeFileSync(GOLDEN, json)
		}
		expect(json).toBe(fs.readFileSync(GOLDEN, 'utf8'))
	})
})
