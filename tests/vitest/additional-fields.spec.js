/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The naming and shape rules for a Secret's additional fields
 * (`src/utils/additionalFields.js`).
 *
 * These are shared by three call sites — the secret create dialog, the secret edit
 * dialog, and the request dialog that asks somebody ELSE to fill a member in — so
 * they are tested once, here, rather than three times through three components.
 *
 * @spec openspec/specs/secrets-write-ui/spec.md#requirement-create-a-secret-from-the-ui
 */

import { describe, expect, it } from 'vitest'
import {
	fieldKindOf,
	fieldValueOf,
	memberNameError,
	membersToObject,
	normaliseKind,
	objectToMembers,
	RESERVED_MEMBER_NAMES,
} from '../../src/utils/additionalFields.js'

describe('additional-field naming rules', () => {
	it('refuses every reserved name, with a reason', () => {
		// `key`, `login` and `url` address the Secret's own columns. A member with
		// one of those names is not a second field with the same label — it is a
		// value that gets misrouted or shadowed, so the user would store something
		// other than what they typed.
		for (const reserved of RESERVED_MEMBER_NAMES) {
			const error = memberNameError(reserved, [])
			expect(error).not.toBe('')
			expect(error.length).toBeGreaterThan(0)
		}
	})

	it('refuses a reserved name whatever its casing', () => {
		// `Key` reaches the same column as `key`, so accepting it would produce
		// exactly the misrouting the rule exists to prevent.
		expect(memberNameError('Key', [])).not.toBe('')
		expect(memberNameError('URL', [])).not.toBe('')
		expect(memberNameError('  Login  ', [])).not.toBe('')
	})

	it('refuses a duplicate, ignoring surrounding whitespace', () => {
		expect(memberNameError('client-id', ['client-id'])).not.toBe('')
		expect(memberNameError('  client-id  ', ['client-id'])).not.toBe('')
	})

	it('refuses a blank name', () => {
		expect(memberNameError('', [])).not.toBe('')
		expect(memberNameError('   ', [])).not.toBe('')
		expect(memberNameError(undefined, [])).not.toBe('')
	})

	it('accepts a name that is neither reserved nor taken', () => {
		expect(memberNameError('client-id', ['tenant'])).toBe('')
		// A name merely CONTAINING a reserved word is fine: only the whole name
		// addresses a column.
		expect(memberNameError('api-key', [])).toBe('')
		expect(memberNameError('login-hint', [])).toBe('')
	})
})

describe('additional-field shape conversion', () => {
	it('turns members into the object the store encrypts', () => {
		expect(
			membersToObject([
				{ name: 'client-id', value: 'abc' },
				{ name: 'tenant', value: 'acme' },
			]),
		).toEqual({ 'client-id': 'abc', tenant: 'acme' })
	})

	it('yields an EMPTY object for no members, never null', () => {
		// Load bearing: `{}` means "this secret has no additional fields", while
		// null means "nothing was sent", which the store reads as "leave the stored
		// blob alone" — the opposite of what removing the last member means.
		expect(membersToObject([])).toEqual({})
		expect(membersToObject()).toEqual({})
	})

	it('drops members whose name was left blank', () => {
		expect(
			membersToObject([
				{ name: '', value: 'orphan' },
				{ name: 'kept', value: '1' },
			]),
		).toEqual({ kept: '1' })
	})

	it('round-trips a decrypted blob back into an editable list', () => {
		const blob = { 'client-id': 'abc', tenant: 'acme' }
		expect(membersToObject(objectToMembers(blob))).toEqual(blob)
	})

	it('treats an unparseable blob as no members rather than guessing', () => {
		// The store hands over a string when the ciphertext did not contain JSON.
		// Inventing members from it would let an edit write a blob that silently
		// omits whatever was actually in there.
		expect(objectToMembers('not json')).toEqual([])
		expect(objectToMembers(null)).toEqual([])
		expect(objectToMembers(undefined)).toEqual([])
	})

	it('stringifies non-string member values', () => {
		expect(objectToMembers({ port: 8080, on: true })).toEqual([
			{ name: 'port', value: '8080', kind: 'text' },
			{ name: 'on', value: 'true', kind: 'text' },
		])
	})
})

/**
 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-hidden-custom-fields
 */
describe('custom field kinds', () => {
	it('reads an old blob without kinds as text, exactly as before', () => {
		expect(
			objectToMembers({ 'Recovery code': 'abc-123', tenant: 'acme' }),
		).toEqual([
			{ name: 'Recovery code', value: 'abc-123', kind: 'text' },
			{ name: 'tenant', value: 'acme', kind: 'text' },
		])
	})

	it('stores a text field as a bare string, so the blob shape is unchanged', () => {
		expect(
			membersToObject([{ name: 'tenant', value: 'acme', kind: 'text' }]),
		).toEqual({ tenant: 'acme' })
		expect(membersToObject([{ name: 'tenant', value: 'acme' }])).toEqual({
			tenant: 'acme',
		})
	})

	it('stores hidden and boolean fields with their kind in the blob', () => {
		expect(
			membersToObject([
				{ name: 'Recovery code', value: 'abc-123', kind: 'hidden' },
				{ name: 'Active', value: 'true', kind: 'boolean' },
			]),
		).toEqual({
			'Recovery code': { value: 'abc-123', kind: 'hidden' },
			Active: { value: 'true', kind: 'boolean' },
		})
	})

	it('round-trips every kind through the blob', () => {
		const members = [
			{ name: 'a', value: 'plain', kind: 'text' },
			{ name: 'b', value: 's3cret', kind: 'hidden' },
			{ name: 'c', value: 'false', kind: 'boolean' },
		]
		expect(
			objectToMembers(JSON.parse(JSON.stringify(membersToObject(members)))),
		).toEqual(members)
	})

	it('reads an unknown kind as text', () => {
		expect(normaliseKind('linked')).toBe('text')
		expect(fieldKindOf({ value: 'x', kind: 'linked' })).toBe('text')
		expect(fieldValueOf({ value: 'x', kind: 'linked' })).toBe('x')
		expect(fieldValueOf(undefined)).toBe('')
	})
})
