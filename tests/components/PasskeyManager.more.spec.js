/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `src/components/PasskeyManager.vue`, beyond the
 * extension-label / revoke / suspended paths pinned in
 * PasskeyManager.spec.js.
 *
 * Covered: nothing renders and nothing is fetched where WebAuthn is absent;
 * the store error note, the stale-envelope warning and its "Needs enrolling
 * again" status, the unnamed-passkey fallback, an unknown status shown raw,
 * and "last used" vs "never used"; "Add passkey" disabled with a hint while
 * the vault is locked; the enrol form (enrol disabled until the master
 * password is entered, the busy label while the ceremony runs, the exact
 * `enroll(masterPassword, label)` call, the success toast, the form closing
 * and clearing), a failed ceremony surfacing its message (or the generic
 * fallback) while keeping the form open, and cancel clearing the inputs.
 *
 * Boundaries mocked: `@nextcloud/axios` (credential list), the store's
 * `enroll` action (WebAuthn + crypto), `@nextcloud/dialogs`, and the
 * WebAuthn globals used for feature detection.
 *
 * @spec openspec/specs/passkey-vault-login/spec.md#requirement-passkeys-are-manageable-revocable-and-owner-scoped
 * @spec openspec/specs/passkey-vault-login/spec.md#requirement-passkey-enrollment-requires-an-unlocked-vault
 * @spec openspec/specs/passkey-vault-login/spec.md#requirement-envelopes-are-invalidated-when-the-unlock-key-changes
 */

import axios from '@nextcloud/axios'
import { showSuccess } from '@nextcloud/dialogs'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import PasskeyManager from '../../src/components/PasskeyManager.vue'
import { usePasskeyStore } from '../../src/store/modules/passkey.js'
import { useSessionStore } from '../../src/store/modules/session.js'

vi.mock('@nextcloud/dialogs', () => ({
	showSuccess: vi.fn(),
}))

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

const stubs = {
	NcNoteCard: {
		props: ['type'],
		template: '<div class="note" :data-type="type"><slot /></div>',
	},
	NcButton: {
		props: ['disabled', 'variant'],
		emits: ['click'],
		template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
	NcTextField: {
		props: ['modelValue', 'label'],
		emits: ['update:modelValue'],
		template:
			'<input class="label-field" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
	},
	NcPasswordField: {
		props: ['modelValue', 'label'],
		emits: ['update:modelValue'],
		template:
			'<input class="master-field" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
	},
	KeyIcon: true,
}

/**
 * Mount the manager and let the credential fetch settle.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mountManager() {
	const wrapper = mount(PasskeyManager, { global: { stubs } })
	await flush()
	return wrapper
}

describe('PasskeyManager more', () => {
	beforeEach(() => {
		window.PublicKeyCredential = function PublicKeyCredential() {}
		Object.defineProperty(navigator, 'credentials', { value: {}, configurable: true })
		setActivePinia(createPinia())
		vi.mocked(showSuccess).mockClear()
	})

	afterEach(() => {
		delete window.PublicKeyCredential
		vi.restoreAllMocks()
	})

	it('renders nothing and fetches nothing where WebAuthn is unavailable', async () => {
		delete window.PublicKeyCredential
		setActivePinia(createPinia())
		const get = vi.spyOn(axios, 'get').mockResolvedValue({ data: [] })

		const wrapper = await mountManager()

		expect(usePasskeyStore().supported).toBe(false)
		expect(wrapper.find('[data-testid="passkey-manager"]').exists()).toBe(false)
		expect(get).not.toHaveBeenCalled()
	})

	it('lists credentials with stale warning, fallbacks and usage text', async () => {
		const get = vi.spyOn(axios, 'get').mockResolvedValue({
			data: [
				{ id: 'p1', label: '', status: 'stale', lastUsedAt: '2026-05-01T12:00:00Z' },
				{ id: 'p2', label: 'Key', status: 'retired', lastUsedAt: null },
			],
		})

		const wrapper = await mountManager()

		expect(get).toHaveBeenCalledExactlyOnceWith('/apps/keepiq/api/v1/passkeys')
		expect(wrapper.find('[data-testid="passkey-stale-note"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="passkey-suspended-note"]').exists()).toBe(false)

		const p1 = wrapper.find('[data-testid="passkey-p1"]')
		expect(p1.find('.passkey-manager__label').text()).toBe('Passkey')
		expect(wrapper.find('[data-testid="passkey-status-p1"]').text()).toBe(
			'Needs enrolling again',
		)
		expect(wrapper.find('[data-testid="passkey-status-p1"]').classes()).toContain(
			'passkey-manager__status--stale',
		)
		// The test t() stub returns the source string, placeholder included.
		expect(p1.find('.passkey-manager__muted').text()).toBe('last used {when}')

		const p2 = wrapper.find('[data-testid="passkey-p2"]')
		expect(p2.find('.passkey-manager__label').text()).toBe('Key')
		expect(wrapper.find('[data-testid="passkey-status-p2"]').text()).toBe('retired')
		expect(p2.find('.passkey-manager__muted').text()).toBe('never used')
	})

	it('formats a last-used date with the locale date format', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: [] })
		const wrapper = await mountManager()
		const iso = '2026-05-01T12:00:00Z'

		expect(wrapper.vm.formatDate(iso)).toBe(new Date(iso).toLocaleDateString())
	})

	it('shows the store error in an error note', async () => {
		vi.spyOn(axios, 'get').mockRejectedValue(new Error('down'))
		const wrapper = await mountManager()
		usePasskeyStore().error = 'Could not load passkeys'
		await wrapper.vm.$nextTick()

		const note = wrapper.findAll('.note').find((n) => n.text() === 'Could not load passkeys')
		expect(note.attributes('data-type')).toBe('error')
		expect(wrapper.find('[data-testid="passkey-list"]').exists()).toBe(false)
	})

	it('disables "Add passkey" with a hint while the vault is locked', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: [] })
		const wrapper = await mountManager()

		expect(useSessionStore().isLocked).toBe(true)
		expect(wrapper.find('[data-testid="passkey-add"]').attributes('disabled')).toBeDefined()
		expect(wrapper.find('.passkey-manager__add').text()).toContain(
			'Unlock your vault to add a passkey.',
		)
	})

	describe('enrolment with an unlocked vault', () => {
		let store

		beforeEach(() => {
			vi.spyOn(axios, 'get').mockResolvedValue({ data: [] })
			useSessionStore().cryptoKey = {}
			store = usePasskeyStore()
		})

		/**
		 * Mount, open the form and fill it.
		 *
		 * @return {Promise<object>} The wrapper.
		 */
		async function openFilledForm() {
			const wrapper = await mountManager()
			const add = wrapper.find('[data-testid="passkey-add"]')
			expect(add.attributes('disabled')).toBeUndefined()
			expect(wrapper.find('.passkey-manager__add').text()).not.toContain('Unlock')
			await add.trigger('click')
			await wrapper.find('.label-field').setValue('Laptop')
			await wrapper.find('.master-field').setValue('pw')
			return wrapper
		}

		it('keeps enrol disabled until the master password is entered', async () => {
			const wrapper = await mountManager()
			await wrapper.find('[data-testid="passkey-add"]').trigger('click')

			const enroll = wrapper.find('[data-testid="passkey-enroll"]')
			expect(wrapper.find('[data-testid="passkey-add"]').exists()).toBe(false)
			expect(enroll.attributes('disabled')).toBeDefined()

			await wrapper.find('.master-field').setValue('pw')
			expect(enroll.attributes('disabled')).toBeUndefined()
		})

		it('enrols, toasts, then closes and clears the form', async () => {
			let finish
			store.enroll = vi.fn(() => new Promise((resolve) => {
				finish = resolve
			}))
			const wrapper = await openFilledForm()

			await wrapper.find('[data-testid="passkey-enroll"]').trigger('click')

			expect(store.enroll).toHaveBeenCalledExactlyOnceWith('pw', 'Laptop')
			const busyButton = wrapper.find('[data-testid="passkey-enroll"]')
			expect(busyButton.text()).toBe('Enrolling…')
			expect(busyButton.attributes('disabled')).toBeDefined()

			finish()
			await flush()

			expect(showSuccess).toHaveBeenCalledExactlyOnceWith('Passkey enrolled.')
			expect(wrapper.find('[data-testid="passkey-enroll-form"]').exists()).toBe(false)
			expect(wrapper.vm.masterPassword).toBe('')
			expect(wrapper.vm.label).toBe('')
			expect(wrapper.vm.busy).toBe(false)
		})

		it('surfaces a failed ceremony and keeps the form open', async () => {
			store.enroll = vi.fn().mockRejectedValue(new Error('Authenticator has no PRF'))
			const wrapper = await openFilledForm()

			await wrapper.find('[data-testid="passkey-enroll"]').trigger('click')
			await flush()

			expect(store.error).toBe('Authenticator has no PRF')
			expect(showSuccess).not.toHaveBeenCalled()
			expect(wrapper.find('[data-testid="passkey-enroll-form"]').exists()).toBe(true)
			expect(wrapper.vm.masterPassword).toBe('pw')
			expect(wrapper.find('[data-testid="passkey-enroll"]').text()).toBe('Enroll passkey')
		})

		it('falls back to a generic error when the failure has no message', async () => {
			store.enroll = vi.fn().mockRejectedValue({})
			const wrapper = await openFilledForm()

			await wrapper.find('[data-testid="passkey-enroll"]').trigger('click')
			await flush()

			expect(store.error).toBe('Enrollment failed')
		})

		it('cancel closes the form and forgets the typed values', async () => {
			store.enroll = vi.fn()
			const wrapper = await openFilledForm()

			const cancel = wrapper
				.findAll('[data-testid="passkey-enroll-form"] button')
				.find((b) => b.text() === 'Cancel')
			await cancel.trigger('click')

			expect(store.enroll).not.toHaveBeenCalled()
			expect(wrapper.find('[data-testid="passkey-enroll-form"]').exists()).toBe(false)
			expect(wrapper.vm.masterPassword).toBe('')
			expect(wrapper.vm.label).toBe('')
		})
	})
})
