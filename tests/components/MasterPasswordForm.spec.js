/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The routine master-password change form: submit stays disabled until the
 * current password, a new password that meets the strength floor and a
 * matching confirmation are all in; a mismatch is called out; submitting hands
 * the current and new password to the encryption-suite store, shows the
 * in-flight state, and on success clears every field and says so. A failure
 * shows the error's message, else a generic one. `NewPasswordFields` is
 * stubbed to its v-models and its strength event.
 *
 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-7
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import MasterPasswordForm from '../../src/components/MasterPasswordForm.vue'
import { useEncryptionSuiteStore } from '../../src/store/modules/encryptionSuite.js'

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

const NewPasswordFieldsStub = {
	name: 'NewPasswordFields',
	props: ['newPassword', 'confirmPassword', 'disabled'],
	emits: ['update:newPassword', 'update:confirmPassword', 'strengthChange'],
	template:
		'<div class="new-fields" :data-disabled="disabled"><input class="new" :value="newPassword" @input="$emit(\'update:newPassword\', $event.target.value)" /><input class="confirm" :value="confirmPassword" @input="$emit(\'update:confirmPassword\', $event.target.value)" /></div>',
}

const stubs = {
	NcButton: {
		props: ['disabled', 'variant'],
		emits: ['click'],
		template:
			'<button class="submit" :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
	NcNoteCard: {
		props: ['type'],
		template: '<div class="note-card" :data-type="type"><slot /></div>',
	},
	NcPasswordField: {
		props: ['modelValue', 'label', 'disabled'],
		emits: ['update:modelValue'],
		template:
			'<input class="current" :value="modelValue" :disabled="disabled" @input="$emit(\'update:modelValue\', $event.target.value)" />',
	},
	NewPasswordFields: NewPasswordFieldsStub,
}

/**
 * Mount the form.
 *
 * @return {object}
 */
function mountForm() {
	return mount(MasterPasswordForm, { global: { stubs } })
}

/**
 * Fill the form.
 *
 * @param {object} wrapper The form.
 * @param {object} values The values.
 * @param {string} values.current Current password.
 * @param {string} values.next New password.
 * @param {string} values.confirm Confirmation.
 * @param {boolean} values.strong Whether the strength meter accepts it.
 * @return {Promise<void>}
 */
async function fill(wrapper, { current = 'old-pw', next = 'N3w-long-pw!', confirm = next, strong = true } = {}) {
	await wrapper.find('.current').setValue(current)
	await wrapper.find('.new').setValue(next)
	await wrapper.find('.confirm').setValue(confirm)
	wrapper
		.findComponent(NewPasswordFieldsStub)
		.vm.$emit('strengthChange', { isValid: strong })
	await wrapper.vm.$nextTick()
}

/**
 * The error/success note cards' texts.
 *
 * @param {object} wrapper The form.
 * @param {string} type The note type.
 * @return {Array<string>}
 */
function notes(wrapper, type) {
	return wrapper
		.findAll(`.note-card[data-type="${type}"]`)
		.map((n) => n.text())
}

describe('MasterPasswordForm', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useEncryptionSuiteStore()
		store.changePassword = vi.fn().mockResolvedValue()
	})

	it('keeps submit disabled until the form is complete', async () => {
		const wrapper = mountForm()

		expect(wrapper.find('.submit').attributes('disabled')).toBeDefined()
		expect(wrapper.find('.submit').text()).toBe('Change password')

		await fill(wrapper)
		expect(wrapper.find('.submit').attributes('disabled')).toBeUndefined()
	})

	it('keeps submit disabled without the current password', async () => {
		const wrapper = mountForm()
		await fill(wrapper, { current: '' })

		expect(wrapper.find('.submit').attributes('disabled')).toBeDefined()
	})

	it('keeps submit disabled when the new password is too weak', async () => {
		const wrapper = mountForm()
		await fill(wrapper, { strong: false })

		expect(wrapper.find('.submit').attributes('disabled')).toBeDefined()
		expect(notes(wrapper, 'error')).toEqual([])
	})

	it('calls out a confirmation that does not match and blocks submit', async () => {
		const wrapper = mountForm()
		await fill(wrapper, { next: 'N3w-long-pw!', confirm: 'N3w-long-pw?' })

		expect(notes(wrapper, 'error')).toEqual(['Passwords do not match'])
		expect(wrapper.find('.submit').attributes('disabled')).toBeDefined()
	})

	it('changes the password, says so and clears every field', async () => {
		const wrapper = mountForm()
		await fill(wrapper, { current: 'old-pw', next: 'N3w-long-pw!' })

		await wrapper.find('.submit').trigger('click')
		await flush()

		expect(store.changePassword).toHaveBeenCalledTimes(1)
		expect(store.changePassword).toHaveBeenCalledWith('old-pw', 'N3w-long-pw!')
		expect(notes(wrapper, 'success')).toEqual([
			'Master password changed successfully',
		])
		expect(wrapper.find('.current').element.value).toBe('')
		expect(wrapper.find('.new').element.value).toBe('')
		expect(wrapper.find('.confirm').element.value).toBe('')
		expect(wrapper.find('.submit').attributes('disabled')).toBeDefined()
	})

	it('disables the form and says it is changing while the request runs', async () => {
		let release
		store.changePassword = vi.fn(
			() => new Promise((resolve) => {
				release = resolve
			}),
		)
		const wrapper = mountForm()
		await fill(wrapper)

		await wrapper.find('.submit').trigger('click')

		expect(wrapper.find('.submit').text()).toBe('Changing…')
		expect(wrapper.find('.submit').attributes('disabled')).toBeDefined()
		expect(wrapper.find('.current').attributes('disabled')).toBeDefined()
		expect(wrapper.find('.new-fields').attributes('data-disabled')).toBe('true')

		release()
		await flush()
		expect(wrapper.find('.submit').text()).toBe('Change password')
		expect(wrapper.find('.current').attributes('disabled')).toBeUndefined()
	})

	it("shows the error's message and keeps the typed values on failure", async () => {
		store.changePassword = vi
			.fn()
			.mockRejectedValue(new Error('Current password is wrong'))
		const wrapper = mountForm()
		await fill(wrapper, { current: 'bad-pw' })

		await wrapper.find('.submit').trigger('click')
		await flush()

		expect(notes(wrapper, 'error')).toEqual(['Current password is wrong'])
		expect(notes(wrapper, 'success')).toEqual([])
		expect(wrapper.find('.current').element.value).toBe('bad-pw')
		expect(wrapper.find('.submit').attributes('disabled')).toBeUndefined()
	})

	it('falls back to a generic message and clears an earlier success', async () => {
		store.changePassword = vi
			.fn()
			.mockResolvedValueOnce()
			.mockRejectedValueOnce(new Error(''))
		const wrapper = mountForm()
		await fill(wrapper)
		await wrapper.find('.submit').trigger('click')
		await flush()
		expect(notes(wrapper, 'success')).toHaveLength(1)

		await fill(wrapper)
		await wrapper.find('.submit').trigger('click')
		await flush()

		expect(notes(wrapper, 'error')).toEqual(['Failed to change password'])
		expect(notes(wrapper, 'success')).toEqual([])
	})
})
