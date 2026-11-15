/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden for the machine lease and team-folder auto-confirm admin sections:
 * what they read on open (with their defaults), what a save sends, and the
 * message they show when reading or saving is refused or fails.
 *
 * @spec openspec/specs/team-folder-auto-confirm/spec.md
 * @spec openspec/specs/application-mgmt/spec.md
 */

import axios from '@nextcloud/axios'
import { flushPromises, shallowMount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import MachineLeaseSection from '../../src/components/settings/MachineLeaseSection.vue'
import TeamFolderAutoConfirmSection from '../../src/components/settings/TeamFolderAutoConfirmSection.vue'

const REFUSED = { response: { status: 403, data: { message: 'Administrators only' } } }

const CASES = [
	{
		name: 'MachineLeaseSection',
		component: MachineLeaseSection,
		url: '/apps/keepiq/api/settings/admin/applications',
		stored: {
			lease_default_ttl_seconds: 60,
			lease_max_ttl_seconds: 3600,
			lease_renewable: false,
			lease_revocation_blocks_refetch: true,
		},
		read: { defaultTtl: 60, maxTtl: 3600, renewable: false, blockOnRevoke: true },
		defaults: { defaultTtl: 900, maxTtl: 86400, renewable: true, blockOnRevoke: false },
		edit: { defaultTtl: 120, renewable: true },
		sent: {
			lease_default_ttl_seconds: 120,
			lease_max_ttl_seconds: 3600,
			lease_renewable: true,
			lease_revocation_blocks_refetch: true,
		},
	},
	{
		name: 'TeamFolderAutoConfirmSection',
		component: TeamFolderAutoConfirmSection,
		url: '/apps/keepiq/api/settings/admin/policies',
		stored: { team_folder_auto_confirm: true },
		read: { enabled: true },
		defaults: { enabled: false },
		edit: { enabled: false },
		sent: { team_folder_auto_confirm: false },
	},
]

/**
 * @param {object} wrapper The mounted section.
 * @param {object} shape The keys to read.
 * @return {object} The section's values for those keys.
 */
function pick(wrapper, shape) {
	return Object.fromEntries(Object.keys(shape).map((k) => [k, wrapper.vm[k]]))
}

afterEach(() => {
	vi.restoreAllMocks()
})

describe.each(CASES)('$name', ({ component, url, stored, read, defaults, edit, sent }) => {
	it('reads the stored settings on open', async () => {
		const get = vi.spyOn(axios, 'get').mockResolvedValue({ data: stored })
		const wrapper = shallowMount(component)
		await flushPromises()
		expect(get).toHaveBeenCalledWith(url)
		expect(pick(wrapper, read)).toEqual(read)
		expect(wrapper.vm.error).toBe(null)
	})

	it('keeps its defaults for settings the server leaves out', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: {} })
		const wrapper = shallowMount(component)
		await flushPromises()
		expect(pick(wrapper, defaults)).toEqual(defaults)
	})

	it('shows why reading failed', async () => {
		vi.spyOn(axios, 'get').mockRejectedValue(REFUSED)
		const wrapper = shallowMount(component)
		await flushPromises()
		expect(wrapper.vm.error).toBe('Administrators only')
		expect(pick(wrapper, defaults)).toEqual(defaults)
	})

	it('saves what is shown, clearing the last error first', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: stored })
		const wrapper = shallowMount(component)
		await flushPromises()
		Object.assign(wrapper.vm, edit)
		wrapper.vm.error = 'stale'
		let seen = 'unset'
		const put = vi.spyOn(axios, 'put').mockImplementation(async () => {
			seen = wrapper.vm.error
			return { data: {} }
		})
		await wrapper.vm.save()
		expect(put).toHaveBeenCalledWith(url, sent)
		expect(seen).toBe(null)
		expect(wrapper.vm.error).toBe(null)
	})

	it('shows a refused save and the plain error of a failed one', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: stored })
		const wrapper = shallowMount(component)
		await flushPromises()
		vi.spyOn(axios, 'put')
			.mockRejectedValueOnce(REFUSED)
			.mockRejectedValueOnce(new Error('Network down'))
		await wrapper.vm.save()
		expect(wrapper.vm.error).toBe('Administrators only')
		await wrapper.vm.save()
		expect(wrapper.vm.error).toBe('Network down')
	})
})
