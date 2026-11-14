/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for the register dialog of `src/views/ApplicationRegisterView.vue`:
 * the index page's add action opens it, its close event shuts it, and a list
 * that fails to load leaves the page usable instead of throwing.
 *
 * @spec openspec/changes/implement-application-mgmt/tasks.md#task-10.1
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ApplicationRegisterView from '../../src/views/ApplicationRegisterView.vue'

const DialogStub = {
	name: 'ApplicationRegisterDialog',
	props: ['open'],
	emits: ['close', 'registered'],
	template: '<div v-if="open" class="register-dialog"><button class="close" @click="$emit(\'close\')" /></div>',
}

const IndexStub = {
	name: 'CnIndexPage',
	emits: ['add', 'search', 'rowClick'],
	template: '<div><button class="add" @click="$emit(\'add\')" /></div>',
}

/**
 * Mount the view with the index page and the dialog stubbed.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mountView() {
	const wrapper = mount(ApplicationRegisterView, {
		global: {
			stubs: { CnIndexPage: IndexStub, ApplicationRegisterDialog: DialogStub },
		},
	})
	await flushPromises()
	return wrapper
}

describe('ApplicationRegisterView register dialog', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
	})

	it('opens on the add action and shuts on the dialog close event', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: [] })
		const wrapper = await mountView()
		expect(wrapper.find('.register-dialog').exists()).toBe(false)

		await wrapper.find('.add').trigger('click')
		expect(wrapper.vm.dialogOpen).toBe(true)
		expect(wrapper.find('.register-dialog').exists()).toBe(true)

		await wrapper.find('.register-dialog .close').trigger('click')
		expect(wrapper.vm.dialogOpen).toBe(false)
		expect(wrapper.find('.register-dialog').exists()).toBe(false)
	})

	it('swallows a failed list load and still offers the add action', async () => {
		const get = vi.spyOn(axios, 'get').mockRejectedValue(new Error('down'))
		const wrapper = await mountView()

		expect(get).toHaveBeenCalled()
		expect(wrapper.find('[data-testid="application-register-view"]').exists()).toBe(true)
		await wrapper.find('.add').trigger('click')
		expect(wrapper.vm.dialogOpen).toBe(true)
	})
})
