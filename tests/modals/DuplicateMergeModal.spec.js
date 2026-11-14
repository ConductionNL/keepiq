/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `src/modals/DuplicateMergeModal.vue`: an exact group
 * starts with its first item picked and a likely group with none, the
 * confirmation says how many people lose access, and Merge runs the merge
 * and reports what was removed.
 *
 * @spec openspec/changes/vault-duplicate-finder/specs/vault-duplicates/spec.md#requirement-merge-a-duplicate-group
 */

import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const merge = vi.hoisted(() => ({
	loadGroup: vi.fn(),
	mergeGroup: vi.fn(),
	peopleLosingAccess: vi.fn(),
}))

vi.mock('../../src/health/merge.js', async () => {
	const { foldFields } = await import('../../src/health/duplicates.js')
	return {
		...merge,
		mergedFields: (kept, others) => foldFields(kept.additionalFields, others.map((o) => o.additionalFields)),
	}
})

const { default: DuplicateMergeModal } = await import('../../src/modals/DuplicateMergeModal.vue')

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

const stubs = {
	NcDialog: {
		props: ['open', 'name'],
		emits: ['update:open'],
		template: '<div v-if="open"><slot /><slot name="actions" /></div>',
	},
	NcButton: {
		props: ['disabled'],
		emits: ['click'],
		template: '<button v-bind="$attrs" :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
	NcCheckboxRadioSwitch: {
		props: ['modelValue', 'value'],
		emits: ['update:modelValue'],
		template: '<label v-bind="$attrs" :data-checked="modelValue === value" @click="$emit(\'update:modelValue\', value)"><slot /></label>',
	},
	NcLoadingIcon: true,
}

const SECRETS = [
	{ id: 'a', name: 'GitHub', additionalFields: null },
	{ id: 'b', name: 'github.com', additionalFields: { 'recovery email': 'anna@home.example' } },
]

const group = (kind) => ({ kind, host: 'github.com', username: 'anna', items: SECRETS.map(({ id, name }) => ({ id, name })) })

describe('DuplicateMergeModal', () => {
	beforeEach(() => {
		merge.loadGroup.mockReset().mockResolvedValue(SECRETS)
		merge.mergeGroup.mockReset().mockResolvedValue({ removed: ['b'] })
		merge.peopleLosingAccess.mockReset().mockResolvedValue(2)
	})

	it('picks the first item of an exact group and says who loses access', async () => {
		const wrapper = mount(DuplicateMergeModal, { props: { open: true, group: group('exact') }, global: { stubs } })
		await flush()
		await flush()

		expect(wrapper.find('[data-testid="duplicate-keep-a"]').attributes('data-checked')).toBe('true')
		expect(merge.peopleLosingAccess).toHaveBeenCalledWith(['b'])
		expect(wrapper.find('[data-testid="duplicate-merge-access"]').exists()).toBe(true)
		expect(wrapper.vm.peopleLosingAccess).toBe(2)
		expect(wrapper.find('[data-testid="duplicate-merge-fields"]').text()).toContain('recovery email')
		expect(wrapper.text()).not.toContain('anna@home.example')
	})

	it('picks nothing in a likely group, so Merge waits for a choice', async () => {
		const wrapper = mount(DuplicateMergeModal, { props: { open: true, group: group('likely') }, global: { stubs } })
		await flush()

		expect(wrapper.find('[data-testid="duplicate-merge-confirm"]').attributes('disabled')).toBeDefined()
		expect(merge.peopleLosingAccess).not.toHaveBeenCalled()
	})

	it('merges into the kept item and reports what was removed', async () => {
		const wrapper = mount(DuplicateMergeModal, { props: { open: true, group: group('exact') }, global: { stubs } })
		await flush()
		await flush()

		await wrapper.find('[data-testid="duplicate-merge-confirm"]').trigger('click')
		await flush()

		expect(merge.mergeGroup).toHaveBeenCalledWith(SECRETS[0], [SECRETS[1]])
		expect(wrapper.emitted('merged')).toEqual([[{ kept: 'a', removed: ['b'] }]])
	})

	it('shows an error and stays open when the merge fails', async () => {
		merge.mergeGroup.mockRejectedValue(new Error('refused'))
		const wrapper = mount(DuplicateMergeModal, { props: { open: true, group: group('exact') }, global: { stubs } })
		await flush()
		await flush()

		await wrapper.find('[data-testid="duplicate-merge-confirm"]').trigger('click')
		await flush()

		expect(wrapper.emitted('merged')).toBeUndefined()
		expect(wrapper.find('[role="alert"]').exists()).toBe(true)
	})
})
