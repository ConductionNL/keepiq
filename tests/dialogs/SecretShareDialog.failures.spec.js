/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The link-share dialog when its calls fail: a failed load of the existing
 * shares or a failed revoke shows the server's message, the error's own
 * message, or a fixed sentence, in that order. Also the expiry formatting
 * of an unreadable date and the clean-up of the one-time password when the
 * dialog goes away.
 *
 * @spec openspec/specs/link-sharing/spec.md#requirement-manual-revocation
 */

import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import SecretShareDialog from '../../src/dialogs/SecretShareDialog.vue'
import { useFederatedShareStore } from '../../src/store/modules/federatedShare.js'
import { useLinkShareStore } from '../../src/store/modules/linkShare.js'

const stubs = {
	NcDialog: { props: ['name', 'open', 'size'], template: '<div><slot /><slot name="actions" /></div>' },
	NcButton: { props: ['type', 'disabled', 'ariaLabel'], template: '<button :disabled="disabled" @click="$emit(\'click\', $event)"><slot name="icon" /><slot /></button>' },
	NcSelect: { props: ['options', 'reduce', 'inputLabel', 'clearable', 'value'], template: '<div />' },
	NcNoteCard: { props: ['type'], template: '<div class="note" :data-type="type"><slot /></div>' },
	NcLoadingIcon: { template: '<span />' },
	ShareVariant: { template: '<i />' },
	AccountPlus: { template: '<i />' },
	Delete: { template: '<i />' },
	CopyButton: { props: ['value', 'label'], template: '<button>{{ value }}</button>' },
}

/**
 * Mount the dialog for one secret once its mount reads settle.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mountDialog() {
	const wrapper = mount(SecretShareDialog, { props: { secretId: 'secret-42' }, global: { stubs } })
	await flushPromises()
	return wrapper
}

describe('SecretShareDialog when a call fails', () => {
	let linkShares

	beforeEach(() => {
		setActivePinia(createPinia())
		linkShares = useLinkShareStore()
		vi.spyOn(useFederatedShareStore(), 'checkAvailable').mockResolvedValue(false)
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('shows the server message when the shares cannot be loaded, and stops loading', async () => {
		vi.spyOn(linkShares, 'fetchLinkShares').mockRejectedValue({ response: { data: { message: 'Not your secret' } } })
		const wrapper = await mountDialog()

		expect(linkShares.fetchLinkShares).toHaveBeenCalledWith('secret-42')
		expect(wrapper.vm.error).toBe('Not your secret')
		expect(wrapper.vm.loadingShares).toBe(false)
	})

	it('falls back to the error message, then to a fixed sentence, when loading fails', async () => {
		const fetch = vi.spyOn(linkShares, 'fetchLinkShares').mockRejectedValueOnce(new Error('Network Error'))
		const wrapper = await mountDialog()
		expect(wrapper.vm.error).toBe('Network Error')

		fetch.mockRejectedValueOnce({})
		await wrapper.vm.loadShares()
		expect(wrapper.vm.error).toBe('Failed to load link shares')
	})

	it('clears an earlier error and shows the revoke failure in the same order', async () => {
		vi.spyOn(linkShares, 'fetchLinkShares').mockResolvedValue()
		const remove = vi.spyOn(linkShares, 'deleteLinkShare')
		const wrapper = await mountDialog()

		remove.mockRejectedValueOnce({ response: { data: { message: 'Already revoked' } } })
		await wrapper.vm.revoke('ls-1')
		expect(remove).toHaveBeenCalledWith('ls-1')
		expect(wrapper.vm.error).toBe('Already revoked')

		remove.mockRejectedValueOnce(new Error('Timeout'))
		await wrapper.vm.revoke('ls-1')
		expect(wrapper.vm.error).toBe('Timeout')

		remove.mockRejectedValueOnce({})
		await wrapper.vm.revoke('ls-1')
		expect(wrapper.vm.error).toBe('Failed to revoke link share')

		remove.mockResolvedValueOnce()
		await wrapper.vm.revoke('ls-1')
		expect(wrapper.vm.error).toBe('')
	})

	it('shows an unreadable expiry as it came and a readable one as a local date', async () => {
		vi.spyOn(linkShares, 'fetchLinkShares').mockResolvedValue()
		const wrapper = await mountDialog()

		expect(wrapper.vm.formatDate('not-a-date')).toBe('not-a-date')
		expect(wrapper.vm.formatDate('2026-12-31T12:00:00Z')).toBe(new Date('2026-12-31T12:00:00Z').toLocaleDateString())
	})

	it('records whether federation is available and forgets the one-time password on unmount', async () => {
		useFederatedShareStore().checkAvailable.mockResolvedValue(true)
		vi.spyOn(linkShares, 'fetchLinkShares').mockResolvedValue()
		const clear = vi.spyOn(linkShares, 'clearCreatedPassword')
		const wrapper = await mountDialog()
		expect(wrapper.vm.federationAvailable).toBe(true)

		wrapper.unmount()

		expect(clear).toHaveBeenCalled()
	})
})
