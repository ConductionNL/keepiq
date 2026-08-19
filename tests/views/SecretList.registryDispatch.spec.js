/**
 * SPDX-FileCopyrightText: 2026 Conduction / Doriath Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Write affordances go through the registry dispatcher, not through local state.
 *
 * ADR-036 says a manifest-v2 app opens its dialogs by registry KEY via
 * `cnOpenModal`, which CnAppRoot provides. Nothing verified that: the inject
 * defaults to a no-op so the view still mounts in isolation, which means a
 * regression to local `dialogOpen` flags would look identical in every existing
 * test — the dialog would simply never open, silently, in the real app.
 *
 * Every key asserted here must exist in src/registry.js as a `kind: 'modal'`
 * entry, otherwise the dispatcher is called with a name nothing resolves.
 *
 * @spec openspec/specs/secrets-write-ui/spec.md#requirement-dialogs-honour-modal-isolation-and-registry-dispatch
 */

import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import registry from '../../src/registry.js'
import SecretList from '../../src/views/SecretList.vue'

describe('SecretList — registry dispatch', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
	})

	it('opens the create-secret dialog by registry key, carrying the current folder', () => {
		const cnOpenModal = vi.fn()
		// Called on the options object directly: mounting the whole list view drags
		// in the folder tree, search, sorting and the type catalogue, none of which
		// this scenario is about. What matters is the CALL — which key, which props.
		SecretList.methods.openCreateSecret.call({
			cnOpenModal,
			selectedFolderId: 'folder-42',
			reload: vi.fn(),
		})

		expect(cnOpenModal).toHaveBeenCalledTimes(1)
		const [key, props] = cnOpenModal.mock.calls[0]
		expect(key).toBe('secret-create')
		expect(props.folderId).toBe('folder-42')
		expect(typeof props.onSaved).toBe('function')
	})

	it('opens the create-folder dialog by registry key', () => {
		const cnOpenModal = vi.fn()
		SecretList.methods.openCreateFolder.call(
			{ cnOpenModal, selectedFolderId: null, reload: vi.fn() },
			{},
		)

		expect(cnOpenModal.mock.calls[0][0]).toBe('folder-create')
	})

	it('dispatches only keys the registry actually registers as modals', () => {
		// The dispatcher resolves by name, so a typo produces a click that does
		// nothing at all rather than an error.
		for (const key of ['secret-create', 'folder-create', 'secret-edit']) {
			expect(registry[key]).toBeDefined()
			expect(registry[key].kind).toBe('modal')
			expect(registry[key].component).toBeDefined()
		}
	})

	it('defaults the dispatcher to a no-op so the view survives in isolation', () => {
		// Documented behaviour of the inject, and the reason a regression here is
		// invisible: calling it without CnAppRoot must not throw.
		const fallback = SecretList.inject.cnOpenModal.default()

		expect(typeof fallback).toBe('function')
		expect(() => fallback('secret-create', {})).not.toThrow()
	})
})
