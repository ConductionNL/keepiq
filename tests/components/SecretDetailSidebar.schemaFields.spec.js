/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Custom fields with a schema in the detail sidebar: a hidden value stays out
 * of the page until revealed, a URL is a link, an object opens in the JSON
 * viewer, a value that does not fit shows a warning, and the `@schema` member
 * itself is never listed.
 *
 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-each-schema-has-its-own-input-and-display
 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-any-custom-field-can-be-hidden
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import SecretDetailSidebar from '../../src/components/SecretDetailSidebar.vue'
import { useSecretStore } from '../../src/store/modules/secret.js'
import { useSecretTypeStore } from '../../src/store/modules/secretType.js'

const stubs = {
	NcAppSidebar: { template: '<aside><slot /></aside>' },
	NcButton: { template: '<button><slot /></button>' },
	NcEmptyContent: { template: '<div><slot /></div>' },
	NcNoteCard: { template: '<div><slot /></div>' },
	NcActions: { template: '<div><slot /></div>' },
	NcActionButton: { template: '<button><slot /></button>' },
	CopyButton: { template: '<button class="copy-stub" />' },
	PasswordField: {
		name: 'PasswordField',
		props: ['label', 'multiline', 'resolve'],
		template: '<div class="password-field-stub" :data-label="label" />',
	},
	ShareList: { template: '<div />' },
}

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

/**
 * Mount the sidebar over a login whose additional fields are `blob`.
 *
 * @param {object} blob The decrypted additional fields.
 * @return {Promise<object>} The wrapper.
 */
async function mountWith(blob) {
	useSecretStore().fetchSecret = vi.fn().mockResolvedValue({
		id: 's-1',
		name: 'example.org',
		typeId: 't-login',
		key: 'pw',
		additionalFields: blob,
	})
	const wrapper = mount(SecretDetailSidebar, {
		props: { secretId: 's-1' },
		global: { stubs },
	})
	await flush()
	await flush()
	return wrapper
}

describe('SecretDetailSidebar custom field schemas', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
		window.OC = { currentUser: 'alice' }
		const typeStore = useSecretTypeStore()
		typeStore.types = [{ id: 't-login', name: 'login', label: 'Login' }]
		typeStore.fetchTypes = vi.fn().mockResolvedValue()
	})

	it('shows each field the way its schema says', async () => {
		const wrapper = await mountWith({
			Webhook: 'https://hooks.example.org/x',
			Account: '{"type":"service_account"}',
			Enabled: 'true',
			'@schema': {
				Webhook: { type: 'string', format: 'uri' },
				Account: { type: 'object' },
				Enabled: { type: 'boolean' },
			},
		})

		const names = wrapper
			.findAll('.secret-detail__extra-item dt')
			.map((dt) => dt.text())
		expect(names).toEqual(['Webhook', 'Account', 'Enabled'])
		expect(wrapper.text()).not.toContain('@schema')

		expect(wrapper.find('a[href="https://hooks.example.org/x"]').exists()).toBe(
			true,
		)
		expect(wrapper.find('[data-stub="CnJsonViewer"]').text()).toBe(
			'{"type":"service_account"}',
		)
		expect(wrapper.text()).toContain('Yes')
	})

	it('shows a URL of another scheme as text, not as a link', async () => {
		const wrapper = await mountWith({
			Webhook: 'javascript:alert(1)',
			'@schema': { Webhook: { type: 'string', format: 'uri' } },
		})

		expect(wrapper.find('a[href^="javascript:"]').exists()).toBe(false)
		expect(wrapper.text()).toContain('javascript:alert(1)')
	})

	it('keeps a hidden value out of the page until it is revealed', async () => {
		const wrapper = await mountWith({
			PIN: '481516',
			'@schema': { PIN: { type: 'string', hidden: true } },
		})

		expect(wrapper.html()).not.toContain('481516')
		// The first masked field is the login's own password.
		const field = wrapper
			.findAllComponents({ name: 'PasswordField' })
			.find((f) => f.props('label') === 'PIN')
		expect(await field.props('resolve')()).toBe('481516')
	})

	it('warns about a value that does not fit its schema', async () => {
		const wrapper = await mountWith({
			Port: 'n/a',
			'@schema': { Port: { type: 'integer' } },
		})

		expect(wrapper.find('[data-testid="schema-value-misfit"]').exists()).toBe(
			true,
		)
		expect(wrapper.text()).toContain('n/a')
	})
})
