/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The admin fields for version and trash retention show the stored values
 * and save them within their bounds.
 *
 * @spec openspec/specs/secret-version-history/spec.md#requirement-admin-configurable-retention-and-pruning
 * @spec openspec/specs/vault-trash-and-archive/spec.md#requirement-restoring-and-purging-trashed-secrets
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import RetentionPolicySection from '../../src/components/settings/RetentionPolicySection.vue'

const ROUTE = '/apps/keepiq/api/settings/admin/policies'
const STORED = {
	version_retention_count: 5,
	version_retention_days: 0,
	trash_retention_days: 30,
}

const stubs = {
	CnSettingsSection: { template: '<section><slot /></section>' },
	NcNoteCard: { props: ['type'], template: '<div class="note"><slot /></div>' },
}

async function mountSection(stored = STORED) {
	vi.spyOn(axios, 'get').mockResolvedValue({ data: stored })
	const wrapper = mount(RetentionPolicySection, { global: { stubs } })
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

describe('RetentionPolicySection', () => {
	afterEach(() => vi.restoreAllMocks())

	it('shows the stored retention', async () => {
		const wrapper = await mountSection({
			version_retention_count: 5,
			version_retention_days: 0,
			trash_retention_days: 400,
		})

		expect(axios.get).toHaveBeenCalledWith(ROUTE)
		expect(value(wrapper, 'version-retention-count')).toBe('5')
		expect(value(wrapper, 'version-retention-days')).toBe('0')
		expect(value(wrapper, 'trash-retention-days')).toBe('400')
	})

	it('defaults to 20 versions, 365 days and 30 trash days', async () => {
		const wrapper = await mountSection({})

		expect(value(wrapper, 'version-retention-count')).toBe('20')
		expect(value(wrapper, 'version-retention-days')).toBe('365')
		expect(value(wrapper, 'trash-retention-days')).toBe('30')
	})

	it('saves a version count of 0 as 1', async () => {
		const put = vi.spyOn(axios, 'put').mockResolvedValue({ data: {} })
		const wrapper = await mountSection()

		await change(wrapper, 'version-retention-count', '0')

		expect(put).toHaveBeenCalledTimes(1)
		expect(put).toHaveBeenCalledWith(ROUTE, {
			...STORED,
			version_retention_count: 1,
		})
	})

	it('saves a negative version age as 0', async () => {
		const put = vi.spyOn(axios, 'put').mockResolvedValue({ data: {} })
		const wrapper = await mountSection()

		await change(wrapper, 'version-retention-days', '-1')

		expect(put).toHaveBeenCalledWith(ROUTE, {
			...STORED,
			version_retention_days: 0,
		})
	})

	it('saves 500 trash days as 365 and 0 as 1', async () => {
		const put = vi.spyOn(axios, 'put').mockResolvedValue({ data: {} })
		const wrapper = await mountSection()

		await change(wrapper, 'trash-retention-days', '500')
		expect(put).toHaveBeenLastCalledWith(ROUTE, {
			...STORED,
			trash_retention_days: 365,
		})

		await change(wrapper, 'trash-retention-days', '0')
		expect(put).toHaveBeenLastCalledWith(ROUTE, {
			...STORED,
			trash_retention_days: 1,
		})
	})

	it('shows the server message when loading is refused', async () => {
		vi.spyOn(axios, 'get').mockRejectedValue({
			response: { data: { message: 'Server says no' } },
		})
		const wrapper = mount(RetentionPolicySection, { global: { stubs } })
		await flushPromises()

		expect(wrapper.find('.note').text()).toBe('Server says no')
	})

	it('shows the server message when saving is refused, and clears it on the next save', async () => {
		const put = vi.spyOn(axios, 'put').mockRejectedValueOnce({
			response: { data: { message: 'Server says no' } },
		})
		const wrapper = await mountSection()

		await change(wrapper, 'version-retention-count', '9')
		expect(wrapper.find('.note').text()).toBe('Server says no')

		put.mockResolvedValue({ data: {} })
		await change(wrapper, 'version-retention-count', '10')
		expect(wrapper.find('.note').exists()).toBe(false)
	})
})
