/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The admin switch for automatic member confirmation loads the stored value
 * and saves only its own key (admin-auto-confirm-members §1.2).
 *
 * @spec openspec/changes/admin-auto-confirm-members/tasks.md#1.2
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import TeamFolderAutoConfirmSection from '../../src/components/settings/TeamFolderAutoConfirmSection.vue'

const stubs = {
	CnSettingsSection: {
		props: ['name', 'description'],
		template: '<section><slot /></section>',
	},
	NcNoteCard: { props: ['type'], template: '<div class="note"><slot /></div>' },
}

describe('TeamFolderAutoConfirmSection', () => {
	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('shows the stored value, off by default', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: {} })
		const wrapper = mount(TeamFolderAutoConfirmSection, { global: { stubs } })
		await flushPromises()

		expect(
			wrapper.find('[data-testid="auto-confirm-enabled"]').element.checked,
		).toBe(false)
	})

	it('saves only the switch when it is turned on', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: { team_folder_auto_confirm: false, min_password_length: 12 },
		})
		const put = vi.spyOn(axios, 'put').mockResolvedValue({ data: {} })
		const wrapper = mount(TeamFolderAutoConfirmSection, { global: { stubs } })
		await flushPromises()

		await wrapper.find('[data-testid="auto-confirm-enabled"]').setValue(true)
		await flushPromises()

		expect(put).toHaveBeenCalledWith(
			'/apps/keepiq/api/settings/admin/policies',
			{
				team_folder_auto_confirm: true,
			},
		)
	})

	it('shows the server refusal', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: {} })
		vi.spyOn(axios, 'put').mockRejectedValue({
			response: { data: { message: 'refused' } },
		})
		const wrapper = mount(TeamFolderAutoConfirmSection, { global: { stubs } })
		await flushPromises()

		await wrapper.find('[data-testid="auto-confirm-enabled"]').setValue(true)
		await flushPromises()

		expect(wrapper.find('[data-testid="auto-confirm-error"]').text()).toBe(
			'refused',
		)
	})
})
