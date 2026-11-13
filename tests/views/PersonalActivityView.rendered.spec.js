/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * `src/views/PersonalActivityView.vue` as rendered: a spinner while loading,
 * the empty state when nothing was recorded, and otherwise one row per entry
 * with its readable event label, the object's name (falling back to its id,
 * then to nothing) and the formatted time. Only the audit store is faked.
 *
 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-5.3
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const auditStore = vi.hoisted(() => ({
	personalEntries: [],
	loading: false,
	fetchPersonalActivity: null,
}))

vi.mock('../../src/store/modules/audit.js', () => ({
	useAuditStore: () => auditStore,
}))

const { default: PersonalActivityView } =
	await import('../../src/views/PersonalActivityView.vue')

/**
 * Mount the view against the current store state.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mountView() {
	const wrapper = mount(PersonalActivityView, {
		global: {
			stubs: {
				NcLoadingIcon: { template: '<span class="loading" />' },
				NcEmptyContent: {
					props: ['name', 'description'],
					template:
						'<div class="empty" v-bind="$attrs"><slot name="icon" />{{ name }}</div>',
				},
				History: { template: '<i class="history" />' },
			},
		},
	})
	await flushPromises()
	return wrapper
}

describe('PersonalActivityView rendered', () => {
	beforeEach(() => {
		auditStore.personalEntries = []
		auditStore.loading = false
		auditStore.fetchPersonalActivity = vi.fn().mockResolvedValue()
	})

	it('shows only the spinner while the activity loads', async () => {
		auditStore.loading = true
		auditStore.personalEntries = [{ id: 1, eventType: 'secret.created' }]

		const wrapper = await mountView()

		expect(wrapper.find('.loading').exists()).toBe(true)
		expect(
			wrapper.find('[data-testid="personal-activity-empty"]').exists(),
		).toBe(false)
		expect(
			wrapper.findAll('[data-testid="personal-activity-item"]'),
		).toHaveLength(0)
	})

	it('shows the empty state with its icon when nothing was recorded', async () => {
		const wrapper = await mountView()

		const empty = wrapper.find('[data-testid="personal-activity-empty"]')
		expect(empty.text()).toBe('No activity recorded yet')
		expect(empty.find('.history').exists()).toBe(true)
		expect(auditStore.fetchPersonalActivity).toHaveBeenCalledTimes(1)
	})

	it('lists each entry with its label, object and time', async () => {
		const iso = '2026-03-04T05:06:07Z'
		auditStore.personalEntries = [
			{
				id: 1,
				eventType: 'secret.created',
				objectName: 'Mail',
				occurredAt: iso,
			},
			{ id: 2, eventType: 'custom.event', objectId: 'obj-2', occurredAt: '' },
			{ id: 3, eventType: 'custom.event' },
		]

		const wrapper = await mountView()

		const rows = wrapper.findAll('[data-testid="personal-activity-item"]')
		expect(rows).toHaveLength(3)
		const cells = (row) =>
			[
				'.personal-activity__event',
				'.personal-activity__object',
				'.personal-activity__time',
			].map((sel) => row.find(sel).text())
		expect(cells(rows[0])).toEqual([
			'Secret created',
			'Mail',
			new Date(iso).toLocaleString(),
		])
		expect(cells(rows[1])).toEqual(['custom.event', 'obj-2', ''])
		expect(cells(rows[2])).toEqual(['custom.event', '', ''])
		expect(
			wrapper.find('[data-testid="personal-activity-empty"]').exists(),
		).toBe(false)
	})
})
