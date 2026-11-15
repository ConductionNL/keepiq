/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The machine lease policy section: it reads the lease settings from the
 * application admin settings (with defaults for what is unset) and writes all
 * four back on every change.
 */

import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import MachineLeaseSection from '../../src/components/settings/MachineLeaseSection.vue'

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

const URL = '/apps/keepiq/api/settings/admin/applications'

const stubs = {
	CnSettingsSection: { template: '<section><slot /></section>' },
	NcNoteCard: { template: '<div data-testid="lease-error"><slot /></div>' },
}

async function mountSection() {
	const wrapper = mount(MachineLeaseSection, { global: { stubs } })
	await flush()
	return wrapper
}

describe('MachineLeaseSection', () => {
	beforeEach(() => {
		vi.restoreAllMocks()
	})

	it('uses the defaults for unset lease settings', async () => {
		const get = vi.spyOn(axios, 'get').mockResolvedValue({ data: {} })
		const wrapper = await mountSection()

		expect(get).toHaveBeenCalledWith(URL)
		expect(wrapper.vm.settingsPayload()).toEqual({
			lease_default_ttl_seconds: 900,
			lease_max_ttl_seconds: 86400,
			lease_renewable: true,
			lease_revocation_blocks_refetch: false,
		})
	})

	it('reads the stored lease settings', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: {
				lease_default_ttl_seconds: 120,
				lease_max_ttl_seconds: 3600,
				lease_renewable: false,
				lease_revocation_blocks_refetch: true,
			},
		})
		const wrapper = await mountSection()

		expect(wrapper.find('[data-testid="lease-default-ttl"]').element.value).toBe('120')
		expect(wrapper.find('[data-testid="lease-max-ttl"]').element.value).toBe('3600')
		expect(wrapper.find('[data-testid="lease-renewable"]').element.checked).toBe(false)
		expect(wrapper.find('[data-testid="lease-block-on-revoke"]').element.checked).toBe(true)
	})

	it('only a literal true blocks re-fetching after revocation', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: { lease_revocation_blocks_refetch: '1', lease_renewable: '0' },
		})
		const wrapper = await mountSection()

		expect(wrapper.vm.blockOnRevoke).toBe(false)
		expect(wrapper.vm.renewable).toBe(true)
	})

	it('writes all four settings when one changes', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: {} })
		const put = vi.spyOn(axios, 'put').mockResolvedValue({ data: {} })
		const wrapper = await mountSection()

		await wrapper.find('[data-testid="lease-max-ttl"]').setValue('7200')
		await flush()

		expect(put).toHaveBeenCalledWith(URL, {
			lease_default_ttl_seconds: 900,
			lease_max_ttl_seconds: 7200,
			lease_renewable: true,
			lease_revocation_blocks_refetch: false,
		})

		await wrapper.find('[data-testid="lease-block-on-revoke"]').setValue(true)
		await flush()

		expect(put).toHaveBeenLastCalledWith(URL, expect.objectContaining({ lease_revocation_blocks_refetch: true }))
	})

	it('shows a load or save error', async () => {
		vi.spyOn(axios, 'get').mockRejectedValue({ response: { data: { message: 'Admins only' } } })
		const wrapper = await mountSection()
		expect(wrapper.find('[data-testid="lease-error"]').text()).toBe('Admins only')

		vi.spyOn(axios, 'put').mockRejectedValue(new Error('Bad TTL'))
		await wrapper.vm.save()
		expect(wrapper.vm.error).toBe('Bad TTL')
	})
})
