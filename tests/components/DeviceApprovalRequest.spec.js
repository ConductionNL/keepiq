/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The lock screen's "Approve from another device" block renders before any
 * request exists (crypto-new-device-approval, keepiq#787).
 *
 * Found by the device-approval Playwright flow: with no request and no
 * organisation request, the waiting block's `v-else` (paired with the
 * organisation `v-if`) rendered and read `request.status` on null, so the
 * whole block threw and the start button never appeared on /lock.
 *
 * @spec openspec/specs/new-device-approval/spec.md#requirement-a-new-device-requests-approval-with-a-one-time-key
 */

import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import DeviceApprovalRequest from '../../src/components/DeviceApprovalRequest.vue'
import { useDeviceApprovalStore } from '../../src/store/modules/deviceApproval.js'

vi.mock('@nextcloud/auth', () => ({
	getCurrentUser: () => ({ uid: 'dana' }),
	getRequestToken: () => 'token',
	onRequestTokenUpdate: () => {},
}))

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

describe('DeviceApprovalRequest on the lock screen', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
		// Not enrolled in organisation recovery, no request filed yet.
		vi.spyOn(axios, 'get').mockResolvedValue({ data: { enrolled: false } })
	})

	it('offers "Approve from another device" before any request exists', async () => {
		const errors = []
		const wrapper = mount(DeviceApprovalRequest, {
			global: { config: { errorHandler: (e) => errors.push(e) } },
		})
		await flush()

		expect(errors).toEqual([])
		expect(wrapper.find('[data-testid="device-approval-start"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="device-approval-phrase"]').exists()).toBe(false)
	})

	it('shows the phrase once a request is pending', async () => {
		const wrapper = mount(DeviceApprovalRequest)
		await flush()
		useDeviceApprovalStore().request = { id: 'r1', status: 'pending', phrase: 'one two three four five' }
		await flush()

		expect(wrapper.find('[data-testid="device-approval-start"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="device-approval-phrase"]').text()).toBe('one two three four five')
	})
})
