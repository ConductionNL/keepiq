/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The rendered account recovery section: it loads the policy into the form,
 * shows the active key's fingerprint or a hint that none exists yet, warns
 * after officers were removed, and saves what the form holds.
 *
 * @spec openspec/specs/organisation-account-recovery/spec.md#requirement-administrators-name-recovery-officers-a-threshold-and-a-policy
 */

import axios from '@nextcloud/axios'
import { confirmPassword } from '@nextcloud/password-confirmation'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import AccountRecoverySection from '../../src/components/settings/AccountRecoverySection.vue'

vi.mock('@nextcloud/password-confirmation', () => ({
	confirmPassword: vi.fn(async () => {}),
}))

const API = '/apps/keepiq/api/v1/recovery/admin'

const stubs = {
	CnSettingsSection: { template: '<section><slot /></section>' },
	NcButton: {
		props: ['variant', 'disabled'],
		emits: ['click'],
		template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
	NcNoteCard: { props: ['type'], template: '<div class="note" :data-type="type"><slot /></div>' },
}

/**
 * @param {object} data The settings the server returns.
 * @return {Promise<object>} The loaded section.
 */
async function mountWith(data) {
	vi.spyOn(axios, 'get').mockResolvedValue({ data })
	const wrapper = mount(AccountRecoverySection, { global: { stubs } })
	await flushPromises()
	return wrapper
}

describe('AccountRecoverySection', () => {
	beforeEach(() => {
		confirmPassword.mockReset().mockResolvedValue(undefined)
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('loads the policy, officers and threshold into the form', async () => {
		const wrapper = await mountWith({ policy: 'optional', officers: ['ada', 'bob'], threshold: 2 })

		expect(axios.get).toHaveBeenCalledWith(API)
		expect(wrapper.find('[data-testid="account-recovery-policy"]').element.value).toBe('optional')
		expect(wrapper.find('[data-testid="account-recovery-officers"]').element.value).toBe('ada, bob')
		const threshold = wrapper.find('[data-testid="account-recovery-threshold"]').element
		expect(threshold.value).toBe('2')
		expect(threshold.getAttribute('max')).toBe('2')
	})

	it('shows the active key fingerprint and a retire button', async () => {
		const wrapper = await mountWith({
			policy: 'required',
			officers: ['ada'],
			threshold: 1,
			activeKey: { id: 'k1', fingerprint: 'AB:CD' },
		})

		expect(wrapper.find('[data-testid="account-recovery-fingerprint"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="account-recovery-retire"]').exists()).toBe(true)
		expect(wrapper.text()).not.toContain('No recovery key yet.')
	})

	it('says no key exists yet when recovery is on without one', async () => {
		const wrapper = await mountWith({ policy: 'optional', officers: [], threshold: 1, activeKey: null })

		expect(wrapper.find('[data-testid="account-recovery-fingerprint"]').exists()).toBe(false)
		expect(wrapper.text()).toContain('No recovery key yet. One of the officers creates it in their Keepiq settings.')
	})

	it('shows neither the key nor the hint while recovery is off', async () => {
		const wrapper = await mountWith({ policy: 'off' })

		expect(wrapper.find('[data-testid="account-recovery-fingerprint"]').exists()).toBe(false)
		expect(wrapper.text()).not.toContain('No recovery key yet.')
		expect(wrapper.find('[data-testid="account-recovery-removed"]').exists()).toBe(false)
	})

	it('keeps the defaults when loading fails', async () => {
		vi.spyOn(axios, 'get').mockRejectedValue(new Error('offline'))
		const wrapper = mount(AccountRecoverySection, { global: { stubs } })
		await flushPromises()

		expect(wrapper.vm.policy).toBe('off')
		expect(wrapper.vm.officersText).toBe('')
		expect(wrapper.vm.threshold).toBe(1)
		expect(wrapper.find('.note').exists()).toBe(false)
	})

	it('saves the edited form, trimming officers, and warns about removed ones', async () => {
		const wrapper = await mountWith({ policy: 'off', officers: [], threshold: 1 })
		const put = vi.spyOn(axios, 'put').mockResolvedValue({
			data: { policy: 'required', officers: ['ada', 'cy'], threshold: 2, removed: ['bob'] },
		})

		await wrapper.find('[data-testid="account-recovery-policy"]').setValue('required')
		await wrapper.find('[data-testid="account-recovery-officers"]').setValue(' ada , ,cy ')
		await wrapper.find('[data-testid="account-recovery-threshold"]').setValue('2')
		await wrapper.find('[data-testid="account-recovery-save"]').trigger('click')
		await flushPromises()

		expect(confirmPassword).toHaveBeenCalledTimes(1)
		expect(put).toHaveBeenCalledWith(API, { policy: 'required', officers: ['ada', 'cy'], threshold: 2 })
		const removed = wrapper.find('[data-testid="account-recovery-removed"]')
		expect(removed.exists()).toBe(true)
		expect(removed.attributes('data-type')).toBe('warning')
		expect(wrapper.find('[data-testid="account-recovery-officers"]').element.value).toBe('ada, cy')
	})

	it('shows the server message when saving is refused', async () => {
		const wrapper = await mountWith({ policy: 'optional', officers: ['ada'], threshold: 1 })
		vi.spyOn(axios, 'put').mockRejectedValue({ response: { data: { message: 'Threshold too high' } } })

		await wrapper.find('[data-testid="account-recovery-save"]').trigger('click')
		await flushPromises()

		const note = wrapper.find('.note[data-type="error"]')
		expect(note.text()).toBe('Threshold too high')
		expect(wrapper.find('[data-testid="account-recovery-save"]').attributes('disabled')).toBeUndefined()
	})

	it('retires the key from its button and then shows the no-key hint', async () => {
		const wrapper = await mountWith({
			policy: 'optional',
			officers: ['ada'],
			threshold: 1,
			activeKey: { id: 'k7', fingerprint: 'EF' },
		})
		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: {} })

		await wrapper.find('[data-testid="account-recovery-retire"]').trigger('click')
		await flushPromises()

		expect(post).toHaveBeenCalledWith(`${API}/keys/k7/retire`)
		expect(wrapper.find('[data-testid="account-recovery-fingerprint"]').exists()).toBe(false)
		expect(wrapper.text()).toContain('No recovery key yet.')
	})

	it('keeps the key when retiring fails', async () => {
		const wrapper = await mountWith({
			policy: 'optional',
			officers: ['ada'],
			threshold: 1,
			activeKey: { id: 'k7', fingerprint: 'EF' },
		})
		vi.spyOn(axios, 'post').mockRejectedValue(new Error('Officers must approve'))

		await wrapper.find('[data-testid="account-recovery-retire"]').trigger('click')
		await flushPromises()

		expect(wrapper.vm.activeKey).toEqual({ id: 'k7', fingerprint: 'EF' })
		expect(wrapper.find('.note[data-type="error"]').text()).toBe('Officers must approve')
	})
})
