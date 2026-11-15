/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pins the display rows a decrypted link-share snapshot becomes, row by
 * row, before the computed property is split into named steps: the name,
 * login and URL rows only when set, a card composite as one row per filled
 * member with the sensitive ones masked and unknown members under their
 * own name, a plain value as one masked row, and every additional field
 * as a masked row.
 *
 * @spec openspec/changes/implement-link-sharing/tasks.md#task-8.1
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import LinkShareAccess from '../../src/views/LinkShareAccess.vue'
import { useLinkShareStore } from '../../src/store/modules/linkShare.js'

/**
 * Mount the page and give it a decrypted snapshot.
 *
 * @param {object|null} snapshot The decrypted snapshot.
 * @return {Array<object>} Its display rows.
 */
async function fieldsOf(snapshot) {
	const wrapper = mount(LinkShareAccess, {
		global: { mocks: { t: (app, s) => s }, stubs: { NcButton: true, NcNoteCard: true, NcPasswordField: true } },
	})
	wrapper.vm.snapshot = snapshot
	return wrapper.vm.snapshotFields
}

describe('LinkShareAccess snapshot rows', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
		Object.defineProperty(window, 'location', {
			value: { pathname: '/nothing' },
			writable: true,
		})
		vi.spyOn(useLinkShareStore(), 'fetchPublicLinkShare').mockResolvedValue({})
	})

	it('shows nothing before the snapshot is decrypted', async () => {
		expect(await fieldsOf(null)).toEqual([])
	})

	it('shows a plain login with its value masked and its additional fields', async () => {
		expect(
			await fieldsOf({
				name: 'Bank',
				login: 'me',
				url: 'https://bank',
				key: 'hunter2',
				additionalFields: { pin: '1234', note: 'n' },
			}),
		).toMatchSnapshot()
	})

	it('shows only the rows that are set, and a number as text', async () => {
		expect(await fieldsOf({ name: '', login: null, key: 42 })).toMatchSnapshot()
		expect(await fieldsOf({ url: 'u' })).toMatchSnapshot()
	})

	it('shows a card composite member by member, skipping empty ones', async () => {
		expect(
			await fieldsOf({
				name: 'Visa',
				key: JSON.stringify({
					number: '4111',
					expiry: '12/30',
					cvv: '123',
					pin: '',
					cardholder: null,
					custom: 'kept',
				}),
			}),
		).toMatchSnapshot()
	})
})
