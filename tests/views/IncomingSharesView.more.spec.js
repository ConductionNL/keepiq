/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The rest of "Incoming from other organisations": opening an accepted copy
 * routes to it in the vault, the spinner shows only while the first load is
 * still empty, each error code gets its own wording, and every status is
 * put in words (an unknown one shown as-is). Only the HTTP client is faked.
 *
 * @spec openspec/specs/federated-sharing/spec.md#scenario-bob-accepts-a-shared-login
 */

import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import IncomingSharesView from '../../src/views/IncomingSharesView.vue'

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

const stubs = {
	NcButton: {
		emits: ['click'],
		template:
			'<button v-bind="$attrs" @click="$emit(\'click\')"><slot /></button>',
	},
	NcEmptyContent: {
		props: ['name', 'description'],
		template: '<div v-bind="$attrs">{{ name }}</div>',
	},
	NcNoteCard: { template: '<div v-bind="$attrs"><slot /></div>' },
	NcLoadingIcon: { template: '<span class="loading" />' },
}

const SHARE = {
	id: 'in-1',
	senderCloudId: 'alice@cloud.city.example',
	name: 'Supplier portal',
	secretId: null,
	status: 'pending',
}

/**
 * Mount the view once the given GET has been arranged.
 *
 * @param {object} [router] The router mock, if any.
 * @return {object} The wrapper.
 */
function mountView(router) {
	return mount(IncomingSharesView, {
		global: { stubs, mocks: router ? { $router: router } : {} },
	})
}

describe('IncomingSharesView (more)', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('routes to the vault copy when an accepted share is opened', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: [{ ...SHARE, status: 'accepted', secretId: 'copy-1' }],
		})
		const router = { push: vi.fn() }
		const wrapper = mountView(router)
		await flush()

		await wrapper.find('[data-testid="incoming-share-open"]').trigger('click')

		expect(router.push).toHaveBeenCalledWith({
			name: 'SecretList',
			params: { id: 'copy-1' },
		})
	})

	it('offers no open button for an accepted share without a local copy', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: [{ ...SHARE, status: 'accepted', secretId: null }],
		})
		const wrapper = mountView()
		await flush()

		expect(wrapper.find('[data-testid="incoming-share-open"]').exists()).toBe(
			false,
		)
		expect(wrapper.find('[data-testid="incoming-share-status"]').text()).toBe(
			'In your vault, read-only',
		)
	})

	it('shows the spinner, not the empty state, while the first load runs', async () => {
		let resolve
		vi.spyOn(axios, 'get').mockReturnValue(
			new Promise((r) => {
				resolve = r
			}),
		)
		const wrapper = mountView()
		await flush()

		expect(wrapper.find('.loading').exists()).toBe(true)
		expect(wrapper.find('[data-testid="incoming-shares-empty"]').exists()).toBe(
			false,
		)

		resolve({ data: [] })
		await flush()

		expect(wrapper.find('.loading').exists()).toBe(false)
		expect(wrapper.find('[data-testid="incoming-shares-empty"]').exists()).toBe(
			true,
		)
	})

	it('tells the user to set up the vault first when there is no suite', async () => {
		vi.spyOn(axios, 'get').mockRejectedValue({
			response: { data: { message: 'no_suite' } },
		})
		const wrapper = mountView()
		await flush()

		expect(wrapper.find('[data-testid="incoming-shares-error"]').text()).toBe(
			'Set up your vault before you accept a shared secret.',
		)
	})

	it('falls back to a generic message for any other error', async () => {
		vi.spyOn(axios, 'get').mockRejectedValue(new Error('Network Error'))
		const wrapper = mountView()
		await flush()

		expect(wrapper.find('[data-testid="incoming-shares-error"]').text()).toBe(
			'Something went wrong. Try again.',
		)
	})

	it('puts withdrawn shares in words and shows an unknown status as-is', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: [
				{ ...SHARE, id: 'in-1', status: 'revoked' },
				{ ...SHARE, id: 'in-2', status: 'mystery' },
			],
		})
		const wrapper = mountView()
		await flush()

		const statuses = wrapper
			.findAll('[data-testid="incoming-share-status"]')
			.map((s) => s.text())
		expect(statuses).toEqual(['Withdrawn by the sender', 'mystery'])
		expect(wrapper.findAll('button')).toHaveLength(0)
	})
})
