/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The optional per-finding action of a health category, used by the
 * Unencrypted address category for Change to https.
 *
 * @spec openspec/changes/health-site-security-checks/specs/health-site-checks/spec.md#scenario-a-vault-user-finds-a-login-saved-with-http
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import HealthCategory from '../../src/components/HealthCategory.vue'

const FINDING = { id: 's1', name: 'Intranet', url: 'http://intranet.example.org', flags: ['insecure-address'] }

describe('HealthCategory action', () => {
	it('shows no action button without a label', () => {
		const wrapper = mount(HealthCategory, { props: { title: 'Weak', findings: [FINDING] } })

		expect(wrapper.find('[data-testid="finding-action-s1"]').exists()).toBe(false)
	})

	it('emits the whole finding from the action button, and still opens on the name', async () => {
		const wrapper = mount(HealthCategory, {
			props: { title: 'Unencrypted address', findings: [FINDING], actionLabel: 'Change to https' },
		})

		const action = wrapper.find('[data-testid="finding-action-s1"]')
		expect(action.text()).toBe('Change to https')
		await action.trigger('click')
		await wrapper.find('[data-testid="finding-s1"]').trigger('click')

		expect(wrapper.emitted('action')).toEqual([[FINDING]])
		expect(wrapper.emitted('open')).toEqual([['s1']])
	})
})
