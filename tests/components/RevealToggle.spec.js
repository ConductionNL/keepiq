/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `src/components/RevealToggle.vue`, the eye button that
 * shows or hides a masked value: a click reaches the host, and the button is
 * named after what it does next.
 *
 * @spec openspec/specs/vault-custom-fields/spec.md#requirement-any-custom-field-can-be-hidden
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import RevealToggle from '../../src/components/RevealToggle.vue'

describe('RevealToggle', () => {
	it('passes a click to the host and is named after what it does next', async () => {
		const onClick = vi.fn()
		const wrapper = mount(RevealToggle, {
			props: { revealed: false },
			attrs: { onClick },
		})

		expect(wrapper.find('button').attributes('aria-label')).toBe('Show')
		await wrapper.find('button').trigger('click')
		expect(onClick).toHaveBeenCalledTimes(1)

		await wrapper.setProps({ revealed: true })
		expect(wrapper.find('button').attributes('aria-label')).toBe('Hide')
	})
})
