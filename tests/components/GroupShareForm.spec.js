/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * GroupShareForm on its own: searching groups, refusing a submit without a
 * group, the restriction it sends, and the error it shows when the share fails.
 *
 * @spec openspec/specs/sharing-group/spec.md#requirement-share-with-a-group
 */

import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import GroupShareForm from '../../src/components/share/GroupShareForm.vue'
import { useGroupShareStore } from '../../src/store/modules/groupShare.js'

const FINANCE = { id: 'finance', label: 'Finance' }

/**
 * Mount the form for secret s-1.
 *
 * @return {object} The wrapper.
 */
function mountForm() {
	return mount(GroupShareForm, { props: { secretId: 's-1' } })
}

/**
 * The rendered error text, or null.
 *
 * @param {object} wrapper The mounted form.
 * @return {string|null}
 */
function errorText(wrapper) {
	const error = wrapper.find('[data-testid="group-share-form-error"]')
	return error.exists() ? error.text() : null
}

describe('GroupShareForm', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
		store = useGroupShareStore()
	})

	it('refuses a submit without a group and sends nothing', async () => {
		const share = vi.spyOn(store, 'shareWithGroup')
		const wrapper = mountForm()

		expect(wrapper.find('[data-testid="group-share-form-submit"]').attributes('disabled')).toBeDefined()
		await wrapper.find('form').trigger('submit')

		expect(errorText(wrapper)).toBe('Group is required')
		expect(share).not.toHaveBeenCalled()
		expect(wrapper.emitted('shared')).toBeUndefined()
	})

	it('searches groups by the typed term and an empty term for none', async () => {
		const search = vi.spyOn(store, 'searchGroups').mockResolvedValue([FINANCE])
		const wrapper = mountForm()

		await wrapper.vm.onSearch('fin')
		expect(search).toHaveBeenLastCalledWith('fin')
		expect(wrapper.vm.options).toEqual([FINANCE])
		expect(wrapper.vm.searching).toBe(false)

		await wrapper.vm.onSearch(undefined)
		expect(search).toHaveBeenLastCalledWith('')
	})

	it('shows no options when the search fails', async () => {
		vi.spyOn(store, 'searchGroups').mockRejectedValue(new Error('down'))
		const wrapper = mountForm()
		wrapper.vm.options = [FINANCE]

		await wrapper.vm.onSearch('fin')

		expect(wrapper.vm.options).toEqual([])
		expect(wrapper.vm.searching).toBe(false)
		expect(errorText(wrapper)).toBeNull()
	})

	it('sends the restriction and emits the share with its group', async () => {
		const share = vi.spyOn(store, 'shareWithGroup').mockResolvedValue({ received: 2, skipped: 0 })
		const wrapper = mountForm()
		wrapper.vm.selected = FINANCE
		wrapper.vm.restriction = { useOnly: true, endDate: '2027-01-31' }

		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(share).toHaveBeenCalledWith('s-1', 'finance', {
			useOnly: true,
			expiresAt: new Date('2027-01-31T23:59:59').toISOString(),
		})
		expect(wrapper.emitted('shared')).toEqual([[{ group: FINANCE, received: 2, skipped: 0 }]])
		expect(wrapper.vm.busy).toBe(false)
		expect(errorText(wrapper)).toBeNull()
	})

	it.each([
		[{ response: { data: { message: 'Group not allowed' } }, message: 'ignored' }, 'Group not allowed'],
		[new Error('Network Error'), 'Network Error'],
		[{}, 'Failed to share'],
	])('shows why a share failed (%#)', async (failure, shown) => {
		vi.spyOn(store, 'shareWithGroup').mockRejectedValue(failure)
		const wrapper = mountForm()
		wrapper.vm.selected = FINANCE

		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(errorText(wrapper)).toBe(shown)
		expect(wrapper.emitted('shared')).toBeUndefined()
		expect(wrapper.vm.busy).toBe(false)
	})

	it('emits cancel from the cancel button', async () => {
		const wrapper = mountForm()

		await wrapper.find('[data-testid="group-share-form-cancel"]').trigger('click')

		expect(wrapper.emitted('cancel')).toHaveLength(1)
	})
})
