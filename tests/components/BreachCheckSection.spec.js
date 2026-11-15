/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The admin breach-check master toggle: off unless the instance setting says
 * so, and every change written straight back to the general admin settings.
 *
 * @spec openspec/changes/password-health/specs/password-health/spec.md#requirement-opt-in-breach-checking-via-k-anonymity
 */

import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import BreachCheckSection from '../../src/components/settings/BreachCheckSection.vue'

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

const URL = '/apps/keepiq/api/settings/admin/general'

function mountSection() {
	return mount(BreachCheckSection, {
		global: { stubs: { CnSettingsSection: { template: '<section><slot /></section>' } } },
	})
}

describe('BreachCheckSection', () => {
	beforeEach(() => {
		vi.restoreAllMocks()
	})

	it.each([
		[true, true],
		['1', true],
		['0', false],
		[false, false],
		[undefined, false],
	])('reads breach_check_enabled=%s as %s', async (stored, expected) => {
		const get = vi.spyOn(axios, 'get').mockResolvedValue({ data: { breach_check_enabled: stored } })
		const wrapper = mountSection()
		await flush()

		expect(get).toHaveBeenCalledWith(URL)
		expect(wrapper.vm.enabled).toBe(expected)
		expect(wrapper.find('[data-testid="breach-check-enabled"]').element.checked).toBe(expected)
	})

	it('stays off when the setting cannot be loaded', async () => {
		vi.spyOn(axios, 'get').mockRejectedValue(new Error('500'))
		const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
		const wrapper = mountSection()
		await flush()

		expect(wrapper.vm.enabled).toBe(false)
		expect(warn).toHaveBeenCalled()
	})

	it('writes the new state as a boolean when toggled', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: {} })
		const put = vi.spyOn(axios, 'put').mockResolvedValue({ data: {} })
		const wrapper = mountSection()
		await flush()

		await wrapper.find('[data-testid="breach-check-enabled"]').setValue(true)
		await flush()

		expect(put).toHaveBeenCalledWith(URL, { breach_check_enabled: true })
	})
})
