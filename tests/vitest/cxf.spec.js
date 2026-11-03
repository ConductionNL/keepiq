/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the FIDO CXF mapping module + parser registration
 * (cxf-import-export §6).
 *
 * Locks down:
 *  - Strict document validation: malformed input fails at parse with a
 *    format-specific error and creates nothing.
 *  - Every supported CXF entity maps to its Keepiq type; a TOTP or
 *    passkey goes on the login of its item (keepiq#1327) in the canonical
 *    passkey-item-type schema; unrepresentable entries are rejected with
 *    a reason — never silently dropped.
 *  - Export builds a CXF document from serialized rows, with a login's
 *    code and passkeys in its own item; values with no CXF home land in
 *    the unmapped report.
 *  - Export → import round-trips core credentials, folders, and a login's
 *    code and passkeys.
 *
 * @spec openspec/specs/cxf-import-export/spec.md#requirement-cxf-entity-to-keepiq-type-mapping
 * @spec openspec/specs/cxf-import-export/spec.md#requirement-unmapped-item-report
 * @spec openspec/changes/vault-login-totp-codes/specs/login-one-time-codes/spec.md#requirement-import-and-export-keep-a-logins-code-and-passkeys-on-it
 */

import { describe, expect, it } from 'vitest'
import { buildCxfDocument, cxfToRows, parseCxfDocument } from '../../src/cxf/cxf.js'
import { getParser } from '../../src/import/parserRegistry.js'
import { passkeyEntries } from '../../src/passkey/loginPasskeys.js'
import {
	buildPasskeyCredential,
	serializePasskey,
} from '../../src/passkey/passkey.js'
import { parseOtpauth } from '../../src/totp/totp.js'

import '../../src/import/parsers/index.js'

const SAMPLE_DOC = {
	version: { major: 1, minor: 0 },
	exporter: 'other-vault',
	timestamp: 1750000000,
	accounts: [
		{
			id: 'acc-1',
			userName: 'alice',
			items: [
				{
					id: 'it-1',
					title: 'Example login',
					credentials: [
						{
							type: 'basic-auth',
							urls: ['https://example.com'],
							username: { fieldType: 'string', value: 'alice' },
							password: {
								fieldType: 'concealed-string',
								value: 'pw-placeholder',
							},
						},
					],
				},
				{
					id: 'it-2',
					title: 'Example passkey',
					credentials: [
						{
							type: 'passkey',
							credentialId: 'CXF_CRED_ID_PLACEHOLDER',
							rpId: 'example.com',
							rpName: 'Example',
							userName: 'alice',
							userDisplayName: 'Alice',
							userHandle: 'CXF_HANDLE_PLACEHOLDER',
							key: 'CXF_KEY_PLACEHOLDER',
						},
					],
				},
				{
					id: 'it-3',
					title: 'Example TOTP',
					credentials: [
						{
							type: 'totp',
							url: 'otpauth://totp/x?secret=PLACEHOLDERSEED',
						},
					],
				},
				{
					id: 'it-4',
					title: 'A note',
					credentials: [{ type: 'note', content: 'note body' }],
				},
				{
					id: 'it-5',
					title: 'Deploy key',
					credentials: [
						{
							type: 'ssh-key',
							privateKey: { value: 'SSH_PRIVATE_PLACEHOLDER' },
							publicKey: { value: 'ssh-ed25519 AAAA_PLACEHOLDER' },
						},
					],
				},
				{
					id: 'it-6',
					title: 'Office Wi-Fi',
					credentials: [
						{
							type: 'wifi',
							ssid: 'OfficeNet',
							passphrase: 'wifi-pw-placeholder',
							security: 'WPA2',
						},
					],
				},
				{
					id: 'it-7',
					title: 'Mystery',
					credentials: [{ type: 'quantum-vault-token' }],
				},
				{
					id: 'it-8',
					title: 'Broken passkey',
					credentials: [{ type: 'passkey', rpId: 'partial.example.com' }],
				},
			],
			collections: [{ id: 'col-1', title: 'Work', items: ['it-1', 'it-5'] }],
		},
	],
}

describe('CXF document validation', () => {
	it('rejects non-CXF input with a format-specific error', () => {
		expect(() => parseCxfDocument('not json')).toThrow(/Not a CXF document/)
		expect(() => parseCxfDocument('{}')).toThrow(/missing accounts/)
		expect(() => parseCxfDocument({ accounts: [{}] })).toThrow(/items array/)
	})

	it('is registered as an import parser under id cxf', () => {
		const parser = getParser('cxf')
		expect(parser).toBeDefined()
		expect(parser.label).toMatch(/CXF/)
	})
})

describe('CXF import mapping', () => {
	const rows = cxfToRows(parseCxfDocument(SAMPLE_DOC))
	const byName = Object.fromEntries(rows.map((row) => [row.name, row]))

	it('maps each supported entity to its Keepiq type', () => {
		expect(byName['Example login'].type).toBe('login')
		expect(byName['Example login'].login).toBe('alice')
		expect(byName['Example login'].password).toBe('pw-placeholder')
		expect('totp' in byName['Example login']).toBe(false)
		expect('passkeys' in byName['Example login']).toBe(false)
		expect(
			rows.some((row) => row.type === 'passkey' || row.type === 'totp'),
		).toBe(false)
		expect(byName['A note'].type).toBe('note')
		expect(byName['Deploy key'].type).toBe('ssh_key')
		expect(byName['Office Wi-Fi'].type).toBe('note')
		expect(byName['Office Wi-Fi'].additionalFields).toEqual({
			ssid: 'OfficeNet',
			security: 'WPA2',
		})
	})

	it('turns an item with only a passkey into a login carrying it', () => {
		const row = byName['Example passkey']
		expect(row.type).toBe('login')
		expect(row.errors).toEqual([])
		expect(row.password).toBe('')
		expect(row.url).toBe('example.com')
		const entries = passkeyEntries(row.passkeys)
		expect(entries).toHaveLength(1)
		const credential = buildPasskeyCredential(entries[0].credential)
		expect(credential.rpId).toBe('example.com')
		expect(credential.privateKey).toBe('CXF_KEY_PLACEHOLDER')
		expect('totp' in row).toBe(false)
	})

	it('turns an item with only a TOTP into a login carrying it', () => {
		const row = byName['Example TOTP']
		expect(row.type).toBe('login')
		expect(row.errors).toEqual([])
		expect(row.password).toBe('')
		expect(row.totp).toBe('otpauth://totp/x?secret=PLACEHOLDERSEED')
		expect('passkeys' in row).toBe(false)
	})

	it('maps collections to folder paths', () => {
		expect(byName['Example login'].folder).toBe('Work')
		expect(byName['Deploy key'].folder).toBe('Work')
		expect(byName['A note'].folder).toBe('')
	})

	it('rejects unrepresentable and partial entries with reasons — never silently drops', () => {
		expect(byName.Mystery.errors[0]).toMatch(/Unsupported CXF credential type/)
		expect(byName['Broken passkey'].errors[0]).toMatch(/Incomplete CXF passkey/)
	})
})

describe('CXF import: a code and passkeys in the same item as a login', () => {
	const passkey = (id) => ({
		type: 'passkey',
		credentialId: id,
		rpId: 'example.com',
		userName: 'alice',
		key: `KEY_${id}`,
	})
	const doc = (credentials) => ({
		accounts: [
			{
				items: [{ id: 'it-1', title: 'Bank', credentials }],
			},
		],
	})
	const basicAuth = {
		type: 'basic-auth',
		urls: ['https://bank.example'],
		username: { value: 'alice' },
		password: { value: 'pw' },
	}

	it('attaches the TOTP and passkeys to the login', () => {
		const rows = cxfToRows(
			doc([
				passkey('P1'),
				basicAuth,
				{ type: 'totp', secret: 'JBSWY3DPEHPK3PXP' },
				passkey('P2'),
			]),
		)
		expect(rows).toHaveLength(1)
		const [login] = rows
		expect(login.errors).toEqual([])
		expect(login.name).toBe('Bank')
		expect(login.url).toBe('https://bank.example')
		expect(login.password).toBe('pw')
		expect(login.totp).toBe('JBSWY3DPEHPK3PXP')
		expect(
			passkeyEntries(login.passkeys).map(
				({ credential }) => credential.credentialId,
			),
		).toEqual(['P1', 'P2'])
	})

	it('keeps the first TOTP, folds an identical one and rejects a different one', () => {
		const rows = cxfToRows(
			doc([
				basicAuth,
				{ type: 'totp', url: 'otpauth://totp/a?secret=JBSWY3DPEHPK3PXP' },
				{ type: 'totp', url: 'otpauth://totp/a?secret=JBSWY3DPEHPK3PXP' },
				{ type: 'totp', secret: 'GEZDGNBVGY3TQOJQ' },
			]),
		)
		const accepted = rows.filter((row) => row.errors.length === 0)
		const rejected = rows.filter((row) => row.errors.length > 0)
		expect(accepted).toHaveLength(1)
		expect(accepted[0].totp).toBe('otpauth://totp/a?secret=JBSWY3DPEHPK3PXP')
		expect(rejected).toHaveLength(1)
		expect(rejected[0].name).toBe('Bank')
		expect(rejected[0].sourceRow).toBe(4)
		expect(rejected[0].errors[0]).toMatch(/second, different one-time code/)
	})

	it('keeps one copy of a passkey listed twice', () => {
		const rows = cxfToRows(doc([basicAuth, passkey('P1'), passkey('P1')]))
		expect(rows).toHaveLength(1)
		expect(passkeyEntries(rows[0].passkeys)).toHaveLength(1)
	})

	it('rejects a second passkey with the same id but another key', () => {
		const rows = cxfToRows(
			doc([basicAuth, passkey('P1'), { ...passkey('P1'), key: 'OTHER_KEY' }]),
		)
		const rejected = rows.filter((row) => row.errors.length > 0)
		expect(rejected).toHaveLength(1)
		expect(rejected[0].sourceRow).toBe(3)
		expect(rejected[0].errors[0]).toMatch(/same credential id/)
		const [{ credential }] = passkeyEntries(rows[0].passkeys)
		expect(credential.privateKey).toBe('KEY_P1')
	})

	it('reads the CXF 1.0 TOTP fields and the legacy userName spelling', () => {
		const rows = cxfToRows(
			doc([
				basicAuth,
				{
					type: 'totp',
					secret: 'JBSWY3DPEHPK3PXP',
					period: 60,
					digits: 8,
					algorithm: 'sha256',
					username: 'alice',
					issuer: 'Bank',
				},
				{ ...passkey('P1'), userName: undefined, username: 'spec-name' },
				passkey('P2'),
			]),
		)
		expect(rows).toHaveLength(1)
		const parsed = parseOtpauth(rows[0].totp)
		expect(parsed).toMatchObject({
			secret: 'JBSWY3DPEHPK3PXP',
			period: 60,
			digits: 8,
			algorithm: 'SHA256',
			issuer: 'Bank',
			account: 'alice',
		})
		expect(
			passkeyEntries(rows[0].passkeys).map(
				({ credential }) => credential.userName,
			),
		).toEqual(['spec-name', 'alice'])
	})

	it('stores a bare secret for an unlabelled CXF TOTP with the default parameters', () => {
		const rows = cxfToRows(
			doc([
				basicAuth,
				{
					type: 'totp',
					secret: 'jbsw y3dp ehpk 3pxp',
					period: 30,
					digits: 6,
					algorithm: 'sha1',
				},
			]),
		)
		expect(rows[0].totp).toBe('JBSWY3DPEHPK3PXP')
	})

	it('keeps the issuer and account of a labelled CXF TOTP with the default parameters', () => {
		const rows = cxfToRows(
			doc([
				basicAuth,
				{
					type: 'totp',
					secret: 'JBSWY3DPEHPK3PXP',
					period: 30,
					digits: 6,
					algorithm: 'sha1',
					issuer: 'Bank',
					username: 'alice',
				},
			]),
		)
		expect(parseOtpauth(rows[0].totp)).toEqual({
			secret: 'JBSWY3DPEHPK3PXP',
			algorithm: 'SHA1',
			digits: 6,
			period: 30,
			issuer: 'Bank',
			account: 'alice',
		})
	})

	it('rejects a CXF TOTP with an unknown algorithm or digit count, not a wrong code', () => {
		const rows = cxfToRows(
			doc([
				basicAuth,
				{ type: 'totp', secret: 'JBSWY3DPEHPK3PXP', algorithm: 'md5' },
				{ type: 'totp', secret: 'JBSWY3DPEHPK3PXP', digits: 7 },
			]),
		)
		const [login, ...rejected] = rows
		expect(login.errors).toEqual([])
		expect('totp' in login).toBe(false)
		expect(rejected.map((row) => row.errors[0])).toEqual([
			expect.stringMatching(/Unsupported TOTP algorithm/),
			expect.stringMatching(/Unsupported TOTP parameters/),
		])
	})

	it('rejects a CXF TOTP whose seed is no authenticator key', () => {
		const rows = cxfToRows(
			doc([basicAuth, { type: 'totp', url: 'steam://ABCDEFGHIJ' }]),
		)
		expect(rows).toHaveLength(2)
		expect('totp' in rows[0]).toBe(false)
		expect(rows[1].errors).toHaveLength(1)
	})

	it('puts a code and passkeys of an item without a login on its own row', () => {
		const rows = cxfToRows(
			doc([
				{ type: 'note', content: 'recovery steps' },
				{ type: 'totp', secret: 'JBSWY3DPEHPK3PXP' },
				passkey('P1'),
			]),
		)
		expect(rows).toHaveLength(1)
		expect(rows[0].type).toBe('note')
		expect(rows[0].password).toBe('recovery steps')
		expect(rows[0].totp).toBe('JBSWY3DPEHPK3PXP')
		expect(passkeyEntries(rows[0].passkeys)).toHaveLength(1)
	})

	it('never puts a code on a login rejected for its own reason', () => {
		const rows = cxfToRows({
			accounts: [
				{
					items: [
						{
							title: '',
							credentials: [
								basicAuth,
								{ type: 'totp', secret: 'JBSWY3DPEHPK3PXP' },
							],
						},
					],
				},
			],
		})
		expect(rows).toHaveLength(2)
		expect(rows[0].errors).toEqual(['Missing name'])
		expect('totp' in rows[0]).toBe(false)
		expect(rows[1].errors).toEqual([])
		expect(rows[1].password).toBe('')
		expect(rows[1].totp).toBe('JBSWY3DPEHPK3PXP')
	})

	describe('picks the target from accepted rows, whatever the order', () => {
		const totp = { type: 'totp', secret: 'JBSWY3DPEHPK3PXP' }
		const brokenPasskey = { type: 'passkey', rpId: 'partial.example.com' }
		const unsupported = { type: 'custom-fields', fields: [] }
		const md5Totp = {
			type: 'totp',
			secret: 'JBSWY3DPEHPK3PXP',
			algorithm: 'md5',
		}

		/**
		 * Split an item's rows and check no rejected row holds a value field.
		 *
		 * @param {Array<object>} credentials The item's credentials.
		 * @return {{accepted: Array<object>, rejected: Array<object>}}
		 */
		function split(credentials) {
			const rows = cxfToRows(doc(credentials))
			const accepted = rows.filter((row) => row.errors.length === 0)
			const rejected = rows.filter((row) => row.errors.length > 0)
			for (const row of rejected) {
				expect('totp' in row).toBe(false)
				expect('passkeys' in row).toBe(false)
			}
			return { accepted, rejected }
		}

		it('an incomplete passkey before the login', () => {
			const { accepted, rejected } = split([brokenPasskey, basicAuth, totp])
			expect(rejected).toHaveLength(1)
			expect(accepted).toHaveLength(1)
			expect(accepted[0].password).toBe('pw')
			expect(accepted[0].totp).toBe('JBSWY3DPEHPK3PXP')
		})

		it('an unsupported credential before the login', () => {
			const { accepted, rejected } = split([
				unsupported,
				basicAuth,
				totp,
				passkey('P1'),
			])
			expect(rejected[0].errors[0]).toMatch(/Unsupported CXF credential type/)
			expect(accepted).toHaveLength(1)
			expect(accepted[0].password).toBe('pw')
			expect(accepted[0].totp).toBe('JBSWY3DPEHPK3PXP')
			expect(passkeyEntries(accepted[0].passkeys)).toHaveLength(1)
		})

		it('an unusable TOTP before a passkey, with no login', () => {
			const { accepted, rejected } = split([md5Totp, passkey('P1')])
			expect(rejected[0].errors[0]).toMatch(/Unsupported TOTP algorithm/)
			expect(accepted).toHaveLength(1)
			expect(accepted[0].sourceRow).toBe(2)
			expect(accepted[0].password).toBe('')
			expect(passkeyEntries(accepted[0].passkeys)).toHaveLength(1)
		})

		it('the login last', () => {
			const { accepted, rejected } = split([totp, passkey('P1'), basicAuth])
			expect(rejected).toEqual([])
			expect(accepted).toHaveLength(1)
			expect(accepted[0].sourceRow).toBe(3)
			expect(accepted[0].password).toBe('pw')
			expect(accepted[0].totp).toBe('JBSWY3DPEHPK3PXP')
			expect(passkeyEntries(accepted[0].passkeys)).toHaveLength(1)
		})
	})

	it('attaches to the first login when an item holds two', () => {
		const rows = cxfToRows(
			doc([
				basicAuth,
				{ ...basicAuth, password: { value: 'pw2' } },
				{ type: 'totp', secret: 'JBSWY3DPEHPK3PXP' },
			]),
		)
		expect(rows).toHaveLength(2)
		expect(rows[0].totp).toBe('JBSWY3DPEHPK3PXP')
		expect('totp' in rows[1]).toBe(false)
	})

	it('gives an item with a TOTP and a passkey but no login one login with both', () => {
		const rows = cxfToRows(
			doc([{ type: 'totp', secret: 'JBSWY3DPEHPK3PXP' }, passkey('P1')]),
		)
		expect(rows).toHaveLength(1)
		expect(rows[0].type).toBe('login')
		expect(rows[0].sourceRow).toBe(1)
		expect(rows[0].password).toBe('')
		expect(rows[0].url).toBe('example.com')
		expect(rows[0].totp).toBe('JBSWY3DPEHPK3PXP')
		expect(passkeyEntries(rows[0].passkeys)).toHaveLength(1)
	})

	it('numbers rows across items by credential', () => {
		const rows = cxfToRows({
			accounts: [
				{
					items: [
						{
							title: 'A',
							credentials: [
								basicAuth,
								{ type: 'totp', secret: 'JBSWY3DPEHPK3PXP' },
							],
						},
						{ title: 'B', credentials: [basicAuth] },
					],
				},
			],
		})
		expect(rows.map((row) => [row.name, row.sourceRow])).toEqual([
			['A', 1],
			['B', 3],
		])
	})
})

describe('CXF export mapping', () => {
	const serialized = [
		{
			name: 'Example login',
			url: 'https://example.com',
			type: 'login',
			login: 'alice',
			password: 'pw-placeholder',
			additionalFields: null,
			folder: 'Work',
		},
		{
			name: 'My passkey',
			url: 'example.com',
			type: 'passkey',
			login: null,
			password: serializePasskey({
				credentialId: 'EXPORT_CRED_ID',
				rpId: 'example.com',
				privateKey: 'EXPORT_KEY',
				counter: 7,
				transports: ['internal'],
			}),
			additionalFields: null,
			folder: '',
		},
		{
			name: 'Root CA',
			url: null,
			type: 'certificate',
			login: null,
			password: 'PEM_PLACEHOLDER',
			additionalFields: null,
			folder: '',
		},
	]

	it('builds a document, reports unmapped values, and preserves folders as collections', () => {
		const { document, unmapped, itemCount } = buildCxfDocument(serialized)
		expect(itemCount).toBe(2)
		expect(document.accounts[0].items).toHaveLength(2)
		expect(document.accounts[0].collections[0].title).toBe('Work')
		// Certificate has no CXF entity; passkey extensions are lossy — both reported.
		expect(unmapped.some((entry) => entry.includes('Root CA'))).toBe(true)
		expect(unmapped.some((entry) => entry.includes('counter/transports'))).toBe(
			true,
		)
	})

	it('round-trips core credentials through export → import', () => {
		const { document } = buildCxfDocument(serialized)
		const rows = cxfToRows(
			parseCxfDocument(JSON.parse(JSON.stringify(document))),
		)
		const login = rows.find((row) => row.name === 'Example login')
		expect(login.password).toBe('pw-placeholder')
		expect(login.login).toBe('alice')
		expect(login.folder).toBe('Work')
		// A standalone passkey item comes back as a login carrying it.
		const passkeyRow = rows.find((row) => row.name === 'My passkey')
		expect(passkeyRow.type).toBe('login')
		const [{ credential: stored }] = passkeyEntries(passkeyRow.passkeys)
		const credential = buildPasskeyCredential(stored)
		expect(credential.credentialId).toBe('EXPORT_CRED_ID')
		expect(credential.privateKey).toBe('EXPORT_KEY')
		// Extensions default honestly on import (documented lossiness).
		expect(credential.counter).toBe(0)
	})

	it('exports a standalone TOTP item as an item of its own, in the CXF 1.0 shape', () => {
		const { document, itemCount } = buildCxfDocument([
			{
				name: 'Old code',
				url: null,
				type: 'totp',
				login: null,
				password: 'JBSWY3DPEHPK3PXP',
				additionalFields: null,
				folder: '',
			},
		])
		expect(itemCount).toBe(1)
		expect(document.accounts[0].items[0].credentials).toEqual([
			{
				type: 'totp',
				secret: 'JBSWY3DPEHPK3PXP',
				period: 30,
				digits: 6,
				algorithm: 'sha1',
			},
		])
	})
})

describe('CXF export of a login with a code and passkeys', () => {
	const stored = (id, extra = {}) =>
		buildPasskeyCredential({
			credentialId: id,
			rpId: 'bank.example',
			userName: 'alice',
			privateKey: `KEY_${id}`,
			...extra,
		})
	const login = {
		name: 'Bank',
		url: 'https://bank.example',
		type: 'login',
		login: 'alice',
		password: 'pw',
		additionalFields: null,
		folder: 'Money',
		totp: 'otpauth://totp/Bank:alice?secret=JBSWY3DPEHPK3PXP&period=60&algorithm=SHA512',
		passkeys: JSON.stringify([stored('P1'), stored('P2')]),
	}

	it("writes the code and passkeys as credentials of the login's item", () => {
		const { document, itemCount, unmapped } = buildCxfDocument([login])
		expect(itemCount).toBe(1)
		expect(unmapped).toEqual([])
		const [item] = document.accounts[0].items
		expect(item.credentials.map((credential) => credential.type)).toEqual([
			'basic-auth',
			'totp',
			'passkey',
			'passkey',
		])
		expect(item.credentials[1]).toEqual({
			type: 'totp',
			secret: 'JBSWY3DPEHPK3PXP',
			period: 60,
			digits: 6,
			algorithm: 'sha512',
			username: 'alice',
			issuer: 'Bank',
		})
		expect(item.credentials[2].credentialId).toBe('P1')
		expect(item.credentials[2].username).toBe('alice')
		expect(item.credentials[2].key).toBe('KEY_P1')
	})

	it('reports a stored code or passkeys it cannot read', () => {
		const { document, unmapped } = buildCxfDocument([
			{ ...login, totp: 'steam://ABCDEFGHIJ', passkeys: 'not json' },
		])
		expect(document.accounts[0].items[0].credentials).toHaveLength(1)
		expect(unmapped).toEqual([
			'Bank: stored one-time code is not an authenticator key — not exported',
			'Bank: stored passkeys could not be read — not exported',
		])
	})

	it('exports a note carrying a code and passkeys as one item that comes back as one row', () => {
		const note = {
			...login,
			name: 'Recovery',
			type: 'note',
			login: null,
			password: 'steps',
		}
		const { document, itemCount } = buildCxfDocument([note])
		expect(itemCount).toBe(1)
		const rows = cxfToRows(parseCxfDocument(JSON.stringify(document)))
		expect(rows).toHaveLength(1)
		expect(rows[0].type).toBe('note')
		expect(parseOtpauth(rows[0].totp)).toEqual(parseOtpauth(login.totp))
		expect(passkeyEntries(rows[0].passkeys)).toHaveLength(2)
	})

	it('reports the passkey counter/transports loss once per secret', () => {
		const { unmapped } = buildCxfDocument([
			{
				...login,
				passkeys: JSON.stringify([
					stored('P1', { counter: 3 }),
					stored('P2', { transports: ['usb'] }),
				]),
			},
		])
		expect(
			unmapped.filter((entry) => entry.includes('counter/transports')),
		).toHaveLength(1)
	})

	it('writes a login with neither as a single credential', () => {
		const { totp, passkeys, ...plain } = login
		const { document } = buildCxfDocument([plain])
		expect(document.accounts[0].items[0].credentials).toHaveLength(1)
	})

	it('reports a stored passkey it cannot export instead of dropping it in silence', () => {
		const { document, unmapped } = buildCxfDocument([
			{
				...login,
				totp: '',
				passkeys: JSON.stringify([
					stored('P1', { counter: 3 }),
					{ rpId: 'x' },
				]),
			},
		])
		expect(document.accounts[0].items[0].credentials).toHaveLength(2)
		expect(
			unmapped.some((entry) => entry.includes('not a complete credential')),
		).toBe(true)
		expect(unmapped.some((entry) => entry.includes('counter/transports'))).toBe(
			true,
		)
	})

	it('comes back as the same login after an export and import', () => {
		const { document } = buildCxfDocument([login])
		const rows = cxfToRows(parseCxfDocument(JSON.stringify(document)))
		expect(rows).toHaveLength(1)
		const [row] = rows
		expect(row.errors).toEqual([])
		expect(row.name).toBe('Bank')
		expect(row.type).toBe('login')
		expect(row.url).toBe('https://bank.example')
		expect(row.login).toBe('alice')
		expect(row.password).toBe('pw')
		expect(row.folder).toBe('Money')
		// The same code: the same secret, period, digits and algorithm.
		expect(parseOtpauth(row.totp)).toEqual(parseOtpauth(login.totp))
		expect(
			passkeyEntries(row.passkeys).map(({ credential }) => [
				credential.credentialId,
				credential.privateKey,
			]),
		).toEqual([
			['P1', 'KEY_P1'],
			['P2', 'KEY_P2'],
		])
	})
})
