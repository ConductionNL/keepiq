/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden outcomes of creating a link share from `SecretShareDialog`: the
 * exact snapshot and arguments it hands the store, and what it shows.
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import SecretShareDialog from '../../src/dialogs/SecretShareDialog.vue'
import { useLinkShareStore } from '../../src/store/modules/linkShare.js'
import { useSecretStore } from '../../src/store/modules/secret.js'

function mountDialog() {
	const linkStore = useLinkShareStore()
	vi.spyOn(linkStore, 'fetchLinkShares').mockResolvedValue([])
	return mount(SecretShareDialog, { propsData: { secretId: 'secret-7' }, shallow: true })
}

describe('SecretShareDialog createLink', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
	})

	it('hands the store exactly the five snapshot fields and its own arguments', async () => {
		useSecretStore().fetchSecret = vi.fn().mockResolvedValue({
			id: 'secret-7',
			name: 'DB',
			url: 'https://db.example',
			login: 'root',
			key: 'k-1',
			additionalFields: { a: 1 },
			totp: 'seed',
			passkeys: [{ id: 'p' }],
		})
		const linkStore = useLinkShareStore()
		const create = vi.spyOn(linkStore, 'createLinkShare').mockImplementation(async () => {
			linkStore.createdLinkUrl = '/share/link/t'
			linkStore.createdPassword = 'pw'
		})
		const wrapper = mountDialog()
		wrapper.vm.usageLimit = 3
		wrapper.vm.expiryDate = '2031-01-02'
		await wrapper.vm.createLink()

		expect(useSecretStore().fetchSecret).toHaveBeenCalledWith('secret-7')
		expect(create).toHaveBeenCalledWith(
			'secret-7',
			{ name: 'DB', url: 'https://db.example', login: 'root', key: 'k-1', additionalFields: { a: 1 } },
			3,
			new Date('2031-01-02T23:59:59').toISOString(),
		)
		expect(Object.keys(create.mock.calls[0][1])).toEqual(['name', 'url', 'login', 'key', 'additionalFields'])
		expect(wrapper.vm.createdUrl).toBe('/share/link/t')
		expect(wrapper.vm.createdPassword).toBe('pw')
		expect(wrapper.emitted('saved')).toHaveLength(1)
		expect(wrapper.vm.creating).toBe(false)
		expect(wrapper.vm.error).toBe('')
	})

	it('fills the empty snapshot fields with their defaults', async () => {
		useSecretStore().fetchSecret = vi.fn().mockResolvedValue({ name: 'Bare', url: '', login: '', key: '' })
		const create = vi.spyOn(useLinkShareStore(), 'createLinkShare').mockResolvedValue(undefined)
		const wrapper = mountDialog()
		await wrapper.vm.createLink()

		expect(create.mock.calls[0][1]).toEqual({ name: 'Bare', url: null, login: null, key: '', additionalFields: null })
		expect(create.mock.calls[0][3]).toBe(null)
	})

	it.each([
		['the fetch', { response: { data: { message: 'locked' } }, message: 'x' }, 'locked'],
		['the store', { message: 'network' }, 'network'],
		['the store', {}, 'Failed to create link share'],
	])('shows a failure of %s and emits nothing (%#)', async (where, failure, text) => {
		const fetchSecret = vi.fn().mockResolvedValue({ name: 'N', key: 'k' })
		if (where === 'the fetch') {
			fetchSecret.mockRejectedValue(failure)
		}
		useSecretStore().fetchSecret = fetchSecret
		const create = vi.spyOn(useLinkShareStore(), 'createLinkShare').mockRejectedValue(failure)
		const wrapper = mountDialog()
		await wrapper.vm.createLink()

		if (where === 'the fetch') {
			expect(create).not.toHaveBeenCalled()
		}
		expect(wrapper.vm.error).toBe(text)
		expect(wrapper.vm.creating).toBe(false)
		expect(wrapper.vm.createdUrl).toBeFalsy()
		expect(wrapper.emitted('saved')).toBeUndefined()
	})

	it.each([
		['a past date', () => '2000-01-01'],
		['today', () => {
			const now = new Date()
			const pad = (n) => String(n).padStart(2, '0')
			return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`
		}],
	])('refuses %s as the end date before decrypting or sending anything', async (label, date) => {
		useSecretStore().fetchSecret = vi.fn().mockResolvedValue({ name: 'N', key: 'k' })
		const create = vi.spyOn(useLinkShareStore(), 'createLinkShare').mockResolvedValue(undefined)
		const wrapper = mountDialog()
		wrapper.vm.expiryDate = date()
		await wrapper.vm.createLink()

		expect(useSecretStore().fetchSecret).not.toHaveBeenCalled()
		expect(create).not.toHaveBeenCalled()
		expect(wrapper.vm.error).toBe('The end date must lie in the future.')
		expect(wrapper.vm.creating).toBe(false)
		expect(wrapper.emitted('saved')).toBeUndefined()
	})
})
