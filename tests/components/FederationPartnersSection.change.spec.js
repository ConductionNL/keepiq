/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Changing or removing a federation partner: the password is confirmed
 * before the request, a refused confirmation sends nothing, a refusal or
 * failure is shown, and the list is read again in every case.
 *
 * @spec openspec/specs/federated-sharing/spec.md#requirement-administrators-approve-and-pin-partner-instances
 */

import axios from '@nextcloud/axios'
import { confirmPassword } from '@nextcloud/password-confirmation'
import { shallowMount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import FederationPartnersSection from '../../src/components/settings/FederationPartnersSection.vue'

vi.mock('@nextcloud/password-confirmation', () => ({
	confirmPassword: vi.fn(async () => {}),
}))

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

const PARTNER = {
	id: 'p 1',
	baseUrl: 'https://cloud.partner.example',
	rootFingerprint: 'ab'.repeat(32),
	allowOutbound: true,
	allowInbound: false,
}
const URL_PARTNER = '/apps/keepiq/api/v1/federation/partners/p%201'
const REFUSED = { response: { status: 403, data: { message: 'Administrators only' } } }

let events

/**
 * @return {Promise<object>} The section over one partner, every call recorded.
 */
async function mountSection() {
	events = []
	vi.spyOn(axios, 'get').mockImplementation(async () => {
		events.push('load')
		return { data: { supported: true, partners: [PARTNER] } }
	})
	const wrapper = shallowMount(FederationPartnersSection)
	await flush()
	events = []
	wrapper.vm.error = 'stale'
	confirmPassword.mockImplementation(async () => {
		events.push(['confirm', wrapper.vm.error])
	})
	return wrapper
}

beforeEach(() => {
	confirmPassword.mockReset()
})

afterEach(() => {
	vi.restoreAllMocks()
})

describe('updatePartner', () => {
	it('confirms the password, puts both directions with the change, then reloads', async () => {
		const wrapper = await mountSection()
		const put = vi.spyOn(axios, 'put').mockImplementation(async (url, body) => {
			events.push(['put', url, body])
			return { data: {} }
		})
		await wrapper.vm.updatePartner(PARTNER, { allowInbound: true })
		expect(put).toHaveBeenCalledTimes(1)
		expect(events).toEqual([
			['confirm', null],
			['put', URL_PARTNER, { allowOutbound: true, allowInbound: true }],
			'load',
		])
		expect(wrapper.vm.error).toBe(null)
	})

	it('sends nothing when the password is not confirmed, shows why and reloads', async () => {
		const wrapper = await mountSection()
		const put = vi.spyOn(axios, 'put')
		confirmPassword.mockRejectedValue(new Error('Password confirmation cancelled'))
		await wrapper.vm.updatePartner(PARTNER, { allowInbound: true })
		expect(put).not.toHaveBeenCalled()
		expect(wrapper.vm.error).toBe('Password confirmation cancelled')
		expect(events).toEqual(['load'])
	})

	it('shows a refusal and reloads', async () => {
		const wrapper = await mountSection()
		vi.spyOn(axios, 'put').mockRejectedValue(REFUSED)
		await wrapper.vm.updatePartner(PARTNER, { allowOutbound: false })
		expect(wrapper.vm.error).toBe('Administrators only')
		expect(events.at(-1)).toBe('load')
	})
})

describe('removePartner', () => {
	it('confirms the password, deletes, then reloads', async () => {
		const wrapper = await mountSection()
		vi.spyOn(axios, 'delete').mockImplementation(async (url) => {
			events.push(['delete', url])
			return { data: {} }
		})
		await wrapper.vm.removePartner(PARTNER)
		expect(events).toEqual([['confirm', null], ['delete', URL_PARTNER], 'load'])
		expect(wrapper.vm.error).toBe(null)
	})

	it('sends nothing when the password is not confirmed, shows why and reloads', async () => {
		const wrapper = await mountSection()
		const del = vi.spyOn(axios, 'delete')
		confirmPassword.mockRejectedValue({})
		await wrapper.vm.removePartner(PARTNER)
		expect(del).not.toHaveBeenCalled()
		expect(wrapper.vm.error).toBe(null)
		expect(events).toEqual(['load'])
	})

	it('shows a refusal and reloads', async () => {
		const wrapper = await mountSection()
		vi.spyOn(axios, 'delete').mockRejectedValue(REFUSED)
		await wrapper.vm.removePartner(PARTNER)
		expect(wrapper.vm.error).toBe('Administrators only')
		expect(events.at(-1)).toBe('load')
	})
})
