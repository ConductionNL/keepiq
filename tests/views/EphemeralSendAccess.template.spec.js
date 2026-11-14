/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for the template wiring of `src/views/EphemeralSendAccess.vue`:
 * the password typed into the field reaches the store, Enter in the field and
 * the reveal button both open the send, and a failure shows its text in the
 * error card.
 *
 * @spec openspec/specs/ephemeral-send/spec.md#requirement-anonymous-recipient-access-with-no-account
 */

import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import EphemeralSendAccess from '../../src/views/EphemeralSendAccess.vue'
import { useEphemeralSendStore } from '../../src/store/modules/ephemeralSend.js'

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

/**
 * Mount the page for a password-protected send.
 *
 * @param {Function} accessSend The store's accessSend implementation.
 * @return {Promise<{wrapper: object, store: object}>} The page and its store.
 */
async function mountPage(accessSend) {
	vi.spyOn(axios, 'get').mockResolvedValue({ data: { hasPassword: true } })
	const store = useEphemeralSendStore()
	store.accessSend = vi.fn(accessSend)
	const wrapper = mount(EphemeralSendAccess, {
		global: {
			mocks: { $route: { params: { token: 'tok' }, query: {}, hash: '#k=KEY' } },
			stubs: {
				NcButton: { template: '<button><slot /></button>' },
				NcNoteCard: { template: '<div><slot /></div>' },
				NcEmptyContent: { template: '<div><slot /></div>' },
			},
		},
	})
	await flush()
	return { wrapper, store }
}

describe('EphemeralSendAccess — template wiring', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
	})

	it('sends the typed password when Enter is pressed in the field', async () => {
		const { wrapper, store } = await mountPage(async () => ({ payload: 'secret text', burned: false }))
		const input = wrapper.find('[data-testid="send-access-password"]')

		await input.setValue('hunter2')
		expect(wrapper.vm.password).toBe('hunter2')
		await input.trigger('keyup.enter')
		await flush()

		expect(store.accessSend).toHaveBeenCalledWith('tok', 'KEY', 'hunter2')
		expect(wrapper.find('[data-testid="send-access-payload"]').text()).toBe('secret text')
	})

	it('opens the send from the reveal button', async () => {
		const { wrapper, store } = await mountPage(async () => ({ payload: 'p', burned: true }))
		await wrapper.find('[data-testid="send-access-password"]').setValue('pw')

		await wrapper.find('[data-testid="send-access-open"]').trigger('click')
		await flush()

		expect(store.accessSend).toHaveBeenCalledTimes(1)
		expect(wrapper.vm.burned).toBe(true)
		expect(wrapper.find('[data-testid="send-burn-notice"]').text()).toContain('This was the last view')
	})

	it('shows the failure text in the error card', async () => {
		const { wrapper } = await mountPage(async () => {
			throw new Error('The server refused')
		})
		await wrapper.find('[data-testid="send-access-password"]').setValue('pw')

		await wrapper.find('[data-testid="send-access-open"]').trigger('click')
		await flush()

		expect(wrapper.find('[data-testid="send-access-error"]').text()).toBe('The server refused')
		expect(wrapper.find('[data-testid="send-access-payload"]').exists()).toBe(false)
	})
})
