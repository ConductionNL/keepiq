/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The personal settings section with the four notification toggles: it reads
 * the stored preferences once and writes back only the toggle that changed,
 * as the '1' / '0' strings the user-settings endpoint stores.
 *
 * @spec openspec/specs/user-settings/spec.md#requirement-notification-toggles-mvp
 */

import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import NotificationTogglesSection from '../../src/components/settings/NotificationTogglesSection.vue'

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

const stubs = {
	CnSettingsSection: { template: '<section><slot /></section>' },
}

describe('NotificationTogglesSection', () => {
	beforeEach(() => {
		vi.restoreAllMocks()
	})

	it('loads the stored preferences, reading "1" and true as on and anything else as off', async () => {
		const get = vi.spyOn(axios, 'get').mockResolvedValue({
			data: { notify_shares: '0', notify_requests: true, notify_security: '1' },
		})
		const wrapper = mount(NotificationTogglesSection, { global: { stubs } })
		await flush()

		expect(get).toHaveBeenCalledWith('/apps/keepiq/api/settings/user')
		expect(wrapper.vm.prefs).toEqual({
			notify_shares: false,
			notify_requests: true,
			notify_group_shares: true,
			notify_security: true,
		})
		expect(wrapper.find('#notify-notify_shares').element.checked).toBe(false)
		expect(wrapper.find('#notify-notify_group_shares').element.checked).toBe(true)
	})

	it('renders one checkbox per category, in order', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: {} })
		const wrapper = mount(NotificationTogglesSection, { global: { stubs } })
		await flush()

		expect(wrapper.findAll('input[type="checkbox"]').map((n) => n.attributes('id'))).toEqual([
			'notify-notify_shares',
			'notify-notify_requests',
			'notify-notify_group_shares',
			'notify-notify_security',
		])
	})

	it('keeps the defaults when loading fails', async () => {
		vi.spyOn(axios, 'get').mockRejectedValue(new Error('offline'))
		const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
		const wrapper = mount(NotificationTogglesSection, { global: { stubs } })
		await flush()

		expect(warn).toHaveBeenCalled()
		expect(Object.values(wrapper.vm.prefs)).toEqual([true, true, true, true])
	})

	it('saves only the toggled key, as "0" when switched off', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: {} })
		const put = vi.spyOn(axios, 'put').mockResolvedValue({ data: {} })
		const wrapper = mount(NotificationTogglesSection, { global: { stubs } })
		await flush()

		await wrapper.find('#notify-notify_requests').setValue(false)
		await flush()

		expect(put).toHaveBeenCalledTimes(1)
		expect(put).toHaveBeenCalledWith('/apps/keepiq/api/settings/user', {
			notify_requests: '0',
		})
	})

	it('saves "1" when a toggle is switched back on', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: { notify_security: '0' } })
		const put = vi.spyOn(axios, 'put').mockResolvedValue({ data: {} })
		const wrapper = mount(NotificationTogglesSection, { global: { stubs } })
		await flush()

		await wrapper.find('#notify-notify_security').setValue(true)
		await flush()

		expect(put).toHaveBeenCalledWith('/apps/keepiq/api/settings/user', {
			notify_security: '1',
		})
	})
})
