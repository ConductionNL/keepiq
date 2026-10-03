/**
 * The item form's rules: drafts per type, the parts sent to the worker, the
 * sparse update, validation and messages.
 *
 * @spec openspec/changes/clients-extension-complete/specs/extension-vault/spec.md#requirement-edit-every-kind-of-item
 */
import { describe, expect, it } from 'vitest'
import {
	changedParts,
	draftFromItem,
	folderPath,
	formKind,
	MAX_FIELD_CHARS,
	partsFromDraft,
	validateDraft,
	writeErrorMessage,
} from '../../browser-extension/src/lib/item-form.js'

const LOGIN = {
	name: 'Mail',
	url: 'https://mail.example',
	folderId: 'f1',
	login: 'ann',
	secret: 'pw',
	additionalFields: { pin: '1234', Notes: 'hello' },
}

describe('drafts and parts', () => {
	it('maps types to form kinds, unknown types to generic', () => {
		expect(formKind('login')).toBe('login')
		expect(formKind('card')).toBe('card')
		expect(formKind('api_key')).toBe('generic')
	})

	it("keeps a login's notes in the notes field and round-trips unchanged", () => {
		const draft = draftFromItem(LOGIN, 'login')
		expect(draft.notes).toBe('hello')
		expect(draft.fields).toEqual([{ name: 'pin', value: '1234' }])
		const parts = partsFromDraft(draft)
		expect(parts).toMatchObject({
			name: 'Mail',
			login: 'ann',
			key: 'pw',
			additionalFields: { pin: '1234', notes: 'hello' },
		})
		expect(
			changedParts(
				partsFromDraft(
					draftFromItem(
						{
							...LOGIN,
							additionalFields: { pin: '1234', notes: 'hello' },
						},
						'login',
					),
				),
				parts,
			),
		).toEqual({})
	})

	it("stores a note's text in the key", () => {
		const draft = draftFromItem({ name: 'N', secret: 'line 1\nline 2' }, 'note')
		expect(draft.notes).toBe('line 1\nline 2')
		expect(partsFromDraft(draft)).toMatchObject({
			key: 'line 1\nline 2',
			additionalFields: null,
		})
	})

	it('stores a card as JSON in the key', () => {
		const draft = draftFromItem(
			{
				name: 'Visa',
				secret: '{"number":"4111111111111111","expiry":"12/30","cvv":"123","pin":"","cardholder":"A"}',
			},
			'card',
		)
		expect(draft.composite.number).toBe('4111111111111111')
		draft.composite.cvv = '999'
		expect(JSON.parse(partsFromDraft(draft).key)).toMatchObject({
			cvv: '999',
			cardholder: 'A',
		})
	})

	it('sends only what changed', () => {
		const before = partsFromDraft(draftFromItem(LOGIN, 'login'))
		const draft = draftFromItem(LOGIN, 'login')
		draft.url = 'https://new.example'
		draft.fields.push({ name: 'account', value: '42' })
		expect(changedParts(before, partsFromDraft(draft))).toEqual({
			url: 'https://new.example',
			additionalFields: { pin: '1234', account: '42', notes: 'hello' },
		})
	})
})

describe('validation', () => {
	it('refuses a blank name, reserved, blank and duplicate field names', () => {
		const draft = draftFromItem(LOGIN, 'login')
		draft.name = ' '
		draft.fields = [
			{ name: 'Login', value: 'x' },
			{ name: '', value: 'y' },
			{ name: 'pin', value: '1' },
			{ name: 'PIN', value: '2' },
		]
		expect(validateDraft(draft)).toEqual({
			name: 'Give the item a name',
			'field-0': 'This name is reserved',
			'field-1': 'Give the field a name',
			'field-3': 'Another field has this name',
		})
	})

	it('refuses values over the limits', () => {
		const draft = draftFromItem(LOGIN, 'login')
		draft.url = 'x'.repeat(MAX_FIELD_CHARS + 1)
		draft.secret = 'y'.repeat(70000)
		expect(validateDraft(draft)).toMatchObject({
			url: expect.any(String),
			secret: 'This value is too long to save',
		})
	})
})

describe('helpers', () => {
	it('builds folder paths', () => {
		const folders = [
			{ id: 'a', name: 'Work' },
			{ id: 'b', name: 'Clients', parentId: 'a' },
		]
		expect(folderPath(folders, 'b')).toBe('Work / Clients')
		expect(folderPath(folders, null)).toBe('No folder')
	})

	it('words write failures', () => {
		expect(writeErrorMessage({ status: 423 })).toMatch(/key migration/)
		expect(writeErrorMessage({ status: 403 })).toMatch(
			/encryption suite is blocked/,
		)
		expect(writeErrorMessage({ status: 400, body: '{"message":"Nope"}' })).toBe(
			'Nope',
		)
		expect(writeErrorMessage(new TypeError('Failed to fetch'))).toBe(
			'Could not reach the server',
		)
	})
})
