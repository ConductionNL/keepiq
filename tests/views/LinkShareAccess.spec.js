/**
 * SPDX-FileCopyrightText: 2026 Conduction / Doriath Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `src/views/LinkShareAccess.vue` — the public
 * (no-auth) recipient-facing page that drives the two-phase link-share
 * access protocol.
 *
 * What this spec locks:
 *  - On mount the view reads the token from the URL path (the
 *    `$route.params.token` shape from the manifest router is mirrored
 *    by a path regex fallback so the spec can drive the page without
 *    spinning up a vue-router).
 *  - Phase 1 — calls `useLinkShareStore.fetchPublicLinkShare(token)`
 *    and surfaces the ciphertext-bearing payload as `share`.
 *  - On unlock — delegates to `decryptPublicSnapshot(share, password)`,
 *    stores the decrypted snapshot, and POSTs Phase 2 via
 *    `confirmPublicLinkShare(token)`.
 *  - A failed decrypt records `priorFailure=true` and re-fetches with
 *    `failed=true` so the server-side brute-force counter ticks.
 *  - A load failure surfaces a 404-style "not available" message via
 *    `loadError` (the controller emits 404 for not-found / expired /
 *    used-up so this is the recipient-side terminal state).
 *
 * @spec openspec/changes/implement-link-sharing/tasks.md#task-13.3
 * @spec openspec/changes/implement-link-sharing/tasks.md#task-8.1
 */

import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount } from '@vue/test-utils'

// @vue/test-utils v1 (Vue 2) does not export flushPromises; the
// canonical equivalent is to await an already-resolved promise twice
// to drain the microtask queue and let v-if branches re-render.
const flushPromises = async () => {
	await Promise.resolve()
	await Promise.resolve()
	await Promise.resolve()
}
import { createPinia, setActivePinia } from 'pinia'

import LinkShareAccess from '../../src/views/LinkShareAccess.vue'
import { useLinkShareStore } from '../../src/store/modules/linkShare.js'

describe('LinkShareAccess', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()

		// Drive the token via the URL path — same shape as the
		// manifest-mounted route /share/link/:token. jsdom defaults to
		// http://localhost/ so the regex needs the explicit path.
		const url = new URL('http://localhost/share/link/tok-abc')
		Object.defineProperty(window, 'location', {
			value: { pathname: url.pathname },
			writable: true,
		})
	})

	it('Phase 1: fetches the public share metadata on mount', async () => {
		const store = useLinkShareStore()
		store.fetchPublicLinkShare = vi.fn().mockResolvedValue({
			encryptedSecretSnapshot: 'CIPHERTEXT_B64',
			argon2idSalt: 'SALT_B64',
			usageLimit: 1,
			usageCount: 0,
		})

		const wrapper = mount(LinkShareAccess)
		await flushPromises()

		expect(store.fetchPublicLinkShare).toHaveBeenCalledWith('tok-abc', false)
		expect(wrapper.vm.share).toBeTruthy()
		expect(wrapper.vm.share.encryptedSecretSnapshot).toBe('CIPHERTEXT_B64')
		// Password form is on screen, snapshot is not yet.
		expect(wrapper.find('[data-testid="link-share-form"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="link-share-snapshot"]').exists()).toBe(
			false,
		)
	})

	it('Phase 2: unlocks with the password and POSTs confirm', async () => {
		const store = useLinkShareStore()
		store.fetchPublicLinkShare = vi.fn().mockResolvedValue({
			encryptedSecretSnapshot: 'CIPHERTEXT_B64',
			argon2idSalt: 'SALT_B64',
			usageLimit: 1,
			usageCount: 0,
		})
		store.decryptPublicSnapshot = vi.fn().mockResolvedValue({
			name: 'GitHub PAT',
			login: 'git-user',
			url: 'https://github.com',
			key: 'ghp_AAA',
		})
		store.confirmPublicLinkShare = vi.fn().mockResolvedValue({
			usageCount: 1,
			usageLimit: 1,
			remaining: 0,
		})

		const wrapper = mount(LinkShareAccess)
		await flushPromises()

		wrapper.vm.password = 'correct-horse-battery-staple-x'
		await wrapper.vm.onUnlock()
		await flushPromises()

		expect(store.decryptPublicSnapshot).toHaveBeenCalledTimes(1)
		expect(store.confirmPublicLinkShare).toHaveBeenCalledWith('tok-abc')
		expect(wrapper.vm.snapshot.key).toBe('ghp_AAA')
		// Snapshot view rendered, form gone.
		expect(wrapper.find('[data-testid="link-share-snapshot"]').exists()).toBe(
			true,
		)
		expect(wrapper.find('[data-testid="link-share-form"]').exists()).toBe(false)
	})

	it('wrong password: surfaces error and re-fetches with failed=true (brute-force counter)', async () => {
		const store = useLinkShareStore()
		// First fetch: returns the share.
		// After a failed decrypt the view calls loadShare again with
		// priorFailure=true, which translates to failed=true on the API.
		store.fetchPublicLinkShare = vi
			.fn()
			.mockResolvedValueOnce({
				encryptedSecretSnapshot: 'CIPHERTEXT_B64',
				argon2idSalt: 'SALT_B64',
				usageLimit: 3,
				usageCount: 0,
			})
			.mockResolvedValueOnce({
				encryptedSecretSnapshot: 'CIPHERTEXT_B64',
				argon2idSalt: 'SALT_B64',
				usageLimit: 3,
				usageCount: 0,
			})
		store.decryptPublicSnapshot = vi
			.fn()
			.mockRejectedValue(new Error('OperationError'))
		store.confirmPublicLinkShare = vi.fn()

		const wrapper = mount(LinkShareAccess)
		await flushPromises()

		wrapper.vm.password = 'wrong-password'
		await wrapper.vm.onUnlock()

		expect(wrapper.vm.unlockError).toContain('does not match')
		expect(store.confirmPublicLinkShare).not.toHaveBeenCalled()
		// The re-fetch was triggered with failed=true.
		expect(store.fetchPublicLinkShare).toHaveBeenCalledTimes(2)
		expect(store.fetchPublicLinkShare.mock.calls[1]).toEqual(['tok-abc', true])
		expect(wrapper.vm.snapshot).toBeNull()
	})

	it('404 on load: surfaces the not-available message and does not render the form', async () => {
		const store = useLinkShareStore()
		store.fetchPublicLinkShare = vi.fn().mockRejectedValue({
			response: {
				status: 404,
				data: { message: 'Link not found or expired' },
			},
		})

		const wrapper = mount(LinkShareAccess)
		await flushPromises()

		expect(wrapper.vm.loadError).toBe('Link not found or expired')
		expect(wrapper.find('[data-testid="link-share-load-error"]').exists()).toBe(
			true,
		)
		expect(wrapper.find('[data-testid="link-share-form"]').exists()).toBe(false)
	})
})
