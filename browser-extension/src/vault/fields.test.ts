import { describe, expect, it } from 'vitest'
import type { EditableItem } from '@/src/messages'
import { parseTotpSeed } from '@/src/totp/totp'
import { customField, diffDraft, emptyValues, formKind, fromItem, toDraft, totpKey, validate, type FormValues } from './fields'

function values(overrides: Partial<FormValues> = {}): FormValues {
	return { ...emptyValues('t-login'), name: 'GitHub', ...overrides }
}

function item(overrides: Partial<EditableItem> = {}): EditableItem {
	return {
		id: 'i1', typeId: 't-login', name: 'GitHub', url: 'https://github.com', folderId: null,
		key: 'hunter2', login: 'alice', additionalFields: {}, updatedAt: '2026-03-02T10:00:00+00:00', ...overrides,
	}
}

describe('formKind', () => {
	it('gives composite types their own form and everything else a credential', () => {
		expect(['note', 'totp', 'card', 'identity', 'passkey'].map(formKind)).toEqual(['note', 'totp', 'card', 'identity', 'passkey'])
		expect(['login', 'database', 'api_key', 'server_access', undefined].map(formKind)).toEqual(Array(5).fill('credential'))
	})
})

describe('toDraft', () => {
	it('puts notes under `notes`, omits an empty username and clears an empty URL', () => {
		expect(toDraft(values({ notes: 'Recovery codes in the safe' }), 'login')).toEqual({
			typeId: 't-login', name: 'GitHub', url: null, folderId: null, key: '', login: null,
			additionalFields: { notes: 'Recovery codes in the safe' },
		})
	})

	it('stores a note in `key` without a username or notes member', () => {
		const draft = toDraft(values({ secret: 'Wifi: hunter2', login: 'ignored', notes: 'typed under another type' }), 'note')
		expect(draft).toMatchObject({ key: 'Wifi: hunter2', login: null, additionalFields: {} })
	})

	it('serializes a card exactly as the web app does', () => {
		const card = { number: '4111 1111 1111 1111', expiry: '12/29', cvv: '', pin: '', cardholder: 'Alice Doe' }
		expect(toDraft(values({ card }), 'card').key).toBe('{"number":"4111 1111 1111 1111","expiry":"12/29","cvv":"","pin":"","cardholder":"Alice Doe"}')
	})

	it('serializes an identity with all six members', () => {
		const identity = { firstName: 'Alice', lastName: 'Doe', address: '', phone: '', email: 'alice@example.org', bsn: '' }
		expect(JSON.parse(toDraft(values({ identity }), 'identity').key)).toEqual(identity)
	})

	it('keeps named custom fields and drops unnamed ones', () => {
		const fields = [customField(' pin ', '1234'), customField('', '')]
		expect(toDraft(values({ fields }), 'login').additionalFields).toEqual({ pin: '1234' })
	})
})

describe('totpKey', () => {
	it('keeps a bare secret as typed until an option changes', () => {
		expect(toDraft(values({ secret: 'JBSWY3DPEHPK3PXP' }), 'totp').key).toBe('JBSWY3DPEHPK3PXP')
	})

	it('writes changed options into a URI labelled with the item name', () => {
		const key = totpKey('jbswy3dpehpk3pxp', { digits: 8 }, 'Git Hub')
		expect(key).toBe('otpauth://totp/Git%20Hub?secret=JBSWY3DPEHPK3PXP&algorithm=SHA1&digits=8&period=30')
		expect(parseTotpSeed(key)).toMatchObject({ digits: 8, period: 30, algorithm: 'SHA1' })
	})

	it('rewrites an existing URI and keeps its issuer', () => {
		const key = totpKey('otpauth://totp/ACME:alice?secret=JBSWY3DPEHPK3PXP&issuer=ACME&period=30', { period: 60, algorithm: 'SHA256' }, 'ignored')
		expect(parseTotpSeed(key)).toMatchObject({ period: 60, algorithm: 'SHA256', issuer: 'ACME', account: 'alice' })
	})
})

describe('fromItem and diffDraft', () => {
	it('round-trips an item without a change', () => {
		const fetched = item({ additionalFields: { Notes: 'old', pin: '1234' } })
		const form = fromItem(fetched)
		expect(form).toMatchObject({ notes: 'old', notesKey: 'Notes', login: 'alice', secret: 'hunter2' })
		expect(form.fields.map(({ name, value }) => [name, value])).toEqual([['pin', '1234']])
		expect(diffDraft(toDraft(form, 'login'), toDraft(fromItem(fetched), 'login'))).toEqual({})
	})

	it('keeps a field named __proto__ through a save', () => {
		const fetched = item({ additionalFields: JSON.parse('{"__proto__":"kept","pin":"1"}') as Record<string, string> })
		const draft = toDraft({ ...fromItem(fetched), name: 'Renamed' }, 'login')
		expect(JSON.stringify(draft.additionalFields)).toBe('{"__proto__":"kept","pin":"1"}')
		expect(diffDraft(toDraft(fromItem(fetched), 'login'), draft)).toEqual({ name: 'Renamed' })
	})

	it('sends only a rename', () => {
		const before = toDraft(fromItem(item()), 'login')
		expect(diffDraft(before, toDraft({ ...fromItem(item()), name: 'GitHub work' }, 'login'))).toEqual({ name: 'GitHub work' })
	})

	it('clears an emptied username and URL with null', () => {
		const before = toDraft(fromItem(item()), 'login')
		expect(diffDraft(before, toDraft({ ...fromItem(item()), login: '', url: '' }, 'login'))).toEqual({ login: null, url: null })
	})

	it('sends the whole additional fields object when notes change, under their own name', () => {
		const fetched = item({ additionalFields: { Notes: 'old', pin: '1234' } })
		const patch = diffDraft(toDraft(fromItem(fetched), 'login'), toDraft({ ...fromItem(fetched), notes: 'new' }, 'login'))
		expect(patch).toEqual({ additionalFields: { pin: '1234', Notes: 'new' } })
	})

	it('leaves an unreadable card alone until a member is typed', () => {
		const fetched = item({ typeId: 't-card', key: 'legacy text', login: null })
		expect(diffDraft(toDraft(fromItem(fetched), 'card'), toDraft(fromItem(fetched), 'card'))).toEqual({})
	})

	it('never sends a withheld passkey credential', () => {
		const fetched = item({ typeId: 't-passkey', key: '', keyWithheld: true, login: null })
		const patch = diffDraft(toDraft(fromItem(fetched), 'passkey'), toDraft({ ...fromItem(fetched), name: 'Renamed' }, 'passkey'))
		expect(patch).toEqual({ name: 'Renamed' })
	})
})

describe('validate', () => {
	it('requires a name of at most 255 characters', () => {
		expect(validate(values({ name: '  ' }), 'login')).toEqual({ name: { code: 'required' } })
		expect(validate(values({ name: 'x'.repeat(255) }), 'login')).toEqual({})
		expect(validate(values({ name: 'x'.repeat(256) }), 'login')).toEqual({ name: { code: 'tooLong', max: 255 } })
	})

	it('caps a URL and a username at 4096 characters', () => {
		const errors = validate(values({ url: 'x'.repeat(4097), login: 'x'.repeat(4097) }), 'login')
		expect(errors).toEqual({ url: { code: 'tooLong', max: 4096 }, login: { code: 'tooLong', max: 4096 } })
	})

	it('refuses reserved, blank and duplicate field names, ignoring case', () => {
		const fields = [customField('Login', 'a'), customField('', 'b'), customField('pin', '1'), customField('PIN', '2'), customField('Notes', 'c')]
		const ids = fields.map((field) => field.id)
		expect(validate(values({ fields }), 'login')).toEqual({
			[`field.${ids[0]}.name`]: { code: 'reserved' },
			[`field.${ids[1]}.name`]: { code: 'required' },
			[`field.${ids[3]}.name`]: { code: 'duplicate' },
			[`field.${ids[4]}.name`]: { code: 'reserved' },
		})
	})

	it('refuses an invalid authenticator secret', () => {
		expect(validate(values({ secret: 'not a secret!' }), 'totp')).toEqual({ secret: { code: 'invalidTotp' } })
		expect(validate(values({ secret: '' }), 'totp')).toEqual({ secret: { code: 'invalidTotp' } })
	})

	it('puts an oversized additional fields blob on Notes', () => {
		expect(validate(values({ notes: 'x'.repeat(70000) }), 'login')).toEqual({ notes: { code: 'tooLongBytes', max: 65536 } })
	})

	it('counts the key in UTF-8 bytes', () => {
		expect(validate(values({ secret: 'é'.repeat(32768) }), 'note')).toEqual({})
		expect(validate(values({ secret: 'é'.repeat(32769) }), 'note')).toEqual({ secret: { code: 'tooLongBytes', max: 65536 } })
	})

	it('checks only name and URL for a passkey', () => {
		expect(validate(values({ secret: '' }), 'passkey')).toEqual({})
	})
})
