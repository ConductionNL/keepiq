/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The offline cache admin section: it reads the instance switch (on unless
 * stored off, as `false` or `'0'`), writes it back on change, and keeps the
 * offline edits switch unusable while caching is off.
 *
 * @spec openspec/specs/offline-readonly-cache/spec.md#requirement-an-admin-can-disable-offline-caching-org-wide
 */

import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import OfflineCacheSection from '../../src/components/settings/OfflineCacheSection.vue'

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

const URL = '/apps/keepiq/api/settings/admin/general'

/**
 * Mount the section and let its read settle.
 *
 * @return {Promise<import('@vue/test-utils').VueWrapper>} The wrapper.
 */
async function mountSection() {
	const wrapper = mount(OfflineCacheSection, {
		global: { stubs: { CnSettingsSection: { template: '<section><slot /></section>' } } },
	})
	await flush()
	return wrapper
}

describe('OfflineCacheSection', () => {
	beforeEach(() => {
		vi.restoreAllMocks()
	})

	it('reads caching as on when nothing is stored', async () => {
		const get = vi.spyOn(axios, 'get').mockResolvedValue({ data: {} })
		const wrapper = await mountSection()

		expect(get).toHaveBeenCalledWith(URL)
		expect(wrapper.find('[data-testid="offline-cache-enabled"]').element.checked).toBe(true)
		expect(wrapper.find('[data-testid="offline-edits-enabled"]').element.checked).toBe(false)
		expect(wrapper.find('[data-testid="offline-edits-enabled"]').element.disabled).toBe(false)
	})

	it.each([[false], ['0']])('reads caching as off when stored as %j, and locks offline edits', async (stored) => {
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: { offline_cache_enabled: stored, offline_edits_enabled: true },
		})
		const wrapper = await mountSection()

		expect(wrapper.find('[data-testid="offline-cache-enabled"]').element.checked).toBe(false)
		expect(wrapper.find('[data-testid="offline-edits-enabled"]').element.checked).toBe(true)
		expect(wrapper.find('[data-testid="offline-edits-enabled"]').element.disabled).toBe(true)
	})

	it('reads offline edits as on only when stored as true', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: { offline_edits_enabled: 'true' } })
		const wrapper = await mountSection()

		expect(wrapper.find('[data-testid="offline-edits-enabled"]').element.checked).toBe(false)
	})

	it('writes the caching switch back when it changes', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: { offline_cache_enabled: true } })
		const put = vi.spyOn(axios, 'put').mockResolvedValue({ data: {} })
		const wrapper = await mountSection()

		await wrapper.find('[data-testid="offline-cache-enabled"]').setValue(false)
		await flush()

		expect(put).toHaveBeenCalledTimes(1)
		expect(put).toHaveBeenCalledWith(URL, { offline_cache_enabled: false })
		expect(wrapper.find('[data-testid="offline-edits-enabled"]').element.disabled).toBe(true)
	})

	it('keeps caching on when the read fails', async () => {
		const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
		vi.spyOn(axios, 'get').mockRejectedValue(new Error('offline'))
		const wrapper = await mountSection()

		expect(wrapper.find('[data-testid="offline-cache-enabled"]').element.checked).toBe(true)
		expect(wrapper.find('[data-testid="offline-edits-enabled"]').element.checked).toBe(false)
		expect(warn).toHaveBeenCalledWith('Keepiq: failed to load offline-cache switch', expect.any(Error))
	})
})
