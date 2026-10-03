/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The use-only and end-date options in the share and team-folder dialogs
 * (sharing-use-only-and-expiring-shares task 2.3).
 *
 * @spec openspec/changes/sharing-use-only-and-expiring-shares/specs/use-only-shares/spec.md#requirement-the-share-dialog-states-the-limit-of-use-only
 */

import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ShareRestrictionFields from '../../src/components/share/ShareRestrictionFields.vue'
import { useGroupShareStore } from '../../src/store/modules/groupShare.js'
import { useTeamFolderStore } from '../../src/store/modules/teamFolder.js'
import {
	accessEndTimestamp,
	restrictionPayload,
} from '../../src/utils/shareRestriction.js'

describe('ShareRestrictionFields', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
	})

	it('shows use-only, its limit, and the end date', () => {
		const wrapper = mount(ShareRestrictionFields)
		expect(wrapper.text()).toContain(
			'Use only (can sign in, cannot view or copy)',
		)
		expect(
			wrapper.find('[data-testid="share-restriction-limit"]').text(),
		).toContain(
			'Someone with technical skill can still read it from their own device',
		)
		expect(
			wrapper.find('[data-testid="share-restriction-end"]').attributes('type'),
		).toBe('date')
	})

	it('hides use-only when asked, keeping the end date', () => {
		const wrapper = mount(ShareRestrictionFields, {
			props: { hideUseOnly: true },
		})
		expect(
			wrapper.find('[data-testid="share-restriction-limit"]').exists(),
		).toBe(false)
		expect(wrapper.find('[data-testid="share-restriction-end"]').exists()).toBe(
			true,
		)
	})

	it('emits the picked end date', async () => {
		const wrapper = mount(ShareRestrictionFields)
		await wrapper
			.find('[data-testid="share-restriction-end"]')
			.setValue('2099-05-01')
		expect(wrapper.emitted('update:modelValue').at(-1)[0]).toEqual({
			useOnly: false,
			endDate: '2099-05-01',
		})
	})

	it('turns the options into request fields at the end of the picked day', () => {
		expect(accessEndTimestamp('')).toBeNull()
		expect(accessEndTimestamp('not a date')).toBeNull()
		const iso = accessEndTimestamp('2099-05-01')
		expect(new Date(iso).getDate()).toBe(1)
		expect(restrictionPayload({ useOnly: true, endDate: '' })).toEqual({
			useOnly: true,
			expiresAt: null,
		})
	})

	it('sends both options with a group share and a team-folder member', async () => {
		const post = vi
			.spyOn(axios, 'post')
			.mockResolvedValue({ data: { groupShare: null, members: [] } })
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: { owned: [], memberOf: [] },
		})

		await useGroupShareStore().shareWithGroup('s-1', 'team', {
			useOnly: true,
			expiresAt: '2099-05-01T21:59:59.000Z',
		})
		expect(post.mock.calls[0][1]).toEqual({
			groupId: 'team',
			useOnly: true,
			expiresAt: '2099-05-01T21:59:59.000Z',
		})

		await useTeamFolderStore().addMember('tf-1', 'user', 'carla', {
			useOnly: false,
			expiresAt: '2099-05-01T21:59:59.000Z',
		})
		expect(post.mock.calls[1][1]).toEqual({
			memberType: 'user',
			memberId: 'carla',
			useOnly: false,
			expiresAt: '2099-05-01T21:59:59.000Z',
		})
	})
})
