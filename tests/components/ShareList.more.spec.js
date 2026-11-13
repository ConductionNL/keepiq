/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * ShareList when the sidebar switches secrets: the recipients of the new
 * secret are fetched, an empty id fetches nothing, and a failed fetch is
 * rendered as the store error instead of escaping. Also the count badge
 * and the "via group" badge on a row that came from a group share.
 *
 * @spec openspec/specs/user-sharing/spec.md#requirement-share-visibility
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ShareList from '../../src/components/share/ShareList.vue'

describe('ShareList secret switch', () => {
	let get

	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
		get = vi.spyOn(axios, 'get').mockImplementation(async (url) => ({
			data: url.includes('sec-2')
				? [
					{ id: 's2', target_user_id: 'bob', group_share_id: 'g1' },
					{ id: 's3', target_user_id: 'carol' },
				]
				: [{ id: 's1', target_user_id: 'alice' }],
		}))
	})

	it('fetches the recipients of the secret it switches to', async () => {
		const wrapper = mount(ShareList, { props: { secretId: 'sec-1' } })
		await flushPromises()
		expect(wrapper.findAll('[data-testid="share-row-target"]').map((r) => r.text())).toEqual(['alice'])

		await wrapper.setProps({ secretId: 'sec-2' })
		await flushPromises()

		expect(get).toHaveBeenCalledTimes(2)
		expect(get.mock.calls[1][0]).toContain('sec-2')
		expect(wrapper.findAll('[data-testid="share-row-target"]').map((r) => r.text())).toEqual([
			'bob',
			'carol',
		])
		expect(wrapper.find('[data-testid="share-list-count"]').text()).toBe('2')
		const rows = wrapper.findAll('[data-testid="share-row"]')
		expect(rows[0].text()).toContain('via group')
		expect(rows[1].text()).not.toContain('via group')
	})

	it('fetches nothing when the secret id is cleared', async () => {
		const wrapper = mount(ShareList, { props: { secretId: 'sec-1' } })
		await flushPromises()

		await wrapper.setProps({ secretId: '' })
		await flushPromises()

		expect(get).toHaveBeenCalledTimes(1)
	})

	it('renders the store error of a failed switch instead of throwing', async () => {
		const wrapper = mount(ShareList, { props: { secretId: 'sec-1' } })
		await flushPromises()
		get.mockRejectedValue(new Error('Network Error'))

		await wrapper.setProps({ secretId: 'sec-3' })
		await flushPromises()

		expect(get).toHaveBeenLastCalledWith(expect.stringContaining('sec-3'))
		expect(wrapper.find('[data-testid="share-list-error"]').text()).toBe('Network Error')
	})
})
