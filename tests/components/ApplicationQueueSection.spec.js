/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Approving or rejecting an application tells the admin when it fails (#755).
 *
 * The calls used to have no try/catch: a failed POST showed nothing and left
 * an unhandled promise rejection.
 *
 * @spec openspec/changes/implement-dashboard-settings/tasks.md#task-4.3
 */

import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import ApplicationQueueSection from '../../src/components/settings/ApplicationQueueSection.vue'

vi.mock('@nextcloud/dialogs', () => ({
	showError: vi.fn(),
}))

const PENDING = [
	{
		id: 7,
		name: 'Billing',
		description: 'Invoices',
		created_at: '2026-10-01T10:00:00Z',
	},
]

/**
 * Mount with one pending application loaded.
 *
 * @return {Promise<object>} The mounted wrapper.
 */
async function mountQueue() {
	vi.spyOn(axios, 'get').mockResolvedValue({ data: PENDING })
	const wrapper = mount(ApplicationQueueSection)
	await flushPromises()
	return wrapper
}

describe('ApplicationQueueSection', () => {
	afterEach(() => {
		vi.restoreAllMocks()
		showError.mockClear()
	})

	for (const [verb, index] of [
		['approve', 0],
		['reject', 1],
	]) {
		it(`shows an error and keeps the row when ${verb} fails`, async () => {
			const wrapper = await mountQueue()
			vi.spyOn(axios, 'post').mockRejectedValue(new Error('500'))

			await wrapper
				.findAll('.application-queue__actions button')
				[index].trigger('click')
			await flushPromises()

			expect(showError).toHaveBeenCalledTimes(1)
			expect(wrapper.findAll('.application-queue__row')).toHaveLength(1)
		})

		it(`reloads the queue and shows no error when ${verb} works`, async () => {
			const wrapper = await mountQueue()
			vi.spyOn(axios, 'post').mockResolvedValue({ data: {} })
			axios.get.mockResolvedValue({ data: [] })

			await wrapper
				.findAll('.application-queue__actions button')
				[index].trigger('click')
			await flushPromises()

			expect(axios.post).toHaveBeenCalledWith(
				expect.stringContaining(`/applications/7/${verb}`),
			)
			expect(showError).not.toHaveBeenCalled()
			expect(wrapper.findAll('.application-queue__row')).toHaveLength(0)
		})
	}
})
