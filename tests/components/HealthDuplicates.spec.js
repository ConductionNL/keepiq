/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `src/components/HealthDuplicates.vue`.
 *
 * Covers the Duplicates section of the password-health report: the empty
 * line when neither list has groups, one heading per non-empty list (the
 * likely list with its warning hint), the group head (host, falling back to
 * the first item's name, and the optional username), one item button per
 * group item emitting `open` with its id, and the Merge action opening the
 * merge dialog for that group, closing it again and forwarding `merged`.
 *
 * @spec openspec/changes/vault-duplicate-finder/specs/vault-duplicates/spec.md#requirement-detect-duplicates-in-the-browser
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import HealthDuplicates from '../../src/components/HealthDuplicates.vue'

const MergeModalStub = {
	name: 'DuplicateMergeModal',
	props: ['open', 'group'],
	emits: ['close', 'merged'],
	template: '<div data-testid="merge-modal-stub" />',
}

/**
 * Mount the section with the given props, the merge dialog stubbed.
 *
 * @param {object} props Component props.
 * @return {object} The wrapper.
 */
function mountSection(props = {}) {
	return mount(HealthDuplicates, {
		props,
		global: { stubs: { DuplicateMergeModal: MergeModalStub } },
	})
}

const exactGroup = {
	host: 'mail.example.com',
	username: 'ann',
	items: [
		{ id: 'a1', name: 'Mail', folderId: 'work' },
		{ id: 'a2', name: 'Mail copy' },
	],
}

const likelyGroup = {
	host: '',
	username: '',
	items: [
		{ id: 'b1', name: 'Bank' },
		{ id: 'b2', name: 'Bank old' },
	],
}

describe('HealthDuplicates', () => {
	it('shows only the empty line when there are no groups', () => {
		const wrapper = mountSection()

		expect(wrapper.find('h3').text()).toBe('Duplicates')
		expect(wrapper.find('[data-testid="duplicates-empty"]').text()).toBe(
			'No duplicates found',
		)
		expect(wrapper.findAll('h4')).toHaveLength(0)
		expect(wrapper.find('[data-testid="merge-modal-stub"]').exists()).toBe(false)
	})

	it('renders the exact list alone, with host, username and folder', () => {
		const wrapper = mountSection({ exact: [exactGroup] })

		expect(wrapper.find('[data-testid="duplicates-empty"]').exists()).toBe(false)
		const headings = wrapper.findAll('h4')
		expect(headings).toHaveLength(1)
		expect(headings[0].text()).toBe('Exact duplicates')

		const group = wrapper.find('[data-testid="duplicate-group-exact"]')
		expect(group.find('.health-duplicates__host').text()).toBe(
			'mail.example.com',
		)
		expect(
			group.find('.health-duplicates__head .health-duplicates__meta').text(),
		).toBe('ann')

		const first = wrapper.find('[data-testid="duplicate-item-a1"]')
		expect(first.text()).toContain('Mail')
		expect(first.find('.health-duplicates__meta').text()).toBe('work')
		expect(
			wrapper
				.find('[data-testid="duplicate-item-a2"] .health-duplicates__meta')
				.exists(),
		).toBe(false)
	})

	it('renders the likely list with its hint and falls back to the first item name', () => {
		const wrapper = mountSection({ likely: [likelyGroup] })

		const headings = wrapper.findAll('h4')
		expect(headings).toHaveLength(1)
		expect(headings[0].text()).toBe('Likely duplicates')
		expect(wrapper.text()).toContain(
			'Same address and username, a different password.',
		)

		const group = wrapper.find('[data-testid="duplicate-group-likely"]')
		expect(group.find('.health-duplicates__host').text()).toBe('Bank')
		expect(
			group.find('.health-duplicates__head .health-duplicates__meta').exists(),
		).toBe(false)
	})

	it('emits open with the item id when an item is clicked', async () => {
		const wrapper = mountSection({ exact: [exactGroup], likely: [likelyGroup] })

		await wrapper.find('[data-testid="duplicate-item-b2"]').trigger('click')

		expect(wrapper.emitted('open')).toEqual([['b2']])
	})

	it('opens the merge dialog for the clicked group and forwards merged', async () => {
		const wrapper = mountSection({ exact: [exactGroup], likely: [likelyGroup] })

		await wrapper
			.find('[data-testid="duplicate-merge-likely-0"] ')
			.trigger('click')
		const modal = wrapper.findComponent(MergeModalStub)
		expect(modal.exists()).toBe(true)
		expect(modal.props('group')).toEqual(likelyGroup)
		expect(modal.props('open')).toBe(true)

		const result = { kept: 'b1', removed: ['b2'] }
		modal.vm.$emit('merged', result)
		await wrapper.vm.$nextTick()

		expect(wrapper.emitted('merged')).toEqual([[result]])
		expect(wrapper.findComponent(MergeModalStub).exists()).toBe(false)
	})

	it('closes the merge dialog without emitting merged', async () => {
		const wrapper = mountSection({ exact: [exactGroup] })

		await wrapper
			.find('[data-testid="duplicate-merge-exact-0"]')
			.trigger('click')
		wrapper.findComponent(MergeModalStub).vm.$emit('close')
		await wrapper.vm.$nextTick()

		expect(wrapper.findComponent(MergeModalStub).exists()).toBe(false)
		expect(wrapper.emitted('merged')).toBeUndefined()
	})
})
