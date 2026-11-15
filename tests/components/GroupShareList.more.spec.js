/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * GroupShareList beyond the happy path: cancelling the share form closes
 * it again, switching secrets clears the last result and the open form
 * and fetches the new secret's group shares (an empty id fetches
 * nothing), a group label falls back to its id, and a failed revoke is
 * shown as the store error.
 *
 * @spec openspec/specs/sharing-group/spec.md#requirement-share-with-a-group
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import GroupShareList from '../../src/components/share/GroupShareList.vue'

describe('GroupShareList more', () => {
	let get

	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
		get = vi.spyOn(axios, 'get').mockImplementation(async (url) => ({
			data: url.includes('s-2')
				? [{ id: 'gs-2', groupId: 'hr' }]
				: [{ id: 'gs-1', groupId: 'finance' }],
		}))
	})

	/**
	 * Mount the list for s-1 and let the first fetch land.
	 *
	 * @return {Promise<object>} The wrapper.
	 */
	async function mountList() {
		const wrapper = mount(GroupShareList, { props: { secretId: 's-1' } })
		await flushPromises()
		return wrapper
	}

	it('closes the share form on cancel', async () => {
		const wrapper = await mountList()
		await wrapper.find('[data-testid="group-share-open-form"]').trigger('click')
		expect(wrapper.find('[data-testid="group-share-form"]').exists()).toBe(true)

		await wrapper.find('[data-testid="group-share-form-cancel"]').trigger('click')

		expect(wrapper.find('[data-testid="group-share-form"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="group-share-open-form"]').exists()).toBe(true)
	})

	it('resets the result and the form and loads the group shares of the next secret', async () => {
		const wrapper = await mountList()
		wrapper.vm.onShared({ group: { id: 'finance' }, received: 1, skipped: 0 })
		expect(wrapper.vm.result).toEqual({ group: 'finance', received: 1, skipped: 0, refused: 0 })
		wrapper.vm.formOpen = true

		await wrapper.setProps({ secretId: 's-2' })
		await flushPromises()

		expect(get).toHaveBeenLastCalledWith('/apps/keepiq/api/v1/secrets/s-2/group-shares')
		expect(wrapper.vm.result).toBeNull()
		expect(wrapper.vm.formOpen).toBe(false)
		expect(wrapper.find('[data-testid="group-share-result"]').exists()).toBe(false)
		expect(wrapper.findAll('[data-testid="group-share-row"]').map((r) => r.text())).toEqual([
			expect.stringContaining('hr'),
		])
	})

	it('fetches nothing when the secret id is cleared, but still resets', async () => {
		const wrapper = await mountList()
		wrapper.vm.formOpen = true

		await wrapper.setProps({ secretId: '' })
		await flushPromises()

		expect(get).toHaveBeenCalledTimes(1)
		expect(wrapper.vm.formOpen).toBe(false)
	})

	it('names the group by its id when it has no label, and an empty name without a group', async () => {
		const wrapper = await mountList()

		wrapper.vm.onShared({ group: { id: 'finance' }, received: 2, skipped: 1, refused: 0 })
		expect(wrapper.vm.result.group).toBe('finance')

		wrapper.vm.onShared({ received: 0, skipped: 0 })
		expect(wrapper.vm.result).toEqual({ group: '', received: 0, skipped: 0, refused: 0 })
	})

	it('shows the error of a failed revoke and keeps the row', async () => {
		const del = vi.spyOn(axios, 'delete').mockRejectedValue({
			response: { data: { message: 'Not allowed' } },
		})
		const wrapper = await mountList()

		await wrapper.find('[data-testid="group-share-row-revoke"]').trigger('click')
		await flushPromises()

		expect(del).toHaveBeenCalledWith('/apps/keepiq/api/v1/group-shares/gs-1')
		expect(wrapper.find('[data-testid="group-share-list-error"]').text()).toBe('Not allowed')
		expect(wrapper.findAll('[data-testid="group-share-row"]')).toHaveLength(1)
	})
})
