/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The partner section's failure paths and switches: a failed load, check or
 * add shows the server's message; add does nothing before the fingerprint
 * was compared; the direction switches of a new or listed partner are sent.
 *
 * @spec openspec/specs/federated-sharing/spec.md#requirement-administrators-approve-and-pin-partner-instances
 */

import axios from '@nextcloud/axios'
import { confirmPassword } from '@nextcloud/password-confirmation'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import FederationPartnersSection from '../../src/components/settings/FederationPartnersSection.vue'

vi.mock('@nextcloud/password-confirmation', () => ({
	confirmPassword: vi.fn(async () => {}),
}))

const BASE = '/apps/keepiq/api/v1/federation/partners'
const FP = 'ab'.repeat(32)
const PREVIEW = { baseUrl: 'https://cloud.partner.example', host: 'cloud.partner.example', rootFingerprint: FP }
const PARTNER = { id: 'p/1', baseUrl: 'https://p.example', rootFingerprint: FP, allowOutbound: true, allowInbound: false }

const stubs = {
	CnSettingsSection: { template: '<section><slot /></section>' },
	NcNoteCard: { props: ['type'], template: '<div class="note" :data-type="type"><slot /></div>' },
	NcButton: {
		props: ['variant', 'disabled'],
		emits: ['click'],
		template: '<button v-bind="$attrs" :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
	NcTextField: {
		props: ['modelValue', 'label'],
		emits: ['update:modelValue'],
		template: '<input v-bind="$attrs" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
	},
	NcCheckboxRadioSwitch: {
		name: 'NcCheckboxRadioSwitch',
		props: ['modelValue', 'type'],
		emits: ['update:modelValue'],
		template: '<label v-bind="$attrs" class="switch"><slot /></label>',
	},
}

/**
 * @param {object} answer The partner list answer.
 * @return {Promise<object>} The loaded section.
 */
async function mountWith(answer) {
	vi.spyOn(axios, 'get').mockResolvedValue({ data: answer })
	const wrapper = mount(FederationPartnersSection, { global: { stubs } })
	await flushPromises()
	return wrapper
}

/**
 * @param {object} wrapper The section.
 * @param {string} text The switch's label.
 * @return {object} The switch.
 */
function switchLabelled(wrapper, text) {
	return wrapper.findAllComponents({ name: 'NcCheckboxRadioSwitch' }).filter((c) => c.text() === text)
}

describe('FederationPartnersSection errors and switches', () => {
	beforeEach(() => {
		confirmPassword.mockReset().mockResolvedValue(undefined)
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('shows the load error and treats a missing partner list as empty', async () => {
		vi.spyOn(axios, 'get').mockRejectedValue({ response: { data: { message: 'Administrators only' } } })
		const wrapper = mount(FederationPartnersSection, { global: { stubs } })
		await flushPromises()

		expect(wrapper.find('.note[data-type="error"]').text()).toBe('Administrators only')
		expect(wrapper.find('[data-testid="federation-unsupported"]').exists()).toBe(true)

		const ok = await mountWith({ supported: true, partners: 'nope' })
		expect(ok.find('[data-testid="federation-no-partners"]').exists()).toBe(true)
		expect(ok.find('[data-testid="federation-own-fingerprint"]').exists()).toBe(false)
	})

	it('shows why a partner could not be checked and offers no preview', async () => {
		const wrapper = await mountWith({ supported: true, partners: [] })
		const post = vi.spyOn(axios, 'post').mockRejectedValue(new Error('Partner unreachable'))

		await wrapper.find('[data-testid="federation-url"]').setValue('  https://down.example  ')
		await wrapper.find('[data-testid="federation-check"]').trigger('click')
		await flushPromises()

		expect(post).toHaveBeenCalledWith(BASE + '/preview', { url: 'https://down.example' })
		expect(wrapper.find('.note[data-type="error"]').text()).toBe('Partner unreachable')
		expect(wrapper.find('[data-testid="federation-preview"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="federation-check"]').attributes('disabled')).toBeUndefined()
	})

	it('adds nothing before the fingerprint was compared', async () => {
		const wrapper = await mountWith({ supported: true, partners: [] })
		const post = vi.spyOn(axios, 'post')

		await wrapper.vm.add()
		wrapper.vm.preview = PREVIEW
		await wrapper.vm.add()

		expect(confirmPassword).not.toHaveBeenCalled()
		expect(post).not.toHaveBeenCalled()
	})

	it('sends the chosen directions for a new partner', async () => {
		const wrapper = await mountWith({ supported: true, partners: [] })
		const post = vi.spyOn(axios, 'post').mockImplementation(async (url) => ({ data: url.endsWith('/preview') ? PREVIEW : {} }))
		await wrapper.find('[data-testid="federation-url"]').setValue('https://cloud.partner.example')
		await wrapper.find('[data-testid="federation-check"]').trigger('click')
		await flushPromises()

		await switchLabelled(wrapper, 'Users here may share to this partner')[0].vm.$emit('update:modelValue', false)
		await switchLabelled(wrapper, 'This partner may share to users here')[0].vm.$emit('update:modelValue', false)
		await wrapper.findComponent('[data-testid="federation-compared"]').vm.$emit('update:modelValue', true)
		await wrapper.find('[data-testid="federation-add"]').trigger('click')
		await flushPromises()

		expect(post).toHaveBeenLastCalledWith(BASE, {
			url: 'https://cloud.partner.example',
			rootFingerprint: FP,
			allowOutbound: false,
			allowInbound: false,
		})
		expect(wrapper.find('[data-testid="federation-preview"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="federation-url"]').element.value).toBe('')
	})

	it('keeps the preview and shows why when adding is refused', async () => {
		const wrapper = await mountWith({ supported: true, partners: [] })
		vi.spyOn(axios, 'post').mockImplementation(async (url) => {
			if (url.endsWith('/preview')) return { data: PREVIEW }
			throw { response: { data: { message: 'Fingerprint changed' } } }
		})
		await wrapper.find('[data-testid="federation-url"]').setValue('https://cloud.partner.example')
		await wrapper.find('[data-testid="federation-check"]').trigger('click')
		await flushPromises()
		await wrapper.findComponent('[data-testid="federation-compared"]').vm.$emit('update:modelValue', true)
		await wrapper.find('[data-testid="federation-add"]').trigger('click')
		await flushPromises()

		expect(wrapper.find('.note[data-type="error"]').text()).toBe('Fingerprint changed')
		expect(wrapper.find('[data-testid="federation-preview"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="federation-add"]').attributes('disabled')).toBeUndefined()
	})

	it('puts both directions when a listed partner\'s switch flips', async () => {
		const wrapper = await mountWith({ supported: true, partners: [PARTNER] })
		const put = vi.spyOn(axios, 'put').mockResolvedValue({ data: {} })
		const row = wrapper.find('[data-testid="federation-partner"]')
		const [outbound, inbound] = row.findAllComponents({ name: 'NcCheckboxRadioSwitch' })
		expect(outbound.props('modelValue')).toBe(true)
		expect(inbound.props('modelValue')).toBe(false)

		await outbound.vm.$emit('update:modelValue', false)
		await flushPromises()
		expect(put).toHaveBeenLastCalledWith(BASE + '/p%2F1', { allowOutbound: false, allowInbound: false })

		await inbound.vm.$emit('update:modelValue', true)
		await flushPromises()
		expect(put).toHaveBeenLastCalledWith(BASE + '/p%2F1', { allowOutbound: true, allowInbound: true })
		expect(confirmPassword).toHaveBeenCalledTimes(2)
	})
})
