/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The user's enrolment in organisation account recovery
 * (crypto-organisation-account-recovery D1, D5). Pins: nothing renders while
 * the policy is off, the certificate is verified before its fingerprint is
 * shown, enrol sends the typed master password and is only possible with a
 * password and a key, a current enrolment hides the enrol form, withdraw is
 * offered unless the policy requires enrolment, and failures (including a
 * certificate that does not verify) land in the error note.
 *
 * @spec openspec/specs/organisation-account-recovery/spec.md#requirement-users-enrol-by-wrapping-their-own-key-to-the-recovery-certificate
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import AccountRecoveryEnrolment from '../../src/components/AccountRecoveryEnrolment.vue'
import { useAccountRecoveryStore } from '../../src/store/modules/accountRecovery.js'

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

const substitutingT = {
	methods: {
		t: (_app, key, vars) =>
			Object.entries(vars || {}).reduce(
				(acc, [name, value]) => acc.replace(`{${name}}`, value),
				key,
			),
	},
}

const stubs = {
	NcButton: {
		props: ['variant', 'disabled'],
		emits: ['click'],
		template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
	NcNoteCard: {
		props: ['type'],
		template: '<div class="note" :data-type="type"><slot /></div>',
	},
	NcPasswordField: {
		props: ['modelValue', 'label', 'disabled'],
		emits: ['update:modelValue'],
		template:
			'<input class="password" :disabled="disabled" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
	},
}

const KEY = { certificate: 'PEM', fingerprint: 'AB:CD', caChain: [] }

describe('AccountRecoveryEnrolment', () => {
	let store

	/**
	 * Make fetchStatus resolve to (and store) the given status.
	 *
	 * @param {object|null} status The enrolment status.
	 */
	function withStatus(status) {
		store.fetchStatus = vi.fn().mockImplementation(async () => {
			store.status = status
			return status
		})
	}

	/**
	 * Mount the component and let its created hook settle.
	 *
	 * @return {Promise<object>} The wrapper.
	 */
	async function mountEnrolment() {
		const wrapper = mount(AccountRecoveryEnrolment, {
			global: { stubs, mixins: [substitutingT] },
		})
		await flush()
		await flush()
		return wrapper
	}

	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
		store = useAccountRecoveryStore()
		store.verifyKey = vi.fn().mockResolvedValue({ fingerprint: 'AB:CD' })
		store.enrol = vi.fn().mockResolvedValue()
		store.withdraw = vi.fn().mockResolvedValue()
	})

	it('renders nothing while the organisation policy is off', async () => {
		withStatus({ policy: 'off', enrolled: false })
		const wrapper = await mountEnrolment()

		expect(wrapper.find('[data-testid="recovery-enrolment"]').exists()).toBe(
			false,
		)
		expect(store.verifyKey).not.toHaveBeenCalled()
	})

	it('verifies the certificate and shows its fingerprint to an unenrolled user', async () => {
		withStatus({ policy: 'optional', enrolled: false, key: KEY })
		const wrapper = await mountEnrolment()

		expect(store.verifyKey).toHaveBeenCalledWith(KEY)
		expect(
			wrapper.find('[data-testid="recovery-enrolment-fingerprint"]').text(),
		).toBe('Recovery key fingerprint: AB:CD')
		expect(
			wrapper.find('[data-testid="recovery-enrolment-enrolled"]').exists(),
		).toBe(false)
		expect(wrapper.text()).toContain('Enrol so your organisation can help you')
		expect(wrapper.find('[data-testid="recovery-withdraw"]').exists()).toBe(
			false,
		)
	})

	it('shows no fingerprint and blocks enrol when there is no key', async () => {
		withStatus({ policy: 'optional', enrolled: false })
		const wrapper = await mountEnrolment()

		expect(store.verifyKey).not.toHaveBeenCalled()
		expect(
			wrapper.find('[data-testid="recovery-enrolment-fingerprint"]').exists(),
		).toBe(false)
		await wrapper.find('input.password').setValue('hunter2hunter2')
		expect(
			wrapper.find('[data-testid="recovery-enrol"]').attributes('disabled'),
		).toBeDefined()
	})

	it('shows the error when the certificate does not verify', async () => {
		withStatus({ policy: 'optional', enrolled: false, key: KEY })
		store.verifyKey.mockRejectedValue(new Error('Not issued by this Keepiq'))
		const wrapper = await mountEnrolment()

		expect(wrapper.find('.note').text()).toBe('Not issued by this Keepiq')
		expect(
			wrapper.find('[data-testid="recovery-enrolment-fingerprint"]').exists(),
		).toBe(false)
	})

	it('enables enrol only once a master password is typed', async () => {
		withStatus({ policy: 'optional', enrolled: false, key: KEY })
		const wrapper = await mountEnrolment()
		const button = wrapper.find('[data-testid="recovery-enrol"]')

		expect(button.attributes('disabled')).toBeDefined()
		await wrapper.find('input.password').setValue('correct horse')
		expect(button.attributes('disabled')).toBeUndefined()
	})

	it('enrols with the typed master password and clears the field', async () => {
		withStatus({ policy: 'optional', enrolled: false, key: KEY })
		const wrapper = await mountEnrolment()

		await wrapper.find('input.password').setValue('correct horse')
		await wrapper.find('[data-testid="recovery-enrol"]').trigger('click')
		await flush()

		expect(store.enrol).toHaveBeenCalledWith('correct horse')
		expect(wrapper.find('input.password').element.value).toBe('')
		expect(wrapper.vm.busy).toBe(false)
		expect(wrapper.find('.note').exists()).toBe(false)
	})

	it('keeps the password and shows the server message when enrol fails', async () => {
		withStatus({ policy: 'optional', enrolled: false, key: KEY })
		store.enrol.mockRejectedValue({
			response: { data: { message: 'Wrong master password' } },
		})
		const wrapper = await mountEnrolment()

		await wrapper.find('input.password').setValue('wrong')
		await wrapper.find('[data-testid="recovery-enrol"]').trigger('click')
		await flush()

		expect(wrapper.find('.note').text()).toBe('Wrong master password')
		expect(wrapper.find('input.password').element.value).toBe('wrong')
		expect(wrapper.vm.busy).toBe(false)
	})

	it('disables the form while enrolling', async () => {
		withStatus({ policy: 'optional', enrolled: false, key: KEY })
		let release
		store.enrol.mockReturnValue(new Promise((resolve) => (release = resolve)))
		const wrapper = await mountEnrolment()

		await wrapper.find('input.password').setValue('correct horse')
		await wrapper.find('[data-testid="recovery-enrol"]').trigger('click')

		expect(
			wrapper.find('[data-testid="recovery-enrol"]').attributes('disabled'),
		).toBeDefined()
		expect(wrapper.find('input.password').attributes('disabled')).toBeDefined()
		release()
		await flush()
		expect(wrapper.find('input.password').attributes('disabled')).toBeUndefined()
	})

	it('hides the enrol form for a current enrolment and offers withdraw', async () => {
		withStatus({ policy: 'optional', enrolled: true, current: true, key: KEY })
		const wrapper = await mountEnrolment()

		expect(
			wrapper.find('[data-testid="recovery-enrolment-enrolled"]').exists(),
		).toBe(true)
		expect(wrapper.find('[data-testid="recovery-enrol"]').exists()).toBe(false)
		expect(wrapper.find('input.password').exists()).toBe(false)
		expect(wrapper.find('[data-testid="recovery-withdraw"]').exists()).toBe(
			true,
		)
	})

	it('offers re-enrolment when the enrolment is not to the current key', async () => {
		withStatus({ policy: 'optional', enrolled: true, current: false, key: KEY })
		const wrapper = await mountEnrolment()

		expect(wrapper.find('[data-testid="recovery-enrol"]').exists()).toBe(true)
	})

	it('does not offer withdraw when the policy requires enrolment', async () => {
		withStatus({ policy: 'required', enrolled: true, current: true, key: KEY })
		const wrapper = await mountEnrolment()

		expect(wrapper.find('[data-testid="recovery-withdraw"]').exists()).toBe(
			false,
		)
	})

	it('withdraws through the store', async () => {
		withStatus({ policy: 'optional', enrolled: true, current: true, key: KEY })
		const wrapper = await mountEnrolment()

		await wrapper.find('[data-testid="recovery-withdraw"]').trigger('click')
		await flush()

		expect(store.withdraw).toHaveBeenCalledTimes(1)
		expect(wrapper.vm.busy).toBe(false)
		expect(wrapper.find('.note').exists()).toBe(false)
	})

	it('shows the error when withdraw fails', async () => {
		withStatus({ policy: 'optional', enrolled: true, current: true, key: KEY })
		store.withdraw.mockRejectedValue(new Error('Network down'))
		const wrapper = await mountEnrolment()

		await wrapper.find('[data-testid="recovery-withdraw"]').trigger('click')
		await flush()

		expect(wrapper.find('.note').text()).toBe('Network down')
		expect(wrapper.vm.busy).toBe(false)
	})
})
