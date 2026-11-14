/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pins what saving a sink in the SIEM section sends and keeps.
 */

import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import SiemSection from '../../src/components/settings/SiemSection.vue'
import { requestBody } from '../../src/components/settings/siemConnectors.js'

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

const SINK = {
	id: 's1',
	name: 'Syslog',
	type: 'syslog',
	format: 'cef',
	endpoint: 'tls://syslog.example:6514',
	tls: true,
	hasCredential: false,
	hasHmacSecret: false,
	categoryFilter: null,
	queueCap: 1000,
	enabled: true,
	connectorOptions: {},
}
const OTHER = { ...SINK, id: 's2', name: 'Other' }

async function mountSection(sinks) {
	vi.spyOn(axios, 'get').mockResolvedValue({ data: sinks })
	const wrapper = mount(SiemSection, {
		global: {
			mixins: [{ methods: { t: (_app, key) => key } }],
			stubs: {
				CnSettingsSection: { template: '<section><slot /></section>' },
				NcButton: { template: '<button v-bind="$attrs"><slot /></button>' },
				NcNoteCard: { template: '<div><slot /></div>' },
				NcSelect: { props: ['modelValue', 'options'], template: '<div v-bind="$attrs" />' },
				NcTextField: { props: ['modelValue', 'label'], template: '<input v-bind="$attrs">' },
				NcCheckboxRadioSwitch: { props: ['modelValue'], template: '<label v-bind="$attrs"><slot /></label>' },
			},
		},
	})
	await flush()
	return wrapper
}

describe('SiemSection save', () => {
	beforeEach(() => {
		vi.restoreAllMocks()
	})

	it('puts an edited sink without its type and swaps the row in place', async () => {
		const wrapper = await mountSection([OTHER, SINK])
		await wrapper.find('[data-testid="siem-edit-s1"]').trigger('click')
		const { type, ...expected } = requestBody(wrapper.vm.form)
		expect(type).toBe('syslog')
		const updated = { ...SINK, name: 'Renamed' }
		const put = vi.spyOn(axios, 'put').mockResolvedValue({ data: updated })
		const post = vi.spyOn(axios, 'post')
		await wrapper.vm.onSave()
		expect(post).not.toHaveBeenCalled()
		expect(put).toHaveBeenCalledWith(expect.stringContaining('/apps/keepiq/api/v1/siem/sinks/s1'), expected)
		expect(wrapper.vm.sinks).toEqual([OTHER, updated])
		expect(wrapper.vm.formOpen).toBe(false)
		expect(wrapper.vm.busy).toBe(false)
		expect(wrapper.vm.error).toBe(null)
	})

	it('posts a new sink with its type last and puts it first', async () => {
		const wrapper = await mountSection([OTHER])
		await wrapper.find('[data-testid="siem-add"]').trigger('click')
		wrapper.vm.form.name = 'New'
		wrapper.vm.form.endpoint = 'tls://new.example:6514'
		const { type, ...rest } = requestBody(wrapper.vm.form)
		const created = { ...SINK, id: 'n1' }
		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: created })
		await wrapper.vm.onSave()
		expect(post.mock.calls[0][0]).toMatch(/\/apps\/keepiq\/api\/v1\/siem\/sinks$/)
		expect(post.mock.calls[0][1]).toEqual({ ...rest, type })
		expect(Object.keys(post.mock.calls[0][1]).at(-1)).toBe('type')
		expect(wrapper.vm.sinks).toEqual([created, OTHER])
		expect(wrapper.vm.formOpen).toBe(false)
	})

	it.each([
		[{ response: { data: { message: 'refused' } }, message: 'x' }, 'refused'],
		[{ message: 'network' }, 'network'],
		[{}, undefined],
	])('keeps the form open with the failure %#', async (failure, text) => {
		const wrapper = await mountSection([SINK])
		await wrapper.find('[data-testid="siem-edit-s1"]').trigger('click')
		vi.spyOn(axios, 'put').mockRejectedValue(failure)
		await wrapper.vm.onSave()
		expect(wrapper.vm.error).toBe(text)
		expect(wrapper.vm.formOpen).toBe(true)
		expect(wrapper.vm.busy).toBe(false)
		expect(wrapper.vm.sinks).toEqual([SINK])
	})
})
