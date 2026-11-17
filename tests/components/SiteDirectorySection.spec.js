/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The admin site directory switch: off unless the instance setting says so,
 * every change written straight back to the general admin settings, and the
 * privacy and attribution text always shown.
 *
 * @spec openspec/changes/archive/2026-11-17-health-site-security-checks/tasks.md#task-2.3
 */

import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import SiteDirectorySection from '../../src/components/settings/SiteDirectorySection.vue'

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

const URL = '/apps/keepiq/api/settings/admin/general'

function mountSection() {
	return mount(SiteDirectorySection, {
		global: { stubs: { CnSettingsSection: { template: '<section><slot /></section>' } } },
	})
}

describe('SiteDirectorySection', () => {
	beforeEach(() => {
		vi.restoreAllMocks()
	})

	it.each([
		[true, true],
		['1', true],
		['0', false],
		[false, false],
		[undefined, false],
	])('reads site_directory_enabled=%s as %s', async (stored, expected) => {
		const get = vi.spyOn(axios, 'get').mockResolvedValue({ data: { site_directory_enabled: stored } })
		const wrapper = mountSection()
		await flush()

		expect(get).toHaveBeenCalledWith(URL)
		expect(wrapper.find('[data-testid="site-directory-enabled"]').element.checked).toBe(expected)
	})

	it('stays off when the setting cannot be loaded', async () => {
		vi.spyOn(axios, 'get').mockRejectedValue(new Error('500'))
		const wrapper = mountSection()
		await flush()

		expect(wrapper.find('[data-testid="site-directory-enabled"]').element.checked).toBe(false)
	})

	it('writes the new state as a boolean when toggled', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: {} })
		const put = vi.spyOn(axios, 'put').mockResolvedValue({ data: {} })
		const wrapper = mountSection()
		await flush()

		await wrapper.find('[data-testid="site-directory-enabled"]').setValue(true)
		await flush()

		expect(put).toHaveBeenCalledWith(URL, { site_directory_enabled: true })
	})

	it('names both hosts it downloads from and says nothing is sent', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: {} })
		const wrapper = mountSection()
		await flush()

		const privacy = wrapper.find('[data-testid="site-directory-privacy"]').text()
		expect(privacy).toContain('api.2fa.directory')
		expect(privacy).toContain('passkeys-api.2fa.directory')
		expect(privacy).toContain('no login, address or result is sent')
	})

	it('credits both directories with their licences and links', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: {} })
		const wrapper = mountSection()
		await flush()

		const attribution = wrapper.find('[data-testid="site-directory-attribution"]')
		expect(attribution.text()).toContain('2FA Directory (MIT)')
		expect(attribution.text()).toContain('Passkeys Directory (CC BY 4.0)')
		expect(attribution.findAll('a').map((a) => a.attributes('href'))).toEqual([
			'https://2fa.directory/',
			'https://passkeys.2fa.directory/',
		])
		attribution.findAll('a').forEach((a) => expect(a.attributes('rel')).toContain('noopener'))
	})
})
