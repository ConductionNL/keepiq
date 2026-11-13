/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for the mounted page of `src/views/EphemeralSendAccess.vue`:
 * the peek request that decides whether to prompt for a password (token taken
 * from the route and URL-encoded), a peek failure marking the send gone, and
 * copying the revealed payload, including a refused clipboard.
 *
 * @spec openspec/specs/ephemeral-send/spec.md#requirement-anonymous-recipient-access-with-no-account
 */

import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import EphemeralSendAccess from '../../src/views/EphemeralSendAccess.vue'

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

/**
 * Mount the page on a route carrying the given token.
 *
 * @param {object} params The route params.
 * @return {Promise<object>} The wrapper, after the peek settles.
 */
async function mountPage(params = { token: 'tok/1' }) {
	const wrapper = mount(EphemeralSendAccess, {
		global: {
			mocks: { $route: { params, query: {}, hash: '' } },
			stubs: {
				NcButton: { template: '<button><slot /></button>' },
				NcNoteCard: { template: '<div><slot /></div>' },
				NcEmptyContent: { template: '<div><slot /></div>' },
			},
		},
	})
	await flush()
	return wrapper
}

describe('EphemeralSendAccess — mounted peek and copy', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
	})

	afterEach(() => {
		vi.unstubAllGlobals()
	})

	it('peeks the URL-encoded token and prompts for a password when the send has one', async () => {
		const get = vi
			.spyOn(axios, 'get')
			.mockResolvedValue({ data: { hasPassword: true } })
		const wrapper = await mountPage()

		expect(get).toHaveBeenCalledWith('/apps/keepiq/api/v1/public/sends/tok%2F1')
		expect(wrapper.vm.needsPassword).toBe(true)
		expect(wrapper.vm.gone).toBe(false)
		expect(wrapper.find('[data-testid="send-access-password"]').exists()).toBe(true)
	})

	it('does not prompt when hasPassword is anything but true', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: { hasPassword: 'yes' } })
		const wrapper = await mountPage()

		expect(wrapper.vm.needsPassword).toBe(false)
		expect(wrapper.find('[data-testid="send-access-password"]').exists()).toBe(false)
	})

	it('peeks an empty token when the route carries none', async () => {
		const get = vi.spyOn(axios, 'get').mockResolvedValue({ data: {} })
		const wrapper = await mountPage({})

		expect(wrapper.vm.token).toBe('')
		expect(get).toHaveBeenCalledWith('/apps/keepiq/api/v1/public/sends/')
	})

	it('marks the send gone when the peek fails', async () => {
		vi.spyOn(axios, 'get').mockRejectedValue({ response: { status: 404 } })
		const wrapper = await mountPage()

		expect(wrapper.vm.gone).toBe(true)
		expect(wrapper.vm.needsPassword).toBe(false)
		expect(wrapper.find('[data-testid="send-access-open"]').exists()).toBe(false)
	})

	it('copies the revealed payload to the clipboard', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: {} })
		const writeText = vi.fn().mockResolvedValue()
		vi.stubGlobal('navigator', { clipboard: { writeText } })
		const wrapper = await mountPage()
		wrapper.vm.payload = 'the secret text'

		await wrapper.vm.copyPayload()

		expect(writeText).toHaveBeenCalledWith('the secret text')
	})

	it('copies an empty string when nothing was revealed', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: {} })
		const writeText = vi.fn().mockResolvedValue()
		vi.stubGlobal('navigator', { clipboard: { writeText } })
		const wrapper = await mountPage()

		await wrapper.vm.copyPayload()

		expect(writeText).toHaveBeenCalledWith('')
	})

	it('swallows a refused clipboard write', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: {} })
		const writeText = vi.fn().mockRejectedValue(new Error('denied'))
		vi.stubGlobal('navigator', { clipboard: { writeText } })
		const wrapper = await mountPage()
		wrapper.vm.payload = 'x'

		await expect(wrapper.vm.copyPayload()).resolves.toBeUndefined()
		expect(writeText).toHaveBeenCalledWith('x')
		expect(wrapper.vm.error).toBeNull()
	})
})
