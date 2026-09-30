/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Item types admin section lists the global types an administrator
 * manages, and opens the editor and the delete confirmation (admin-18).
 *
 * @spec openspec/specs/admin-secret-types/spec.md#requirement-item-type-definitions
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ItemTypesSection from '../../src/components/settings/ItemTypesSection.vue'
import { useSecretTypeStore } from '../../src/store/modules/secretType.js'

const stubs = {
	CnSettingsSection: {
		props: ['name', 'description'],
		template: '<section><slot /></section>',
	},
	NcButton: {
		props: ['variant'],
		template: '<button @click="$emit(\'click\')"><slot /></button>',
	},
	NcNoteCard: { props: ['type'], template: '<div><slot /></div>' },
	Plus: { template: '<i />' },
	SecretTypeEditorDialog: {
		props: ['open', 'type'],
		template: '<div data-testid="editor" />',
	},
	SecretTypeDeleteDialog: {
		props: ['open', 'type'],
		template: '<div data-testid="deleter" />',
	},
}

describe('ItemTypesSection', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		const store = useSecretTypeStore()
		store.types = [
			{
				id: 'type-login',
				name: 'login',
				label: 'Login',
				scope: 'system',
				fields: [],
			},
			{
				id: 'type-mine',
				name: 'mine',
				label: 'Mine',
				scope: 'user',
				fields: [],
			},
			{
				id: 'type-server',
				name: 'server-access',
				label: 'Server access',
				scope: 'global',
				fields: [
					{ key: 'host', label: 'Host', kind: 'url', required: true },
				],
			},
		]
		store.fetchTypes = vi.fn().mockResolvedValue()
	})

	it('lists only the global types, with their field count', async () => {
		const wrapper = mount(ItemTypesSection, { global: { stubs } })
		await wrapper.vm.$nextTick()

		expect(
			wrapper.find('[data-testid="item-type-server-access"]').text(),
		).toContain('Fields: 1')
		expect(wrapper.find('[data-testid="item-type-login"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="item-type-mine"]').exists()).toBe(false)
	})

	it('opens the editor for a new type', async () => {
		const wrapper = mount(ItemTypesSection, { global: { stubs } })
		await wrapper.find('[data-testid="item-types-new"]').trigger('click')

		expect(wrapper.find('[data-testid="editor"]').exists()).toBe(true)
	})
})
