/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The card number row of the detail sidebar: labelled with the derived
 * brand, masked and revealed in the brand's grouping.
 *
 * @spec openspec/changes/vault-card-brand-formats/specs/card-identity-items/spec.md#requirement-type-specific-presentation-and-masked-reveal
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

// The setup's `t` returns the key as is, and the sidebar binds the global `t` on import; the brand label needs its placeholder filled.
vi.hoisted(() => {
	globalThis.t = (_app, key, vars) => key.replace(/{(\w+)}/g, (match, name) => vars?.[name] ?? match)
})

import SecretDetailSidebar from '../../src/components/SecretDetailSidebar.vue'
import { useSecretStore } from '../../src/store/modules/secret.js'
import { useSecretTypeStore } from '../../src/store/modules/secretType.js'

const stubAll = {
	NcAppSidebar: { template: '<aside><slot /></aside>' },
	NcButton: { template: '<button><slot /></button>' },
	NcEmptyContent: { template: '<div><slot /></div>' },
	NcNoteCard: { template: '<div><slot /></div>' },
	NcActions: { template: '<div><slot /></div>' },
	NcActionButton: { template: '<button><slot /></button>' },
	CopyButton: { template: '<button />' },
	PasswordField: { template: '<input />' },
	ShareList: { template: '<div />' },
}

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

/**
 * Mount the detail sidebar over a card with the given number.
 *
 * @param {string} number The decrypted card number.
 * @return {Promise<object>} The mounted wrapper.
 */
async function mountCard(number) {
	useSecretTypeStore().types = [{ id: 'type-card', name: 'card', scope: 'system' }]
	useSecretTypeStore().fetchTypes = vi.fn().mockResolvedValue([])
	useSecretStore().fetchSecret = vi.fn().mockResolvedValue({
		id: 's-1',
		name: 'Card',
		typeId: 'type-card',
		key: JSON.stringify({ number, expiry: '', cvv: '', pin: '', cardholder: '' }),
		additionalFields: null,
	})
	const wrapper = mount(SecretDetailSidebar, {
		props: { secretId: 's-1' },
		global: { stubs: stubAll },
	})
	await flush()
	await flush()
	return wrapper
}

const numberRow = (wrapper) => wrapper.get('[data-testid="card-number-value"]').element.closest('.secret-detail__row')

describe('SecretDetailSidebar — card number', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
		window.OC = { currentUser: 'alice' }
	})

	it('labels, masks and reveals an American Express number 4-6-5', async () => {
		const wrapper = await mountCard('378282246310005')

		expect(numberRow(wrapper).textContent).toContain('Number · American Express')
		expect(wrapper.get('[data-testid="card-number-value"]').text()).toBe('3782 •••••• •0005')

		wrapper.vm.cardRevealed.number = true
		await wrapper.vm.$nextTick()
		expect(wrapper.get('[data-testid="card-number-value"]').text()).toBe('3782 822463 10005')
	})

	it('groups a 14-digit Diners Club number 4-6-4', async () => {
		const wrapper = await mountCard('30569309025904')

		expect(wrapper.get('[data-testid="card-number-value"]').text()).toBe('3056 •••••• 5904')
	})

	it('shows no brand for an unknown prefix', async () => {
		const wrapper = await mountCard('6999 1234 1234 1234')

		expect(numberRow(wrapper).textContent).not.toContain('·')
		expect(wrapper.get('[data-testid="card-number-value"]').text()).toBe('6999 •••• •••• 1234')
	})
})
