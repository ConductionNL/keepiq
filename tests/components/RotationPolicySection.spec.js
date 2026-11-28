/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The admin fields for the rotation defaults show the stored maximum age and
 * reminder thresholds, and save only positive thresholds.
 *
 * @spec openspec/specs/rotation-expiry-policies/spec.md#requirement-expiry-policies-with-admin-default-and-user-override
 * @spec openspec/specs/rotation-expiry-policies/spec.md#requirement-approaching-expiry-and-overdue-reminders
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import RotationPolicySection from '../../src/components/settings/RotationPolicySection.vue'

const ROUTE = '/apps/keepiq/api/settings/admin/policies'
const STORED = { expiry_default_max_age_days: 90, expiry_reminder_days: [14, 2] }

const stubs = {
	CnSettingsSection: { template: '<section><slot /></section>' },
	NcNoteCard: { props: ['type'], template: '<div class="note"><slot /></div>' },
}

async function mountSection(stored = STORED) {
	vi.spyOn(axios, 'get').mockResolvedValue({ data: stored })
	const wrapper = mount(RotationPolicySection, { global: { stubs } })
	await flushPromises()
	return wrapper
}

async function change(wrapper, testid, value) {
	await wrapper.find(`[data-testid="${testid}"]`).setValue(value)
	await flushPromises()
}

function value(wrapper, testid) {
	return wrapper.find(`[data-testid="${testid}"]`).element.value
}

describe('RotationPolicySection', () => {
	afterEach(() => vi.restoreAllMocks())

	it('shows the stored maximum age and thresholds', async () => {
		const wrapper = await mountSection()

		expect(axios.get).toHaveBeenCalledWith(ROUTE)
		expect(value(wrapper, 'expiry-default-max-age')).toBe('90')
		expect(value(wrapper, 'expiry-reminder-days')).toBe('14, 2')
	})

	it('defaults to no maximum age and thresholds of 30, 7 and 1 days', async () => {
		const wrapper = await mountSection({})

		expect(value(wrapper, 'expiry-default-max-age')).toBe('0')
		expect(value(wrapper, 'expiry-reminder-days')).toBe('30, 7, 1')
	})

	it('saves a negative or empty maximum age as 0', async () => {
		const put = vi.spyOn(axios, 'put').mockResolvedValue({ data: {} })
		const wrapper = await mountSection()

		await change(wrapper, 'expiry-default-max-age', '-5')
		expect(put).toHaveBeenLastCalledWith(ROUTE, {
			...STORED,
			expiry_default_max_age_days: 0,
		})

		await change(wrapper, 'expiry-default-max-age', '')
		expect(put).toHaveBeenLastCalledWith(ROUTE, {
			...STORED,
			expiry_default_max_age_days: 0,
		})
	})

	it('saves only the positive thresholds and shows the cleaned list', async () => {
		const put = vi.spyOn(axios, 'put').mockResolvedValue({ data: {} })
		const wrapper = await mountSection()

		await change(wrapper, 'expiry-reminder-days', ' 10, x, 0, 3 ')

		expect(put).toHaveBeenCalledTimes(1)
		expect(put).toHaveBeenCalledWith(ROUTE, {
			...STORED,
			expiry_reminder_days: [10, 3],
		})
		expect(value(wrapper, 'expiry-reminder-days')).toBe('10, 3')
	})

	it('refuses thresholds without a positive day and sends nothing', async () => {
		const put = vi.spyOn(axios, 'put').mockResolvedValue({ data: {} })
		const wrapper = await mountSection()

		await change(wrapper, 'expiry-reminder-days', 'none, -2')

		expect(put).not.toHaveBeenCalled()
		expect(wrapper.find('.note').text()).toBe(
			'At least one positive reminder threshold is required.',
		)
		expect(value(wrapper, 'expiry-reminder-days')).toBe('none, -2')

		await change(wrapper, 'expiry-reminder-days', '5')
		expect(put).toHaveBeenCalledWith(ROUTE, {
			...STORED,
			expiry_reminder_days: [5],
		})
		expect(wrapper.find('.note').exists()).toBe(false)
	})

	it('shows the server message when loading is refused', async () => {
		vi.spyOn(axios, 'get').mockRejectedValue({
			response: { data: { message: 'Server says no' } },
		})
		const wrapper = mount(RotationPolicySection, { global: { stubs } })
		await flushPromises()

		expect(wrapper.find('.note').text()).toBe('Server says no')
		expect(value(wrapper, 'expiry-reminder-days')).toBe('30, 7, 1')
	})

	it('shows the server message when saving is refused, and clears it on the next save', async () => {
		const put = vi.spyOn(axios, 'put').mockRejectedValueOnce({
			response: { data: { message: 'Server says no' } },
		})
		const wrapper = await mountSection()

		await change(wrapper, 'expiry-default-max-age', '30')
		expect(wrapper.find('.note').text()).toBe('Server says no')
		expect(value(wrapper, 'expiry-reminder-days')).toBe('14, 2')

		put.mockResolvedValue({ data: {} })
		await change(wrapper, 'expiry-default-max-age', '31')
		expect(wrapper.find('.note').exists()).toBe(false)
	})
})
