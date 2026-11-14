/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The SIEM section's sink rows and row actions: the delivery status label and
 * colour, dates, a failed list load, test-firing a sink (delivered, failed,
 * refused), deleting a sink, and the per-connector labels of the form.
 *
 * @spec openspec/specs/siem-audit-export/spec.md#requirement-admin-configured-syslog-and-webhook-sinks
 * @spec openspec/specs/siem-audit-export/spec.md#requirement-backpressure-and-observability
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import SiemSection from '../../src/components/settings/SiemSection.vue'

const SINKS = [
	{ id: 1, name: 'Splunk', type: 'splunk_hec', enabled: true, lastDeliveryStatus: 'ok', lastSuccessAt: '2026-11-01T10:00:00Z' },
	{ id: 2, name: 'Old syslog', type: 'syslog_cef', enabled: false, lastDeliveryStatus: 'failing', lastError: 'connection refused', lastSuccessAt: null },
	{ id: 3, name: 'Hook', type: 'webhook', enabled: true, lastDeliveryStatus: 'dead' },
	{ id: 4, name: 'Fresh', type: 'webhook', enabled: true, lastDeliveryStatus: null },
	{ id: 5, name: 'Odd', type: 'webhook', enabled: true, lastDeliveryStatus: 'paused' },
]

/**
 * Mount the section with the given sink list (or a failing list load).
 *
 * @param {Array<object>|null|object} sinks The sinks (null for an empty body), or the load failure.
 * @return {Promise<object>} The wrapper.
 */
async function mountSection(sinks = SINKS) {
	if (sinks !== null && !Array.isArray(sinks)) {
		vi.spyOn(axios, 'get').mockRejectedValue(sinks)
	} else {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: sinks })
	}
	const wrapper = mount(SiemSection, {
		global: {
			stubs: {
				CnSettingsSection: { template: '<section><slot /></section>' },
				NcButton: { template: '<button v-bind="$attrs"><slot /></button>' },
				NcNoteCard: { props: ['type'], template: '<div :class="`note-${type}`"><slot /></div>' },
				NcSelect: { props: ['modelValue', 'options'], template: '<div v-bind="$attrs" />' },
				NcTextField: {
					props: ['modelValue', 'label', 'placeholder'],
					template: '<input v-bind="$attrs" :value="modelValue" :aria-label="label" :placeholder="placeholder">',
				},
				NcCheckboxRadioSwitch: { props: ['modelValue'], template: '<label v-bind="$attrs"><slot /></label>' },
			},
		},
	})
	await flushPromises()
	return wrapper
}

afterEach(() => {
	vi.restoreAllMocks()
})

describe('SiemSection rows', () => {
	it.each([
		[1, 'ok', 'siem__status--ok'],
		[2, 'failing', 'siem__status--bad'],
		[3, 'dead', 'siem__status--bad'],
		[4, 'never attempted', 'siem__muted'],
		[5, 'paused', 'siem__muted'],
	])('labels sink %i as %s with class %s', async (id, label, cls) => {
		const wrapper = await mountSection()
		const status = wrapper.find(`[data-testid="siem-status-${id}"]`)

		expect(status.text()).toBe(label)
		expect(status.classes()).toEqual([cls])
	})

	it('marks a disabled sink, shows its last error and a dash for no success', async () => {
		const wrapper = await mountSection()
		const row = wrapper.find('[data-testid="siem-sink-2"]')
		const cells = row.findAll('td')

		expect(cells[0].text()).toContain('(disabled)')
		expect(row.find('.siem__error-detail').text()).toBe('connection refused')
		expect(cells[3].text()).toBe('—')
		expect(wrapper.find('[data-testid="siem-sink-1"]').findAll('td')[0].text()).not.toContain('disabled')
	})

	it('renders a last-success time as a locale string', async () => {
		const wrapper = await mountSection()

		expect(wrapper.find('[data-testid="siem-sink-1"]').findAll('td')[3].text()).toBe(
			new Date('2026-11-01T10:00:00Z').toLocaleString(),
		)
	})

	it('says so when there are no sinks, and treats a null list as empty', async () => {
		const wrapper = await mountSection(null)

		expect(wrapper.find('[data-testid="siem-sink-list"]').exists()).toBe(false)
		expect(wrapper.find('.siem__muted').text()).toBe('No SIEM sinks configured.')
	})

	it('shows the server message when the list cannot be loaded', async () => {
		const wrapper = await mountSection({ response: { data: { message: 'Admins only' } } })

		expect(wrapper.find('.note-error').text()).toBe('Admins only')
	})
})

describe('SiemSection row actions', () => {
	it('reports a delivered test event as a notice', async () => {
		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: { ok: true } })
		const wrapper = await mountSection()

		await wrapper.find('[data-testid="siem-test-1"]').trigger('click')
		await flushPromises()

		expect(post).toHaveBeenCalledWith('/apps/keepiq/api/v1/siem/sinks/1/test')
		expect(wrapper.find('.note-success').text()).toBe('Test event delivered to "{name}".')
		expect(wrapper.find('.note-error').exists()).toBe(false)
		expect(wrapper.vm.busy).toBe(false)
	})

	it('reports a failed test delivery as an error', async () => {
		vi.spyOn(axios, 'post').mockResolvedValue({ data: { ok: false, error: 'timeout' } })
		const wrapper = await mountSection()

		await wrapper.find('[data-testid="siem-test-2"]').trigger('click')
		await flushPromises()

		expect(wrapper.find('.note-error').text()).toBe('Test delivery to "{name}" failed: {error}')
		expect(wrapper.find('.note-success').exists()).toBe(false)
	})

	it('surfaces a refused test request', async () => {
		vi.spyOn(axios, 'post').mockRejectedValue(new Error('Request failed with status code 403'))
		const wrapper = await mountSection()

		await wrapper.find('[data-testid="siem-test-3"]').trigger('click')
		await flushPromises()

		expect(wrapper.find('.note-error').text()).toBe('Request failed with status code 403')
		expect(wrapper.vm.busy).toBe(false)
	})

	it('deletes a sink and drops only its row', async () => {
		const del = vi.spyOn(axios, 'delete').mockResolvedValue({ data: {} })
		const wrapper = await mountSection()

		await wrapper.find('[data-testid="siem-delete-3"]').trigger('click')
		await flushPromises()

		expect(del).toHaveBeenCalledWith('/apps/keepiq/api/v1/siem/sinks/3')
		expect(wrapper.find('[data-testid="siem-sink-3"]').exists()).toBe(false)
		expect(wrapper.findAll('tbody tr')).toHaveLength(4)
	})

	it('keeps the row when the delete is refused', async () => {
		vi.spyOn(axios, 'delete').mockRejectedValue({ response: { data: { message: 'Locked' } } })
		const wrapper = await mountSection()

		await wrapper.find('[data-testid="siem-delete-3"]').trigger('click')
		await flushPromises()

		expect(wrapper.find('[data-testid="siem-sink-3"]').exists()).toBe(true)
		expect(wrapper.find('.note-error').text()).toBe('Locked')
	})

	it('closes the form on cancel', async () => {
		const wrapper = await mountSection()

		await wrapper.find('[data-testid="siem-add"]').trigger('click')
		expect(wrapper.find('[data-testid="siem-form"]').exists()).toBe(true)

		await wrapper.find('[data-testid="siem-form-cancel"]').trigger('click')
		expect(wrapper.find('[data-testid="siem-form"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="siem-add"]').exists()).toBe(true)
	})
})

describe('SiemSection form labels', () => {
	it.each([
		['splunk_hec', 'HTTP Event Collector URL (https)', 'HEC token (write-only)'],
		['sentinel', 'Data collection endpoint (https URL)', 'Client secret (write-only)'],
		['syslog_cef', 'Endpoint (host:port)', 'HEC token (write-only)'],
		['syslog_json', 'Endpoint (host:port)', 'HEC token (write-only)'],
		['webhook', 'Endpoint (https URL)', 'HEC token (write-only)'],
	])('labels the %s endpoint and credential', async (connector, endpoint, credential) => {
		const wrapper = await mountSection([])
		await wrapper.find('[data-testid="siem-add"]').trigger('click')

		wrapper.vm.form.connector = connector
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.endpointLabel).toBe(endpoint)
		expect(wrapper.vm.credentialLabel).toBe(credential)
		expect(wrapper.find('[data-testid="siem-form-endpoint"]').attributes('aria-label')).toBe(endpoint)
	})

	it('falls back to Splunk when the picker is cleared', async () => {
		const wrapper = await mountSection([])
		await wrapper.find('[data-testid="siem-add"]').trigger('click')

		wrapper.vm.connectorOption = { id: 'webhook' }
		expect(wrapper.vm.form.connector).toBe('webhook')
		wrapper.vm.connectorOption = null
		expect(wrapper.vm.form.connector).toBe('splunk_hec')
		expect(wrapper.vm.connectorOption.id).toBe('splunk_hec')
	})
})
