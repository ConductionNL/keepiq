/**
 * @vitest-environment jsdom
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the secret-import format parsers (`src/import/parsers/`).
 * Runs under jsdom so the KeePass XML parser's DOMParser is available; the CSV,
 * Bitwarden, and Passwords parsers are environment-agnostic.
 *
 * Locks down per format: header auto-detection + remap (CSV), login vs
 * non-login items + TOTP + collections (Bitwarden), nested groups + custom
 * strings + History-ignored (KeePass XML), folders + custom fields (Nextcloud
 * Passwords), KDBX magic-byte detection, and the normalized-row contract.
 *
 * @spec openspec/specs/secret-import/spec.md#requirement-supported-import-formats
 */

import { describe, expect, it } from 'vitest'
import { generateCsv } from '../../src/export/csv.js'
import { dedupeKey, isKdbx, normalizeUrl } from '../../src/import/model.js'
import {
	parseBitwardenCsv,
	parseBitwardenJson,
} from '../../src/import/parsers/bitwarden.js'
import { detectMapping, parseCsvImport } from '../../src/import/parsers/csv.js'
import { parseKeepassXml } from '../../src/import/parsers/keepassXml.js'
import { parseNcPasswords } from '../../src/import/parsers/ncPasswords.js'

describe('CSV parser', () => {
	it('auto-detects common headers case-insensitively', () => {
		const mapping = detectMapping([
			'Title',
			'Website',
			'Username',
			'Password',
			'Notes',
			'Group',
		])
		const targets = Object.fromEntries(mapping.map((m) => [m.column, m.target]))
		expect(targets.Title).toBe('name')
		expect(targets.Website).toBe('url')
		expect(targets.Username).toBe('login')
		expect(targets.Password).toBe('password')
		expect(targets.Group).toBe('folder')
		expect(targets.Notes).toBe('notes')
	})

	it('parses quoted/escaped fields and folder paths', () => {
		const csv =
			'name,url,username,password,folder\r\n'
			+ '"GitHub, Inc.",https://github.com,octocat,"hun""ter2",Work/CI\r\n'
		const { rows } = parseCsvImport(csv)
		expect(rows).toHaveLength(1)
		expect(rows[0].name).toBe('GitHub, Inc.')
		expect(rows[0].password).toBe('hun"ter2')
		expect(rows[0].folder).toBe('Work/CI')
		expect(rows[0].errors).toHaveLength(0)
	})

	it('rejects a row with a missing name but keeps the others', () => {
		const csv = 'name,password\r\nKept,k1\r\n,k2\r\n'
		const { rows } = parseCsvImport(csv)
		expect(rows[0].name).toBe('Kept')
		expect(rows[0].errors).toHaveLength(0)
		expect(rows[1].errors).toContain('Missing name')
	})

	it('applies an explicit remap (password column corrected)', () => {
		const csv = 'name,secretvalue\r\nThing,topsecret\r\n'
		const mapping = [
			{ column: 'name', target: 'name' },
			{ column: 'secretvalue', target: 'password' },
		]
		const { rows } = parseCsvImport(csv, { mapping })
		expect(rows[0].password).toBe('topsecret')
	})
})

describe('Bitwarden JSON parser', () => {
	const fixture = {
		folders: [{ id: 'f1', name: 'Work/CI' }],
		collections: [{ id: 'c1', name: 'Shared' }],
		items: [
			{
				type: 1,
				name: 'GitHub',
				folderId: 'f1',
				notes: 'a note',
				login: {
					username: 'octocat',
					password: 'hunter2',
					uri: 'https://github.com',
					totp: 'otpauth://totp/GitHub:octocat?secret=JBSWY3DPEHPK3PXP',
				},
			},
			{
				type: 1,
				name: 'SharedThing',
				collectionIds: ['c1'],
				login: { username: 'u', password: 'p' },
			},
			{ type: 2, name: 'A secure note', secureNote: { type: 0 } },
		],
	}

	it('maps login items incl. TOTP + folder, rejects non-login items', () => {
		const rows = parseBitwardenJson(fixture)
		expect(rows).toHaveLength(3)
		const gh = rows[0]
		expect(gh.name).toBe('GitHub')
		expect(gh.login).toBe('octocat')
		expect(gh.password).toBe('hunter2')
		expect(gh.url).toBe('https://github.com')
		expect(gh.folder).toBe('Work/CI')
		// The seed goes on the login's own field, not into additional fields.
		expect(gh.totp).toBe('otpauth://totp/GitHub:octocat?secret=JBSWY3DPEHPK3PXP')
		expect(gh.additionalFields).toEqual({ notes: 'a note' })
		expect(rows.some((row) => row.type === 'totp')).toBe(false)
		// A login without a seed or passkeys carries neither field.
		expect('totp' in rows[1]).toBe(false)
		expect('passkeys' in rows[1]).toBe(false)
		// Collection name resolves to folder.
		expect(rows[1].folder).toBe('Shared')
		// Non-login item rejected.
		expect(rows[2].errors.join(' ')).toMatch(/secure note/i)
	})

	it('parses Bitwarden CSV via the fixed header mapping', () => {
		const csv =
			'name,login_uri,login_username,login_password,notes,login_totp,folder,type\r\n'
			+ 'Item,https://x.test,user,pass,note1,jbsw y3dp ehpk 3pxp,Work,login\r\n'
			+ 'NoCode,https://y.test,user,pass,,,Work,login\r\n'
		const rows = parseBitwardenCsv(csv)
		expect(rows).toHaveLength(2)
		expect(rows[0].name).toBe('Item')
		expect(rows[0].login).toBe('user')
		expect(rows[0].password).toBe('pass')
		// Stored as the create dialog stores an entered key.
		expect(rows[0].totp).toBe('JBSWY3DPEHPK3PXP')
		expect(rows[0].additionalFields).toEqual({ notes: 'note1' })
		expect('totp' in rows[1]).toBe(false)
	})

	it('keeps a seed that is no authenticator key as an additional field', () => {
		const item = (name, totp) => ({
			type: 1,
			name,
			login: { username: 'u', password: 'p', totp },
		})
		const rows = parseBitwardenJson({
			items: [
				item('Steam', 'steam://ABCDEFGHIJ'),
				item(
					'Counter',
					'otpauth://hotp/x?secret=JBSWY3DPEHPK3PXP&counter=1',
				),
				item('Junk', 'not a seed!'),
			],
		})
		for (const row of rows) {
			expect(row.errors).toEqual([])
			expect('totp' in row).toBe(false)
		}
		expect(rows[0].additionalFields).toEqual({ totp: 'steam://ABCDEFGHIJ' })
		expect(rows[1].additionalFields.totp).toMatch(/^otpauth:\/\/hotp/)
		expect(rows[2].additionalFields).toEqual({ totp: 'not a seed!' })
	})

	it('gives a passkey-only login without a URI the passkey site as its URL', () => {
		const rows = parseBitwardenJson({
			items: [
				{
					type: 1,
					name: 'Passwordless',
					login: {
						fido2Credentials: [
							{
								credentialId: 'C',
								rpId: 'example.com',
								keyValue: 'K',
							},
						],
					},
				},
			],
		})
		expect(rows[0].url).toBe('example.com')
	})
})

describe('CSV export and import of a one-time code', () => {
	it('round-trips the totp column through the generic CSV import', () => {
		const csv = generateCsv([
			{
				name: 'Bank',
				url: 'https://bank.example',
				login: 'alice',
				password: 'pw',
				additionalFields: null,
				folder: '',
				type: 'login',
				totp: 'otpauth://totp/Bank:alice?secret=JBSWY3DPEHPK3PXP&period=60',
				passkeys: '[{"credentialId":"C"}]',
			},
		])
		expect(csv).not.toContain('credentialId')
		const { rows, mapping } = parseCsvImport(csv)
		expect(mapping.find((entry) => entry.column === 'totp').target).toBe('totp')
		expect(rows[0].totp).toBe(
			'otpauth://totp/Bank:alice?secret=JBSWY3DPEHPK3PXP&period=60',
		)
		expect(rows[0].password).toBe('pw')
		expect('passkeys' in rows[0]).toBe(false)
	})
})

describe('KeePass XML parser', () => {
	const xml = `<?xml version="1.0"?>
<KeePassFile><Root>
  <Group><Name>Database</Name>
    <Entry>
      <String><Key>Title</Key><Value>RootEntry</Value></String>
      <String><Key>Password</Key><Value>rootpass</Value></String>
      <History>
        <Entry><String><Key>Password</Key><Value>OLD-SHOULD-IGNORE</Value></String></Entry>
      </History>
    </Entry>
    <Group><Name>Work</Name>
      <Group><Name>CI</Name>
        <Entry>
          <String><Key>Title</Key><Value>Jenkins</Value></String>
          <String><Key>UserName</Key><Value>ci</Value></String>
          <String><Key>Password</Key><Value>cipass</Value></String>
          <String><Key>URL</Key><Value>https://ci.test</Value></String>
          <String><Key>CustomToken</Key><Value>tok123</Value></String>
        </Entry>
      </Group>
    </Group>
  </Group>
</Root></KeePassFile>`

	it('parses entries with group hierarchy as folder paths and ignores History', () => {
		const rows = parseKeepassXml(xml)
		expect(rows).toHaveLength(2)
		const root = rows.find((r) => r.name === 'RootEntry')
		expect(root.folder).toBe('')
		expect(root.password).toBe('rootpass')
		// The History value must NOT leak.
		expect(JSON.stringify(rows)).not.toContain('OLD-SHOULD-IGNORE')

		const jenkins = rows.find((r) => r.name === 'Jenkins')
		expect(jenkins.folder).toBe('Work/CI')
		expect(jenkins.login).toBe('ci')
		expect(jenkins.url).toBe('https://ci.test')
		expect(jenkins.additionalFields.CustomToken).toBe('tok123')
	})

	it("puts a KeePass one-time-code seed on the login's totp field (keepiq#1327)", () => {
		const rows = parseKeepassXml(`<?xml version="1.0"?>
<KeePassFile><Root><Group><Name>Database</Name>
  <Entry>
    <String><Key>Title</Key><Value>XC</Value></String>
    <String><Key>Password</Key><Value>p</Value></String>
    <String><Key>otp</Key><Value>otpauth://totp/x?secret=JBSWY3DPEHPK3PXP</Value></String>
  </Entry>
  <Entry>
    <String><Key>Title</Key><Value>KP</Value></String>
    <String><Key>Password</Key><Value>p</Value></String>
    <String><Key>TimeOtp-Secret-Base32</Key><Value>JBSWY3DPEHPK3PXP</Value></String>
  </Entry>
  <Entry>
    <String><Key>Title</Key><Value>Plain</Value></String>
    <String><Key>Password</Key><Value>p</Value></String>
  </Entry>
</Group></Root></KeePassFile>`)
		expect(rows).toHaveLength(3)
		const xc = rows.find((r) => r.name === 'XC')
		const kp = rows.find((r) => r.name === 'KP')
		const plain = rows.find((r) => r.name === 'Plain')
		expect(xc.type).toBe('login')
		expect(xc.totp).toBe('otpauth://totp/x?secret=JBSWY3DPEHPK3PXP')
		expect(xc.additionalFields).toBeNull()
		expect(kp.totp).toBe('JBSWY3DPEHPK3PXP')
		expect(kp.additionalFields).toBeNull()
		expect('totp' in plain).toBe(false)
	})

	it('keeps the first KeePass seed and a different second one as an additional field', () => {
		const rows = parseKeepassXml(`<?xml version="1.0"?>
<KeePassFile><Root><Group><Name>Database</Name>
  <Entry>
    <String><Key>Title</Key><Value>Both</Value></String>
    <String><Key>otp</Key><Value>otpauth://totp/x?secret=JBSWY3DPEHPK3PXP</Value></String>
    <String><Key>TimeOtp-Secret-Base32</Key><Value>GEZDGNBVGY3TQOJQ</Value></String>
  </Entry>
  <Entry>
    <String><Key>Title</Key><Value>Same</Value></String>
    <String><Key>otp</Key><Value>JBSWY3DPEHPK3PXP</Value></String>
    <String><Key>TimeOtp-Secret-Base32</Key><Value>JBSWY3DPEHPK3PXP</Value></String>
  </Entry>
</Group></Root></KeePassFile>`)
		const both = rows.find((r) => r.name === 'Both')
		expect(both.totp).toBe('otpauth://totp/x?secret=JBSWY3DPEHPK3PXP')
		expect(both.additionalFields).toEqual({
			'TimeOtp-Secret-Base32': 'GEZDGNBVGY3TQOJQ',
		})
		const same = rows.find((r) => r.name === 'Same')
		expect(same.totp).toBe('JBSWY3DPEHPK3PXP')
		expect(same.additionalFields).toBeNull()
	})

	it('folds KeePass TimeOtp parameters into the seed and consumes them', () => {
		const rows = parseKeepassXml(`<?xml version="1.0"?>
<KeePassFile><Root><Group><Name>Database</Name>
  <Entry>
    <String><Key>Title</Key><Value>Custom</Value></String>
    <String><Key>TimeOtp-Secret-Base32</Key><Value>JBSWY3DPEHPK3PXP</Value></String>
    <String><Key>TimeOtp-Period</Key><Value>60</Value></String>
    <String><Key>TimeOtp-Length</Key><Value>8</Value></String>
    <String><Key>TimeOtp-Algorithm</Key><Value>HMAC-SHA-256</Value></String>
  </Entry>
  <Entry>
    <String><Key>Title</Key><Value>Defaults</Value></String>
    <String><Key>TimeOtp-Secret-Base32</Key><Value>JBSWY3DPEHPK3PXP</Value></String>
    <String><Key>TimeOtp-Period</Key><Value>30</Value></String>
    <String><Key>TimeOtp-Algorithm</Key><Value>HMAC-SHA-1</Value></String>
  </Entry>
  <Entry>
    <String><Key>Title</Key><Value>Unusable</Value></String>
    <String><Key>TimeOtp-Secret-Base32</Key><Value>JBSWY3DPEHPK3PXP</Value></String>
    <String><Key>TimeOtp-Length</Key><Value>7</Value></String>
  </Entry>
</Group></Root></KeePassFile>`)
		const custom = rows.find((r) => r.name === 'Custom')
		const params = new URL(custom.totp).searchParams
		expect(custom.totp).toMatch(/^otpauth:\/\/totp\//)
		expect(params.get('secret')).toBe('JBSWY3DPEHPK3PXP')
		expect(params.get('period')).toBe('60')
		expect(params.get('digits')).toBe('8')
		expect(params.get('algorithm')).toBe('SHA256')
		expect(custom.additionalFields).toBeNull()

		const defaults = rows.find((r) => r.name === 'Defaults')
		expect(defaults.totp).toBe('JBSWY3DPEHPK3PXP')
		expect(defaults.additionalFields).toBeNull()

		// A 7-digit code cannot be generated: the strings stay as they were.
		const unusable = rows.find((r) => r.name === 'Unusable')
		expect('totp' in unusable).toBe(false)
		expect(unusable.additionalFields).toEqual({
			'TimeOtp-Secret-Base32': 'JBSWY3DPEHPK3PXP',
			'TimeOtp-Length': '7',
		})
	})

	it('reads a protected (ProtectInMemory) otp value', () => {
		const rows = parseKeepassXml(`<?xml version="1.0"?>
<KeePassFile><Root><Group><Name>Database</Name>
  <Entry>
    <String><Key>Title</Key><Value>XC</Value></String>
    <String><Key>otp</Key><Value ProtectInMemory="True">otpauth://totp/XC?secret=JBSWY3DPEHPK3PXP</Value></String>
  </Entry>
</Group></Root></KeePassFile>`)
		expect(rows[0].totp).toBe('otpauth://totp/XC?secret=JBSWY3DPEHPK3PXP')
		expect(rows[0].additionalFields).toBeNull()
	})

	it('throws when the KeePass root element is missing', () => {
		expect(() => parseKeepassXml('<NotKeePass/>')).toThrow(/KeePassFile/)
	})
})

describe('Nextcloud Passwords parser', () => {
	const backup = {
		folders: [
			{
				id: 'fa',
				label: 'Work',
				parent: '00000000-0000-0000-0000-000000000000',
			},
			{ id: 'fb', label: 'CI', parent: 'fa' },
		],
		passwords: [
			{
				label: 'Jenkins',
				url: 'https://ci.test',
				username: 'ci',
				password: 'cipass',
				notes: 'ci notes',
				folder: 'fb',
				customFields: JSON.stringify([
					{ label: 'PIN', type: 'text', value: '1234' },
				]),
			},
		],
	}

	it('maps fields + resolves folder hierarchy + custom fields', () => {
		const rows = parseNcPasswords(backup)
		expect(rows).toHaveLength(1)
		expect(rows[0].name).toBe('Jenkins')
		expect(rows[0].login).toBe('ci')
		expect(rows[0].password).toBe('cipass')
		expect(rows[0].folder).toBe('Work/CI')
		expect(rows[0].additionalFields.notes).toBe('ci notes')
		expect(rows[0].additionalFields.PIN).toBe('1234')
	})

	it('throws on a non-Passwords file', () => {
		expect(() => parseNcPasswords({ foo: 1 })).toThrow(/Passwords/)
	})
})

describe('KDBX detection + dedupe helpers', () => {
	it('detects the KDBX magic bytes', () => {
		expect(isKdbx(new Uint8Array([0x9a, 0xa2, 0xd9, 0x03, 0x00]))).toBe(true)
		expect(isKdbx(new Uint8Array([0x7b, 0x22, 0x61]))).toBe(false)
	})

	it('normalizes urls scheme/trailing-slash-insensitively', () => {
		expect(normalizeUrl('https://github.com/')).toBe('github.com')
		expect(normalizeUrl('HTTP://GitHub.com')).toBe('github.com')
		expect(normalizeUrl('')).toBe('')
	})

	it('matches duplicates on normalized name + url, both-empty too', () => {
		expect(dedupeKey({ name: 'GitHub', url: 'https://github.com/' })).toBe(
			dedupeKey({ name: ' github ', url: 'http://github.com' }),
		)
		expect(dedupeKey({ name: 'X', url: '' })).toBe(
			dedupeKey({ name: 'x', url: null }),
		)
	})
})
