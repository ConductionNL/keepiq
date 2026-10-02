/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The admin field for the browser extension's longest idle delay
 * (keepiq#784): it shows the stored maximum and saves a new one.
 *
 * @spec openspec/specs/browser-extension-autofill/spec.md#requirement-user-chosen-idle-lock-period-with-an-administrator-maximum
 */

import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import ExtensionSection from '../../src/components/settings/ExtensionSection.vue'

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

function mountSection() {
	return mount(ExtensionSection, {
		global: {
			stubs: {
				CnSettingsSection: { template: '<section><slot /></section>' },
				NcNoteCard: { template: '<div><slot /></div>' },
			},
		},
	})
}

describe('ExtensionSection', () => {
	afterEach(() => vi.restoreAllMocks())

	it('shows the stored maximum and saves a new one', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: { extension_max_idle_minutes: 60 },
		})
		const put = vi.spyOn(axios, 'put').mockResolvedValue({ data: {} })
		const wrapper = mountSection()
		await flush()

		const select = wrapper.find('[data-testid="extension-max-idle"]')
		expect(select.element.value).toBe('60')
		expect(wrapper.findAll('option').map((o) => o.element.value)).toEqual([
			'1',
			'5',
			'15',
			'30',
			'60',
			'240',
		])

		await select.setValue('30')
		await flush()

		expect(put).toHaveBeenCalledTimes(1)
		expect(put.mock.calls[0][1]).toEqual({ extension_max_idle_minutes: 30 })
	})

	it('defaults to four hours when nothing is stored', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: {} })
		const wrapper = mountSection()
		await flush()
		expect(
			wrapper.find('[data-testid="extension-max-idle"]').element.value,
		).toBe('240')
	})
})
