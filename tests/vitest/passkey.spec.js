/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the canonical passkey credential module (`src/passkey/`)
 * and the Bitwarden fido2Credentials import mapping.
 *
 * Locks down:
 *  - Canonical JSON serialize/parse round-trips all fields.
 *  - Malformed/partial credentials yield null (explicit invalid state) —
 *    never fabricated fields.
 *  - Bitwarden `login.fido2Credentials[]` entries go on the login's own
 *    `passkeys` field as canonical credentials (keepiq#1327); a partial
 *    entry is rejected with a reason.
 *
 * @spec openspec/changes/passkey-item-type/specs/passkey-item-type/spec.md#requirement-canonical-cxf-aligned-passkey-credential-schema
 * @spec openspec/changes/passkey-item-type/specs/passkey-item-type/spec.md#requirement-passkey-creation-via-api-and-bitwarden-import
 */

import { describe, expect, it } from 'vitest'
import { parseBitwardenJson } from '../../src/import/parsers/bitwarden.js'
import { passkeyEntries } from '../../src/passkey/loginPasskeys.js'
import {
	buildPasskeyCredential,
	parsePasskey,
	passkeyRpId,
	serializePasskey,
	truncateCredentialId,
} from '../../src/passkey/passkey.js'

const FULL_CREDENTIAL = {
	credentialId: 'BASE64URL_CREDENTIAL_ID_HERE',
	rpId: 'example.com',
	rpName: 'Example',
	userName: 'alice',
	userDisplayName: 'Alice Example',
	userHandle: 'BASE64URL_USER_HANDLE_HERE',
	privateKey: 'BASE64URL_PRIVATE_KEY_HERE',
	algorithm: -7,
	counter: 0,
	transports: ['internal', 'hybrid'],
	createdAt: '2026-07-01T00:00:00.000Z',
}

describe('canonical passkey schema', () => {
	it('serialize/parse round-trips every canonical field', () => {
		const json = serializePasskey(FULL_CREDENTIAL)
		expect(json).toBeTypeOf('string')
		const parsed = parsePasskey(json)
		expect(parsed).toEqual(FULL_CREDENTIAL)
	})

	it('defaults only the documented extension fields, never credential material', () => {
		const minimal = buildPasskeyCredential({
			credentialId: 'ID',
			rpId: 'example.com',
			privateKey: 'KEY',
		})
		expect(minimal.counter).toBe(0)
		expect(minimal.transports).toEqual([])
		expect(minimal.algorithm).toBe(-7)
		expect(minimal.createdAt).toBeTypeOf('string')
		expect(minimal.userName).toBe('')
	})

	it('yields null for malformed JSON and for missing required fields — never fabricated fields', () => {
		expect(parsePasskey('not-json')).toBeNull()
		expect(parsePasskey('')).toBeNull()
		expect(parsePasskey('{"rpId":"example.com"}')).toBeNull()
		expect(
			serializePasskey({ credentialId: 'ID', rpId: 'example.com' }),
		).toBeNull()
		expect(buildPasskeyCredential(null)).toBeNull()
	})

	it('extracts the RP id for the plaintext url mirror', () => {
		const json = serializePasskey(FULL_CREDENTIAL)
		expect(passkeyRpId(json)).toBe('example.com')
		expect(passkeyRpId('garbage')).toBeNull()
	})

	it('truncates long credential ids for display', () => {
		expect(truncateCredentialId('short')).toBe('short')
		expect(truncateCredentialId('ABCDEFGHIJKLMNOP')).toBe('ABCDEFGHIJKL…')
	})
})

describe('Bitwarden fido2Credentials import', () => {
	const bitwardenExport = {
		folders: [],
		items: [
			{
				type: 1,
				name: 'Example login',
				login: {
					username: 'alice',
					password: 'hunter2-placeholder',
					uris: [{ uri: 'https://example.com' }],
					fido2Credentials: [
						{
							credentialId: 'BW_CRED_ID_PLACEHOLDER',
							rpId: 'example.com',
							rpName: 'Example',
							userName: 'alice',
							userDisplayName: 'Alice Example',
							userHandle: 'BW_USER_HANDLE_PLACEHOLDER',
							keyValue: 'BW_KEY_VALUE_PLACEHOLDER',
							counter: '0',
							creationDate: '2026-07-01T00:00:00.000Z',
						},
						{
							// Partial: no key material — must be rejected.
							credentialId: 'BW_PARTIAL_ID_PLACEHOLDER',
							rpId: 'partial.example.com',
						},
					],
				},
			},
		],
	}

	it("puts a complete entry on the login's passkeys field, with no passkey row", () => {
		const rows = parseBitwardenJson(bitwardenExport)
		expect(rows.some((row) => row.type === 'passkey')).toBe(false)

		const loginRow = rows.find((row) => row.errors.length === 0)
		expect(loginRow.name).toBe('Example login')
		expect(loginRow.url).toBe('https://example.com')
		// The stored JSON itself, not a re-canonicalised copy.
		const stored = JSON.parse(loginRow.passkeys)
		expect(stored).toHaveLength(1)
		expect(stored[0]).toMatchObject({
			credentialId: 'BW_CRED_ID_PLACEHOLDER',
			rpId: 'example.com',
			rpName: 'Example',
			userName: 'alice',
			userDisplayName: 'Alice Example',
			userHandle: 'BW_USER_HANDLE_PLACEHOLDER',
			privateKey: 'BW_KEY_VALUE_PLACEHOLDER',
			counter: 0,
			createdAt: '2026-07-01T00:00:00.000Z',
		})
		expect(typeof stored[0].counter).toBe('number')
	})

	it('rejects a second passkey with the same id but another key', () => {
		const entry = {
			credentialId: 'SAME',
			rpId: 'example.com',
			keyValue: 'FIRST_KEY',
		}
		const rows = parseBitwardenJson({
			items: [
				{
					type: 1,
					name: 'Twice',
					login: {
						password: 'p',
						fido2Credentials: [
							entry,
							{ ...entry, keyValue: 'SECOND_KEY' },
						],
					},
				},
			],
		})
		expect(rows).toHaveLength(2)
		const [login, rejected] = rows
		expect(JSON.parse(login.passkeys).map((c) => c.privateKey)).toEqual([
			'FIRST_KEY',
		])
		expect(rejected.name).toBe('Twice')
		expect(rejected.errors[0]).toMatch(/same credential id/)
	})

	it('rejects a partial entry with a reason instead of attaching a partial passkey', () => {
		const rows = parseBitwardenJson(bitwardenExport)
		const rejected = rows.filter((row) => row.errors.length > 0)
		expect(rejected).toHaveLength(1)
		expect(rejected[0].name).toBe('Example login')
		expect(rejected[0].errors[0]).toMatch(/Incomplete Bitwarden passkey/)
		const loginRow = rows.find((row) => row.errors.length === 0)
		expect(loginRow.passkeys).not.toContain('BW_PARTIAL_ID_PLACEHOLDER')
	})

	it('keeps the parent login row importing normally alongside its passkeys', () => {
		const rows = parseBitwardenJson(bitwardenExport)
		const loginRow = rows.find(
			(row) => row.type === 'login' && row.errors.length === 0,
		)
		expect(loginRow).toBeDefined()
		expect(loginRow.password).toBe('hunter2-placeholder')
		expect(rows).toHaveLength(2)
	})

	it('imports a passkey-only login with an empty password and a seed', () => {
		const rows = parseBitwardenJson({
			items: [
				{
					type: 1,
					name: 'Passwordless',
					login: {
						totp: 'JBSWY3DPEHPK3PXP',
						fido2Credentials: [
							{
								credentialId: 'ONE',
								rpId: 'example.com',
								keyValue: 'KEY_ONE',
							},
							{
								// The same credential twice: kept once.
								credentialId: 'ONE',
								rpId: 'example.com',
								keyValue: 'KEY_ONE',
							},
							{
								credentialId: 'TWO',
								rpId: 'example.com',
								keyValue: 'KEY_TWO',
							},
						],
					},
				},
			],
		})
		expect(rows).toHaveLength(1)
		expect(rows[0].errors).toEqual([])
		expect(rows[0].password).toBeNull()
		expect(rows[0].totp).toBe('JBSWY3DPEHPK3PXP')
		expect(
			passkeyEntries(rows[0].passkeys).map(
				({ credential }) => credential.credentialId,
			),
		).toEqual(['ONE', 'TWO'])
	})
})
