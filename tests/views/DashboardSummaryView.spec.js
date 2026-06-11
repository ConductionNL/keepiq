/**
 * SPDX-FileCopyrightText: 2026 Conduction / Doriath Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `src/views/DashboardSummaryView.vue`.
 *
 * @spec openspec/changes/implement-dashboard-settings/tasks.md#13.2
 */

import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import axios from '@nextcloud/axios'

import DashboardSummaryView from '../../src/views/DashboardSummaryView.vue'

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

describe('DashboardSummaryView', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
	})

	it('renders the empty state when the summary has zero secrets', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: { total_secrets: 0 } })
		const wrapper = mount(DashboardSummaryView)
		await flush()
		expect(wrapper.find('[data-testid="dashboard-summary-empty"]').exists()).toBe(true)
	})

	it('renders the KPI grid + migration banner from the payload', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: {
				total_secrets: 4,
				shared_secrets: 1,
				folder_count: 2,
				compromised_count: 0,
				migration_status: 'in_progress',
				migration_remaining: 3,
				pending_apps_count: 0,
			},
		})
		const wrapper = mount(DashboardSummaryView)
		await flush()
		const cards = wrapper.findAll('[data-testid="dashboard-kpi-count"]')
		const counts = cards.wrappers.map((n) => n.text())
		expect(counts).toEqual(['4', '1', '2', '0'])
		expect(wrapper.find('[data-testid="migration-banner"]').exists()).toBe(true)
	})

	it('hides the pending-apps card unless isAdmin is true and count > 0', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: { total_secrets: 1, pending_apps_count: 3 },
		})
		const wrapper = mount(DashboardSummaryView)
		await flush()
		expect(wrapper.find('[data-testid="pending-apps-card"]').exists()).toBe(false)

		await wrapper.setProps({ isAdmin: true })
		expect(wrapper.find('[data-testid="pending-apps-card"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="pending-apps-count"]').text()).toBe('3')
	})

	it('renders an error block when the summary fetch rejects', async () => {
		vi.spyOn(axios, 'get').mockRejectedValue({ response: { data: { message: 'boom' } } })
		const wrapper = mount(DashboardSummaryView)
		await flush()
		expect(wrapper.find('[data-testid="dashboard-summary-error"]').text()).toContain('boom')
	})
})
