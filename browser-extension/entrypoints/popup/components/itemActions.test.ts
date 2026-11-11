import { i18n } from '#i18n'
import { describe, expect, it, vi } from 'vitest'
import type { ItemMeta, TypeMeta } from '@/src/messages'
import { language } from '../i18n'
import { itemActions } from './ItemCard'

const ITEM: ItemMeta = {
	id: 'i1', name: 'Mail', url: null, typeId: 't', folderId: null, hasLogin: true, blocked: false,
	createdAt: '2026-01-01T00:00:00Z', updatedAt: '2026-01-01T00:00:00Z', expiresAt: null,
	useOnly: false, readOnly: false, accessExpiresAt: null,
}
const type = (name: string): TypeMeta => ({ id: 't', name, label: name })
const handlers = () => ({ add: vi.fn(), edit: vi.fn(), clone: vi.fn(), move: vi.fn(), remove: vi.fn(), send: vi.fn() })

/** Each action's refusal, `undefined` when it is allowed and `null` when the action is absent. */
function reasons(item: Partial<ItemMeta>, typeName: string | undefined, offline: boolean, viewOnly?: boolean) {
	const all = itemActions({ ...ITEM, ...item }, typeName === undefined ? undefined : type(typeName), offline, handlers(), viewOnly)
	return Object.fromEntries(Object.entries(all).map(([key, action]) => [key, action === undefined ? null : action.disabledReason]))
}

describe('itemActions', () => {
	it('allows everything on an own login online, and offers send for logins only', () => {
		expect(reasons({}, 'login', false)).toEqual({ edit: undefined, clone: undefined, move: undefined, remove: undefined, send: undefined })
		expect(reasons({}, 'note', false).send).toBeNull()
		expect(reasons({}, undefined, false).send).toBeNull()
	})

	it('refuses what needs the server while offline, but not edit', () => {
		expect(reasons({}, 'login', true)).toEqual({
			edit: undefined, clone: i18n.t('form.offline'), move: i18n.t('form.offline'), remove: i18n.t('form.offline'), send: i18n.t('send.offline'),
		})
	})

	it('puts a blocked item before every share limit', () => {
		const r = reasons({ blocked: true, useOnly: true, readOnly: true }, 'login', true, true)
		expect(r).toEqual({
			edit: i18n.t('blocked.generic'), clone: i18n.t('blocked.generic'), move: i18n.t('item.useOnly'), remove: i18n.t('form.offline'), send: i18n.t('blocked.generic'),
		})
	})

	it('refuses cloning a passkey before anything else', () => {
		expect(reasons({ blocked: true }, 'passkey', true).clone).toBe(i18n.t('item.passkeyNoClone'))
	})

	it('orders read-only, view-only and expiring access', () => {
		expect(reasons({ readOnly: true }, 'login', false, true)).toEqual({
			edit: i18n.t('item.readOnly'), clone: i18n.t('item.readOnly'), move: undefined, remove: undefined, send: undefined,
		})
		expect(reasons({}, 'login', false, true).edit).toBe(i18n.t('item.viewOnly'))
		const at = '2030-05-06T00:00:00Z'
		const date = new Date(at).toLocaleDateString(language())
		expect(reasons({ accessExpiresAt: at }, 'login', true)).toEqual({
			edit: undefined, clone: i18n.t('item.expiringNoClone', { date }), move: i18n.t('form.offline'), remove: i18n.t('form.offline'), send: i18n.t('item.expiringNoSend', { date }),
		})
	})

	it('hands each action its item', () => {
		const actions = handlers()
		const all = itemActions(ITEM, type('login'), false, actions)
		all.edit.onSelect()
		all.clone.onSelect()
		all.move.onSelect()
		all.remove.onSelect()
		all.send!.onSelect()
		expect(actions.edit).toHaveBeenCalledWith('i1')
		expect(actions.clone).toHaveBeenCalledWith('i1')
		expect(actions.move).toHaveBeenCalledWith(ITEM)
		expect(actions.remove).toHaveBeenCalledWith(ITEM)
		expect(actions.send).toHaveBeenCalledWith(ITEM)
	})
})
