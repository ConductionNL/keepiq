/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A certificate in the detail sidebar: the certificate shows at once with its
 * line breaks, its private key and passphrase stay masked until revealed, and
 * a member the person added shows as a label above its value.
 *
 * @spec openspec/changes/vault-builtin-type-fields/specs/builtin-type-fields/spec.md#requirement-the-detail-sidebar-shows-the-fields-of-the-type
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import SecretDetailSidebar from '../../src/components/SecretDetailSidebar.vue'
import { useSecretStore } from '../../src/store/modules/secret.js'
import { useSecretTypeStore } from '../../src/store/modules/secretType.js'

const PEM = '-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----'

const stubs = {
	NcAppSidebar: { template: '<aside><slot /></aside>' },
	NcButton: { template: '<button><slot /></button>' },
	NcEmptyContent: { template: '<div><slot /></div>' },
	NcNoteCard: { template: '<div><slot /></div>' },
	NcActions: { template: '<div><slot /></div>' },
	NcActionButton: { template: '<button><slot /></button>' },
	CopyButton: { template: '<button />' },
	PasswordField: {
		name: 'PasswordField',
		props: ['label', 'multiline', 'alwaysShown', 'useOnly', 'resolve'],
		template:
			'<div class="password-field-stub" :data-label="label" :data-multiline="String(!!multiline)" :data-always-shown="String(!!alwaysShown)" />',
	},
	ShareList: { template: '<div />' },
}

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

/**
 * Mount the sidebar over a secret of a type.
 *
 * @param {string} typeId The secret's type.
 * @param {object} additionalFields The decrypted members.
 * @return {Promise<object>} The wrapper.
 */
async function mountOn(typeId, additionalFields) {
	useSecretStore().fetchSecret = vi.fn().mockResolvedValue({
		id: 's-1',
		name: 'example.org',
		typeId,
		key: PEM,
		additionalFields,
	})
	const wrapper = mount(SecretDetailSidebar, {
		props: { secretId: 's-1' },
		global: { stubs },
	})
	await flush()
	await flush()
	return wrapper
}

/**
 * The masked fields the sidebar renders, by label.
 *
 * @param {object} wrapper The sidebar.
 * @return {object} Their multiline and always-shown flags by label.
 */
function fieldsByLabel(wrapper) {
	return Object.fromEntries(
		wrapper.findAll('.password-field-stub').map((f) => [
			f.attributes('data-label'),
			{
				multiline: f.attributes('data-multiline') === 'true',
				alwaysShown: f.attributes('data-always-shown') === 'true',
			},
		]),
	)
}

describe('SecretDetailSidebar built-in type fields', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
		window.OC = { currentUser: 'alice' }
		const typeStore = useSecretTypeStore()
		typeStore.types = [
			{ id: 't-certificate', name: 'certificate', label: 'Certificate' },
			{ id: 't-login', name: 'login', label: 'Login' },
		]
		typeStore.fetchTypes = vi.fn().mockResolvedValue()
	})

	it('shows a certificate at once and masks its private key and passphrase', async () => {
		const wrapper = await mountOn('t-certificate', {
			'Private key': 'PRIVATE',
			Passphrase: 'secret',
			'serial-note': 'line one\nline two',
		})

		expect(fieldsByLabel(wrapper)).toEqual({
			Certificate: { multiline: true, alwaysShown: true },
			'Private key': { multiline: true, alwaysShown: false },
			Passphrase: { multiline: false, alwaysShown: false },
		})
		expect(wrapper.html()).not.toContain('PRIVATE')
		expect(wrapper.html()).not.toContain('>secret<')

		// Reveal and copy both run through the resolver, so it must work as
		// rendered by the template, not only as a function in a test.
		const resolved = {}
		for (const field of wrapper.findAllComponents({ name: 'PasswordField' })) {
			if (field.props('label') !== 'Certificate') {
				resolved[field.props('label')] = await field.props('resolve')()
			}
		}
		expect(resolved).toEqual({ 'Private key': 'PRIVATE', Passphrase: 'secret' })

		// The type's fields sit in the value's box; only the member the
		// person added is listed under Additional fields.
		expect(
			wrapper.findAll('[data-testid="secret-detail-type-field"]'),
		).toHaveLength(2)
		const own = wrapper.findAll('.secret-detail__extra-item')
		expect(own).toHaveLength(1)
		expect(own[0].find('dt').text()).toBe('serial-note')
		expect(own[0].find('dd').element.textContent).toBe('line one\nline two')
	})

	it('shows no additional fields box when only the type fields hold values', async () => {
		const wrapper = await mountOn('t-certificate', { 'Private key': 'PRIVATE' })

		expect(
			wrapper.findAll('[data-testid="secret-detail-type-field"]'),
		).toHaveLength(1)
		expect(wrapper.text()).not.toContain('Additional fields')
	})

	it('shows a plain field of the type as a labelled row', async () => {
		useSecretTypeStore().types.push({
			id: 't-database',
			name: 'database',
			label: 'Database',
		})
		const wrapper = await mountOn('t-database', {
			Port: '5432',
			Host: 'db.example.org',
		})

		// In the type's order, not the stored order.
		expect(
			wrapper
				.findAll('[data-testid="secret-detail-type-field"]')
				.map((row) => row.find('.secret-detail__row-label').text()),
		).toEqual(['Host', 'Port'])
		expect(wrapper.text()).toContain('db.example.org')
		expect(wrapper.text()).not.toContain('Additional fields')
	})

	it('keeps the generic masked value on a login', async () => {
		const wrapper = await mountOn('t-login', {})

		expect(fieldsByLabel(wrapper)).toEqual({
			Password: { multiline: false, alwaysShown: false },
		})
	})
})
