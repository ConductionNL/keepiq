/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The extra website addresses field follows a new stored list from outside
 * (another secret loaded into the dialog), but keeps the rows being typed when
 * the parent only echoes back what the field emitted.
 *
 * @spec openspec/changes/vault-website-addresses/specs/website-addresses/spec.md#requirement-a-login-carries-several-website-addresses
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import AdditionalUrlsField from '../../src/components/AdditionalUrlsField.vue'

const inputs = (wrapper) => wrapper.findAll('[data-testid="additional-url-input"]')

describe('AdditionalUrlsField syncing', () => {
	it('starts with no rows when no list is given', () => {
		const wrapper = mount(AdditionalUrlsField)

		expect(wrapper.vm.rows).toEqual([])
		expect(inputs(wrapper)).toHaveLength(0)
	})

	it('replaces its rows when a different list arrives', async () => {
		const wrapper = mount(AdditionalUrlsField, { props: { modelValue: ['https://a.test'] } })

		await wrapper.setProps({ modelValue: ['https://b.test', 'https://c.test'] })

		expect(wrapper.vm.rows).toEqual(['https://b.test', 'https://c.test'])
		expect(inputs(wrapper)).toHaveLength(2)
	})

	it('keeps an empty row being typed when the same list is echoed back', async () => {
		const wrapper = mount(AdditionalUrlsField, { props: { modelValue: ['https://a.test'] } })
		await wrapper.find('[data-testid="additional-url-add"]').trigger('click')
		expect(wrapper.vm.rows).toEqual(['https://a.test', ''])

		await wrapper.setProps({ modelValue: [' https://a.test '] })

		expect(wrapper.vm.rows).toEqual(['https://a.test', ''])
	})
})
