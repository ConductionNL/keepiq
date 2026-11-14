/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The compromise recovery form's progress wording, its retry without a
 * retained password, failed retries and finishes, and the re-ask for the
 * previous password when finishing needs a proof over the old key.
 *
 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-a-migration-always-has-a-way-to-terminate
 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-compromise-recovery-states-that-regained-access-is-not-an-all-clear
 */

import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import CompromiseRecoveryForm from '../../src/components/CompromiseRecoveryForm.vue'
import { useEncryptionSuiteStore } from '../../src/store/modules/encryptionSuite.js'

/**
 * Translate with the placeholders filled in.
 *
 * @param {string} app The app id.
 * @param {string} text The source string.
 * @param {object} vars The placeholder values.
 * @return {string} The interpolated text.
 */
function interpolate(app, text, vars) {
	return Object.keys(vars ?? {}).reduce((out, key) => out.replace(`{${key}}`, String(vars[key])), text)
}

/**
 * @return {object} The mounted form.
 */
function mountForm() {
	return mount(CompromiseRecoveryForm, {
		global: {
			mixins: [{ methods: { t: interpolate } }],
			stubs: {
				NcButton: { template: '<button><slot /></button>' },
				NcNoteCard: { template: '<div class="note-card"><slot /></div>' },
				NcPasswordField: {
					name: 'NcPasswordField',
					props: ['modelValue', 'label', 'disabled'],
					emits: ['update:modelValue'],
					template: '<input class="password" />',
				},
				NcProgressBar: { template: '<div class="progress-bar" />' },
				NewPasswordFields: true,
				PasswordStrengthMeter: true,
				NcCheckboxRadioSwitch: true,
			},
		},
	})
}

describe('CompromiseRecoveryForm paths', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useEncryptionSuiteStore()
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('words the progress without ever reporting an outcome', () => {
		const wrapper = mountForm()
		expect(wrapper.vm.progressLabel).toBe('')
		expect(wrapper.vm.progressPercent).toBe(0)

		store.migrationProgress = { phase: 'starting', done: 0, total: 0 }
		expect(wrapper.vm.progressLabel).toBe('Preparing…')
		expect(wrapper.vm.progressPercent).toBe(0)

		store.migrationProgress = { phase: 'running', done: 3, total: 8 }
		expect(wrapper.vm.progressLabel).toBe('3 of 8 records re-encrypted')
		expect(wrapper.vm.progressPercent).toBe(38)

		store.migrationProgress = { phase: 'running', done: 12, total: 10 }
		expect(wrapper.vm.progressLabel).toBe('12 of 12 records re-encrypted')
		expect(wrapper.vm.progressPercent).toBe(100)
	})

	it('takes the old password from its field', async () => {
		const wrapper = mountForm()
		await wrapper.findComponent({ name: 'NcPasswordField' }).vm.$emit('update:modelValue', 'old-secret')

		expect(wrapper.vm.oldPassword).toBe('old-secret')
	})

	it('asks for the old password again when a retry has none retained', async () => {
		const resume = vi.spyOn(store, 'resumeMigration')
		const wrapper = mountForm()
		wrapper.vm.phase = 'running'

		await wrapper.vm.handleRetry()

		expect(resume).not.toHaveBeenCalled()
		expect(wrapper.vm.error).toBe('Re-enter your old password to retry the remaining secrets.')
		expect(wrapper.vm.phase).toBe('idle')
	})

	it('shows why a retry failed and stays where it was', async () => {
		const resume = vi.spyOn(store, 'resumeMigration').mockRejectedValue({ response: { data: { message: 'Vault is locked' } } })
		const wrapper = mountForm()
		wrapper.vm.activeOldPassword = 'old-secret'
		wrapper.vm.phase = 'running'

		await wrapper.vm.handleRetry()

		expect(resume).toHaveBeenCalledWith('old-secret')
		expect(wrapper.vm.error).toBe('Vault is locked')
		expect(wrapper.vm.phase).toBe('running')
		expect(wrapper.vm.loading).toBe(false)
	})

	it.each([
		[{ code: 'key_proof_required', message: 'Proof needed' }],
		[{ response: { data: { error: 'key_proof_required', message: 'Proof needed' } } }],
	])('re-asks for the previous password when finishing needs a proof (%j)', async (refusal) => {
		vi.spyOn(store, 'acceptMigrationLosses').mockRejectedValue(refusal)
		const wrapper = mountForm()
		wrapper.vm.phase = 'running'

		await wrapper.vm.handleAcceptLosses()
		await flushPromises()

		expect(wrapper.vm.needsReauth).toBe(true)
		expect(wrapper.vm.error).toBe('Proof needed')
		expect(wrapper.vm.phase).toBe('running')
		expect(wrapper.vm.loading).toBe(false)
	})

	it('does not re-ask for the password on any other refusal, and falls back to a generic message', async () => {
		vi.spyOn(store, 'acceptMigrationLosses').mockRejectedValue({})
		const wrapper = mountForm()

		await wrapper.vm.handleAcceptLosses()

		expect(wrapper.vm.needsReauth).toBe(false)
		expect(wrapper.vm.error).toBe('Failed to start recovery')
	})

	it('finishes with the re-entered password when none was retained', async () => {
		store.migrationStatus = { id: 'mig-1' }
		const accept = vi.spyOn(store, 'acceptMigrationLosses').mockResolvedValue({ residualContacts: [] })
		const wrapper = mountForm()
		wrapper.vm.needsReauth = true
		wrapper.vm.oldPassword = 'typed-again'

		await wrapper.vm.handleAcceptLosses()

		expect(accept).toHaveBeenCalledWith('mig-1', 'typed-again')
		expect(wrapper.vm.needsReauth).toBe(false)
		expect(wrapper.vm.phase).toBe('terminal')
	})

	it('adds and removes a contact to carry without duplicating it', () => {
		const wrapper = mountForm()
		wrapper.vm.toggleCarry('rel-1', true)
		wrapper.vm.toggleCarry('rel-2', true)
		wrapper.vm.toggleCarry('rel-1', true)
		expect(wrapper.vm.carryContactIds).toEqual(['rel-2', 'rel-1'])

		wrapper.vm.toggleCarry('rel-2', false)
		expect(wrapper.vm.carryContactIds).toEqual(['rel-1'])
	})
})
