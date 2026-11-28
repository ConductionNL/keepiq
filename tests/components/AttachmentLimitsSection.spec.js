/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The admin fields for the attachment limits show the stored limits in MiB
 * and save them as bytes, at least 1 MiB each.
 *
 * @spec openspec/specs/encrypted-attachments/spec.md#requirement-per-attachment-size-limit-and-per-user-quota
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import AttachmentLimitsSection from '../../src/components/settings/AttachmentLimitsSection.vue'

const ROUTE = '/apps/keepiq/api/settings/admin/general'
const MIB = 1048576

const stubs = {
	CnSettingsSection: { template: '<section><slot /></section>' },
	NcNoteCard: { props: ['type'], template: '<div class="note"><slot /></div>' },
}

async function mountSection(stored = { attachment_max_bytes: 10 * MIB }) {
	vi.spyOn(axios, 'get').mockResolvedValue({ data: stored })
	const wrapper = mount(AttachmentLimitsSection, { global: { stubs } })
	await flushPromises()
	return wrapper
}

async function change(wrapper, testid, value) {
	await wrapper.find(`[data-testid="${testid}"]`).setValue(value)
	await flushPromises()
}

describe('AttachmentLimitsSection', () => {
	afterEach(() => vi.restoreAllMocks())

	it('shows the stored limits rounded to whole MiB', async () => {
		const wrapper = await mountSection({
			attachment_max_bytes: 10 * MIB,
			attachment_user_quota_bytes: 3 * MIB + 600000,
		})

		expect(axios.get).toHaveBeenCalledWith(ROUTE)
		expect(
			wrapper.find('[data-testid="attachment-max-mib"]').element.value,
		).toBe('10')
		expect(
			wrapper.find('[data-testid="attachment-quota-mib"]').element.value,
		).toBe('4')
	})

	it('defaults to 25 MiB per attachment and 100 MiB per user', async () => {
		const wrapper = await mountSection({})

		expect(
			wrapper.find('[data-testid="attachment-max-mib"]').element.value,
		).toBe('25')
		expect(
			wrapper.find('[data-testid="attachment-quota-mib"]').element.value,
		).toBe('100')
	})

	it('saves a maximum of 0 as 1 MiB', async () => {
		const put = vi.spyOn(axios, 'put').mockResolvedValue({ data: {} })
		const wrapper = await mountSection()

		await change(wrapper, 'attachment-max-mib', '0')

		expect(put).toHaveBeenCalledTimes(1)
		expect(put).toHaveBeenCalledWith(ROUTE, {
			attachment_max_bytes: MIB,
			attachment_user_quota_bytes: 100 * MIB,
		})
	})

	it('saves the quota in bytes, at least 1 MiB', async () => {
		const put = vi.spyOn(axios, 'put').mockResolvedValue({ data: {} })
		const wrapper = await mountSection()

		await change(wrapper, 'attachment-quota-mib', '7')
		expect(put).toHaveBeenLastCalledWith(ROUTE, {
			attachment_max_bytes: 10 * MIB,
			attachment_user_quota_bytes: 7 * MIB,
		})

		await change(wrapper, 'attachment-quota-mib', '0')
		expect(put).toHaveBeenLastCalledWith(ROUTE, {
			attachment_max_bytes: 10 * MIB,
			attachment_user_quota_bytes: MIB,
		})
	})

	it('shows the server message when loading is refused', async () => {
		vi.spyOn(axios, 'get').mockRejectedValue({
			response: { data: { message: 'Server says no' } },
		})
		const wrapper = mount(AttachmentLimitsSection, { global: { stubs } })
		await flushPromises()

		expect(wrapper.find('.note').text()).toBe('Server says no')
	})

	it('shows the server message when saving is refused, and clears it on the next save', async () => {
		const put = vi.spyOn(axios, 'put').mockRejectedValueOnce({
			response: { data: { message: 'Server says no' } },
		})
		const wrapper = await mountSection()

		await change(wrapper, 'attachment-max-mib', '12')
		expect(wrapper.find('.note').text()).toBe('Server says no')

		put.mockResolvedValue({ data: {} })
		await change(wrapper, 'attachment-max-mib', '13')
		expect(wrapper.find('.note').exists()).toBe(false)
	})
})
