/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The admin switch for passphrases in the org password policy section: on
 * by default, loaded from the stored policy, and saved with the policy.
 *
 * @spec openspec/changes/client-side-key-generator/specs/passphrase-generator/spec.md#requirement-passphrases-follow-the-organisation-password-policy
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import OrgPasswordPolicySection from '../../src/components/settings/OrgPasswordPolicySection.vue'

const stubs = {
	CnSettingsSection: {
		props: ['name', 'description'],
		template: '<section><slot /></section>',
	},
	NcNoteCard: { props: ['type'], template: '<div class="note"><slot /></div>' },
	NcSelect: { props: ['modelValue', 'options'], template: '<div />' },
}

/**
 * Answer the admin settings GET with `settings`, and the type list.
 *
 * @param {object} settings The stored admin settings.
 */
function mockGet(settings) {
	vi.spyOn(axios, 'get').mockImplementation(async (url) => {
		if (url.includes('/api/settings/admin')) {
			return { data: settings }
		}
		return { data: [{ name: 'login' }] }
	})
}

describe('OrgPasswordPolicySection: passphrases', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('allows passphrases by default', async () => {
		mockGet({})
		const wrapper = mount(OrgPasswordPolicySection, { global: { stubs } })
		await flushPromises()

		expect(
			wrapper.find('[data-testid="generator-allow-passphrase"]').element
				.checked,
		).toBe(true)
	})

	it('shows a stored "off" and saves the switch with the policy', async () => {
		mockGet({ policy_enabled: true, generator_allow_passphrase: false })
		const put = vi.spyOn(axios, 'put').mockResolvedValue({ data: {} })
		const wrapper = mount(OrgPasswordPolicySection, { global: { stubs } })
		await flushPromises()

		const box = wrapper.find('[data-testid="generator-allow-passphrase"]')
		expect(box.element.checked).toBe(false)

		await box.setValue(true)
		await flushPromises()

		expect(put).toHaveBeenCalledWith(
			'/apps/keepiq/api/settings/admin',
			expect.objectContaining({
				policy_enabled: true,
				generator_allow_passphrase: true,
			}),
		)
	})
})
