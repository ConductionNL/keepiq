/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The SIEM section's connector picker: the field set per connector, the
 * request body each one sends, and a credential field that is never
 * prefilled.
 *
 * @spec openspec/specs/siem-vendor-connectors/spec.md
 */

import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import SiemSection from '../../src/components/settings/SiemSection.vue'
import { CONNECTORS, connectorOf, fieldsFor, formIsValid, requestBody } from '../../src/components/settings/siemConnectors.js'

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

const FIELD_TESTIDS = {
	endpoint: 'siem-form-endpoint',
	tls: 'siem-form-tls',
	hmacSecret: 'siem-form-secret',
	credential: 'siem-form-credential',
	tenantId: 'siem-form-tenant',
	clientId: 'siem-form-client',
	dcrImmutableId: 'siem-form-dcr',
	streamName: 'siem-form-stream',
	index: 'siem-form-index',
	sourcetype: 'siem-form-sourcetype',
}

function mountSection(sinks = []) {
	vi.spyOn(axios, 'get').mockResolvedValue({ data: sinks })
	return mount(SiemSection, {
		global: {
			mixins: [{ methods: { t: (_app, key) => key } }],
			stubs: {
				CnSettingsSection: { template: '<section><slot /></section>' },
				NcButton: { template: '<button v-bind="$attrs"><slot /></button>' },
				NcNoteCard: { template: '<div><slot /></div>' },
				NcSelect: { props: ['modelValue', 'options'], template: '<div v-bind="$attrs" />' },
				NcTextField: {
					props: ['modelValue', 'label'],
					emits: ['update:modelValue'],
					template: '<input v-bind="$attrs" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)">',
				},
				NcCheckboxRadioSwitch: { props: ['modelValue'], template: '<label v-bind="$attrs"><slot /></label>' },
			},
		},
	})
}

describe('siemConnectors', () => {
	it('offers the five connectors with their own field sets', () => {
		expect(Object.keys(CONNECTORS)).toEqual(['splunk_hec', 'sentinel', 'syslog_cef', 'syslog_json', 'webhook'])
		expect(fieldsFor('splunk_hec')).toEqual(['endpoint', 'credential', 'index', 'sourcetype'])
		expect(fieldsFor('sentinel')).toEqual(['endpoint', 'tenantId', 'clientId', 'dcrImmutableId', 'streamName', 'credential'])
		expect(fieldsFor('syslog_cef')).toEqual(['endpoint', 'tls'])
		expect(fieldsFor('webhook')).toEqual(['endpoint', 'hmacSecret'])
	})

	it('maps a stored sink back to its connector', () => {
		expect(connectorOf({ type: 'syslog', format: 'cef' })).toBe('syslog_cef')
		expect(connectorOf({ type: 'syslog', format: 'json' })).toBe('syslog_json')
		expect(connectorOf({ type: 'sentinel', format: 'json' })).toBe('sentinel')
	})

	it('builds the request body per connector', () => {
		const base = { name: '', endpoint: 'https://dce.example', tls: true, hmacSecret: 'h', credential: 'secret', categoryFilter: [], queueCap: '50', enabled: true }
		const sentinel = requestBody({ ...base, connector: 'sentinel', tenantId: 't', clientId: 'c', dcrImmutableId: 'd', streamName: '' })
		expect(sentinel).toMatchObject({ type: 'sentinel', format: 'json', credential: 'secret', hmacSecret: '', queueCap: 50 })
		expect(sentinel.connectorOptions).toEqual({ tenantId: 't', clientId: 'c', dcrImmutableId: 'd', dataCollectionEndpoint: 'https://dce.example' })
		const cef = requestBody({ ...base, connector: 'syslog_cef', endpoint: 'h:514' })
		expect(cef).toMatchObject({ type: 'syslog', format: 'cef', credential: '', hmacSecret: '' })
		expect(cef.connectorOptions).toBeUndefined()
	})

	it('requires https, the Sentinel settings and, on create, the credential', () => {
		const ok = { connector: 'splunk_hec', endpoint: 'https://s:8088/services/collector/event', credential: 't' }
		expect(formIsValid(ok, true)).toBe(true)
		expect(formIsValid({ ...ok, endpoint: 'http://s' }, true)).toBe(false)
		expect(formIsValid({ ...ok, credential: '' }, true)).toBe(false)
		expect(formIsValid({ ...ok, credential: '' }, false)).toBe(true)
		expect(formIsValid({ connector: 'sentinel', endpoint: 'https://d', credential: 's', tenantId: 't', clientId: '' }, true)).toBe(false)
		expect(formIsValid({ connector: 'syslog_cef', endpoint: 'h:514' }, true)).toBe(true)
	})
})

describe('SiemSection connector form', () => {
	beforeEach(() => vi.restoreAllMocks())

	for (const connector of Object.keys(CONNECTORS)) {
		it(`shows only the ${connector} fields`, async () => {
			const wrapper = mountSection()
			await flush()
			await wrapper.find('[data-testid="siem-add"]').trigger('click')
			wrapper.vm.form.connector = connector
			await flush()
			for (const [field, testid] of Object.entries(FIELD_TESTIDS)) {
				expect(wrapper.find(`[data-testid="${testid}"]`).exists(), `${connector} ${field}`).toBe(fieldsFor(connector).includes(field))
			}
		})
	}

	it('never prefills the credential when editing', async () => {
		const sink = {
			id: 's1', name: 'Sentinel', type: 'sentinel', format: 'json', endpoint: 'https://dce.example', tls: true,
			hasCredential: true, hasHmacSecret: false, categoryFilter: null, queueCap: 1000, enabled: true,
			connectorOptions: { tenantId: 't-1', clientId: 'c-1', dcrImmutableId: 'd-1', streamName: 'Custom-KeepiqAudit' },
		}
		const wrapper = mountSection([sink])
		await flush()
		await wrapper.find('[data-testid="siem-edit-s1"]').trigger('click')
		await flush()
		const credential = wrapper.find('[data-testid="siem-form-credential"]')
		expect(credential.exists()).toBe(true)
		expect(credential.element.value).toBe('')
		expect(credential.attributes('placeholder')).toBe('Leave blank to keep the current one')
		expect(wrapper.find('[data-testid="siem-form-tenant"]').element.value).toBe('t-1')
	})

	it('posts the picked connector', async () => {
		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: { id: 'n', name: 'x', type: 'splunk_hec' } })
		const wrapper = mountSection()
		await flush()
		await wrapper.find('[data-testid="siem-add"]').trigger('click')
		await wrapper.find('[data-testid="siem-form-endpoint"]').setValue('https://splunk.example.org:8088/services/collector/event')
		await wrapper.find('[data-testid="siem-form-credential"]').setValue('hec-token')
		await wrapper.find('[data-testid="siem-form-index"]').setValue('security')
		await wrapper.find('[data-testid="siem-form-save"]').trigger('click')
		await flush()
		expect(post).toHaveBeenCalledTimes(1)
		expect(post.mock.calls[0][1]).toMatchObject({ type: 'splunk_hec', format: 'json', credential: 'hec-token', connectorOptions: { index: 'security' } })
	})
})
