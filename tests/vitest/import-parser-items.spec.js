/**
 * @vitest-environment jsdom
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The rows the Bitwarden JSON and KeePass XML parsers make of the item and
 * entry shapes the golden exports leave out: cards and identities with
 * missing parts, unsupported item types, loose custom fields and passkey
 * counters, and KeePass strings without a key or value, unnamed groups and
 * protected values. Recorded once from the parsers as they stood.
 *
 * @spec openspec/specs/secret-import/spec.md#requirement-supported-import-formats
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { parseBitwardenJson } from '../../src/import/parsers/bitwarden.js'
import { parseKeepassXml } from '../../src/import/parsers/keepassXml.js'

const ITEMS = [
	{ type: 3, name: 'No number', card: { cardholderName: 'A', expMonth: 3, expYear: 2031 } },
	{ type: 3, card: { number: '4111111111111111', expMonth: '7' } },
	{ type: 3, name: 'Year only', card: { number: '4111111111111111', expYear: '2030', code: '123' } },
	{ type: 3, name: 'No card' },
	{ type: 4, name: 'Address only', identity: { address1: 'Street 1', city: 'Town' } },
	{ type: 4, identity: { ssn: '123456782', address1: 'Street 1', address2: '', city: 'Town', postalCode: '1234 AB', country: 'NL', phone: '06' } },
	{ type: 4, name: 'Empty strings', identity: { firstName: '', lastName: '', email: '' } },
	{ type: 4, name: 'No identity' },
	{ type: 2, name: 'Note' },
	{ type: 5, name: 'SSH key' },
	{ name: 'Untyped', login: { username: 'u', password: 'p', uris: [] } },
	{
		type: 1,
		name: 'Loose fields',
		notes: '',
		fields: [null, { name: null, value: 'x' }, { name: 'a', value: null }, { name: 7, value: 0 }, { name: 'b', value: false }],
		login: { uris: [{ uri: null }, { uri: 'https://second' }], password: 'p' },
	},
	{
		type: 1,
		name: 'Counters',
		folderId: 'missing',
		collectionIds: ['c-none', 'c-1'],
		login: {
			fido2Credentials: [
				{ credentialId: 'A', rpId: 'a.test', keyValue: 'K', counter: '12', rpName: 'A', userHandle: 'h', userDisplayName: 'U' },
				{ credentialId: 'B', rpId: 'b.test', keyValue: 'K', counter: 'many' },
				{ credentialId: 'C', rpId: '', keyValue: 'K' },
				null,
			],
		},
	},
	{ type: 1, login: null, collectionIds: 'not-a-list' },
]

const KEEPASS = `<?xml version="1.0"?>
<KeePassFile><Root>
  <Group><Name>Db</Name>
    <Entry>
      <String><Value>no key</Value></String>
      <String><Key>Title</Key></String>
      <String><Key>UserName</Key><Value></Value></String>
      <String><Key>Notes</Key><Value>note</Value></String>
      <String><Key>Extra</Key><Value>kept</Value></String>
    </Entry>
    <Group><Name>  </Name>
      <Entry>
        <String><Key>Title</Key><Value>In unnamed</Value></String>
        <String><Key>Password</Key><Value>pw</Value></String>
        <String><Key>otp</Key><Value>not-a-seed</Value></String>
      </Entry>
      <Group>
        <Entry><String><Key>Title</Key><Value>No name element</Value></String></Entry>
      </Group>
    </Group>
    <Group><Name>Team</Name>
      <Entry>
        <String><Key>Title</Key><Value>Seeded</Value></String>
        <String><Key>TimeOtp-Secret-Base32</Key><Value>JBSWY3DPEHPK3PXP</Value></String>
        <String><Key>TimeOtp-Period</Key><Value>60</Value></String>
      </Entry>
    </Group>
  </Group>
</Root></KeePassFile>`

describe('import parser items', () => {
	beforeEach(() => {
		vi.useFakeTimers()
		vi.setSystemTime(new Date('2026-01-02T03:04:05.000Z'))
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	it('keeps the Bitwarden rows of every item shape', () => {
		const rows = parseBitwardenJson({
			collections: [{ id: 'c-1', name: 'Shared' }],
			items: ITEMS,
		})
		expect(rows).toMatchSnapshot()
	})

	it('keeps the KeePass rows of every entry shape', () => {
		expect(parseKeepassXml(KEEPASS)).toMatchSnapshot()
	})
})
