/**
 * SPDX-FileCopyrightText: 2026 Conduction / Doriath Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `src/views/ApplicationRegisterView.vue`.
 *
 * @spec openspec/changes/implement-application-mgmt/tasks.md#15.2
 */

import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import axios from '@nextcloud/axios'

import ApplicationRegisterView from '../../src/views/ApplicationRegisterView.vue'

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

describe('ApplicationRegisterView', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
	})

	it('renders the empty state when the user has no applications', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: [] })
		const wrapper = mount(ApplicationRegisterView)
		await flush()
		expect(wrapper.find('.cn-index-page__empty').exists()).toBe(true)
	})

	it('renders one row per registered application', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: [
				{ id: 'a1', name: 'CI', status: 'pending', type: 'external' },
				{ id: 'a2', name: 'OC', status: 'active', type: 'internal' },
			],
		})
		const wrapper = mount(ApplicationRegisterView)
		await flush()
		expect(wrapper.findAll('.cn-object-row')).toHaveLength(2)
	})

	it('renders the #row-badges slot content into each row', async () => {
		// The row COUNT above passes whether or not the scoped slot renders,
		// so it never covered the slot. Vue 3 removed `$scopedSlots` (all
		// slots moved to `$slots`), and a stub still reading the old property
		// silently produced empty rows while the count assertion stayed green.
		// This asserts on the slot's actual output.
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: [
				{ id: 'a1', name: 'CI', status: 'pending', type: 'external' },
				{ id: 'a2', name: 'OC', status: 'active', type: 'internal' },
			],
		})
		const wrapper = mount(ApplicationRegisterView)
		await flush()

		const badges = wrapper.findAll('.cn-object-row .cn-status-badge')
		// One status badge + one type badge per row.
		expect(badges).toHaveLength(4)
		expect(wrapper.findAll('.cn-object-row').at(0).text()).toContain('external')
		expect(wrapper.findAll('.cn-object-row').at(1).text()).toContain('internal')
	})

	it('opens the dialog when the register (add) button is clicked', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: [] })
		const wrapper = mount(ApplicationRegisterView)
		await flush()
		await wrapper.find('[data-testid="cn-cta-primary"]').trigger('click')
		expect(wrapper.find('[data-testid="application-register-dialog"]').exists()).toBe(true)
	})

	it('mounts the PrivateKeyDownloadDialog when the store has a one-time key', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: [] })
		const wrapper = mount(ApplicationRegisterView)
		await flush()
		// Simulate the registration flow having captured the key.
		const { useApplicationStore } = await import('../../src/store/modules/application.js')
		const store = useApplicationStore()
		store.oneTimePrivateKey = '-----BEGIN PRIVATE KEY-----\nabc\n-----END PRIVATE KEY-----'
		await flush()
		expect(wrapper.find('[data-testid="private-key-dialog"]').exists()).toBe(true)
	})
})
