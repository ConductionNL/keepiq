/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The admin settings form mixin loads one settings object into its host and
 * saves the host's payload back to the same route.
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { adminSettingsForm } from '../../src/mixins/adminSettingsForm.js'

const ROUTE = '/apps/keepiq/api/settings/admin/example'

function mountHost(methods = {}) {
	return mount({
		mixins: [adminSettingsForm(ROUTE)],
		data: () => ({ loaded: null, payload: { example: 1 } }),
		methods: {
			readSettings(data) {
				this.loaded = data
			},
			settingsPayload() {
				return this.payload
			},
			...methods,
		},
		template: '<p>{{ error }}</p>',
	})
}

describe('adminSettingsForm', () => {
	afterEach(() => vi.restoreAllMocks())

	it('hands the loaded settings to readSettings', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: { example: 3 } })
		const wrapper = mountHost()
		await flushPromises()

		expect(axios.get).toHaveBeenCalledWith(ROUTE)
		expect(wrapper.vm.loaded).toEqual({ example: 3 })
		expect(wrapper.vm.error).toBe(null)
	})

	it('saves the payload and passes it to afterSave', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: {} })
		const put = vi.spyOn(axios, 'put').mockResolvedValue({ data: {} })
		const afterSave = vi.fn()
		const wrapper = mountHost({ afterSave })
		await flushPromises()

		await wrapper.vm.save()

		expect(put).toHaveBeenCalledWith(ROUTE, { example: 1 })
		expect(afterSave).toHaveBeenCalledWith({ example: 1 })
	})

	it('sends nothing when the payload is null', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: {} })
		const put = vi.spyOn(axios, 'put').mockResolvedValue({ data: {} })
		const afterSave = vi.fn()
		const wrapper = mountHost({ afterSave })
		await flushPromises()
		wrapper.vm.payload = null

		await wrapper.vm.save()

		expect(put).not.toHaveBeenCalled()
		expect(afterSave).not.toHaveBeenCalled()
	})

	it('does not call afterSave when the save fails', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: {} })
		vi.spyOn(axios, 'put').mockRejectedValue({ message: 'Network Error' })
		const afterSave = vi.fn()
		const wrapper = mountHost({ afterSave })
		await flushPromises()

		await wrapper.vm.save()

		expect(afterSave).not.toHaveBeenCalled()
		expect(wrapper.text()).toBe('Network Error')
	})

	it('shows the error message when the response has no server message', async () => {
		vi.spyOn(axios, 'get').mockRejectedValue({ message: 'Network Error' })
		const wrapper = mountHost()
		await flushPromises()

		expect(wrapper.vm.loaded).toBe(null)
		expect(wrapper.text()).toBe('Network Error')
	})
})
