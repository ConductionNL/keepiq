/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The offboarding flow: pick a leaving user and a successor, confirm in the
 * dialog, and the run posts both ids; a failed search empties the options
 * and a refused run shows the server's message.
 *
 * @spec openspec/specs/admin-member-overview/spec.md#requirement-administrator-acts-on-a-member-row
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import OffboardingSection from '../../src/components/settings/OffboardingSection.vue'
import { useMemberOverviewStore } from '../../src/store/modules/memberOverview.js'

const ALICE = { userId: 'alice', displayName: 'Alice' }
const BOB = { userId: 'bob', displayName: 'Bob' }

const stubs = {
	CnSettingsSection: { template: '<section><slot /></section>' },
	NcButton: {
		props: ['variant', 'disabled'],
		emits: ['click'],
		template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
	NcNoteCard: { props: ['type'], template: '<div class="note" :data-type="type"><slot /></div>' },
	NcSelect: {
		name: 'NcSelect',
		props: ['modelValue', 'options', 'label', 'filterable', 'loading', 'inputLabel'],
		emits: ['update:modelValue', 'search'],
		template: '<div class="select" />',
	},
	OffboardingConfirmDialog: {
		name: 'OffboardingConfirmDialog',
		props: ['open', 'leavingUserId', 'successorUserId'],
		emits: ['update:open', 'confirm'],
		template: '<div class="confirm" />',
	},
}

/**
 * @return {object} The mounted section.
 */
function mountSection() {
	return mount(OffboardingSection, { global: { stubs } })
}

/**
 * @param {object} wrapper The section.
 * @return {Array<object>} The leaving and successor pickers.
 */
function pickers(wrapper) {
	return wrapper.findAllComponents({ name: 'NcSelect' })
}

describe('OffboardingSection flow', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('enables the run only for two different users, then opens the confirmation', async () => {
		const wrapper = mountSection()
		const run = () => wrapper.find('[data-testid="offboarding-run"]')
		const dialog = () => wrapper.findComponent({ name: 'OffboardingConfirmDialog' })
		expect(run().attributes('disabled')).toBeDefined()

		await pickers(wrapper)[0].vm.$emit('update:modelValue', ALICE)
		await pickers(wrapper)[1].vm.$emit('update:modelValue', ALICE)
		expect(run().attributes('disabled')).toBeDefined()

		await pickers(wrapper)[1].vm.$emit('update:modelValue', BOB)
		expect(run().attributes('disabled')).toBeUndefined()
		expect(dialog().props('open')).toBe(false)

		await run().trigger('click')
		expect(dialog().props()).toEqual({ open: true, leavingUserId: 'alice', successorUserId: 'bob' })

		await dialog().vm.$emit('update:open', false)
		expect(dialog().props('open')).toBe(false)
	})

	it('posts both users when the dialog confirms and shows the summary', async () => {
		const post = vi.spyOn(axios, 'post').mockResolvedValue({
			data: { revoked: 2, transferred: 1, skipped: [], membershipsRemoved: 0, stillCoveredByGroups: [] },
		})
		const wrapper = mountSection()
		await pickers(wrapper)[0].vm.$emit('update:modelValue', ALICE)
		await pickers(wrapper)[1].vm.$emit('update:modelValue', BOB)
		await wrapper.find('[data-testid="offboarding-run"]').trigger('click')

		await wrapper.findComponent({ name: 'OffboardingConfirmDialog' }).vm.$emit('confirm')
		await flushPromises()

		expect(post).toHaveBeenCalledWith('/apps/keepiq/api/v1/team-folders/offboard', {
			leavingUserId: 'alice',
			successorUserId: 'bob',
		})
		expect(wrapper.findComponent({ name: 'OffboardingConfirmDialog' }).props('open')).toBe(false)
		expect(wrapper.find('[data-testid="offboarding-summary"]').attributes('data-type')).toBe('success')
		expect(wrapper.find('[data-testid="offboarding-error"]').exists()).toBe(false)
	})

	it('shows the server message when the run is refused', async () => {
		vi.spyOn(axios, 'post').mockRejectedValue({ response: { data: { message: 'Successor has no vault' } } })
		const wrapper = mountSection()
		await pickers(wrapper)[0].vm.$emit('update:modelValue', ALICE)
		await pickers(wrapper)[1].vm.$emit('update:modelValue', BOB)

		await wrapper.findComponent({ name: 'OffboardingConfirmDialog' }).vm.$emit('confirm')
		await flushPromises()

		expect(wrapper.find('[data-testid="offboarding-error"]').text()).toBe('Successor has no vault')
		expect(wrapper.find('[data-testid="offboarding-summary"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="offboarding-run"]').attributes('disabled')).toBeUndefined()
	})

	it('offers the users a search finds, and none when the search fails', async () => {
		const store = useMemberOverviewStore()
		const search = vi.spyOn(store, 'searchUsers').mockResolvedValueOnce([ALICE, BOB])
		const wrapper = mountSection()

		await pickers(wrapper)[1].vm.$emit('search', 'b')
		await flushPromises()
		expect(search).toHaveBeenCalledWith('b')
		expect(pickers(wrapper)[0].props('options')).toEqual([ALICE, BOB])
		expect(pickers(wrapper)[1].props('loading')).toBe(false)

		search.mockRejectedValueOnce(new Error('offline'))
		await pickers(wrapper)[0].vm.$emit('search', undefined)
		await flushPromises()
		expect(search).toHaveBeenLastCalledWith('')
		expect(pickers(wrapper)[0].props('options')).toEqual([])
		expect(pickers(wrapper)[0].props('loading')).toBe(false)
	})

	it('ignores a cleared prefill and falls back to the id when the row is unknown', async () => {
		const store = useMemberOverviewStore()
		const wrapper = mountSection()

		store.prefillOffboarding('carol')
		await flushPromises()
		expect(pickers(wrapper)[0].props('modelValue')).toEqual({ userId: 'carol', displayName: 'carol' })

		store.prefillOffboarding('')
		await flushPromises()
		expect(pickers(wrapper)[0].props('modelValue')).toEqual({ userId: 'carol', displayName: 'carol' })
	})
})
