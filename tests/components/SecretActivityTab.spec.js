/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `src/components/SecretActivityTab.vue`.
 *
 * Mounts the tab against the real audit store with only `@nextcloud/axios`
 * mocked. Covered: the immediate fetch of `/audit/secret/<id>` on mount and
 * a refetch when `secretId` changes, the loading indicator while the request
 * is in flight, the empty state (also after a failed request), and each row's
 * event label (known type and raw fallback), actor label (user, anonymous
 * visitor, system, deleted account) and timestamp (blank when absent).
 *
 * The timestamp is compared with `new Date(iso).toLocaleString()` evaluated
 * in the same process, so the assertion does not depend on the timezone.
 *
 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-5.2
 */

import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import SecretActivityTab from '../../src/components/SecretActivityTab.vue'

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

const ISO = '2026-03-01T10:00:00Z'

describe('SecretActivityTab', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('fetches the secret activity on mount and shows a loader meanwhile', async () => {
		let release
		const get = vi.spyOn(axios, 'get').mockReturnValue(
			new Promise((resolve) => {
				release = resolve
			}),
		)

		const wrapper = mount(SecretActivityTab, { props: { secretId: 's1' } })
		await wrapper.vm.$nextTick()

		expect(get).toHaveBeenCalledExactlyOnceWith('/apps/keepiq/api/v1/audit/secret/s1')
		expect(wrapper.find('[data-stub="NcLoadingIcon"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="secret-activity-item"]').exists()).toBe(false)

		release({ data: { entries: [] } })
		await flush()

		expect(wrapper.find('[data-stub="NcLoadingIcon"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="secret-activity-empty"]').exists()).toBe(true)
	})

	it('shows the empty state when the request fails', async () => {
		vi.spyOn(axios, 'get').mockRejectedValue(new Error('404'))

		const wrapper = mount(SecretActivityTab, { props: { secretId: 's1' } })
		await flush()

		const empty = wrapper.findComponent({ name: 'NcEmptyContent' })
		expect(empty.exists()).toBe(true)
		expect(empty.props('name')).toBe('No activity recorded yet')
	})

	it('renders one row per entry with event, actor and time labels', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: {
				entries: [
					{ id: 1, eventType: 'secret.read', actorType: 'user', actorId: 'alice', occurredAt: ISO },
					{ id: 2, eventType: 'secret.updated', actorType: 'link_visitor', actorId: null, occurredAt: ISO },
					{ id: 3, eventType: 'custom.event', actorType: 'system', actorId: null, occurredAt: null },
					{ id: 4, eventType: 'secret.created', actorType: 'user', actorId: 'deleted-account', occurredAt: '' },
				],
			},
		})

		const wrapper = mount(SecretActivityTab, { props: { secretId: 's1' } })
		await flush()

		const rows = wrapper.findAll('[data-testid="secret-activity-item"]').map((row) => [
			row.find('.secret-activity__event').text(),
			row.find('.secret-activity__actor').text(),
			row.find('.secret-activity__time').text(),
		])
		const time = new Date(ISO).toLocaleString()
		expect(rows).toEqual([
			['Secret read', 'alice', time],
			['Secret updated', 'Anonymous visitor', time],
			['custom.event', 'System', ''],
			['Secret created', 'Deleted account', ''],
		])
		expect(wrapper.find('[data-testid="secret-activity-empty"]').exists()).toBe(false)
	})

	it('refetches when the secretId prop changes', async () => {
		const get = vi.spyOn(axios, 'get').mockResolvedValue({ data: { entries: [] } })

		const wrapper = mount(SecretActivityTab, { props: { secretId: 's1' } })
		await flush()
		await wrapper.setProps({ secretId: 's2' })
		await flush()

		expect(get.mock.calls.map((c) => c[0])).toEqual([
			'/apps/keepiq/api/v1/audit/secret/s1',
			'/apps/keepiq/api/v1/audit/secret/s2',
		])
	})

	it('does not fetch for an empty secretId', async () => {
		const get = vi.spyOn(axios, 'get').mockResolvedValue({ data: { entries: [] } })

		mount(SecretActivityTab, { props: { secretId: '' } })
		await flush()

		expect(get).not.toHaveBeenCalled()
	})
})
