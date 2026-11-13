/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * "Approve from another device" on the lock screen (crypto-new-device-
 * approval D1 to D4, D6), beyond the first-render pin in
 * DeviceApprovalRequest.spec.js. Pins: start asks for a `web` request and
 * polls every POLL_MS until the status is no longer pending, an `unlocked`
 * poll emits `unlocked`, denied / expired requests show their notes, a 429
 * is explained, cancel stops polling and forgets the request, polling stops
 * on unmount, and the administrator path is offered only to an enrolled user
 * and files a `device`-purpose recovery request whose sealed result unlocks
 * the device.
 *
 * The device-approval and account-recovery store actions are replaced with
 * mocks: the real ones generate one-time key pairs and open sealed keys
 * with WebCrypto, which is covered by their own store specs.
 *
 * @spec openspec/specs/new-device-approval/spec.md#requirement-a-new-device-requests-approval-with-a-one-time-key
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import DeviceApprovalRequest, {
	POLL_MS,
} from '../../src/components/DeviceApprovalRequest.vue'
import { useAccountRecoveryStore } from '../../src/store/modules/accountRecovery.js'
import { useDeviceApprovalStore } from '../../src/store/modules/deviceApproval.js'

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
}

const PENDING = { id: 'r1', status: 'pending', phrase: 'one two three' }

describe('DeviceApprovalRequest flows', () => {
	let device
	let recovery

	/**
	 * Mount the block and let its created hook settle.
	 *
	 * @return {Promise<object>} The wrapper.
	 */
	async function mountBlock() {
		const wrapper = mount(DeviceApprovalRequest, { global: { stubs } })
		await flush()
		return wrapper
	}

	/**
	 * Make each poll resolve to the next status, updating the request the
	 * way the real store does.
	 *
	 * @param {...string} statuses The statuses, last one repeating.
	 */
	function pollsReturn(...statuses) {
		let i = 0
		device.pollOnce = vi.fn().mockImplementation(async () => {
			const status = statuses[Math.min(i++, statuses.length - 1)]
			device.request = { ...device.request, status }
			return status
		})
	}

	beforeEach(() => {
		vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval'] })
		setActivePinia(createPinia())
		vi.restoreAllMocks()
		device = useDeviceApprovalStore()
		recovery = useAccountRecoveryStore()
		device.startRequest = vi.fn().mockImplementation(async () => {
			device.request = { ...PENDING }
			return device.request
		})
		device.cancelRequest = vi.fn().mockImplementation(() => {
			device.request = null
		})
		pollsReturn('pending')
		recovery.fetchStatus = vi
			.fn()
			.mockResolvedValue({ enrolled: false, policy: 'optional' })
		recovery.startRequest = vi.fn()
		recovery.fetchMyRequest = vi.fn()
		recovery.unlockDevice = vi.fn().mockResolvedValue()
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	it('starts a web request, shows the phrase and polls every POLL_MS', async () => {
		const wrapper = await mountBlock()

		await wrapper.find('[data-testid="device-approval-start"]').trigger('click')
		await flush()

		expect(device.startRequest).toHaveBeenCalledWith('web')
		expect(wrapper.find('[data-testid="device-approval-phrase"]').text()).toBe(
			'one two three',
		)
		expect(wrapper.text()).toContain('Open Keepiq on a device where it is unlocked')
		expect(device.pollOnce).not.toHaveBeenCalled()

		await vi.advanceTimersByTimeAsync(POLL_MS)
		expect(device.pollOnce).toHaveBeenCalledTimes(1)
		await vi.advanceTimersByTimeAsync(POLL_MS)
		expect(device.pollOnce).toHaveBeenCalledTimes(2)
		expect(POLL_MS).toBe(3000)
	})

	it('emits unlocked and stops polling once the request is approved', async () => {
		pollsReturn('pending', 'unlocked')
		const wrapper = await mountBlock()

		await wrapper.find('[data-testid="device-approval-start"]').trigger('click')
		await flush()
		await vi.advanceTimersByTimeAsync(POLL_MS * 2)

		expect(wrapper.emitted('unlocked')).toEqual([[]])
		expect(device.pollOnce).toHaveBeenCalledTimes(2)
		await vi.advanceTimersByTimeAsync(POLL_MS * 3)
		expect(device.pollOnce).toHaveBeenCalledTimes(2)
	})

	it('shows the denied note and stops polling without unlocking', async () => {
		pollsReturn('denied')
		const wrapper = await mountBlock()

		await wrapper.find('[data-testid="device-approval-start"]').trigger('click')
		await flush()
		await vi.advanceTimersByTimeAsync(POLL_MS)
		await flush()

		expect(wrapper.find('[data-testid="device-approval-denied"]').exists()).toBe(
			true,
		)
		expect(wrapper.emitted('unlocked')).toBeUndefined()
		await vi.advanceTimersByTimeAsync(POLL_MS * 2)
		expect(device.pollOnce).toHaveBeenCalledTimes(1)
	})

	it.each(['expired', 'consumed'])(
		'shows the expired note for a %s request',
		async (status) => {
			pollsReturn(status)
			const wrapper = await mountBlock()

			await wrapper
				.find('[data-testid="device-approval-start"]')
				.trigger('click')
			await flush()
			await vi.advanceTimersByTimeAsync(POLL_MS)
			await flush()

			expect(
				wrapper.find('[data-testid="device-approval-expired"]').exists(),
			).toBe(true)
			expect(
				wrapper.find('[data-testid="device-approval-denied"]').exists(),
			).toBe(false)
		},
	)

	it('stops polling and shows the error when a poll fails', async () => {
		const wrapper = await mountBlock()
		await wrapper.find('[data-testid="device-approval-start"]').trigger('click')
		await flush()
		device.pollOnce.mockRejectedValue({
			response: { data: { message: 'Request not found' } },
		})

		await vi.advanceTimersByTimeAsync(POLL_MS)
		await flush()

		expect(wrapper.find('.device-approval-request__error').text()).toBe(
			'Request not found',
		)
		await vi.advanceTimersByTimeAsync(POLL_MS * 2)
		expect(device.pollOnce).toHaveBeenCalledTimes(1)
	})

	it('explains a rate limit and does not start polling', async () => {
		device.startRequest.mockRejectedValue({ response: { status: 429 } })
		const wrapper = await mountBlock()

		await wrapper.find('[data-testid="device-approval-start"]').trigger('click')
		await flush()

		expect(wrapper.find('.device-approval-request__error').text()).toBe(
			'Too many requests. Try again in an hour or use your master password.',
		)
		await vi.advanceTimersByTimeAsync(POLL_MS * 2)
		expect(device.pollOnce).not.toHaveBeenCalled()
		expect(
			wrapper.find('[data-testid="device-approval-start"]').attributes('disabled'),
		).toBeUndefined()
	})

	it('shows any other start failure by its message', async () => {
		device.startRequest.mockRejectedValue(new Error('Feature disabled'))
		const wrapper = await mountBlock()

		await wrapper.find('[data-testid="device-approval-start"]').trigger('click')
		await flush()

		expect(wrapper.find('.device-approval-request__error').text()).toBe(
			'Feature disabled',
		)
	})

	it('cancel stops polling and forgets the request', async () => {
		const wrapper = await mountBlock()
		await wrapper.find('[data-testid="device-approval-start"]').trigger('click')
		await flush()

		await wrapper.find('[data-testid="device-approval-cancel"]').trigger('click')
		await flush()

		expect(device.cancelRequest).toHaveBeenCalledTimes(1)
		expect(wrapper.find('[data-testid="device-approval-start"]').exists()).toBe(
			true,
		)
		await vi.advanceTimersByTimeAsync(POLL_MS * 2)
		expect(device.pollOnce).not.toHaveBeenCalled()
	})

	it('stops polling when unmounted', async () => {
		const wrapper = await mountBlock()
		await wrapper.find('[data-testid="device-approval-start"]').trigger('click')
		await flush()

		wrapper.unmount()
		await vi.advanceTimersByTimeAsync(POLL_MS * 2)

		expect(device.pollOnce).not.toHaveBeenCalled()
	})

	describe('administrator path', () => {
		it.each([
			[{ enrolled: false, policy: 'optional' }, false],
			[{ enrolled: true, policy: 'off' }, false],
			[{ enrolled: true, policy: 'optional' }, true],
		])('with status %o offers it: %s', async (status, offered) => {
			recovery.fetchStatus.mockResolvedValue(status)
			const wrapper = await mountBlock()

			expect(
				wrapper
					.find('[data-testid="device-approval-ask-organisation"]')
					.exists(),
			).toBe(offered)
		})

		it('is not offered when the status cannot be loaded', async () => {
			recovery.fetchStatus.mockRejectedValue(new Error('offline'))
			const wrapper = await mountBlock()

			expect(
				wrapper
					.find('[data-testid="device-approval-ask-organisation"]')
					.exists(),
			).toBe(false)
		})

		describe('for an enrolled user', () => {
			beforeEach(() => {
				recovery.fetchStatus.mockResolvedValue({
					enrolled: true,
					policy: 'required',
				})
				recovery.startRequest.mockResolvedValue({
					id: 'o1',
					phrase: 'red green blue',
				})
			})

			/**
			 * Mount and file the organisation request.
			 *
			 * @return {Promise<object>} The wrapper.
			 */
			async function askOrganisation() {
				const wrapper = await mountBlock()
				await wrapper
					.find('[data-testid="device-approval-ask-organisation"]')
					.trigger('click')
				await flush()
				return wrapper
			}

			it('files a device-purpose request and shows its phrase', async () => {
				const wrapper = await askOrganisation()

				expect(recovery.startRequest).toHaveBeenCalledWith('device')
				const block = wrapper.find('[data-testid="device-approval-organisation"]')
				expect(block.find('.device-approval-request__phrase').text()).toBe(
					'red green blue',
				)
				expect(
					wrapper.find('[data-testid="device-approval-start"]').exists(),
				).toBe(false)
			})

			it('shows the error when filing fails', async () => {
				recovery.startRequest.mockRejectedValue(new Error('No officers'))
				const wrapper = await askOrganisation()

				expect(wrapper.find('.device-approval-request__error').text()).toBe(
					'No officers',
				)
				expect(
					wrapper.find('[data-testid="device-approval-organisation"]').exists(),
				).toBe(false)
			})

			it('unlocks the device once an officer handed the key over', async () => {
				recovery.fetchMyRequest.mockResolvedValue({
					purpose: 'device',
					status: 'approved',
					sealedResult: 'sealed',
				})
				const wrapper = await askOrganisation()

				await wrapper
					.find('[data-testid="device-approval-organisation-check"]')
					.trigger('click')
				await flush()

				expect(recovery.unlockDevice).toHaveBeenCalledTimes(1)
				expect(wrapper.emitted('unlocked')).toEqual([[]])
			})

			it('does not unlock from a sealed password-purpose request', async () => {
				recovery.fetchMyRequest.mockResolvedValue({
					purpose: 'password',
					status: 'approved',
					sealedResult: 'sealed',
				})
				const wrapper = await askOrganisation()

				await wrapper
					.find('[data-testid="device-approval-organisation-check"]')
					.trigger('click')
				await flush()

				expect(recovery.unlockDevice).not.toHaveBeenCalled()
				expect(wrapper.emitted('unlocked')).toBeUndefined()
			})

			it('keeps waiting while the request is still pending', async () => {
				recovery.fetchMyRequest.mockResolvedValue({
					purpose: 'device',
					status: 'pending',
				})
				const wrapper = await askOrganisation()

				await wrapper
					.find('[data-testid="device-approval-organisation-check"]')
					.trigger('click')
				await flush()

				expect(
					wrapper.find('[data-testid="device-approval-organisation"]').exists(),
				).toBe(true)
				expect(wrapper.find('.device-approval-request__error').exists()).toBe(
					false,
				)
			})

			it.each(['declined', 'expired'])(
				'ends a %s request and says so',
				async (status) => {
					recovery.fetchMyRequest.mockResolvedValue({
						purpose: 'device',
						status,
					})
					const wrapper = await askOrganisation()

					await wrapper
						.find('[data-testid="device-approval-organisation-check"]')
						.trigger('click')
					await flush()

					expect(
						wrapper
							.find('[data-testid="device-approval-organisation"]')
							.exists(),
					).toBe(false)
					expect(wrapper.find('.device-approval-request__error').text()).toBe(
						'The request ended. Ask again or use your master password.',
					)
					expect(
						wrapper.find('[data-testid="device-approval-start"]').exists(),
					).toBe(true)
				},
			)

			it('shows the error when checking fails', async () => {
				recovery.fetchMyRequest.mockRejectedValue({
					response: { data: { message: 'Server error' } },
				})
				const wrapper = await askOrganisation()

				await wrapper
					.find('[data-testid="device-approval-organisation-check"]')
					.trigger('click')
				await flush()

				expect(wrapper.find('.device-approval-request__error').text()).toBe(
					'Server error',
				)
				expect(
					wrapper
						.find('[data-testid="device-approval-organisation-check"]')
						.attributes('disabled'),
				).toBeUndefined()
			})
		})
	})
})
