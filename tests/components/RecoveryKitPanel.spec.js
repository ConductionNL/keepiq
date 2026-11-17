/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The recovery kit panel and the lock screen's way back in
 * (crypto-recovery-kit 2.2, 2.3). Pins: the panel says when the kit was made,
 * makes a kit with the typed master password and the session's envelope,
 * shows the code once with download and print, and reports a failure; the
 * lock screen offers "I lost my master password" only to a user with a kit,
 * recovers and unlocks with the new password, offers a new kit, and opens
 * nothing on a wrong code.
 *
 * @spec openspec/changes/crypto-recovery-kit/specs/vault-recovery-kit/spec.md#requirement-a-user-can-create-a-recovery-kit-that-reopens-their-own-vault
 * @spec openspec/changes/crypto-recovery-kit/specs/vault-recovery-kit/spec.md#requirement-the-recovery-code-reopens-the-vault-and-sets-a-new-master-password
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const service = vi.hoisted(() => ({
	recoveryKitStatus: vi.fn(),
	createRecoveryKit: vi.fn(),
	recoverWithCode: vi.fn(),
}))

vi.mock('../../src/services/recoveryKit.js', () => service)
vi.mock('@nextcloud/auth', () => ({ getCurrentUser: () => ({ uid: 'alice' }) }))
vi.mock('@nextcloud/l10n', () => ({
	translate: (_app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (match, name) => String(vars[name] ?? match)),
}))

const { default: RecoveryKitPanel } = await import('../../src/components/RecoveryKitPanel.vue')
const { default: RecoveryKitUnlock } = await import('../../src/components/RecoveryKitUnlock.vue')
const { useSessionStore } = await import('../../src/store/modules/session.js')

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

const stubs = {
	NcButton: {
		props: ['variant', 'disabled'],
		emits: ['click'],
		template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
	NcNoteCard: { props: ['type'], template: '<div class="note" :data-type="type"><slot /></div>' },
	NcPasswordField: {
		props: ['modelValue', 'label', 'disabled'],
		emits: ['update:modelValue'],
		template: '<input class="password" :disabled="disabled" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
	},
	NcTextField: {
		props: ['modelValue', 'label', 'disabled'],
		emits: ['update:modelValue'],
		template: '<input class="text" :disabled="disabled" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
	},
}

/**
 * Mount a component and let its created hook settle.
 *
 * @param {object} component The component.
 * @return {Promise<object>} The wrapper.
 */
async function mounted(component) {
	const wrapper = mount(component, { global: { stubs } })
	await flush()
	return wrapper
}

beforeEach(() => {
	setActivePinia(createPinia())
	vi.clearAllMocks()
})

describe('RecoveryKitPanel', () => {
	it('says there is no kit, and when the current one was made', async () => {
		service.recoveryKitStatus.mockResolvedValueOnce(null)
		expect((await mounted(RecoveryKitPanel)).get('[data-testid="recovery-kit-status"]').text()).toBe('You have no recovery kit.')

		service.recoveryKitStatus.mockResolvedValueOnce({ suiteId: 's-1', createdAt: '2026-11-01T10:00:00+00:00' })
		const wrapper = await mounted(RecoveryKitPanel)
		expect(wrapper.get('[data-testid="recovery-kit-status"]').text()).toBe('Your recovery kit was made on 2026-11-01.')
		expect(wrapper.get('[data-testid="recovery-kit-create"]').text()).toBe('Make a new recovery kit')
	})

	it('makes a kit with the typed master password and shows the code once, with download and print', async () => {
		service.recoveryKitStatus.mockResolvedValue(null)
		service.createRecoveryKit.mockResolvedValue({ code: 'ABCD-EFGH', kit: { suiteId: 's-1', createdAt: '2026-11-14T10:00:00+00:00' } })
		useSessionStore().encryptedPrivateKey = 'ENVELOPE'
		const wrapper = await mounted(RecoveryKitPanel)

		await wrapper.get('[data-testid="recovery-kit-create"]').trigger('click')
		await wrapper.get('[data-testid="recovery-kit-password"]').setValue('master password')
		await wrapper.get('[data-testid="recovery-kit-create"]').trigger('click')
		await flush()

		expect(service.createRecoveryKit).toHaveBeenCalledWith('ENVELOPE', 'master password')
		expect(wrapper.get('[data-testid="recovery-kit-code"]').text()).toBe('ABCD-EFGH')
		expect(wrapper.find('[data-testid="recovery-kit-download"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="recovery-kit-print"]').exists()).toBe(true)
		expect(wrapper.get('[data-testid="recovery-kit-status"]').text()).toBe('Your recovery kit was made on 2026-11-14.')

		await wrapper.get('[data-testid="recovery-kit-done"]').trigger('click')
		expect(wrapper.find('[data-testid="recovery-kit-code"]').exists()).toBe(false)
	})

	it('reports a failure and shows no code', async () => {
		service.recoveryKitStatus.mockResolvedValue(null)
		service.createRecoveryKit.mockRejectedValue(new Error('OperationError'))
		const wrapper = await mounted(RecoveryKitPanel)

		await wrapper.get('[data-testid="recovery-kit-create"]').trigger('click')
		await wrapper.get('[data-testid="recovery-kit-password"]').setValue('wrong')
		await wrapper.get('[data-testid="recovery-kit-create"]').trigger('click')
		await flush()

		expect(wrapper.find('[data-testid="recovery-kit-code"]').exists()).toBe(false)
		expect(wrapper.get('[role="alert"]').text()).toBe('The recovery kit could not be made. Check your master password.')
	})
})

describe('RecoveryKitUnlock', () => {
	it('offers nothing to a user without a kit', async () => {
		service.recoveryKitStatus.mockResolvedValue(null)

		expect((await mounted(RecoveryKitUnlock)).find('[data-testid="recovery-kit-unlock"]').exists()).toBe(false)
	})

	it('recovers, unlocks with the new password and offers a new kit before continuing', async () => {
		service.recoveryKitStatus.mockResolvedValue({ suiteId: 's-1', createdAt: '2026-11-01T10:00:00+00:00' })
		service.recoverWithCode.mockResolvedValue(undefined)
		const session = useSessionStore()
		session.unlock = vi.fn().mockResolvedValue(undefined)
		const wrapper = await mounted(RecoveryKitUnlock)

		await wrapper.get('[data-testid="recovery-kit-unlock-start"]').trigger('click')
		const submit = () => wrapper.get('[data-testid="recovery-kit-unlock-submit"]')
		await wrapper.get('[data-testid="recovery-kit-unlock-code"]').setValue('0123-4567-89ab-cdef-ghjk-mnpq-rstv-wxyz')
		await wrapper.get('[data-testid="recovery-kit-unlock-password"]').setValue('a new master password')
		await wrapper.get('[data-testid="recovery-kit-unlock-repeat"]').setValue('a new master passwor')
		expect(submit().attributes('disabled')).toBeDefined()
		await wrapper.get('[data-testid="recovery-kit-unlock-repeat"]').setValue('a new master password')
		await submit().trigger('click')
		await flush()

		expect(service.recoverWithCode).toHaveBeenCalledWith('0123-4567-89ab-cdef-ghjk-mnpq-rstv-wxyz', 'a new master password')
		expect(session.unlock).toHaveBeenCalledWith('a new master password')
		expect(wrapper.find('[data-testid="recovery-kit"]').exists()).toBe(true)
		expect(wrapper.emitted('recovered')).toBeUndefined()

		await wrapper.get('[data-testid="recovery-kit-unlock-continue"]').trigger('click')
		expect(wrapper.emitted('recovered')).toHaveLength(1)
	})

	it('opens nothing on a wrong code, and says so', async () => {
		service.recoveryKitStatus.mockResolvedValue({ suiteId: 's-1', createdAt: null })
		service.recoverWithCode.mockRejectedValue(new Error('The recovery code does not open this vault'))
		const session = useSessionStore()
		session.unlock = vi.fn()
		const wrapper = await mounted(RecoveryKitUnlock)

		await wrapper.get('[data-testid="recovery-kit-unlock-start"]').trigger('click')
		await wrapper.get('[data-testid="recovery-kit-unlock-code"]').setValue('0123-4567-89ab-cdef-ghjk-mnpq-rstv-wxyz')
		await wrapper.get('[data-testid="recovery-kit-unlock-password"]').setValue('a new master password')
		await wrapper.get('[data-testid="recovery-kit-unlock-repeat"]').setValue('a new master password')
		await wrapper.get('[data-testid="recovery-kit-unlock-submit"]').trigger('click')
		await flush()

		expect(session.unlock).not.toHaveBeenCalled()
		expect(wrapper.get('[role="alert"]').text()).toBe('This recovery code does not open your vault.')
		expect(wrapper.find('[data-testid="recovery-kit-unlock-continue"]').exists()).toBe(false)
	})
})
