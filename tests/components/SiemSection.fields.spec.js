/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Every field of the SIEM sink form reaches the request body: a Sentinel
 * sink with all its settings, a syslog sink with TLS off, and a webhook
 * with its signing secret, each created through the form.
 *
 * @spec openspec/specs/siem-vendor-connectors/spec.md
 * @spec openspec/specs/siem-audit-export/spec.md#requirement-admin-configured-syslog-and-webhook-sinks
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import SiemSection from '../../src/components/settings/SiemSection.vue'

const URL = '/apps/keepiq/api/v1/siem/sinks'

const stubs = {
	CnSettingsSection: { template: '<section><slot /></section>' },
	NcButton: {
		props: ['variant', 'disabled'],
		emits: ['click'],
		template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
	NcNoteCard: { template: '<div class="note"><slot /></div>' },
	NcSelect: {
		name: 'NcSelect',
		props: ['modelValue', 'options', 'inputLabel', 'label', 'clearable', 'multiple'],
		emits: ['update:modelValue'],
		template: '<div class="select" />',
	},
	NcTextField: {
		props: ['modelValue', 'label', 'placeholder', 'type'],
		emits: ['update:modelValue'],
		template: '<input :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)">',
	},
	NcCheckboxRadioSwitch: {
		name: 'NcCheckboxRadioSwitch',
		props: ['modelValue', 'type'],
		emits: ['update:modelValue'],
		template: '<label class="switch"><slot /></label>',
	},
}

/**
 * @return {Promise<object>} The section with the add form open.
 */
async function openForm() {
	vi.spyOn(axios, 'get').mockResolvedValue({ data: [] })
	const wrapper = mount(SiemSection, { global: { stubs } })
	await flushPromises()
	await wrapper.find('[data-testid="siem-add"]').trigger('click')
	return wrapper
}

/**
 * @param {object} wrapper The section.
 * @param {string} testid The picker's test id.
 * @return {object} The NcSelect stub.
 */
function picker(wrapper, testid) {
	return wrapper.findAllComponents({ name: 'NcSelect' }).find((c) => c.attributes('data-testid') === testid)
}

/**
 * @param {object} wrapper The section.
 * @param {string} testid The switch's test id.
 * @return {object} The switch stub.
 */
function toggle(wrapper, testid) {
	return wrapper.findAllComponents({ name: 'NcCheckboxRadioSwitch' }).find((c) => c.attributes('data-testid') === testid)
}

describe('SiemSection form fields', () => {
	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('creates a Sentinel sink with every field the form holds', async () => {
		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: { id: 'n1', name: 'SOC', type: 'sentinel' } })
		const wrapper = await openForm()

		await picker(wrapper, 'siem-form-connector').vm.$emit('update:modelValue', { id: 'sentinel', label: 'Microsoft Sentinel' })
		await wrapper.find('[data-testid="siem-form-name"]').setValue('SOC')
		await wrapper.find('[data-testid="siem-form-endpoint"]').setValue('https://dce.example')
		await wrapper.find('[data-testid="siem-form-tenant"]').setValue('tenant-1')
		await wrapper.find('[data-testid="siem-form-client"]').setValue('client-1')
		await wrapper.find('[data-testid="siem-form-dcr"]').setValue('dcr-1')
		await wrapper.find('[data-testid="siem-form-stream"]').setValue('Custom-Audit')
		await wrapper.find('[data-testid="siem-form-credential"]').setValue('client-secret')
		await picker(wrapper, 'siem-form-categories').vm.$emit('update:modelValue', ['secret', 'share'])
		await wrapper.find('[data-testid="siem-form-queuecap"]').setValue('250')
		await toggle(wrapper, 'siem-form-enabled').vm.$emit('update:modelValue', false)
		await wrapper.find('[data-testid="siem-form-save"]').trigger('click')
		await flushPromises()

		expect(post).toHaveBeenCalledWith(URL, {
			name: 'SOC',
			type: 'sentinel',
			format: 'json',
			endpoint: 'https://dce.example',
			tls: true,
			hmacSecret: '',
			credential: 'client-secret',
			categoryFilter: ['secret', 'share'],
			queueCap: 250,
			enabled: false,
			connectorOptions: {
				tenantId: 'tenant-1',
				clientId: 'client-1',
				dcrImmutableId: 'dcr-1',
				streamName: 'Custom-Audit',
				dataCollectionEndpoint: 'https://dce.example',
			},
		})
		expect(wrapper.find('[data-testid="siem-form"]').exists()).toBe(false)
	})

	it('keeps Save disabled until the Sentinel ids are filled', async () => {
		const wrapper = await openForm()
		await picker(wrapper, 'siem-form-connector').vm.$emit('update:modelValue', { id: 'sentinel' })
		await wrapper.find('[data-testid="siem-form-endpoint"]').setValue('https://dce.example')
		await wrapper.find('[data-testid="siem-form-credential"]').setValue('x')
		await wrapper.find('[data-testid="siem-form-tenant"]').setValue('t')
		await wrapper.find('[data-testid="siem-form-client"]').setValue('c')

		expect(wrapper.find('[data-testid="siem-form-save"]').attributes('disabled')).toBeDefined()
		await wrapper.find('[data-testid="siem-form-dcr"]').setValue('d')
		expect(wrapper.find('[data-testid="siem-form-save"]').attributes('disabled')).toBeUndefined()
	})

	it('creates a CEF syslog sink with TLS switched off and its connector as name', async () => {
		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: { id: 'n2', name: 'syslog_cef', type: 'syslog' } })
		const wrapper = await openForm()

		await picker(wrapper, 'siem-form-connector').vm.$emit('update:modelValue', { id: 'syslog_cef' })
		await wrapper.find('[data-testid="siem-form-endpoint"]').setValue('logs.example:6514')
		await toggle(wrapper, 'siem-form-tls').vm.$emit('update:modelValue', false)
		await wrapper.find('[data-testid="siem-form-save"]').trigger('click')
		await flushPromises()

		expect(post.mock.calls[0][1]).toMatchObject({
			name: 'syslog_cef',
			type: 'syslog',
			format: 'cef',
			endpoint: 'logs.example:6514',
			tls: false,
			credential: '',
			hmacSecret: '',
			queueCap: 1000,
		})
		expect(post.mock.calls[0][1].connectorOptions).toBeUndefined()
	})

	it('sends the webhook signing secret', async () => {
		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: { id: 'n3', name: 'hook', type: 'webhook' } })
		const wrapper = await openForm()

		await picker(wrapper, 'siem-form-connector').vm.$emit('update:modelValue', { id: 'webhook' })
		await wrapper.find('[data-testid="siem-form-endpoint"]').setValue('https://hooks.example/audit')
		await wrapper.find('[data-testid="siem-form-secret"]').setValue('sign-me')
		await wrapper.find('[data-testid="siem-form-save"]').trigger('click')
		await flushPromises()

		expect(post.mock.calls[0][1]).toMatchObject({
			type: 'webhook',
			endpoint: 'https://hooks.example/audit',
			hmacSecret: 'sign-me',
			credential: '',
		})
	})

	it('sends the Splunk sourcetype as a connector option', async () => {
		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: { id: 'n4', name: 'splunk', type: 'splunk_hec' } })
		const wrapper = await openForm()

		await wrapper.find('[data-testid="siem-form-endpoint"]').setValue('https://splunk.example/services/collector/event')
		await wrapper.find('[data-testid="siem-form-credential"]').setValue('hec')
		await wrapper.find('[data-testid="siem-form-sourcetype"]').setValue('keepiq:audit')
		await wrapper.find('[data-testid="siem-form-save"]').trigger('click')
		await flushPromises()

		expect(post.mock.calls[0][1].connectorOptions).toEqual({ sourcetype: 'keepiq:audit' })
	})
})
