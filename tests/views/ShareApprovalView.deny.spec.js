/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * `src/views/ShareApprovalView.vue`: denying a share request, a deny that
 * fails, the question asked for a new group member, and the links the page
 * refuses (an unknown kind, an incomplete group-member link, no route).
 *
 * @spec openspec/specs/user-sharing/spec.md#requirement-share-request-recipient-initiated
 * @spec openspec/specs/user-sharing/spec.md#requirement-new-group-member-owner-notification
 */

import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ShareApprovalView from '../../src/views/ShareApprovalView.vue'
import { useShareApprovalStore } from '../../src/store/modules/shareApproval.js'

const SHARE_REQUEST = { sourceSecretId: 's-1', requesterId: 'bob', targetUserId: 'carol' }

/**
 * A `t()` that fills in its placeholders, so the question's names are checked.
 *
 * @param {string} _app The app id.
 * @param {string} text The source text.
 * @param {object} vars The placeholder values.
 * @return {string} The text with its placeholders filled in.
 */
function substitute (_app, text, vars = {}) {
  return text.replace(/\{(\w+)\}/g, (all, name) => vars[name] ?? all)
}

/**
 * Mount the page on a route.
 *
 * @param {object|undefined} route The `$route` mock.
 * @return {object} The wrapper.
 */
function mountOn(route) {
	return mount(ShareApprovalView, {
		global: {
			mocks: { $route: route },
			mixins: [{ methods: { t: substitute } }],
			stubs: {
				NcButton: {
					template:
						'<button v-bind="$attrs" @click="$emit(\'click\')"><slot /></button>',
				},
				NcNoteCard: { template: '<div v-bind="$attrs"><slot /></div>' },
			},
		},
	})
}

describe('ShareApprovalView deny and refused links', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('denies a share request and drops the buttons', async () => {
		const store = useShareApprovalStore()
		store.denyShareRequest = vi.fn().mockResolvedValue(undefined)
		store.denyGroupMember = vi.fn()
		const wrapper = mountOn({ params: { kind: 'share-request' }, query: SHARE_REQUEST })

		await wrapper.find('[data-testid="share-approval-deny"]').trigger('click')
		await flushPromises()

		expect(store.denyShareRequest).toHaveBeenCalledWith(SHARE_REQUEST)
		expect(store.denyGroupMember).not.toHaveBeenCalled()
		expect(wrapper.vm.done).toBe(true)
		expect(wrapper.vm.busy).toBe(false)
		expect(wrapper.find('[data-testid="share-approval-outcome"]').text()).toBe(
			'Denied. Nothing was shared.',
		)
		expect(wrapper.find('[data-testid="share-approval-deny"]').exists()).toBe(false)
	})

	it('keeps the buttons and says to try again when the deny fails', async () => {
		const store = useShareApprovalStore()
		store.denyShareRequest = vi.fn().mockRejectedValue(new Error('down'))
		const wrapper = mountOn({ params: { kind: 'share-request' }, query: SHARE_REQUEST })

		await wrapper.vm.deny()

		expect(wrapper.vm.outcome).toEqual({
			type: 'error',
			text: 'Could not deny the request. Try again.',
		})
		expect(wrapper.vm.done).toBe(false)
		expect(wrapper.vm.busy).toBe(false)
	})

	it('asks the owner about the new group member by name', () => {
		const wrapper = mountOn({
			params: { kind: 'group-member' },
			query: { groupShareId: 'gs-1', newMemberId: 'dave', secretId: 's-1' },
		})

		expect(wrapper.vm.request).toEqual({
			kind: 'group-member',
			groupShareId: 'gs-1',
			newMemberId: 'dave',
			secretId: 's-1',
		})
		expect(wrapper.find('[data-testid="share-approval-question"]').text()).toBe(
			'dave joined a group you share a secret with. Share the secret with them too?',
		)
	})

	it.each([
		['an unknown kind', { params: { kind: 'something-else' }, query: SHARE_REQUEST }],
		['an incomplete group-member link', { params: { kind: 'group-member' }, query: { groupShareId: 'gs-1' } }],
		['no route at all', undefined],
	])('refuses %s', (_label, route) => {
		const wrapper = mountOn(route)

		expect(wrapper.vm.request).toBeNull()
		expect(wrapper.find('[data-testid="share-approval-invalid"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="share-approval-approve"]').exists()).toBe(false)
	})
})
