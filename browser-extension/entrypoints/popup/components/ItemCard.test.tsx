// @vitest-environment happy-dom
import { describe, expect, it, vi } from 'vitest'
import { i18n } from '#i18n'
import type { ItemMeta } from '@/src/messages'
import { language } from '../i18n'
import type { ItemActions } from '../shell-context'
import { itemMeta, types } from '../testing'
import { itemActions } from './ItemCard'

const login = types.find((type) => type.name === 'login')
const passkey = types.find((type) => type.name === 'passkey')
const note = types.find((type) => type.name === 'note')
const ENDS = '2027-02-03T10:00:00Z'

function handlers(): ItemActions {
	return { add: vi.fn(), edit: vi.fn(), clone: vi.fn(), move: vi.fn(), remove: vi.fn(), send: vi.fn() }
}

/** Each action's disabled reason, `null` for an absent action. */
function refusals(item: ItemMeta, type = login, offline = false, viewOnly = false) {
	const actions = itemActions({ item, type, offline, actions: handlers(), viewOnly })
	return Object.fromEntries(Object.entries(actions).map(([key, action]) => [key, action === undefined ? null : action.disabledReason]))
}

describe('itemActions', () => {
	const ends = () => new Date(ENDS).toLocaleDateString(language())

	it('allows everything on a plain login, online', () => {
		expect(refusals(itemMeta())).toEqual({ edit: undefined, clone: undefined, move: undefined, remove: undefined, send: undefined })
	})

	it('offers Send for logins only', () => {
		expect(refusals(itemMeta(), note).send).toBeNull()
		expect(itemActions({ item: itemMeta(), type: undefined, offline: false, actions: handlers() }).send).toBeUndefined()
	})

	it('refuses all but Edit offline, with Send saying why in its own words', () => {
		expect(refusals(itemMeta(), login, true)).toEqual({ edit: undefined, clone: i18n.t('form.offline'), move: i18n.t('form.offline'), remove: i18n.t('form.offline'), send: i18n.t('send.offline') })
	})

	it('refuses Edit, Clone and Send on a blocked item, with the blocked reason first', () => {
		expect(refusals(itemMeta({ blocked: true, useOnly: true }), login, true)).toEqual({
			edit: i18n.t('blocked.generic'), clone: i18n.t('blocked.generic'), move: i18n.t('item.useOnly'), remove: i18n.t('form.offline'), send: i18n.t('blocked.generic'),
		})
	})

	it('refuses Edit, Clone, Move and Send on a use-only share', () => {
		expect(refusals(itemMeta({ useOnly: true, readOnly: true }))).toEqual({
			edit: i18n.t('item.useOnly'), clone: i18n.t('item.useOnly'), move: i18n.t('item.useOnly'), remove: undefined, send: i18n.t('item.useOnly'),
		})
	})

	it('refuses Edit and Clone on a read-only copy, which may still be moved and sent', () => {
		expect(refusals(itemMeta({ readOnly: true }))).toEqual({ edit: i18n.t('item.readOnly'), clone: i18n.t('item.readOnly'), move: undefined, remove: undefined, send: undefined })
	})

	it('refuses Edit in view-only detail, ahead of nothing else', () => {
		expect(refusals(itemMeta(), login, true, true)).toEqual({ edit: i18n.t('item.viewOnly'), clone: i18n.t('form.offline'), move: i18n.t('form.offline'), remove: i18n.t('form.offline'), send: i18n.t('send.offline') })
		expect(refusals(itemMeta({ readOnly: true }), login, false, true).edit).toBe(i18n.t('item.readOnly'))
	})

	it('refuses Clone and Send on an expiring share, naming the date, ahead of offline', () => {
		expect(refusals(itemMeta({ accessExpiresAt: ENDS }), login, true)).toEqual({
			edit: undefined,
			clone: i18n.t('item.expiringNoClone', { date: ends() }),
			move: i18n.t('form.offline'),
			remove: i18n.t('form.offline'),
			send: i18n.t('item.expiringNoSend', { date: ends() }),
		})
	})

	it('refuses Clone of a passkey first of all', () => {
		expect(refusals(itemMeta({ blocked: true, accessExpiresAt: ENDS }), passkey, true).clone).toBe(i18n.t('item.passkeyNoClone'))
		expect(refusals(itemMeta(), passkey).clone).toBe(i18n.t('item.passkeyNoClone'))
	})

	it('runs each handler with the item or its id', () => {
		const actions = handlers()
		const item = itemMeta()
		const all = itemActions({ item, type: login, offline: false, actions })
		all.edit.onSelect()
		all.clone.onSelect()
		all.move.onSelect()
		all.remove.onSelect()
		all.send?.onSelect()
		expect([actions.edit, actions.clone, actions.move, actions.remove, actions.send].map((fn) => vi.mocked(fn).mock.calls)).toEqual([[['i1']], [['i1']], [[item]], [[item]], [[item]]])
		expect([all.edit.label, all.clone.label, all.move.label, all.remove.label, all.send?.label]).toEqual(['item.edit', 'item.clone', 'item.move', 'item.delete', 'item.send'].map((key) => i18n.t(key as 'item.edit')))
	})
})
