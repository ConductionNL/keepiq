/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * ShareRequestForm states the submit test does not reach: no recipient,
 * a trimmed recipient, every error fallback, the close button, and a form
 * that starts clean each time it is opened again.
 *
 * @spec openspec/changes/implement-user-sharing/tasks.md#16.5
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ShareRequestForm from '../../src/components/share/ShareRequestForm.vue'

/**
 * Mount an open form for secret sec-1.
 *
 * @return {object} The wrapper.
 */
function mountOpen() {
	return mount(ShareRequestForm, { props: { secretId: 'sec-1', open: true } })
}

/**
 * The rendered error, or null.
 *
 * @param {object} wrapper The form.
 * @return {string|null}
 */
function errorText(wrapper) {
	const error = wrapper.find('[data-testid="share-request-form-error"]')
	return error.exists() ? error.text() : null
}

describe('ShareRequestForm states', () => {
	beforeEach(() => {
		vi.restoreAllMocks()
	})

	it('asks for a recipient instead of posting without one', async () => {
		const post = vi.spyOn(axios, 'post')
		const wrapper = mountOpen()

		expect(wrapper.find('[data-testid="share-request-form-submit"]').attributes('disabled')).toBeDefined()
		await wrapper.find('form').trigger('submit')

		expect(errorText(wrapper)).toBe('Pick a recipient user')
		expect(post).not.toHaveBeenCalled()
	})

	it('posts the trimmed recipient and the secret, then closes', async () => {
		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: {} })
		const wrapper = mountOpen()

		await wrapper.find('[data-testid="share-request-form-target"]').setValue('  carol  ')
		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(post).toHaveBeenCalledTimes(1)
		expect(post.mock.calls[0][1]).toEqual({ sourceSecretId: 'sec-1', targetUserId: 'carol' })
		expect(wrapper.emitted('submitted')).toEqual([[{ targetUserId: 'carol' }]])
		expect(wrapper.emitted('close')).toHaveLength(1)
		expect(wrapper.vm.submitting).toBe(false)
	})

	it.each([
		[new Error('Network Error'), 'Network Error'],
		[{}, 'Failed to send the share request'],
	])('shows why the request failed and stays open (%#)', async (failure, shown) => {
		vi.spyOn(axios, 'post').mockRejectedValue(failure)
		const wrapper = mountOpen()

		await wrapper.find('[data-testid="share-request-form-target"]').setValue('carol')
		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(errorText(wrapper)).toBe(shown)
		expect(wrapper.emitted('close')).toBeUndefined()
		expect(wrapper.emitted('submitted')).toBeUndefined()
		expect(wrapper.find('[data-testid="share-request-form-submit"]').attributes('disabled')).toBeUndefined()
	})

	it('closes from the header button', async () => {
		const wrapper = mountOpen()

		await wrapper.find('[data-testid="share-request-form-close"]').trigger('click')

		expect(wrapper.emitted('close')).toHaveLength(1)
	})

	it('starts clean when it is opened again', async () => {
		const wrapper = mountOpen()
		await wrapper.find('[data-testid="share-request-form-target"]').setValue('carol')
		wrapper.vm.error = 'old error'
		wrapper.vm.submitting = true

		await wrapper.setProps({ open: false })
		expect(wrapper.vm.targetUserId).toBe('carol')

		await wrapper.setProps({ open: true })

		expect(wrapper.vm.targetUserId).toBe('')
		expect(wrapper.vm.error).toBeNull()
		expect(wrapper.vm.submitting).toBe(false)
	})
})
