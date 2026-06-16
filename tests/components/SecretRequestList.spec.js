/**
 * SPDX-FileCopyrightText: 2026 Conduction / Doriath Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `src/components/secretRequest/SecretRequestList.vue`.
 *
 * @spec openspec/changes/implement-secret-requests/tasks.md#13.4
 */

import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import axios from '@nextcloud/axios'

import SecretRequestList from '../../src/components/secretRequest/SecretRequestList.vue'

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

describe('SecretRequestList', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
	})

	it('renders the empty state when there are no requests', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: [] })
		const wrapper = mount(SecretRequestList)
		await flush()
		expect(wrapper.find('[data-testid="secret-request-list-empty"]').exists()).toBe(true)
	})

	it('renders one row per request with the correct status-flavored testid', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: [
				{ id: 'r1', status: 'pending', token: 'tok-abcdef0123', requested_fields: ['key'] },
				{ id: 'r2', status: 'fulfilled', token: 'tok-zzzzzzzzzz', requested_fields: ['key'] },
			],
		})
		const wrapper = mount(SecretRequestList)
		await flush()
		expect(wrapper.find('[data-testid="secret-request-row-pending"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="secret-request-row-fulfilled"]').exists()).toBe(true)
		// Revoke only on pending rows.
		expect(wrapper.findAll('[data-testid="secret-request-row-revoke"]')).toHaveLength(1)
	})

	it('filters by secretId when the prop is set', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: [
				{ id: 'r1', status: 'pending', token: 't1', secret_id: 'sec-1', requested_fields: ['key'] },
				{ id: 'r2', status: 'pending', token: 't2', secret_id: 'sec-2', requested_fields: ['key'] },
			],
		})
		const wrapper = mount(SecretRequestList, { propsData: { secretId: 'sec-2' } })
		await flush()
		expect(wrapper.findAll('[data-testid="secret-request-row-pending"]')).toHaveLength(1)
	})

	it('dispatches the revoke action when the revoke button is clicked', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: [{ id: 'r1', status: 'pending', token: 'tok-1', requested_fields: ['key'] }],
		})
		const del = vi.spyOn(axios, 'delete').mockResolvedValue({ data: {} })
		const wrapper = mount(SecretRequestList)
		await flush()
		await wrapper.find('[data-testid="secret-request-row-revoke"]').trigger('click')
		await flush()
		expect(del).toHaveBeenCalled()
	})
})
