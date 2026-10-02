/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The page the notification's Approve action opens (#747).
 *
 * @spec openspec/specs/user-sharing/spec.md#requirement-share-request-recipient-initiated
 * @spec openspec/specs/user-sharing/spec.md#requirement-new-group-member-owner-notification
 */

import { flushPromises, mount } from '@vue/test-utils'
import * as fs from 'fs'
import * as path from 'path'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ShareApprovalView from '../../src/views/ShareApprovalView.vue'
import registry from '../../src/registry.js'
import { useShareApprovalStore } from '../../src/store/modules/shareApproval.js'

/**
 * Mount the page on a route.
 *
 * @param {string} kind The `:kind` route param.
 * @param {object} query The route query.
 * @return {object} The wrapper.
 */
function mountOn(kind, query) {
	return mount(ShareApprovalView, {
		global: {
			mocks: { $route: { params: { kind }, query } },
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

describe('ShareApprovalView', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('is a registered page on /approvals/:kind', () => {
		const manifest = JSON.parse(
			fs.readFileSync(
				path.resolve(__dirname, '../../src/manifest.json'),
				'utf8',
			),
		)
		const page = manifest.pages.find((p) => p.id === 'ShareApproval')
		expect(page).toMatchObject({
			route: '/approvals/:kind',
			component: 'ShareApprovalView',
		})
		expect(registry.ShareApprovalView?.component).toBe(ShareApprovalView)
	})

	it('approves a share request from the notification link', async () => {
		const store = useShareApprovalStore()
		store.approveShareRequest = vi.fn().mockResolvedValue('created')
		const wrapper = mountOn('share-request', {
			sourceSecretId: 's-1',
			requesterId: 'bob',
			targetUserId: 'carol',
		})

		await wrapper.find('[data-testid="share-approval-approve"]').trigger('click')
		await flushPromises()

		expect(store.approveShareRequest).toHaveBeenCalledWith({
			sourceSecretId: 's-1',
			requesterId: 'bob',
			targetUserId: 'carol',
		})
		expect(wrapper.find('[data-testid="share-approval-outcome"]').exists()).toBe(
			true,
		)
		expect(wrapper.find('[data-testid="share-approval-approve"]').exists()).toBe(
			false,
		)
	})

	it('denies a new group member', async () => {
		const store = useShareApprovalStore()
		store.denyGroupMember = vi.fn().mockResolvedValue(undefined)
		const wrapper = mountOn('group-member', {
			groupShareId: 'gs-1',
			newMemberId: 'dave',
			secretId: 's-1',
		})

		await wrapper.find('[data-testid="share-approval-deny"]').trigger('click')
		await flushPromises()

		expect(store.denyGroupMember).toHaveBeenCalledWith({
			groupShareId: 'gs-1',
			newMemberId: 'dave',
			secretId: 's-1',
		})
	})

	it('keeps the buttons when the recipient has no suite', async () => {
		const store = useShareApprovalStore()
		store.approveGroupMember = vi.fn().mockResolvedValue('no_suite')
		const wrapper = mountOn('group-member', {
			groupShareId: 'gs-1',
			newMemberId: 'dave',
			secretId: 's-1',
		})

		await wrapper.find('[data-testid="share-approval-approve"]').trigger('click')
		await flushPromises()

		expect(wrapper.find('[data-testid="share-approval-approve"]').exists()).toBe(
			true,
		)
	})

	it('refuses an incomplete link', () => {
		const wrapper = mountOn('share-request', { sourceSecretId: 's-1' })

		expect(wrapper.find('[data-testid="share-approval-invalid"]').exists()).toBe(
			true,
		)
		expect(wrapper.find('[data-testid="share-approval-approve"]').exists()).toBe(
			false,
		)
	})
})
