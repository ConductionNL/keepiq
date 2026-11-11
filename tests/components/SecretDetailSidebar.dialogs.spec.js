/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The edit and move dialogs the detail sidebar opens: which modal, with
 * which ids, and that a save reloads the detail and then the list behind
 * it. Options-object style, like SecretList.defaultView.spec.js.
 *
 * @spec openspec/specs/secrets-write-ui/spec.md#requirement-edit-a-secret-from-the-ui
 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
 */

import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import SecretDetailSidebar from '../../src/components/SecretDetailSidebar.vue'

let events

/**
 * @param {object|null} secret The loaded secret.
 * @return {object} A sidebar instance with the modal opener, load and list refresh recorded.
 */
function sidebar(secret) {
	events = []
	const ctx = Object.assign({}, SecretDetailSidebar.methods)
	return Object.assign(ctx, {
		secretId: 's1',
		secret,
		cnOpenModal: vi.fn(),
		load: vi.fn(() => events.push('load')),
		refreshList: vi.fn(() => events.push('refreshList')),
	})
}

beforeEach(() => {
	setActivePinia(createPinia())
})

describe('SecretDetailSidebar dialogs', () => {
	it('opens the edit dialog for the secret; a save reloads the detail, then the list', () => {
		const ctx = sidebar({ id: 's1', folderId: 'f1' })
		ctx.openEdit()
		const [name, props] = ctx.cnOpenModal.mock.calls[0]
		expect(name).toBe('secret-edit')
		expect(Object.keys(props)).toEqual(['secretId', 'onSaved'])
		expect(props.secretId).toBe('s1')
		expect(events).toEqual([])
		props.onSaved()
		expect(events).toEqual(['load', 'refreshList'])
	})

	it('opens the move dialog with the current folder; a save reloads the detail, then the list', () => {
		const ctx = sidebar({ id: 's1', folderId: 'f1' })
		ctx.openMove()
		const [name, props] = ctx.cnOpenModal.mock.calls[0]
		expect(name).toBe('secret-move')
		expect(Object.keys(props)).toEqual(['subject', 'secretId', 'currentFolderId', 'onSaved'])
		expect(props).toMatchObject({ subject: 'secret', secretId: 's1', currentFolderId: 'f1' })
		props.onSaved()
		expect(events).toEqual(['load', 'refreshList'])
	})

	it('moves a secret without a folder, or not yet loaded, from no folder', () => {
		for (const secret of [{ id: 's1', folderId: '' }, null]) {
			const ctx = sidebar(secret)
			ctx.openMove()
			expect(ctx.cnOpenModal.mock.calls[0][1].currentFolderId).toBe(null)
		}
	})
})
