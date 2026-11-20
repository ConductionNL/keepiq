/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The recipient field of the user share dialogs (keepiq#37): Nextcloud's
 * sharee search, with each result marked when that user has no vault yet.
 * The marks come from one call per search that names the term and that
 * page's ids; there is no list of vault holders.
 *
 * @spec openspec/specs/user-sharing/spec.md#requirement-recipient-search-marks-who-cannot-receive-a-share
 */

import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import RecipientPicker from '../../src/components/share/RecipientPicker.vue'
import { useShareStore } from '../../src/store/modules/share.js'

/**
 * Answer the sharee search with these users, as `[id, displayName]`.
 *
 * @param {Array<Array<string>>} users The users.
 * @return {object} The spy.
 */
function mockSharees(users) {
	return vi.spyOn(axios, 'get').mockResolvedValue({
		data: {
			ocs: {
				data: {
					exact: { users: [] },
					users: users.map(([id, label]) => ({
						label,
						value: { shareType: 0, shareWith: id },
					})),
				},
			},
		},
	})
}

/**
 * Answer the recipient-status call.
 *
 * @param {Array<object>} recipients The `{userId, hasSuite}` rows.
 * @return {object} The spy.
 */
function mockStatus(recipients) {
	return vi.spyOn(axios, 'post').mockResolvedValue({ data: { recipients } })
}

describe('useShareStore().searchRecipients', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
	})

	it('asks about one search page by term and ids, and marks who has a vault', async () => {
		const get = mockSharees([
			['alice', 'Alice Adams'],
			['albert', 'Albert Ames'],
		])
		const post = mockStatus([
			{ userId: 'alice', hasSuite: true },
			{ userId: 'albert', hasSuite: false },
		])

		const found = await useShareStore().searchRecipients('al')

		expect(get.mock.calls[0][1].params).toMatchObject({
			search: 'al',
			shareType: 0,
			perPage: 25,
			lookup: false,
		})
		expect(post).toHaveBeenCalledTimes(1)
		expect(post.mock.calls[0][0]).toContain(
			'/apps/keepiq/api/v1/shares/recipient-status',
		)
		expect(post.mock.calls[0][1]).toEqual({
			search: 'al',
			userIds: ['alice', 'albert'],
		})
		expect(found).toEqual([
			{ id: 'alice', label: 'Alice Adams', hasSuite: true },
			{ id: 'albert', label: 'Albert Ames', hasSuite: false },
		])
	})

	it('leaves out a user the server did not answer about', async () => {
		mockSharees([
			['alice', 'Alice'],
			['ghost', 'Ghost'],
		])
		mockStatus([{ userId: 'alice', hasSuite: true }])

		const found = await useShareStore().searchRecipients('a')

		expect(found.map((row) => row.id)).toEqual(['alice'])
	})

	it('makes no status call when the search finds nobody', async () => {
		mockSharees([])
		const post = mockStatus([])

		expect(await useShareStore().searchRecipients('zz')).toEqual([])
		expect(post).not.toHaveBeenCalled()
	})
})

describe('RecipientPicker', () => {
	const NcSelectStub = {
		props: ['options', 'selectable', 'inputLabel', 'modelValue'],
		emits: ['update:modelValue', 'search'],
		template: `<div class="stub-select" :data-label="inputLabel">
			<div v-for="o in options" :key="o.id" class="opt" :data-selectable="String(selectable(o))">
				<slot name="option" v-bind="o" />
			</div>
		</div>`,
	}

	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
	})

	/**
	 * Mount the picker and run one search.
	 *
	 * @return {Promise<object>} The wrapper.
	 */
	async function searched() {
		mockSharees([
			['alice', 'Alice Adams'],
			['albert', 'Albert Ames'],
		])
		mockStatus([
			{ userId: 'alice', hasSuite: true },
			{ userId: 'albert', hasSuite: false },
		])
		const wrapper = mount(RecipientPicker, {
			global: { stubs: { NcSelect: NcSelectStub } },
		})
		await wrapper.vm.search('al')
		return wrapper
	}

	it('marks a user without a vault and does not let them be picked', async () => {
		const wrapper = await searched()
		const rows = wrapper.findAll('.opt')

		expect(rows).toHaveLength(2)
		expect(rows[0].text()).toBe('Alice Adams')
		expect(rows[0].attributes('data-selectable')).toBe('true')
		expect(rows[1].text()).toContain('Albert Ames')
		expect(rows[1].find('[data-testid="recipient-no-vault"]').text()).toBe(
			'No vault yet',
		)
		expect(rows[1].attributes('data-selectable')).toBe('false')
	})

	it('passes only a user with a vault up as the recipient', async () => {
		const wrapper = await searched()
		const [alice, albert] = wrapper.vm.options

		wrapper.vm.onPick(albert)
		expect(wrapper.emitted('update:modelValue')).toBeUndefined()

		wrapper.vm.onPick(alice)
		expect(wrapper.emitted('update:modelValue')).toEqual([['alice']])
	})

	it('labels the field', async () => {
		const wrapper = await searched()
		expect(wrapper.find('.stub-select').attributes('data-label')).toBe(
			'Recipient',
		)
	})
})
