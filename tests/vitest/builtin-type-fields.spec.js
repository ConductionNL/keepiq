/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The built-in item types' own forms: the value field, the extra fields
 * stored under a fixed English name, and the generic fields left out.
 *
 * @spec openspec/changes/vault-builtin-type-fields/specs/builtin-type-fields/spec.md#requirement-built-in-types-carry-their-own-fields
 * @spec openspec/changes/vault-builtin-type-fields/specs/builtin-type-fields/spec.md#requirement-built-in-field-values-keep-a-fixed-name
 */

import { describe, expect, it } from 'vitest'
import {
	builtinFieldNamed,
	leavesOut,
	loginLabelOf,
	memberLabel,
	typeFieldsOf,
	valueFieldOf,
	valueLockedInRequests,
} from '../../src/utils/builtinTypeFields.js'

describe('builtinTypeFields', () => {
	it('gives a certificate a public multi-line value and no login', () => {
		expect(valueFieldOf('certificate')).toEqual({
			label: 'Certificate',
			kind: 'multiline',
			public: true,
		})
		expect(leavesOut('certificate', 'login')).toBe(true)
		expect(leavesOut('certificate', 'url')).toBe(false)
		expect(
			typeFieldsOf({ name: 'certificate' }).map((f) => [f.label, f.kind]),
		).toEqual([
			['Private key', 'multiline'],
			['Passphrase', 'hidden'],
		])
	})

	it('gives a database a username, connection fields and no URL', () => {
		expect(valueFieldOf('database')?.label).toBe('Password')
		expect(loginLabelOf('database')).toBe('Username')
		expect(loginLabelOf('database', true)).toBe('Username (optional)')
		expect(leavesOut('database', 'url')).toBe(true)
		expect(typeFieldsOf({ name: 'database' }).map((f) => f.label)).toEqual([
			'Host',
			'Port',
			'Database',
		])
	})

	it('gives an SSH key a multi-line private key, a public key and a passphrase', () => {
		expect(valueFieldOf('ssh_key')).toEqual({
			label: 'Private key',
			kind: 'multiline',
		})
		expect(
			typeFieldsOf({ name: 'ssh_key' }).map((f) => [f.label, f.kind]),
		).toEqual([
			['Public key', 'multiline'],
			['Passphrase', 'hidden'],
		])
		expect(leavesOut('ssh_key', 'login')).toBe(false)
		expect(leavesOut('ssh_key', 'url')).toBe(false)
	})

	it('gives a secure note a multi-line note and no login or URL', () => {
		expect(valueFieldOf('note')).toEqual({ label: 'Note', kind: 'multiline' })
		expect(leavesOut('note', 'login')).toBe(true)
		expect(leavesOut('note', 'url')).toBe(true)
		expect(typeFieldsOf({ name: 'note' })).toEqual([])
	})

	it('locks the value in requests for the types that require one', () => {
		expect(valueLockedInRequests('certificate')).toBe(true)
		expect(valueLockedInRequests('api_key')).toBe(true)
		expect(valueLockedInRequests('database')).toBe(true)
		expect(valueLockedInRequests('ssh_key')).toBe(true)
		expect(valueLockedInRequests('note')).toBe(true)
		expect(valueLockedInRequests('login')).toBe(true)
		expect(valueLockedInRequests('passkey')).toBe(false)
	})

	it('calls a login value the password and keeps its login and URL', () => {
		expect(valueFieldOf('login')).toEqual({ label: 'Password', kind: 'hidden' })
		expect(loginLabelOf('login')).toBeNull()
		expect(leavesOut('login', 'login')).toBe(false)
		expect(leavesOut('login', 'url')).toBe(false)
		expect(typeFieldsOf({ name: 'login' })).toEqual([])
	})

	it('leaves the other types and admin-defined types as they were', () => {
		const adminType = {
			name: 'server-access',
			fields: [{ key: 'host', label: 'Host', kind: 'url', required: true }],
		}
		expect(valueFieldOf('passkey')).toBeNull()
		expect(loginLabelOf('passkey')).toBeNull()
		expect(typeFieldsOf(adminType)).toBe(adminType.fields)
		expect(typeFieldsOf(null)).toEqual([])
	})

	it('finds a built-in field by its stored name and labels other members by name', () => {
		expect(builtinFieldNamed('certificate', 'Private key')?.key).toBe(
			'private-key',
		)
		expect(builtinFieldNamed('certificate', 'private-key')).toBeNull()
		expect(memberLabel('api_key', 'API secret')).toBe('API secret')
		expect(memberLabel('api_key', 'client-id')).toBe('client-id')
	})
})
