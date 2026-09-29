/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * GroupShareList + GroupShareForm (sharing-02): the owner opens Share with
 * group, picks a group, and reads how many members received the secret;
 * a group share can be revoked.
 *
 * @spec openspec/changes/sharing-group-share-entry-point/specs/sharing-group/spec.md#requirement-share-with-a-group
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import GroupShareList from '../../src/components/share/GroupShareList.vue'
import { useGroupShareStore } from '../../src/store/modules/groupShare.js'

describe('GroupShareList', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: [{ id: 'gs-1', groupId: 'finance' }],
		})
	})

	it('lists the group shares of the secret', async () => {
		const wrapper = mount(GroupShareList, { props: { secretId: 's-1' } })
		await flushPromises()

		const rows = wrapper.findAll('[data-testid="group-share-row"]')
		expect(rows).toHaveLength(1)
		expect(rows[0].text()).toContain('finance')
	})

	it('shares with the picked group and says how many members received it', async () => {
		const wrapper = mount(GroupShareList, { props: { secretId: 's-1' } })
		await flushPromises()
		const store = useGroupShareStore()
		const share = vi
			.spyOn(store, 'shareWithGroup')
			.mockResolvedValue({ received: 3, skipped: 1 })

		await wrapper.find('[data-testid="group-share-open-form"]').trigger('click')
		const form = wrapper.findComponent({ name: 'GroupShareForm' })
		expect(form.exists()).toBe(true)
		form.vm.selected = { id: 'finance', label: 'Finance' }
		await form.find('form').trigger('submit')
		await flushPromises()

		expect(share).toHaveBeenCalledWith('s-1', 'finance')
		expect(wrapper.findComponent({ name: 'GroupShareForm' }).exists()).toBe(
			false,
		)
		// The test t() stub returns the key untranslated, so the counts are
		// read from the values the message is rendered with.
		expect(wrapper.find('[data-testid="group-share-result"]').exists()).toBe(
			true,
		)
		expect(wrapper.vm.result).toEqual({
			group: 'Finance',
			received: 3,
			skipped: 1,
		})
	})

	it('revokes a group share', async () => {
		const del = vi.spyOn(axios, 'delete').mockResolvedValue({ data: {} })
		const wrapper = mount(GroupShareList, { props: { secretId: 's-1' } })
		await flushPromises()

		await wrapper.find('[data-testid="group-share-row-revoke"]').trigger('click')
		await flushPromises()

		expect(del).toHaveBeenCalledWith('/apps/keepiq/api/v1/group-shares/gs-1')
		expect(wrapper.findAll('[data-testid="group-share-row"]')).toHaveLength(0)
		expect(wrapper.find('[data-testid="group-share-list-empty"]').exists()).toBe(
			true,
		)
	})
})
