/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * RecipientPicker: it searches after a pause, lets only the newest search fill
 * the list, shows why a search failed, and never passes up a user without a vault.
 *
 * @spec openspec/specs/user-sharing/spec.md#requirement-recipient-search-marks-who-cannot-receive-a-share
 */

import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import RecipientPicker, { RECIPIENT_SEARCH_DEBOUNCE_MS } from '../../src/components/share/RecipientPicker.vue'
import { useShareStore } from '../../src/store/modules/share.js'

const ALICE = { id: 'alice', label: 'Alice', hasSuite: true }
const BOB = { id: 'bob', label: 'Bob', hasSuite: false }

// A select that exposes its props and lets the test emit what vue-select would.
const NcSelect = {
	name: 'NcSelect',
	props: ['modelValue', 'options', 'selectable', 'loading', 'disabled', 'error', 'helperText'],
	emits: ['update:modelValue', 'search'],
	template: '<div data-testid="select"><slot name="option" v-for="o in options" v-bind="o" /></div>',
}

/**
 * Mount the picker with the given props.
 *
 * @param {object} props The props.
 * @return {object} The wrapper.
 */
function mountPicker(props = {}) {
	return mount(RecipientPicker, { props, global: { stubs: { NcSelect } } })
}

describe('RecipientPicker', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
		vi.useFakeTimers()
		store = useShareStore()
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	it('searches only after the pause since the last keystroke', async () => {
		const search = vi.spyOn(store, 'searchRecipients').mockResolvedValue([ALICE, BOB])
		const wrapper = mountPicker()
		const select = wrapper.findComponent({ name: 'NcSelect' })

		select.vm.$emit('search', 'a')
		vi.advanceTimersByTime(RECIPIENT_SEARCH_DEBOUNCE_MS - 1)
		select.vm.$emit('search', 'al')
		vi.advanceTimersByTime(RECIPIENT_SEARCH_DEBOUNCE_MS - 1)
		expect(search).not.toHaveBeenCalled()

		vi.advanceTimersByTime(1)
		await flushPromises()

		expect(search).toHaveBeenCalledTimes(1)
		expect(search).toHaveBeenCalledWith('al')
		expect(select.props('options')).toEqual([ALICE, BOB])
		expect(select.props('loading')).toBe(false)
		expect(RECIPIENT_SEARCH_DEBOUNCE_MS).toBe(300)
	})

	it('keeps the list when vue-select emits an empty term after a pick', async () => {
		const search = vi.spyOn(store, 'searchRecipients').mockResolvedValue([ALICE])
		const wrapper = mountPicker()
		const select = wrapper.findComponent({ name: 'NcSelect' })

		select.vm.$emit('search', 'al')
		select.vm.$emit('search', '')
		vi.advanceTimersByTime(RECIPIENT_SEARCH_DEBOUNCE_MS * 2)
		await flushPromises()

		expect(search).not.toHaveBeenCalled()
	})

	it('lets only the newest search fill the list', async () => {
		let answerFirst
		vi.spyOn(store, 'searchRecipients')
			.mockImplementationOnce(() => new Promise((resolve) => { answerFirst = resolve }))
			.mockResolvedValueOnce([ALICE])
		const wrapper = mountPicker()

		const first = wrapper.vm.search('b')
		await wrapper.vm.search('al')
		answerFirst([BOB])
		await first

		expect(wrapper.vm.options).toEqual([ALICE])
		expect(wrapper.vm.loading).toBe(false)
	})

	it('shows why a search failed and empties the list', async () => {
		vi.spyOn(store, 'searchRecipients')
			.mockRejectedValueOnce({ response: { data: { message: 'Search is disabled' } } })
			.mockRejectedValueOnce(new Error('Network Error'))
			.mockRejectedValueOnce({})
		const wrapper = mountPicker()
		wrapper.vm.options = [ALICE]

		await wrapper.vm.search('a')
		await flushPromises()
		const select = wrapper.findComponent({ name: 'NcSelect' })
		expect(select.props('helperText')).toBe('Search is disabled')
		expect(select.props('error')).toBe(true)
		expect(select.props('options')).toEqual([])

		await wrapper.vm.search('a')
		expect(wrapper.vm.error).toBe('Network Error')

		await wrapper.vm.search('a')
		await flushPromises()
		expect(wrapper.vm.error).toBe('')
		expect(select.props('error')).toBe(false)
	})

	it('passes up a user with a vault, or none, but never a user without one', () => {
		const wrapper = mountPicker({ modelValue: 'alice' })
		const select = wrapper.findComponent({ name: 'NcSelect' })

		select.vm.$emit('update:modelValue', BOB)
		select.vm.$emit('update:modelValue', ALICE)
		select.vm.$emit('update:modelValue', null)

		expect(wrapper.emitted('update:modelValue')).toEqual([['alice'], ['']])
		expect(select.props('selectable')(ALICE)).toBe(true)
		expect(select.props('selectable')(BOB)).toBe(false)
	})

	it('keeps showing the picked user after a search drops them', async () => {
		vi.spyOn(store, 'searchRecipients').mockResolvedValue([BOB])
		const wrapper = mountPicker({ modelValue: 'carol', disabled: true })
		const select = wrapper.findComponent({ name: 'NcSelect' })

		expect(select.props('modelValue')).toEqual({ id: 'carol', label: 'carol', hasSuite: true })
		expect(select.props('disabled')).toBe(true)

		await wrapper.vm.search('b')
		await wrapper.setProps({ modelValue: 'bob' })
		expect(select.props('modelValue')).toEqual(BOB)

		await wrapper.setProps({ modelValue: '' })
		expect(select.props('modelValue')).toBeNull()
	})

	it('marks the users who have no vault yet', async () => {
		vi.spyOn(store, 'searchRecipients').mockResolvedValue([ALICE, BOB])
		const wrapper = mountPicker()

		await wrapper.vm.search('a')
		await flushPromises()

		const marks = wrapper.findAll('[data-testid="recipient-no-vault"]')
		expect(marks).toHaveLength(1)
		expect(marks[0].element.parentElement.textContent).toContain('Bob')
	})

	it('cancels a pending search when it is unmounted', async () => {
		const search = vi.spyOn(store, 'searchRecipients').mockResolvedValue([])
		const wrapper = mountPicker()

		wrapper.findComponent({ name: 'NcSelect' }).vm.$emit('search', 'al')
		wrapper.unmount()
		vi.advanceTimersByTime(RECIPIENT_SEARCH_DEBOUNCE_MS * 2)

		expect(search).not.toHaveBeenCalled()
	})
})
