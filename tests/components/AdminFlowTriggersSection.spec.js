/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Flows section of the audit administration: hidden without
 * OpenRegister, every group off by default, and a switched group saved
 * through the audit area settings.
 *
 * @spec openspec/changes/clients-vault-event-flow-triggers/tasks.md#task-2.2
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import AdminFlowTriggersSection, { FLOW_TRIGGER_GROUPS } from '../../src/components/settings/AdminFlowTriggersSection.vue'

const URL = '/apps/keepiq/api/settings/admin/audit'

const stubs = {
	CnSettingsSection: {
		props: ['name', 'description'],
		template: '<section v-bind="$attrs"><h2>{{ name }}</h2><p class="desc">{{ description }}</p><slot /></section>',
	},
	NcCheckboxRadioSwitch: {
		props: ['modelValue', 'disabled'],
		emits: ['update:modelValue'],
		template: '<label v-bind="$attrs"><input type="checkbox" :checked="modelValue" :disabled="disabled" @change="$emit(\'update:modelValue\', $event.target.checked)"><slot /></label>',
	},
}

async function mountIt() {
	const wrapper = mount(AdminFlowTriggersSection, { global: { stubs } })
	await flushPromises()
	return wrapper
}

describe('AdminFlowTriggersSection', () => {
	let previousOC

	beforeEach(() => {
		previousOC = window.OC
		window.OC = { ...(window.OC || {}), appswebroots: { keepiq: '/apps/keepiq', openregister: '/apps/openregister' } }
	})

	afterEach(() => {
		window.OC = previousOC
		vi.restoreAllMocks()
	})

	it('is hidden and asks nothing without OpenRegister', async () => {
		window.OC = { ...(window.OC || {}), appswebroots: { keepiq: '/apps/keepiq' } }
		const get = vi.spyOn(axios, 'get')
		const wrapper = await mountIt()
		expect(wrapper.find('[data-testid="flow-triggers"]').exists()).toBe(false)
		expect(get).not.toHaveBeenCalled()
	})

	it('shows one switch per group, all off on a fresh install, and says flows never get a secret', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: { audit_retention_days: 365, flow_trigger_groups: [] } })
		const wrapper = await mountIt()
		expect(wrapper.find('[data-testid="flow-triggers"]').exists()).toBe(true)
		expect(wrapper.find('.desc').text()).toBe('Flows receive who did what and when, never a secret.')
		for (const group of FLOW_TRIGGER_GROUPS) {
			const input = wrapper.find(`[data-testid="flow-group-${group}"] input`)
			expect(input.exists()).toBe(true)
			expect(input.element.checked).toBe(false)
		}
		expect(wrapper.find('[data-testid="flow-group-emergency_access"]').text()).toBe('Emergency access')
	})

	it('saves the emergency access group when it is switched on', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: { flow_trigger_groups: ['sharing'] } })
		const put = vi.spyOn(axios, 'put').mockResolvedValue({ data: { flow_trigger_groups: ['sharing', 'emergency_access'] } })
		const wrapper = await mountIt()
		expect(wrapper.find('[data-testid="flow-group-sharing"] input').element.checked).toBe(true)

		await wrapper.find('[data-testid="flow-group-emergency_access"] input').setValue(true)
		await flushPromises()
		expect(put).toHaveBeenCalledWith(URL, { flow_trigger_groups: ['sharing', 'emergency_access'] })
		expect(wrapper.find('[data-testid="flow-group-emergency_access"] input').element.checked).toBe(true)
	})

	it('switches a group off and shows a refused save, reloading the stored groups', async () => {
		const get = vi.spyOn(axios, 'get').mockResolvedValue({ data: { flow_trigger_groups: ['sharing'] } })
		vi.spyOn(axios, 'put').mockRejectedValue({ response: { data: { message: 'Unknown flow trigger group' } } })
		const wrapper = await mountIt()
		await wrapper.find('[data-testid="flow-group-sharing"] input').setValue(false)
		await flushPromises()
		expect(wrapper.find('[data-testid="flow-triggers-error"]').text()).toBe('Unknown flow trigger group')
		expect(get).toHaveBeenCalledTimes(2)
		expect(wrapper.findComponent('[data-testid="flow-group-sharing"]').props('modelValue')).toBe(true)
	})
})
