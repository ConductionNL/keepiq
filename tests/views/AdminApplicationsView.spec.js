/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `src/views/AdminApplicationsView.vue`.
 *
 * @spec openspec/changes/implement-application-mgmt/tasks.md#15.6
 */

import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const flushPromises = () => new Promise((resolve) => setTimeout(resolve, 0))
import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import AdminApplicationsView from '../../src/views/AdminApplicationsView.vue'

describe('AdminApplicationsView', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
	})

	it('renders the empty state when there are no pending applications', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: [] })

		const wrapper = mount(AdminApplicationsView)
		await flushPromises()

		expect(wrapper.text()).toContain('No applications are awaiting approval.')
	})

	it('renders one row per pending application', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: [
				{
					id: 'a1',
					name: 'CI Bot',
					description: 'GitLab',
					registered_by: 'alice',
				},
				{ id: 'a2', name: 'Monitor', registered_by: 'bob' },
			],
		})

		const wrapper = mount(AdminApplicationsView)
		await flushPromises()

		const rows = wrapper.findAll('[data-testid="pending-application"]')
		expect(rows).toHaveLength(2)
		expect(rows.at(0).text()).toContain('CI Bot')
		expect(rows.at(1).text()).toContain('Monitor')
	})

	it('calls approveApplication when the approve button is clicked', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: [{ id: 'a1', name: 'CI Bot' }],
		})
		const post = vi.spyOn(axios, 'post').mockResolvedValue({
			data: { id: 'a1', status: 'active' },
		})

		const wrapper = mount(AdminApplicationsView)
		await flushPromises()

		await wrapper.find('[data-testid="approve-button"]').trigger('click')
		await flushPromises()

		expect(post).toHaveBeenCalledWith(
			'/apps/keepiq/api/v1/applications/a1/approve',
			{},
		)
	})

	it('calls rejectApplication when the reject button is clicked', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: [{ id: 'a1', name: 'CI Bot' }],
		})
		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: {} })

		const wrapper = mount(AdminApplicationsView)
		await flushPromises()

		await wrapper.find('[data-testid="reject-button"]').trigger('click')
		await flushPromises()

		expect(post).toHaveBeenCalledWith(
			'/apps/keepiq/api/v1/applications/a1/reject',
			{},
		)
	})

	it('shows no private-key block after an approval, since the server never returns a key (pki-09 decided no)', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: [{ id: 'a1', name: 'A', status: 'pending' }],
		})
		vi.spyOn(axios, 'post').mockResolvedValue({
			data: { id: 'a1', status: 'active', private_key: 'PEM' },
		})

		const wrapper = mount(AdminApplicationsView)
		await flushPromises()
		await wrapper.find('[data-testid="approve-button"]').trigger('click')
		await flushPromises()

		expect(wrapper.find('[data-testid="private-key-dialog"]').exists()).toBe(
			false,
		)
		expect(wrapper.text()).not.toContain('PEM')
	})
})
