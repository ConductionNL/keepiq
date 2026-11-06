/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The table view's Name cell marks a row that holds a one-time code or
 * passkeys, as the list and card rows do (keepiq#1326).
 *
 * @spec openspec/changes/vault-login-totp-codes/specs/login-one-time-codes/spec.md#requirement-a-login-can-carry-its-own-totp-seed
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'
import SecretList from '../../src/views/SecretList.vue'
import { useSecretStore } from '../../src/store/modules/secret.js'

/** Renders only the table's Name cell for each object. */
const CnIndexPage = {
	props: ['objects'],
	render() {
		return h(
			'div',
			(this.objects ?? []).map((row) =>
				h('div', { class: 'name-cell', 'data-id': row.id }, [
					this.$slots['column-name']?.({ row }),
				]),
			),
		)
	},
}

beforeEach(() => {
	setActivePinia(createPinia())
	vi.restoreAllMocks()
})

describe('SecretList table Name cell', () => {
	it('marks the rows holding a code or passkeys, and only those', async () => {
		const store = useSecretStore()
		store.fetchSecrets = vi.fn().mockResolvedValue()
		const wrapper = mount(SecretList, {
			shallow: true,
			global: {
				stubs: { CnIndexPage, LoginExtrasMarkers: false },
				mocks: {
					$route: { query: {}, params: {}, name: 'secrets' },
					$router: { push: vi.fn(), replace: vi.fn() },
				},
			},
		})
		store.secrets = [
			{ id: 'a', name: 'Bank', totp: 'CIPHER-T', passkeys: 'CIPHER-P' },
			{ id: 'b', name: 'Mail', totp: null, passkeys: '' },
		]
		wrapper.vm.folderSwitching = false
		await wrapper.vm.$nextTick()

		const bank = wrapper.find('.name-cell[data-id="a"]')
		const mail = wrapper.find('.name-cell[data-id="b"]')
		expect(bank.find('[data-testid="login-marker-totp"]').exists()).toBe(true)
		expect(bank.find('[data-testid="login-marker-passkeys"]').exists()).toBe(
			true,
		)
		expect(mail.find('[data-testid="login-marker-totp"]').exists()).toBe(false)
		expect(wrapper.html()).not.toContain('CIPHER-')
	})
})
