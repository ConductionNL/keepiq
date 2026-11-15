/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * "Forgot your master password?" on the lock screen
 * (crypto-organisation-account-recovery D3, D4). Pins: only an enrolled
 * user under an active policy sees it, an open request is resumed on load,
 * a device-purpose request is left to the device-approval block, start
 * files a request and shows its phrase, a request from another browser
 * shows the warning instead, the new-password form appears once the key is
 * back and enforces the minimum length and match, complete sends the new
 * password and emits `recovered` with the handling officer, and errors land
 * in the error line.
 *
 * @spec openspec/specs/organisation-account-recovery/spec.md#requirement-a-recovery-request-carries-a-one-time-key-and-a-verification-phrase
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ForgotPasswordRecovery from '../../src/components/ForgotPasswordRecovery.vue'
import { useAccountRecoveryStore } from '../../src/store/modules/accountRecovery.js'

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

const stubs = {
	NcButton: {
		props: ['variant', 'disabled', 'wide'],
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
			'<input class="password" :data-label="label" :disabled="disabled" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
	},
}

const PENDING = {
	id: 'r1',
	purpose: 'password',
	status: 'pending',
	phrase: 'apple banana cherry',
	fromThisBrowser: true,
}

const READY = { ...PENDING, status: 'approved', sealedResult: 'sealed' }

describe('ForgotPasswordRecovery', () => {
	let store

	/**
	 * Mount the component and let its created hook settle.
	 *
	 * @param {object} props Props.
	 * @return {Promise<object>} The wrapper.
	 */
	async function mountForgot(props = {}) {
		const wrapper = mount(ForgotPasswordRecovery, {
			props,
			global: { stubs },
		})
		await flush()
		await flush()
		return wrapper
	}

	/**
	 * Make fetchMyRequest resolve to (and store) the given requests in turn.
	 *
	 * @param {...object} requests The requests, last one repeating.
	 */
	function withRequests(...requests) {
		let i = 0
		store.fetchMyRequest = vi.fn().mockImplementation(async () => {
			const request = requests[Math.min(i++, requests.length - 1)]
			store.myRequest = request
			return request
		})
	}

	/**
	 * Find the password inputs by label.
	 *
	 * @param {object} wrapper The wrapper.
	 * @param {string} label The field label.
	 * @return {object} The input wrapper.
	 */
	const field = (wrapper, label) =>
		wrapper.find(`input.password[data-label="${label}"]`)

	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
		store = useAccountRecoveryStore()
		store.fetchStatus = vi
			.fn()
			.mockResolvedValue({ enrolled: true, policy: 'optional' })
		withRequests(null)
		store.startRequest = vi.fn().mockResolvedValue()
		store.complete = vi.fn().mockResolvedValue('officer-olga')
	})

	it('renders nothing for a user who is not enrolled', async () => {
		store.fetchStatus.mockResolvedValue({ enrolled: false, policy: 'optional' })
		const wrapper = await mountForgot()

		expect(wrapper.find('[data-testid="forgot-password"]').exists()).toBe(false)
		expect(store.fetchMyRequest).not.toHaveBeenCalled()
	})

	it('renders nothing when the policy is off, even if enrolled', async () => {
		store.fetchStatus.mockResolvedValue({ enrolled: true, policy: 'off' })
		const wrapper = await mountForgot()

		expect(wrapper.find('[data-testid="forgot-password"]').exists()).toBe(false)
	})

	it('renders nothing when the status cannot be loaded', async () => {
		store.fetchStatus.mockRejectedValue(new Error('offline'))
		const wrapper = await mountForgot()

		expect(wrapper.find('[data-testid="forgot-password"]').exists()).toBe(false)
	})

	it('offers the start button to an enrolled user with no request', async () => {
		const wrapper = await mountForgot()

		expect(store.fetchMyRequest).toHaveBeenCalledTimes(1)
		expect(
			wrapper.find('[data-testid="forgot-password-start"]').text(),
		).toBe('Forgot your master password?')
		expect(wrapper.find('[data-testid="forgot-password-phrase"]').exists()).toBe(
			false,
		)
	})

	it('leaves a device-purpose request to the device-approval block', async () => {
		withRequests({ ...PENDING, purpose: 'device' })
		const wrapper = await mountForgot()

		expect(wrapper.find('[data-testid="forgot-password-start"]').exists()).toBe(
			true,
		)
		expect(wrapper.find('[data-testid="forgot-password-phrase"]').exists()).toBe(
			false,
		)
	})

	it.each(['declined', 'expired', 'fulfilled'])(
		'offers a fresh start after a %s request',
		async (status) => {
			withRequests({ ...PENDING, status })
			const wrapper = await mountForgot()

			expect(
				wrapper.find('[data-testid="forgot-password-start"]').exists(),
			).toBe(true)
		},
	)

	it('starts a request, then shows its phrase and a check-again button', async () => {
		withRequests(null, PENDING)
		const wrapper = await mountForgot()

		await wrapper.find('[data-testid="forgot-password-start"]').trigger('click')
		await flush()

		expect(store.startRequest).toHaveBeenCalledTimes(1)
		expect(store.fetchMyRequest).toHaveBeenCalledTimes(2)
		expect(wrapper.find('[data-testid="forgot-password-phrase"]').text()).toBe(
			'apple banana cherry',
		)
		expect(wrapper.find('[data-testid="forgot-password-check"]').exists()).toBe(
			true,
		)
		expect(wrapper.find('[data-testid="forgot-password-start"]').exists()).toBe(
			false,
		)
	})

	it('shows the server message when starting fails', async () => {
		store.startRequest.mockRejectedValue({
			response: { data: { message: 'Too many requests' } },
		})
		const wrapper = await mountForgot()

		await wrapper.find('[data-testid="forgot-password-start"]').trigger('click')
		await flush()

		expect(wrapper.find('.forgot-password__error').text()).toBe(
			'Too many requests',
		)
		expect(wrapper.vm.busy).toBe(false)
	})

	it('tells a different browser to finish where the request was made', async () => {
		withRequests({ ...READY, fromThisBrowser: false })
		const wrapper = await mountForgot()

		expect(wrapper.find('.note').text()).toBe(
			'Finish the recovery in the browser you asked from.',
		)
		expect(wrapper.find('[data-testid="forgot-password-phrase"]').exists()).toBe(
			false,
		)
		// The sealed key is useless here: no password form, only check again.
		expect(
			wrapper.find('[data-testid="forgot-password-complete"]').exists(),
		).toBe(false)
		expect(wrapper.find('[data-testid="forgot-password-check"]').exists()).toBe(
			true,
		)
	})

	it('check again re-fetches the request and shows the form once the key is back', async () => {
		withRequests(PENDING, READY)
		const wrapper = await mountForgot()
		expect(
			wrapper.find('[data-testid="forgot-password-complete"]').exists(),
		).toBe(false)

		await wrapper.find('[data-testid="forgot-password-check"]').trigger('click')
		await flush()

		expect(store.fetchMyRequest).toHaveBeenCalledTimes(2)
		expect(
			wrapper.find('[data-testid="forgot-password-complete"]').exists(),
		).toBe(true)
		expect(wrapper.find('[data-testid="forgot-password-check"]').exists()).toBe(
			false,
		)
	})

	it('shows the error when check again fails', async () => {
		withRequests(PENDING)
		const wrapper = await mountForgot()
		store.fetchMyRequest.mockRejectedValueOnce(new Error('Server error'))

		await wrapper.find('[data-testid="forgot-password-check"]').trigger('click')
		await flush()

		expect(wrapper.find('.forgot-password__error').text()).toBe('Server error')
	})

	describe('setting the new master password', () => {
		beforeEach(() => {
			withRequests(READY)
		})

		it('requires the organisation minimum length and a matching repeat', async () => {
			const wrapper = await mountForgot({ minLength: 8 })
			const button = wrapper.find('[data-testid="forgot-password-complete"]')

			expect(button.attributes('disabled')).toBeDefined()

			await field(wrapper, 'New master password').setValue('short')
			await field(wrapper, 'Repeat the new master password').setValue('short')
			expect(button.attributes('disabled')).toBeDefined()

			await field(wrapper, 'New master password').setValue('longenough')
			await field(wrapper, 'Repeat the new master password').setValue(
				'longenougX',
			)
			expect(button.attributes('disabled')).toBeDefined()

			await field(wrapper, 'Repeat the new master password').setValue(
				'longenough',
			)
			expect(button.attributes('disabled')).toBeUndefined()
		})

		it('defaults the minimum length to 12', async () => {
			const wrapper = await mountForgot()
			const button = wrapper.find('[data-testid="forgot-password-complete"]')

			await field(wrapper, 'New master password').setValue('elevenchars')
			await field(wrapper, 'Repeat the new master password').setValue(
				'elevenchars',
			)
			expect(button.attributes('disabled')).toBeDefined()

			await field(wrapper, 'New master password').setValue('twelve chars')
			await field(wrapper, 'Repeat the new master password').setValue(
				'twelve chars',
			)
			expect(button.attributes('disabled')).toBeUndefined()
		})

		it('completes with the new password, clears both fields and emits the officer', async () => {
			const wrapper = await mountForgot()

			await field(wrapper, 'New master password').setValue('a brand new one')
			await field(wrapper, 'Repeat the new master password').setValue(
				'a brand new one',
			)
			await wrapper
				.find('[data-testid="forgot-password-complete"]')
				.trigger('click')
			await flush()

			expect(store.complete).toHaveBeenCalledWith('a brand new one')
			expect(wrapper.emitted('recovered')).toEqual([['officer-olga']])
			expect(field(wrapper, 'New master password').element.value).toBe('')
			expect(
				field(wrapper, 'Repeat the new master password').element.value,
			).toBe('')
		})

		it('keeps the fields, emits nothing and shows the error when completing fails', async () => {
			store.complete.mockRejectedValue(new Error('Key does not open'))
			const wrapper = await mountForgot()

			await field(wrapper, 'New master password').setValue('a brand new one')
			await field(wrapper, 'Repeat the new master password').setValue(
				'a brand new one',
			)
			await wrapper
				.find('[data-testid="forgot-password-complete"]')
				.trigger('click')
			await flush()

			expect(wrapper.emitted('recovered')).toBeUndefined()
			expect(wrapper.find('.forgot-password__error').text()).toBe(
				'Key does not open',
			)
			expect(field(wrapper, 'New master password').element.value).toBe(
				'a brand new one',
			)
		})

		it('disables the fields while completing', async () => {
			let release
			store.complete.mockReturnValue(
				new Promise((resolve) => (release = resolve)),
			)
			const wrapper = await mountForgot()

			await field(wrapper, 'New master password').setValue('a brand new one')
			await field(wrapper, 'Repeat the new master password').setValue(
				'a brand new one',
			)
			await wrapper
				.find('[data-testid="forgot-password-complete"]')
				.trigger('click')

			expect(
				field(wrapper, 'New master password').attributes('disabled'),
			).toBeDefined()
			release('officer-olga')
			await flush()
			expect(
				field(wrapper, 'New master password').attributes('disabled'),
			).toBeUndefined()
		})
	})
})
