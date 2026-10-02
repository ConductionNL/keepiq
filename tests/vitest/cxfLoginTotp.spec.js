/**
 * @spec openspec/changes/vault-login-totp-codes/specs/login-one-time-codes/spec.md#requirement-a-login-can-carry-its-own-totp-seed
 *
 * A CXF item that carries a login and a TOTP credential becomes one login with
 * the seed under `totp` in its additional fields, not a second row without an
 * address (vault-login-totp-codes task 2.2). A TOTP credential on an item
 * without a login stays an Authenticator item.
 */
import { describe, expect, it } from 'vitest'
import { cxfToRows, parseCxfDocument } from '../../src/cxf/cxf.js'

const SEED = 'otpauth://totp/x?secret=GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'

function doc(items) {
	return {
		version: { major: 1, minor: 0 },
		exporter: 'other-vault',
		timestamp: 1750000000,
		accounts: [{ id: 'acc-1', userName: 'alice', items }],
	}
}

const login = {
	type: 'basic-auth',
	urls: ['https://example.com'],
	username: { fieldType: 'string', value: 'alice' },
	password: { fieldType: 'concealed-string', value: 'pw' },
	notes: { fieldType: 'string', value: 'note' },
}

describe('CXF login with a TOTP credential', () => {
	it('attaches the seed to the login of the same item', () => {
		const rows = cxfToRows(
			parseCxfDocument(
				doc([
					{
						id: 'i1',
						title: 'Example',
						credentials: [login, { type: 'totp', url: SEED }],
					},
				]),
			),
		)

		expect(rows).toHaveLength(1)
		expect(rows[0].type).toBe('login')
		expect(rows[0].url).toBe('https://example.com')
		expect(rows[0].additionalFields).toEqual({ notes: 'note', totp: SEED })
	})

	it('also when the TOTP credential comes first', () => {
		const rows = cxfToRows(
			parseCxfDocument(
				doc([
					{
						id: 'i1',
						title: 'Example',
						credentials: [
							{ type: 'totp', secret: 'GEZDGNBVGY3TQOJQ' },
							login,
						],
					},
				]),
			),
		)

		expect(rows).toHaveLength(1)
		expect(rows[0].additionalFields.totp).toBe('GEZDGNBVGY3TQOJQ')
	})

	it('keeps a TOTP credential on an item without a login as an Authenticator item', () => {
		const rows = cxfToRows(
			parseCxfDocument(
				doc([
					{
						id: 'i1',
						title: 'Only code',
						credentials: [{ type: 'totp', url: SEED }],
					},
				]),
			),
		)

		expect(rows).toHaveLength(1)
		expect(rows[0].type).toBe('totp')
	})
})
