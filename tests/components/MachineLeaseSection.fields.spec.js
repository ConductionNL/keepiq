/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The default TTL and renewable fields of the machine lease section: a
 * change to either writes the whole policy back.
 *
 * @spec openspec/specs/machine-secret-leases/spec.md#requirement-admin-lease-ttl-policy
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import MachineLeaseSection from '../../src/components/settings/MachineLeaseSection.vue'

const URL = '/apps/keepiq/api/settings/admin/applications'

const stubs = {
	CnSettingsSection: { template: '<section><slot /></section>' },
	NcNoteCard: { template: '<div data-testid="lease-error"><slot /></div>' },
}

/**
 * @return {Promise<object>} The loaded section.
 */
async function mountSection() {
	const wrapper = mount(MachineLeaseSection, { global: { stubs } })
	await flushPromises()
	return wrapper
}

describe('MachineLeaseSection fields', () => {
	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('writes a changed default TTL as a number', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: { lease_max_ttl_seconds: 3600 } })
		const put = vi.spyOn(axios, 'put').mockResolvedValue({ data: {} })
		const wrapper = await mountSection()

		await wrapper.find('[data-testid="lease-default-ttl"]').setValue('300')
		await flushPromises()

		expect(put).toHaveBeenCalledTimes(1)
		expect(put).toHaveBeenCalledWith(URL, {
			lease_default_ttl_seconds: 300,
			lease_max_ttl_seconds: 3600,
			lease_renewable: true,
			lease_revocation_blocks_refetch: false,
		})
	})

	it('writes leases as not renewable when the box is cleared', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: {} })
		const put = vi.spyOn(axios, 'put').mockResolvedValue({ data: {} })
		const wrapper = await mountSection()

		await wrapper.find('[data-testid="lease-renewable"]').setValue(false)
		await flushPromises()

		expect(wrapper.vm.renewable).toBe(false)
		expect(put).toHaveBeenCalledWith(URL, expect.objectContaining({ lease_renewable: false }))
		expect(wrapper.find('[data-testid="lease-error"]').exists()).toBe(false)
	})
})
