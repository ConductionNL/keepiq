/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The normalised import row and the value fields put on it: the default of
 * every row field, which one-time-code seed wins, and which passkeys are
 * folded in, added or refused as a clash.
 *
 * @spec openspec/specs/secret-import/spec.md#requirement-supported-import-formats
 */

import { describe, expect, it } from 'vitest'
import {
	attachPasskeys,
	attachTotp,
	SECOND_TOTP_REASON,
} from '../../src/import/loginValueFields.js'
import { makeRow } from '../../src/import/model.js'

const SEED = 'JBSWY3DPEHPK3PXP'

describe('makeRow', () => {
	it('defaults every field and stringifies the given ones', () => {
		expect(makeRow({}, 3)).toEqual({
			sourceRow: 3,
			name: '',
			url: null,
			login: null,
			password: null,
			additionalFields: null,
			folder: '',
			type: 'login',
			errors: [],
		})
		expect(
			makeRow({ name: 0, url: false, login: 5, password: '', additionalFields: { a: 1 }, folder: 7, type: null }, 1),
		).toEqual({
			sourceRow: 1,
			name: '0',
			url: 'false',
			login: '5',
			password: '',
			additionalFields: { a: 1 },
			folder: '7',
			type: 'login',
			errors: [],
		})
	})
})

describe('attachTotp', () => {
	it('puts the first authenticator key on the row and refuses a different second one', () => {
		const row = makeRow({}, 1)
		expect(attachTotp(row, null)).toBeNull()
		expect(attachTotp(row, '   ')).toBeNull()
		expect(row.totp).toBeUndefined()
		expect(attachTotp(row, 'not a key!')).toMatch(/not an authenticator key/)
		expect(attachTotp(row, ' jbsw y3dp ehpk 3pxp ')).toBeNull()
		expect(row.totp).toBe(SEED)
		expect(attachTotp(row, SEED)).toBeNull()
		expect(attachTotp(row, 'GEZDGNBVGY3TQOJQ')).toBe(SECOND_TOTP_REASON)
		expect(row.totp).toBe(SEED)

		const blank = { ...makeRow({}, 2), totp: '' }
		expect(attachTotp(blank, SEED)).toBeNull()
		expect(blank.totp).toBe(SEED)
	})
})

describe('attachPasskeys', () => {
	const passkey = (id, rpId, privateKey) => ({ credentialId: id, rpId, privateKey })

	it('adds new passkeys, folds a repeat and returns a clash', () => {
		const row = makeRow({}, 1)
		expect(attachPasskeys(row, [])).toEqual([])
		expect(row.passkeys).toBeUndefined()
		expect(attachPasskeys(row, [passkey('A', 'a.test', 'K1'), passkey('A', 'b.test', 'K2')])).toEqual([])
		const clash = passkey('A', 'a.test', 'OTHER')
		expect(attachPasskeys(row, [passkey('A', 'a.test', 'K1'), clash, passkey('B', 'a.test', 'K3')])).toEqual([clash])
		expect(JSON.parse(row.passkeys).map((p) => `${p.credentialId}@${p.rpId}`)).toEqual(['A@a.test', 'A@b.test', 'B@a.test'])
	})
})
