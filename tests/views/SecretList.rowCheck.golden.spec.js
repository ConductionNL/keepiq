/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden for the selection checkbox the secret list renders in its list and
 * card rows: the same checkbox in both (checked state from the bulk
 * selection, label, test id, class), a plain click toggling one row and a
 * shift-click selecting the range from the last-clicked row.
 *
 * @spec openspec/specs/bulk-actions/spec.md#requirement-multi-select-and-bulk-action-bar
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'
import SecretList from '../../src/views/SecretList.vue'
import { useBulkStore } from '../../src/store/modules/bulk.js'
import { useSecretStore } from '../../src/store/modules/secret.js'

/** Renders the list-item and the card slot for each object. */
const CnIndexPage = {
	props: ['objects'],
	render() {
		return h(
			'div',
			(this.objects ?? []).flatMap((object) => [
				h('div', { class: 'list-row', 'data-id': object.id }, [
					this.$slots['list-item']?.({ object, selected: false }),
				]),
				h('div', { class: 'card-row', 'data-id': object.id }, [
					this.$slots.card?.({ object, selected: false }),
				]),
			]),
		)
	},
}

const NcCheckboxRadioSwitch = {
	name: 'NcCheckboxRadioSwitch',
	props: ['modelValue'],
	emits: ['update:modelValue'],
	template:
		'<input type="checkbox" :checked="modelValue" @click="$emit(\'update:modelValue\', !modelValue)" />',
}

/**
 * @param {object} wrapper The mounted list.
 * @return {Array<object>} Every selection checkbox's attributes and checked state, without scope ids.
 */
function checkboxes(wrapper) {
	return wrapper
		.findAll('input[type="checkbox"]')
		.map((box) => ({
			attrs: Object.fromEntries(
				Object.entries(box.attributes())
					.filter(([key]) => !key.startsWith('data-v-'))
					.sort(([a], [b]) => a.localeCompare(b)),
			),
			checked: box.element.checked,
		}))
}

beforeEach(() => {
	setActivePinia(createPinia())
	vi.restoreAllMocks()
})

describe('SecretList selection checkbox (golden)', () => {
	it('renders the same checkbox in list and card rows, toggles and selects ranges', async () => {
		const store = useSecretStore()
		store.fetchSecrets = vi.fn().mockResolvedValue()
		const wrapper = mount(SecretList, {
			shallow: true,
			global: {
				stubs: { CnIndexPage, NcCheckboxRadioSwitch, SecretRowCheck: false },
				mocks: {
					$route: { query: {}, params: {}, name: 'secrets' },
					$router: { push: vi.fn(), replace: vi.fn() },
				},
			},
		})
		store.secrets = [
			{ id: 'a', name: 'Bank' },
			{ id: 'b', name: 'Mail' },
			{ id: 'c', name: 'VPN' },
		]
		wrapper.vm.folderSwitching = false
		await wrapper.vm.$nextTick()
		const bulk = useBulkStore()
		const initial = checkboxes(wrapper)

		await wrapper.find('.list-row[data-id="a"] input').trigger('click')
		const afterPlain = [...bulk.selectedIds]
		await wrapper.find('.card-row[data-id="c"] input').trigger('click', { shiftKey: true })
		const afterRange = [...bulk.selectedIds].sort()
		await wrapper.find('.card-row[data-id="b"] input').trigger('click')
		const afterToggle = [...bulk.selectedIds].sort()

		expect({ initial, afterPlain, afterRange, afterToggle, final: checkboxes(wrapper) }).toMatchSnapshot()
		expect(afterPlain).toEqual(['a'])
		expect(afterRange).toEqual(['a', 'b', 'c'])
		expect(afterToggle).toEqual(['a', 'c'])
	})
})
