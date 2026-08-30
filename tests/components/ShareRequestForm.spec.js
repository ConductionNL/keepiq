/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `src/components/share/ShareRequestForm.vue`.
 *
 * @spec openspec/changes/implement-user-sharing/tasks.md#16.5
 */

import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import axios from '@nextcloud/axios'

import ShareRequestForm from '../../src/components/share/ShareRequestForm.vue'

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

describe('ShareRequestForm', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
	})

	it('does not render when closed', () => {
		const wrapper = mount(ShareRequestForm, {
			propsData: { secretId: 'sec-1', open: false },
		})
		expect(wrapper.find('[data-testid="share-request-form"]').exists()).toBe(
			false,
		)
	})

	it('renders when open', () => {
		const wrapper = mount(ShareRequestForm, {
			propsData: { secretId: 'sec-1', open: true },
		})
		expect(wrapper.find('[data-testid="share-request-form"]').exists()).toBe(
			true,
		)
	})

	it('emits close when the cancel button is clicked', async () => {
		const wrapper = mount(ShareRequestForm, {
			propsData: { secretId: 'sec-1', open: true },
		})
		await wrapper
			.find('[data-testid="share-request-form-cancel"]')
			.trigger('click')
		expect(wrapper.emitted('close')).toBeTruthy()
	})

	it('POSTs the request and emits submitted on success', async () => {
		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: {} })
		const wrapper = mount(ShareRequestForm, {
			propsData: { secretId: 'sec-1', open: true },
		})
		await wrapper
			.find('[data-testid="share-request-form-target"]')
			.setValue('carol')
		await wrapper.find('form').trigger('submit.prevent')
		await flush()
		expect(post).toHaveBeenCalled()
		const [url, body] = post.mock.calls[0]
		expect(url).toContain('/api/v1/share-requests')
		expect(body.targetUserId).toBe('carol')
		expect(wrapper.emitted('submitted')).toBeTruthy()
	})

	it('surfaces the API error message', async () => {
		vi.spyOn(axios, 'post').mockRejectedValue({
			response: { data: { message: 'rate limited' } },
		})
		const wrapper = mount(ShareRequestForm, {
			propsData: { secretId: 'sec-1', open: true },
		})
		await wrapper
			.find('[data-testid="share-request-form-target"]')
			.setValue('carol')
		await wrapper.find('form').trigger('submit.prevent')
		await flush()
		expect(
			wrapper.find('[data-testid="share-request-form-error"]').text(),
		).toContain('rate limited')
	})
})
