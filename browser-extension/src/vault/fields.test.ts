import { describe, expect, it } from 'vitest'
import type { EditableItem } from '@/src/messages'
import { parseTotpSeed } from '@/src/totp/totp'
import { CARD_FIELDS, customField, diffDraft, emptyValues, formKind, fromItem, IDENTITY_FIELDS, keepLineBreaks, shownInFull, toDraft, totpKey, validate, type FormValues } from './fields'

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

	it('stores a new note in `key` without a username or notes member', () => {
		const draft = toDraft(values({ secret: 'Wifi: hunter2', login: 'ignored', notes: 'typed under another type' }), 'note')
		expect(draft).toMatchObject({ key: 'Wifi: hunter2', login: null, additionalFields: {} })
	})

	it('keeps the username and notes a note came with, as the form shows them', () => {
		const form = fromItem(item({ typeId: 't-note', key: 'Wifi', login: 'alice', additionalFields: { Notes: 'old' } }), 'note')
		expect(toDraft(form, 'note')).toMatchObject({ key: 'Wifi', login: 'alice', additionalFields: { Notes: 'old' } })
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

describe('keepLineBreaks', () => {
	it.each([
		['CRLF', 'a\r\nb', 'a\r\nb\r\nc'],
		['a lone CR', 'a\rb', 'a\rb\rc'],
		['LF', 'a\nb', 'a\nb\nc'],
		['mixed breaks, which cannot be told apart', 'a\r\nb\nc', 'a\nb\nc'],
		['no break', 'ab', 'a\nb\nc'],
	])('gives an edited text area the breaks of a secret that used %s', (_, original, expected) => {
		// What a browser's text area reads back: LF only.
		expect(keepLineBreaks('a\nb\nc', original)).toBe(expected)
	})

	it('reads breaks a text area left as set, as happy-dom does, the same way', () => {
		expect(keepLineBreaks('a\r\nb\nc', 'a\r\nb')).toBe('a\r\nb\r\nc')
		expect(keepLineBreaks('a\rb\nc', 'a\rb')).toBe('a\rb\rc')
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
	/** What a save of `fetched` sends after `edit`, as the form computes it. */
	const patchOf = (fetched: EditableItem, type: string, edit: (values: FormValues) => Partial<FormValues> = () => ({})) => {
		const form = fromItem(fetched, type)
		return diffDraft(toDraft(form, type), toDraft({ ...form, ...edit(form) }, type))
	}

	it('round-trips an item without a change', () => {
		const fetched = item({ additionalFields: { Notes: 'old', pin: '1234' } })
		const form = fromItem(fetched, 'login')
		expect(form).toMatchObject({ notes: 'old', notesKey: 'Notes', login: 'alice', secret: 'hunter2' })
		expect(form.fields.map(({ name, value }) => [name, value])).toEqual([['pin', '1234']])
		expect(patchOf(fetched, 'login')).toEqual({})
	})

	it('keeps a field named __proto__ through a save', () => {
		const fetched = item({ additionalFields: JSON.parse('{"__proto__":"kept","pin":"1"}') as Record<string, string> })
		const draft = toDraft({ ...fromItem(fetched, 'login'), name: 'Renamed' }, 'login')
		expect(JSON.stringify(draft.additionalFields)).toBe('{"__proto__":"kept","pin":"1"}')
		expect(patchOf(fetched, 'login', () => ({ name: 'Renamed' }))).toEqual({ name: 'Renamed' })
	})

	it('sends only a rename', () => {
		expect(patchOf(item(), 'login', () => ({ name: 'GitHub work' }))).toEqual({ name: 'GitHub work' })
	})

	it('clears an emptied username and URL with null', () => {
		expect(patchOf(item(), 'login', () => ({ login: '', url: '' }))).toEqual({ login: null, url: null })
	})

	it.each(['note', 'totp', 'card', 'identity'])('keeps a %s\'s username editable once it came with one, and clears it with null', (type) => {
		const fetched = item({ typeId: `t-${type}`, key: '' })
		expect(patchOf(fetched, type, () => ({ login: 'bob' }))).toEqual({ login: 'bob' })
		expect(patchOf(fetched, type, () => ({ login: '' }))).toEqual({ login: null })
		expect(patchOf(item({ typeId: `t-${type}`, key: '', login: null }), type, () => ({ login: 'bob' }))).toEqual({})
	})

	it('sends the whole additional fields object when notes change, under their own name', () => {
		const fetched = item({ additionalFields: { Notes: 'old', pin: '1234' } })
		expect(patchOf(fetched, 'login', () => ({ notes: 'new' }))).toEqual({ additionalFields: { pin: '1234', Notes: 'new' } })
	})

	it('shows a card or identity key that is not a JSON object as one secret, and writes it back as typed', () => {
		for (const key of ['legacy text', '[1]', ' {"number":"4111"}', '{broken']) {
			const fetched = item({ typeId: 't-card', key, login: null })
			expect(fromItem(fetched, 'card')).toMatchObject({ plainKey: true, secret: key })
			expect(patchOf(fetched, 'card')).toEqual({})
			expect(patchOf(fetched, 'card', () => ({ secret: 'new' }))).toEqual({ key: 'new' })
		}
		expect(fromItem(item({ typeId: 't-identity', key: 'legacy text' }), 'identity').plainKey).toBe(true)
		expect(fromItem(item({ typeId: 't-card', key: '' }), 'card').plainKey).toBe(false)
		expect(fromItem(item({ key: 'hunter2' }), 'login').plainKey).toBe(false)
	})

	it('sends a card it opened with missing members or a number unchanged until a member is typed', () => {
		const fetched = item({ typeId: 't-card', key: '{"number":4111,"cardholder":"Alice"}', login: null })
		expect(fromItem(fetched, 'card').card).toEqual({ number: '4111', expiry: '', cvv: '', pin: '', cardholder: 'Alice' })
		expect(patchOf(fetched, 'card', () => ({ notes: 'n' }))).toEqual({ additionalFields: { notes: 'n' } })
		expect(patchOf(fetched, 'card', (values) => ({ card: { ...values.card, cvv: '123' } }))).toEqual({
			key: '{"number":"4111","expiry":"","cvv":"123","pin":"","cardholder":"Alice"}',
		})
	})

	it('never sends a withheld passkey credential', () => {
		const fetched = item({ typeId: 't-passkey', key: '', login: null })
		expect(patchOf(fetched, 'passkey', () => ({ name: 'Renamed' }))).toEqual({ name: 'Renamed' })
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
		const note = fromItem(item({ typeId: 't-note', key: 'Wifi' }), 'note')
		expect(validate({ ...note, login: 'x'.repeat(4097) }, 'note')).toEqual({ login: { code: 'tooLong', max: 4096 } })
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

describe('shownInFull', () => {
	const TYPES = ['login', 'database', 'ssh_key', undefined, 'note', 'totp', 'card', 'identity', 'passkey']
	const card = JSON.stringify(Object.fromEntries(CARD_FIELDS.map((member) => [member, `${member} value`])))
	const identity = JSON.stringify(Object.fromEntries(IDENTITY_FIELDS.map((member) => [member, `${member} value`])))
	const shownUnder = (field: 'key' | 'login' | 'additionalFields', plaintext: string) => TYPES.filter((type) => shownInFull(field, plaintext, type))

	it('counts an empty field as shown under every type', () => {
		for (const field of ['key', 'login', 'additionalFields'] as const) {
			expect(shownUnder(field, '')).toEqual(TYPES)
			expect(TYPES.every((type) => shownInFull(field, null, type))).toBe(true)
		}
	})

	const notPasskey = TYPES.filter((type) => type !== 'passkey')

	it('shows a username under every type but a passkey, as the web app does', () => {
		expect(shownUnder('login', 'alice')).toEqual(notPasskey)
	})

	it('shows a plain key in full for every type but a passkey, a card or identity one as its one secret field', () => {
		for (const key of ['Y-BANK-PASSWORD\nsecond line', '[1]', ` ${card}`, '{broken']) expect(shownUnder('key', key)).toEqual(notPasskey)
	})

	it('shows a card or identity key when every member is one the form has a field for, holding text or a number', () => {
		expect(shownUnder('key', card)).toEqual(TYPES.filter((type) => type !== 'passkey' && type !== 'identity'))
		expect(shownInFull('key', identity, 'identity')).toBe(true)
		expect(shownInFull('key', '{"number":4111,"cardholder":"Alice"}', 'card')).toBe(true)
		expect(shownInFull('key', '{}', 'card')).toBe(true)
		expect(shownInFull('key', card.replace(':', ': '), 'card')).toBe(true)
		const hidden = [
			card.replace('}', ',"recovery":"Y-BANK-PASSWORD"}'),
			card.replace('"cvv":"cvv value"', '"cvv":"Y-BANK-PASSWORD","cvv":"cvv value"'),
			card.replace('"pin":"pin value"', '"pin":{"code":"Y-BANK-PASSWORD"}'),
			card.replace('"pin":"pin value"', '"pin":["Y-BANK-PASSWORD"]'),
			card.replace('"pin":"pin value"', '"pin":true'),
			card.replace('"pin":"pin value"', '"pin":null'),
			'{"number":"4111","__proto__":"Y-BANK-PASSWORD"}',
			'{"number":4.10}',
			'{"number":12345678901234567890}',
		]
		for (const key of hidden) expect(shownInFull('key', key, 'card')).toBe(false)
		expect(shownInFull('key', identity.replace('}', ',"recovery":"Y-BANK-PASSWORD"}'), 'identity')).toBe(false)
	})

	it('shows additional fields in full for all but a passkey, notes included', () => {
		expect(shownUnder('additionalFields', '{"pin":"1234","meta":{"a":1}}')).toEqual(notPasskey)
		expect(shownUnder('additionalFields', '{"pin":"1234","Notes":"Y-BANK-PASSWORD"}')).toEqual(notPasskey)
	})

	it('shows additional fields in any writer\'s formatting, as Python\'s json.dumps', () => {
		for (const fields of ['{"pin": "1234", "meta": {"a": 1, "b": [1, 2]}}', '{\n  "pin": "1234"\n}', '{"p\\u0069n":"1234"}']) {
			expect(shownUnder('additionalFields', fields)).toEqual(notPasskey)
		}
	})

	it('refuses additional fields whose parse loses something, as a duplicate member does', () => {
		for (const fields of ['{"pin":"Y-BANK-PASSWORD","pin":"1234"}', '{"meta":{"a":"Y-BANK-PASSWORD","a":1}}', '{"pin":"Y-BANK-PASSWORD", "p\\u0069n":"1234"}', '{"pin": 12345678901234567890}', '[1]']) {
			expect(shownUnder('additionalFields', fields)).toEqual([])
		}
	})
})
