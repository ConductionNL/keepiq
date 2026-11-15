/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for the form wiring of `src/views/LinkShareAccess.vue`: the
 * password typed into the field is what the snapshot is decrypted with when
 * the form is submitted, an empty password submits nothing, a URL without a
 * token asks for nothing, and a load failure without a server message shows
 * the generic not-available text.
 *
 * @spec openspec/specs/link-sharing/spec.md#requirement-access-via-link
 * @spec openspec/specs/link-sharing/spec.md#requirement-kdf-for-snapshot-encryption
 */

import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import LinkShareAccess from '../../src/views/LinkShareAccess.vue'
import { useLinkShareStore } from '../../src/store/modules/linkShare.js'

const SHARE = { encryptedSecretSnapshot: 'CT', argon2idSalt: 'SALT', usageLimit: 1, usageCount: 0 }

/**
 * Point the page's URL at the given path.
 *
 * @param {string} pathname The path.
 * @return {void}
 */
function atPath(pathname) {
	Object.defineProperty(window, 'location', { value: { pathname }, writable: true })
}

describe('LinkShareAccess form wiring', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
		atPath('/share/link/tok%20one')
		store = useLinkShareStore()
		store.fetchPublicLinkShare = vi.fn().mockResolvedValue(SHARE)
		store.decryptPublicSnapshot = vi.fn().mockResolvedValue({ name: 'Mail' })
		store.confirmPublicLinkShare = vi.fn().mockResolvedValue(undefined)
	})

	it('decrypts with the typed password when the form is submitted', async () => {
		const wrapper = mount(LinkShareAccess)
		await flushPromises()
		expect(store.fetchPublicLinkShare).toHaveBeenCalledWith('tok one')

		await wrapper.find('[data-testid="link-share-password"]').setValue('s3cret')
		await wrapper.find('[data-testid="link-share-form"]').trigger('submit')
		await flushPromises()

		expect(store.decryptPublicSnapshot).toHaveBeenCalledWith(SHARE, 's3cret')
		expect(store.confirmPublicLinkShare).toHaveBeenCalledWith('tok one')
		expect(wrapper.vm.snapshot).toEqual({ name: 'Mail' })
	})

	it('submits nothing while the password is empty', async () => {
		const wrapper = mount(LinkShareAccess)
		await flushPromises()

		await wrapper.find('[data-testid="link-share-form"]').trigger('submit')
		await flushPromises()

		expect(store.decryptPublicSnapshot).not.toHaveBeenCalled()
		expect(wrapper.vm.busy).toBe(false)
	})

	it('submits nothing while an unlock is already running', async () => {
		const wrapper = mount(LinkShareAccess)
		await flushPromises()
		wrapper.vm.password = 'pw'
		wrapper.vm.busy = true

		await wrapper.vm.onUnlock()

		expect(store.decryptPublicSnapshot).not.toHaveBeenCalled()
	})

	it('asks for no share when the URL carries no token', async () => {
		atPath('/somewhere/else')
		const wrapper = mount(LinkShareAccess)
		await flushPromises()

		expect(wrapper.vm.token).toBe('')
		expect(store.fetchPublicLinkShare).not.toHaveBeenCalled()
		expect(wrapper.vm.loadError).toBe('No share token in the URL.')
	})

	it('shows the generic not-available text when the failure carries no message', async () => {
		store.fetchPublicLinkShare = vi.fn().mockRejectedValue(new Error('network'))
		const wrapper = mount(LinkShareAccess)
		await flushPromises()

		expect(wrapper.vm.share).toBeNull()
		expect(wrapper.vm.loadError).toBe(
			'This share is not available. It may have expired or been used up.',
		)
		expect(wrapper.find('[data-testid="link-share-form"]').exists()).toBe(false)
	})
})
