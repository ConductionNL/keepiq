/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for the CXF import (cxfToRows) and export (buildCxfDocument):
 * documents with every credential type, codes and passkeys in every order,
 * broken entities and collections, and vault rows of every type with their
 * own codes and passkeys, compared with fixtures/cxf.golden.json. The clock
 * is fixed, so passkey creation times and the export timestamp are stable.
 *
 * Each case is its own test. Set RECORD_GOLDEN=1 to rewrite the fixture.
 *
 * @spec openspec/specs/secret-import/spec.md
 */

import fs from 'node:fs'
import path from 'node:path'
import { afterAll, beforeAll, describe, expect, it, vi } from 'vitest'
import { buildCxfDocument, cxfToRows, parseCxfDocument } from '../../src/cxf/cxf.js'

const GOLDEN = path.resolve(__dirname, 'fixtures/cxf.golden.json')
const KEY = 'JBSWY3DPEHPK3PXP'

/**
 * A CXF passkey entity.
 *
 * @param {string} id The credential id.
 * @param {object} [extra] Fields to add or override.
 * @return {object}
 */
function passkey(id, extra = {}) {
	return {
		type: 'passkey',
		credentialId: id,
		rpId: 'example.com',
		rpName: 'Example',
		username: 'alice',
		userHandle: 'H',
		key: `PK_${id}`,
		...extra,
	}
}

/**
 * A stored canonical passkey, as a row's `passkeys` or a passkey row's key holds it.
 *
 * @param {string} id The credential id.
 * @param {object} [extra] Fields to add or override.
 * @return {object}
 */
function stored(id, extra = {}) {
	return {
		credentialId: id,
		rpId: 'example.com',
		rpName: 'Example',
		userName: 'alice',
		userDisplayName: '',
		userHandle: 'H',
		privateKey: `PK_${id}`,
		algorithm: -7,
		counter: 0,
		transports: [],
		createdAt: '2026-01-01T00:00:00.000Z',
		...extra,
	}
}

const ITEMS = [
	{
		id: 'i1',
		title: 'Login',
		credentials: [
			{
				type: 'basic-auth',
				urls: ['https://a.test', { value: 'https://b.test' }],
				username: { value: 'u' },
				password: { value: 'p' },
				notes: 'n',
			},
		],
	},
	{
		id: 'i2',
		title: 'Password type',
		credentials: [{ type: 'password', password: 'p2' }],
	},
	{
		id: 'i3',
		title: 'Login entity',
		credentials: [{ type: 'login', username: 'u3', password: { value: null } }],
	},
	{ id: 'i4', title: '', credentials: [{ type: 'note', content: 'c' }] },
	{
		id: 'i5',
		title: 'Note',
		credentials: [{ type: 'note', content: { value: 'c5' } }],
	},
	{
		id: 'i6',
		title: '',
		credentials: [{ type: 'api-key', username: 'svc', key: 'k6' }],
	},
	{
		id: 'i7',
		title: '',
		credentials: [{ type: 'ssh-key', privateKey: 'priv', publicKey: 'pub' }],
	},
	{
		id: 'i8',
		title: 'SSH',
		credentials: [{ type: 'ssh-key', privateKey: 'priv' }],
	},
	{
		id: 'i9',
		title: '',
		credentials: [
			{ type: 'wifi', ssid: 'Home', security: 'wpa2', passphrase: 'w' },
		],
	},
	{ id: 'i10', title: '', credentials: [{ type: 'wifi', passphrase: 'w' }] },
	{
		id: 'i11',
		title: 'Wifi',
		credentials: [{ type: 'wifi', ssid: { value: '' }, security: 'open' }],
	},
	{ id: 'i12', title: '', credentials: [{ type: 'drivers-license' }] },
	{ id: 'i13', title: 'Null credential', credentials: [null] },
	{ id: 'i14', title: 'No credentials', credentials: [] },
	{ id: 'i15', title: 'Not a list' },
	{
		id: 'i16',
		title: 'Login with code',
		credentials: [
			{
				type: 'totp',
				secret: KEY,
				period: 30,
				digits: 6,
				algorithm: 'sha1',
				issuer: 'I',
				username: 'u',
			},
			{ type: 'basic-auth', username: 'u', password: 'p' },
		],
	},
	{
		id: 'i17',
		title: 'Code via url',
		credentials: [
			{ type: 'basic-auth', password: 'p' },
			{ type: 'totp', url: `otpauth://totp/X?secret=${KEY}` },
		],
	},
	{
		id: 'i18',
		title: 'Two codes',
		credentials: [
			{ type: 'basic-auth', password: 'p' },
			{ type: 'totp', secret: KEY },
			{ type: 'totp', secret: 'GEZDGNBVGY3TQOJQ' },
			{ type: 'totp', secret: KEY },
		],
	},
	{
		id: 'i19',
		title: 'Bad code',
		credentials: [
			{ type: 'basic-auth', password: 'p' },
			{ type: 'totp', secret: KEY, algorithm: 'md5' },
			{ type: 'totp' },
			{ type: 'totp', secret: 'NOT BASE32!' },
		],
	},
	{ id: 'i20', title: '', credentials: [{ type: 'totp', secret: KEY }] },
	{ id: 'i21', title: 'Only passkey', credentials: [passkey('c21')] },
	{ id: 'i22', title: '', credentials: [passkey('c22', { rpId: 'rp22.test' })] },
	{
		id: 'i23',
		title: 'Passkeys on a note',
		credentials: [
			{ type: 'note', content: 'n' },
			passkey('c23'),
			passkey('c23b'),
		],
	},
	{
		id: 'i24',
		title: 'Incomplete passkey',
		credentials: [
			{ type: 'basic-auth', password: 'p' },
			passkey('c24', { key: '' }),
		],
	},
	{
		id: 'i25',
		title: 'Clashing passkeys',
		credentials: [
			{ type: 'basic-auth', password: 'p' },
			passkey('dup'),
			passkey('dup', { key: 'OTHER' }),
		],
	},
	{
		id: 'i26',
		title: 'Rejected login with code',
		credentials: [
			{
				type: 'basic-auth',
				urls: 'x',
				password: 'p',
				username: 'x'.repeat(5000),
			},
			{ type: 'totp', secret: KEY },
		],
	},
	{
		id: 'i27',
		title: 'Legacy userName passkey',
		credentials: [
			{ type: 'basic-auth', password: 'p' },
			passkey('c27', { username: undefined, userName: 'legacy' }),
		],
	},
	{
		id: 'i29',
		title: 'Note before login',
		credentials: [
			{ type: 'note', content: 'n' },
			{ type: 'basic-auth', password: 'p' },
			{ type: 'totp', secret: KEY },
		],
	},
	{
		id: 'i30',
		title: '',
		credentials: [{ type: 'totp', secret: KEY }, passkey('c30')],
	},
	{
		id: 'i31',
		title: 'Only a bad code',
		credentials: [{ type: 'totp', secret: 'NOT BASE32!' }],
	},
	{
		id: 'i32',
		title: 'Only a bad code URL',
		credentials: [{ type: 'totp', url: 'otpauth://totp/X?secret=NOT-BASE32' }],
	},
	{
		id: 'i28',
		title: 'Unknown type then login',
		credentials: [
			{ type: 'mystery' },
			{ type: 'login', password: 'p28' },
			{ type: 'totp', secret: KEY },
		],
	},
]

const DOCUMENTS = {
	'every item, one account': {
		version: { major: 1, minor: 0 },
		accounts: [
			{
				id: 'a1',
				items: ITEMS,
				collections: [
					{ id: 'c1', title: 'Work', items: ['i1', 'i16'] },
					{ id: 'c2', title: null, items: ['i2'] },
					{ id: 'c3', title: 'Bad', items: 'i3' },
				],
			},
		],
	},
	'two accounts': {
		version: { major: 1, minor: 0 },
		accounts: [
			{ id: 'a1', items: ITEMS.slice(0, 3) },
			{
				id: 'a2',
				items: ITEMS.slice(15, 19),
				collections: [{ title: 'Second', items: ['i17'] }],
			},
		],
	},
	'as JSON text': JSON.stringify({
		version: { major: 1, minor: 0 },
		accounts: [{ id: 'a1', items: ITEMS.slice(20, 25) }],
	}),
	'not a document': { accounts: 'none' },
	'unparsable text': '{ nope',
}

const ROWS = [
	{
		name: 'Login',
		url: 'https://a.test',
		type: 'login',
		login: 'u',
		password: 'p',
		additionalFields: { notes: 'n' },
		folder: 'Work',
	},
	{
		name: 'Login, no url',
		url: null,
		type: 'login',
		login: null,
		password: null,
		folder: '',
	},
	{
		name: 'Database',
		type: 'database',
		login: 'db',
		password: 'dp',
		folder: 'Work/Sub',
	},
	{ name: 'Note', type: 'note', password: 'text', folder: 'Work' },
	{ name: 'Api', type: 'api_key', login: 'svc', password: 'k' },
	{
		name: 'Ssh',
		type: 'ssh_key',
		password: 'priv',
		additionalFields: { publicKey: 'pub' },
	},
	{
		name: 'Ssh, string fields',
		type: 'ssh_key',
		password: 'priv',
		additionalFields: 'pub',
	},
	{ name: 'Cert', type: 'certificate', password: 'pem' },
	{ name: 'Custom', type: 'uuid-custom', password: 'x' },
	{ name: 'Mapped id', type: 'uuid-login', login: 'm', password: 'mp' },
	{ name: 'Totp row', type: 'totp', password: `otpauth://totp/A?secret=${KEY}` },
	{ name: 'Bad totp row', type: 'totp', password: 'not a key' },
	{
		name: 'Passkey row',
		type: 'passkey',
		password: JSON.stringify(stored('row-pk')),
	},
	{
		name: 'Passkey row with counter',
		type: 'passkey',
		password: JSON.stringify(
			stored('row-pk2', { counter: 3, transports: ['usb'] }),
		),
	},
	{ name: 'Bad passkey row', type: 'passkey', password: '{ nope' },
	{
		name: 'Login with own code and passkeys',
		type: 'login',
		password: 'p',
		totp: KEY,
		passkeys: JSON.stringify([stored('own1'), stored('own2', { counter: 1 })]),
		folder: 'Work',
	},
	{
		name: 'Login with bad own code',
		type: 'login',
		password: 'p',
		totp: 'bad',
		passkeys: '[]',
	},
	{
		name: 'Login with unreadable passkeys',
		type: 'login',
		password: 'p',
		passkeys: '{ nope',
	},
	{
		name: 'Login with an incomplete passkey',
		type: 'login',
		password: 'p',
		passkeys: JSON.stringify([{ credentialId: 'x' }]),
	},
	{ name: 'Login with an empty own code', type: 'login', password: 'p', totp: '' },
	{ name: 'No type', password: 'p' },
]

const EXPORTS = {
	'every row, map of ids': [
		ROWS,
		{
			typeNamesById: new Map([['uuid-login', 'login']]),
			accountUserName: 'alice',
		},
	],
	'every row, object of ids': [ROWS, { typeNamesById: { 'uuid-login': 'note' } }],
	'no options': [ROWS.slice(0, 4)],
	nothing: [[]],
}

const RECORD = process.env.RECORD_GOLDEN === '1'
const recorded = RECORD ? {} : JSON.parse(fs.readFileSync(GOLDEN, 'utf8'))

/**
 * The rows or the error of an import.
 *
 * @param {object|string} doc The document.
 * @return {object}
 */
function imported(doc) {
	try {
		return { rows: cxfToRows(parseCxfDocument(doc)) }
	} catch (error) {
		return { threw: `${error.name}: ${error.message}` }
	}
}

/**
 * Record or compare one case.
 *
 * @param {string} name The case name.
 * @param {object} actual The outcome.
 * @return {void}
 */
function check(name, actual) {
	const plain = JSON.parse(JSON.stringify(actual))
	if (RECORD) {
		recorded[name] = plain
		fs.writeFileSync(GOLDEN, JSON.stringify(recorded, null, '\t') + '\n')
	}
	expect(plain).toEqual(recorded[name])
}

describe('CXF (golden)', () => {
	beforeAll(() => {
		vi.useFakeTimers({ toFake: ['Date'] })
		vi.setSystemTime(new Date('2026-10-23T10:00:00Z'))
	})
	afterAll(() => {
		vi.useRealTimers()
	})

	it.each(Object.keys(DOCUMENTS))('import: %s', (name) => {
		check(`import: ${name}`, imported(DOCUMENTS[name]))
	})

	it.each(Object.keys(EXPORTS))('export: %s', (name) => {
		check(`export: ${name}`, buildCxfDocument(...EXPORTS[name]))
	})
})
