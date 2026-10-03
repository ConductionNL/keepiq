/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The opt-in to receiving secrets from partner organisations (keepiq#789,
 * sharing-federated-recipients D6): off unless the stored preference says
 * on, and each change is stored as federation_receive.
 *
 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#requirement-users-opt-in-to-receiving
 */

import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import FederationReceiveSection from '../../src/components/settings/FederationReceiveSection.vue'

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

describe('FederationReceiveSection', () => {
	beforeEach(() => {
		vi.restoreAllMocks()
	})

	it('is off by default and stores the opt-in', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: { federation_receive: '0' } })
		const put = vi.spyOn(axios, 'put').mockResolvedValue({ data: {} })
		const wrapper = mount(FederationReceiveSection)
		await flush()

		const toggle = wrapper.find('[data-testid="federation-receive-toggle"]')
		expect(toggle.element.checked).toBe(false)

		await toggle.setValue(true)
		await flush()

		expect(put).toHaveBeenCalledWith(expect.stringContaining('/apps/keepiq/api/settings/user'), { federation_receive: '1' })
	})

	it('shows a stored opt-in, and turning it off stores that', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: { federation_receive: '1' } })
		const put = vi.spyOn(axios, 'put').mockResolvedValue({ data: {} })
		const wrapper = mount(FederationReceiveSection)
		await flush()

		const toggle = wrapper.find('[data-testid="federation-receive-toggle"]')
		expect(toggle.element.checked).toBe(true)
		await toggle.setValue(false)
		await flush()

		expect(put).toHaveBeenCalledWith(expect.stringContaining('/api/settings/user'), { federation_receive: '0' })
	})
})
