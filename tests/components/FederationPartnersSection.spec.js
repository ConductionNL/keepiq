/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The admin partner section (keepiq#789, sharing-federated-recipients task
 * 1.3): a partner is added only after its fingerprint was read and the
 * administrator ticked that they compared it, with the password confirmed
 * first; below Nextcloud 33 the section says so and offers nothing.
 *
 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#requirement-administrators-approve-and-pin-partner-instances
 */

import axios from '@nextcloud/axios'
import { confirmPassword } from '@nextcloud/password-confirmation'
import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import FederationPartnersSection from '../../src/components/settings/FederationPartnersSection.vue'

vi.mock('@nextcloud/password-confirmation', () => ({
	confirmPassword: vi.fn(async () => {}),
}))

const FP = 'ab'.repeat(32)

const stubs = {
	CnSettingsSection: { template: '<section><slot /></section>' },
	NcButton: {
		emits: ['click'],
		template:
			'<button v-bind="$attrs" @click="$emit(\'click\')"><slot /></button>',
	},
	NcTextField: {
		props: ['modelValue', 'label'],
		emits: ['update:modelValue'],
		template:
			'<input v-bind="$attrs" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
	},
	NcCheckboxRadioSwitch: {
		props: ['modelValue'],
		emits: ['update:modelValue'],
		template:
			'<label v-bind="$attrs"><input type="checkbox" :checked="modelValue" @change="$emit(\'update:modelValue\', $event.target.checked)" /><slot /></label>',
	},
}

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

/**
 * Mount the section over this GET answer.
 *
 * @param {object} answer The partner list answer.
 * @return {Promise<object>} The wrapper.
 */
async function mountWith(answer) {
	vi.spyOn(axios, 'get').mockResolvedValue({ data: answer })
	const wrapper = mount(FederationPartnersSection, { global: { stubs } })
	await flush()
	return wrapper
}

describe('FederationPartnersSection', () => {
	beforeEach(() => {
		vi.restoreAllMocks()
		confirmPassword.mockClear()
	})

	it('says federation needs Nextcloud 33 and offers nothing below it', async () => {
		const wrapper = await mountWith({ supported: false, partners: [] })

		expect(wrapper.find('[data-testid="federation-unsupported"]').exists()).toBe(
			true,
		)
		expect(wrapper.find('[data-testid="federation-url"]').exists()).toBe(false)
	})

	it('adds a partner only after the fingerprint was read and compared, with the password confirmed', async () => {
		const wrapper = await mountWith({
			supported: true,
			localRootFingerprint: 'cd'.repeat(32),
			partners: [],
		})
		expect(
			wrapper.find('[data-testid="federation-own-fingerprint"]').text(),
		).toMatch(/^CD:CD:/)
		const post = vi.spyOn(axios, 'post').mockImplementation(async (url) =>
			url.endsWith('/preview')
				? {
						data: {
							baseUrl: 'https://cloud.partner.example',
							host: 'cloud.partner.example',
							rootFingerprint: FP,
						},
					}
				: { data: {} },
		)

		await wrapper
			.find('[data-testid="federation-url"]')
			.setValue('https://cloud.partner.example')
		await wrapper.find('[data-testid="federation-check"]').trigger('click')
		await flush()

		expect(wrapper.find('[data-testid="federation-preview"]').text()).toContain(
			'AB:AB:',
		)
		const addButton = wrapper.find('[data-testid="federation-add"]')
		expect(addButton.attributes('disabled')).toBeDefined()

		await wrapper
			.find('[data-testid="federation-compared"] input')
			.setValue(true)
		await addButton.trigger('click')
		await flush()

		expect(confirmPassword).toHaveBeenCalledTimes(1)
		const create = post.mock.calls.find(([url]) =>
			url.endsWith('/federation/partners'),
		)
		expect(create[1]).toEqual({
			url: 'https://cloud.partner.example',
			rootFingerprint: FP,
			allowOutbound: true,
			allowInbound: true,
		})
	})

	it('removes a partner after the password is confirmed', async () => {
		const wrapper = await mountWith({
			supported: true,
			partners: [
				{
					id: 'p1',
					baseUrl: 'https://cloud.partner.example',
					rootFingerprint: FP,
					allowOutbound: true,
					allowInbound: false,
				},
			],
		})
		const del = vi.spyOn(axios, 'delete').mockResolvedValue({ data: {} })

		await wrapper.find('[data-testid="federation-remove"]').trigger('click')
		await flush()

		expect(confirmPassword).toHaveBeenCalledTimes(1)
		expect(del.mock.calls[0][0]).toContain(
			'/apps/keepiq/api/v1/federation/partners/p1',
		)
	})
})
