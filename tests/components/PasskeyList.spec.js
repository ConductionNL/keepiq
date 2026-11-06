/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A login's passkeys: metadata only, never a private key, and a confirmed
 * Delete that emits the remaining array. A standalone passkey item keeps its
 * masked private key row with reveal and copy.
 *
 * @spec openspec/changes/vault-login-totp-codes/specs/login-one-time-codes/spec.md#requirement-a-login-can-carry-its-own-totp-seed
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import PasskeyDisplay from '../../src/components/PasskeyDisplay.vue'
import PasskeyList from '../../src/components/PasskeyList.vue'

const BANK = {
	credentialId: 'Y3JlZC1iYW5rLTAwMDE',
	rpId: 'bank.example',
	rpName: 'Bank',
	userName: 'alice@example.org',
	userHandle: 'dXNlcg',
	privateKey: 'PRIVATE-KEY-BANK',
	algorithm: -7,
	counter: 3,
	transports: ['internal'],
	createdAt: '2026-09-01T10:00:00Z',
}
const MAIL = {
	...BANK,
	credentialId: 'Y3JlZC1tYWlsLTAwMDI',
	rpId: 'mail.example',
	rpName: 'Mail',
	privateKey: 'PRIVATE-KEY-MAIL',
}

const stubs = {
	NcButton: {
		props: ['disabled'],
		emits: ['click'],
		template:
			'<button :disabled="disabled" @click="$emit(\'click\')"><slot name="icon" /><slot /></button>',
	},
	NcDialog: {
		props: ['name', 'open'],
		template: '<div v-if="open"><slot /><slot name="actions" /></div>',
	},
	CopyButton: {
		props: ['value', 'label'],
		template: '<button :data-value="value" />',
	},
}

/**
 * Mount the list over these credentials.
 *
 * @param {Array<object>} credentials The login's passkeys.
 * @param {object} [props] Other props.
 * @return {object} The wrapper.
 */
function mountList(credentials, props = {}) {
	return mount(PasskeyList, {
		props: { passkeysJson: JSON.stringify(credentials), ...props },
		global: { stubs },
	})
}

describe('PasskeyList', () => {
	it('lists each passkey by site, account and date, never its private key', () => {
		const wrapper = mountList([BANK, MAIL], { canDelete: true })

		expect(wrapper.findAll('[data-testid="passkey-list-item"]')).toHaveLength(2)
		expect(wrapper.find('[data-testid="passkey-list-count"]').text()).toBe(
			'%n passkeys',
		)
		const sites = wrapper.findAll('[data-testid="passkey-site"]')
		expect(sites[0].text()).toBe('Bank (bank.example)')
		expect(wrapper.find('[data-testid="passkey-account"]').text()).toContain(
			'alice@example.org',
		)
		expect(wrapper.find('[data-testid="passkey-created"]').exists()).toBe(true)
		expect(wrapper.html()).not.toContain('PRIVATE-KEY')
		expect(wrapper.find('[data-testid="passkey-private-key"]').exists()).toBe(
			false,
		)
		expect(wrapper.find('[data-testid="passkey-reveal"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="passkey-copy-key"]').exists()).toBe(false)
	})

	it('offers Delete only where the login may be changed', () => {
		expect(
			mountList([BANK]).find('[data-testid="passkey-list-delete"]').exists(),
		).toBe(false)
		expect(
			mountList([BANK], { canDelete: true })
				.find('[data-testid="passkey-list-delete"]')
				.exists(),
		).toBe(true)
	})

	it('asks first, then emits the others exactly as stored', async () => {
		const wrapper = mountList([BANK, MAIL], { canDelete: true })

		await wrapper
			.findAll('[data-testid="passkey-list-delete"]')[0]
			.trigger('click')
		expect(wrapper.emitted('delete')).toBeUndefined()
		expect(wrapper.find('[data-testid="passkey-delete-dialog"]').exists()).toBe(
			true,
		)

		await wrapper.find('[data-testid="passkey-delete-confirm"]').trigger('click')
		expect(wrapper.emitted('delete')).toEqual([[JSON.stringify([MAIL])]])
		expect(wrapper.find('[data-testid="passkey-delete-dialog"]').exists()).toBe(
			false,
		)
	})

	it('keeps entries of another shape when deleting the passkey after them', async () => {
		const wrapper = mountList(['opaque', BANK, 7, MAIL], { canDelete: true })
		expect(wrapper.findAll('[data-testid="passkey-list-item"]')).toHaveLength(2)

		await wrapper
			.findAll('[data-testid="passkey-list-delete"]')[1]
			.trigger('click')
		await wrapper.find('[data-testid="passkey-delete-confirm"]').trigger('click')
		expect(wrapper.emitted('delete')).toEqual([
			[JSON.stringify(['opaque', BANK, 7])],
		])
	})

	it("emits '' when the last passkey is deleted", async () => {
		const wrapper = mountList([BANK], { canDelete: true })

		await wrapper.find('[data-testid="passkey-list-delete"]').trigger('click')
		await wrapper.find('[data-testid="passkey-delete-confirm"]').trigger('click')
		expect(wrapper.emitted('delete')).toEqual([['']])
	})

	it('shows nothing for a value that does not parse', () => {
		const wrapper = mount(PasskeyList, {
			props: { passkeysJson: 'not json' },
			global: { stubs },
		})
		expect(wrapper.findAll('[data-testid="passkey-list-item"]')).toHaveLength(0)
	})
})

describe('PasskeyDisplay of a standalone passkey item', () => {
	it('keeps the masked private key with reveal and copy', async () => {
		const wrapper = mount(PasskeyDisplay, {
			props: { credentialJson: JSON.stringify(BANK) },
			global: { stubs },
		})
		const key = wrapper.find('[data-testid="passkey-private-key"]')
		expect(key.text()).not.toContain('PRIVATE-KEY-BANK')
		await wrapper.find('[data-testid="passkey-reveal"]').trigger('click')
		expect(key.text()).toBe('PRIVATE-KEY-BANK')
		expect(
			wrapper
				.find('[data-testid="passkey-copy-key"]')
				.attributes('data-value'),
		).toBe('PRIVATE-KEY-BANK')
	})

	it('leaves the row out with hidePrivateKey, even when the key is in the JSON', () => {
		const wrapper = mount(PasskeyDisplay, {
			props: { credentialJson: JSON.stringify(BANK), hidePrivateKey: true },
			global: { stubs },
		})
		expect(wrapper.find('[data-testid="passkey-site"]').exists()).toBe(true)
		expect(wrapper.html()).not.toContain('PRIVATE-KEY-BANK')
		expect(wrapper.find('[data-testid="passkey-reveal"]').exists()).toBe(false)
	})
})
