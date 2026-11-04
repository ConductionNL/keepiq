/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Bitwarden's encrypted JSON exports hold cipher strings, not values, so the
 * parser refuses them instead of importing cipher text as names and passwords.
 */
import { describe, expect, it } from 'vitest'
import { parseBitwardenJson } from '../../src/import/parsers/bitwarden.js'

const REFUSAL = /^Encrypted Bitwarden exports cannot be imported/

const accountRestricted = {
	encrypted: true,
	encKeyValidation_DO_NOT_EDIT: '2.AAAA|BBBB|CCCC',
	folders: [{ id: 'f1', name: '2.Zm9sZGVy|aXY=|bWFj' }],
	items: [
		{
			type: 1,
			name: '2.bmFtZQ==|aXY=|bWFj',
			login: {
				username: '2.dXNlcg==|aXY=|bWFj',
				password: '2.cGFzcw==|aXY=|bWFj',
			},
		},
	],
}

const passwordProtected = {
	encrypted: true,
	passwordProtected: true,
	salt: 'c2FsdA==',
	kdfType: 0,
	kdfIterations: 600000,
	encKeyValidation_DO_NOT_EDIT: '2.AAAA|BBBB|CCCC',
	data: '2.ZGF0YQ==|aXY=|bWFj',
}

describe('parseBitwardenJson with an encrypted export', () => {
	it('refuses an account-restricted export instead of importing cipher text', () => {
		expect(() => parseBitwardenJson(accountRestricted)).toThrow(REFUSAL)
	})

	it('refuses it as JSON text too', () => {
		expect(() => parseBitwardenJson(JSON.stringify(accountRestricted))).toThrow(
			REFUSAL,
		)
	})

	it('refuses a password-protected export with the same message', () => {
		expect(() => parseBitwardenJson(passwordProtected)).toThrow(REFUSAL)
	})

	it('keeps no cipher text or key material in the message', () => {
		expect(() => parseBitwardenJson(accountRestricted)).toThrow(
			expect.objectContaining({
				message: expect.not.stringMatching(/2\.|\|/),
			}),
		)
	})

	it.each([
		['the string "true"', 'true'],
		['1', 1],
		['an object', {}],
	])('refuses an export whose encrypted flag is %s', (_, flag) => {
		expect(() =>
			parseBitwardenJson({ ...accountRestricted, encrypted: flag }),
		).toThrow(REFUSAL)
	})

	it.each([
		['0', 0],
		['an empty string', ''],
		['null', null],
	])('still imports an export whose encrypted flag is %s', (_, flag) => {
		const rows = parseBitwardenJson({
			encrypted: flag,
			items: [
				{
					type: 1,
					name: 'GitHub',
					login: { username: 'me', password: 'pw' },
				},
			],
		})
		expect(rows.map((row) => row.name)).toEqual(['GitHub'])
	})

	it('still imports an unencrypted export that says encrypted: false', () => {
		const rows = parseBitwardenJson({
			encrypted: false,
			items: [
				{
					type: 1,
					name: 'GitHub',
					login: { username: 'me', password: 'pw' },
				},
			],
		})
		expect(rows).toHaveLength(1)
		expect(rows[0].name).toBe('GitHub')
	})
})
