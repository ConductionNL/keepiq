/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pins what submitting `SecretRequestForm` sends to the store and shows.
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import SecretRequestForm from '../../src/components/secretRequest/SecretRequestForm.vue'
import { useSecretRequestStore } from '../../src/store/modules/secretRequest.js'

function mountForm(props = {}) {
	return mount(SecretRequestForm, {
		propsData: { secretId: 'sec-1', encryptionSuiteId: 'suite-1', ...props },
	})
}

describe('SecretRequestForm submit', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
	})

	it('refuses an empty field choice without calling the store', async () => {
		const store = useSecretRequestStore()
		const create = vi.spyOn(store, 'createRequest')
		const wrapper = mountForm()
		await wrapper.vm.onSubmit()
		expect(create).not.toHaveBeenCalled()
		expect(wrapper.vm.error).toBe('Pick at least one field')
		expect(wrapper.vm.busy).toBe(false)
	})

	it('sends the exact payload with an expiry and builds the link', async () => {
		const store = useSecretRequestStore()
		const create = vi.spyOn(store, 'createRequest').mockResolvedValue({ id: 'r1', token: 'tok-9' })
		const wrapper = mountForm({ linkBaseUrl: '/base' })
		wrapper.setData({ selectedFields: ['key', 'url'], expiresAt: '2030-01-02T03:04', isReRequest: true })
		await wrapper.vm.onSubmit()
		expect(create).toHaveBeenCalledWith({
			secretId: 'sec-1',
			encryptionSuiteId: 'suite-1',
			requestedFields: ['key', 'url'],
			isReRequest: true,
			expiresAt: new Date('2030-01-02T03:04').toISOString(),
		})
		expect(Object.keys(create.mock.calls[0][0])).toEqual(['secretId', 'encryptionSuiteId', 'requestedFields', 'isReRequest', 'expiresAt'])
		expect(wrapper.vm.createdLink).toBe('/base/tok-9')
		expect(wrapper.emitted('created')[0]).toEqual([{ id: 'r1', token: 'tok-9' }])
		expect(wrapper.vm.busy).toBe(false)
		expect(wrapper.vm.error).toBe(null)
	})

	it('sends a null expiry and keeps no link without a token', async () => {
		const store = useSecretRequestStore()
		const create = vi.spyOn(store, 'createRequest').mockResolvedValue({ id: 'r2' })
		const wrapper = mountForm()
		wrapper.setData({ selectedFields: ['login'] })
		await wrapper.vm.onSubmit()
		expect(create.mock.calls[0][0].expiresAt).toBe(null)
		expect(create.mock.calls[0][0].isReRequest).toBe(false)
		expect(wrapper.vm.createdLink).toBe(null)
		expect(wrapper.emitted('created')[0]).toEqual([{ id: 'r2' }])
	})

	it('emits created with a null row', async () => {
		const store = useSecretRequestStore()
		vi.spyOn(store, 'createRequest').mockResolvedValue(null)
		const wrapper = mountForm()
		wrapper.setData({ selectedFields: ['login'] })
		await wrapper.vm.onSubmit()
		expect(wrapper.vm.createdLink).toBe(null)
		expect(wrapper.emitted('created')[0]).toEqual([null])
	})

	it.each([
		[{ response: { data: { message: 'server says no' } }, message: 'x' }, 'server says no'],
		[{ message: 'plain error' }, 'plain error'],
		[{}, 'Failed to create request'],
	])('shows the failure text %#', async (failure, text) => {
		const store = useSecretRequestStore()
		vi.spyOn(store, 'createRequest').mockRejectedValue(failure)
		const wrapper = mountForm()
		wrapper.setData({ selectedFields: ['key'] })
		await wrapper.vm.onSubmit()
		expect(wrapper.vm.error).toBe(text)
		expect(wrapper.vm.busy).toBe(false)
		expect(wrapper.emitted('created')).toBeUndefined()
	})
})
