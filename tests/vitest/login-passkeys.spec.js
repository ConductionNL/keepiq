/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Reading a secret's `passkeys` value and dropping one entry: every entry
 * not deleted survives, including ones in a shape this client does not list.
 *
 * @spec openspec/changes/vault-login-totp-codes/specs/login-one-time-codes/spec.md#requirement-a-login-can-carry-its-own-totp-seed
 */

import { describe, expect, it } from 'vitest'
import { passkeyEntries, withoutPasskey } from '../../src/passkey/loginPasskeys.js'

const A = { credentialId: 'a', rpId: 'a.example', privateKey: 'K1' }
const B = { credentialId: 'b', rpId: 'b.example', privateKey: 'K2' }
/** Entries a newer client might store in another shape. */
const STRANGE = ['opaque-string', 42, null, ['nested']]

describe('passkeyEntries', () => {
	it('lists the object entries with their stored position', () => {
		const json = JSON.stringify([STRANGE[0], A, STRANGE[1], B])
		expect(passkeyEntries(json)).toEqual([
			{ index: 1, credential: A },
			{ index: 3, credential: B },
		])
	})

	it.each(['', 'not json', '{"credentialId":"a"}', null])(
		'lists nothing for %j',
		(value) => {
			expect(passkeyEntries(value)).toEqual([])
		},
	)
})

describe('withoutPasskey', () => {
	it('keeps every entry it does not drop, whatever its shape', () => {
		const json = JSON.stringify([STRANGE[0], A, ...STRANGE.slice(1), B])
		expect(JSON.parse(withoutPasskey(json, 1))).toEqual([
			STRANGE[0],
			...STRANGE.slice(1),
			B,
		])
	})

	it("returns '' only when nothing remains", () => {
		expect(withoutPasskey(JSON.stringify([A]), 0)).toBe('')
		expect(JSON.parse(withoutPasskey(JSON.stringify([A, 'x']), 0))).toEqual([
			'x',
		])
	})

	it('returns a value that is not an array unchanged', () => {
		expect(withoutPasskey('not json', 0)).toBe('not json')
		expect(withoutPasskey('{"a":1}', 0)).toBe('{"a":1}')
	})
})
