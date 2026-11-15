/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pins the secret list's page actions: their ids, test ids, disabled
 * states and what each one runs.
 */

import { describe, expect, it, vi } from 'vitest'
import SecretList from '../../src/views/SecretList.vue'

function toolbar(state) {
	const context = {
		newFolderLabel: 'New vault',
		selectedFolderId: null,
		offlineReadOnly: false,
		vaultLocked: false,
		credentialRequestOpen: false,
		teamFolderOpen: false,
		openCreateFolder: vi.fn(),
		openImport: vi.fn(),
		...state,
	}
	return { context, items: SecretList.computed.toolbarItems.call(context) }
}

const shape = (items) => items.map(({ id, label, disabled, testid }) => ({ id, label, disabled, testid }))

describe('SecretList toolbarItems', () => {
	it('at the top level offers a new vault, a credential request and an import', () => {
		const { items } = toolbar()
		expect(shape(items)).toEqual([
			{ id: 'new-folder', label: 'New vault', disabled: false, testid: 'open-create-folder' },
			{ id: 'credential-request', label: 'Ask for a credential', disabled: false, testid: 'open-credential-request' },
			{ id: 'import', label: 'Import', disabled: false, testid: 'import-secrets' },
		])
		expect(items.every((item) => item.icon)).toBe(true)
	})

	it('in a folder adds team sharing, with the folder glyph on the first entry', () => {
		const top = toolbar().items
		const { items } = toolbar({ selectedFolderId: 'f-1', newFolderLabel: 'New folder' })
		expect(items.map((item) => item.id)).toEqual(['new-folder', 'credential-request', 'import', 'team-sharing'])
		expect(shape(items)[3]).toEqual({ id: 'team-sharing', label: 'Team sharing', disabled: false, testid: 'team-folder-open' })
		expect(items[0].icon).not.toBe(top[0].icon)
	})

	it.each([
		[{ vaultLocked: true }, [false, true, true, true]],
		[{ offlineReadOnly: true }, [true, true, true, false]],
	])('disables per state %j', (state, disabled) => {
		const { items } = toolbar({ selectedFolderId: 'f-1', ...state })
		expect(items.map((item) => item.disabled)).toEqual(disabled)
	})

	it('runs each entry', () => {
		const { context, items } = toolbar({ selectedFolderId: 'f-1' })
		items.forEach((item) => item.run())
		expect(context.openCreateFolder).toHaveBeenCalledTimes(1)
		expect(context.openImport).toHaveBeenCalledTimes(1)
		expect(context.credentialRequestOpen).toBe(true)
		expect(context.teamFolderOpen).toBe(true)
	})
})
