/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The write-a-secret-for-an-application dialog's controls: every field
 * through its own control, closing resets the form, the organisation
 * password policy refuses a weak value before anything is encrypted, and
 * the failure text and the additional fields are read as the dialog does.
 *
 * @spec openspec/changes/implement-application-mgmt/tasks.md#task-10.6
 */

import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import WriteSecretForAppDialog from '../../src/dialogs/WriteSecretForAppDialog.vue'
import { resetPolicyCache } from '../../src/policy/policy.js'
import { useApplicationStore } from '../../src/store/modules/application.js'

function field (cls, type = 'text') {
  return {
	props: ['modelValue', 'label', 'required', 'rows'],
	emits: ['update:modelValue'],
	template: `<input class="${cls}" type="${type}" :aria-label="label" :value="modelValue" @input="$emit('update:modelValue', $event.target.value)" />`,
}
}

const STUBS = {
	NcDialog: {
		props: ['name', 'open', 'size'],
		emits: ['update:open'],
		template: '<div class="dialog" :data-name="name"><button class="dialog-close" @click="$emit(\'update:open\', false)" /><button class="dialog-keep" @click="$emit(\'update:open\', true)" /><slot /><slot name="actions" /></div>',
	},
	NcButton: {
		props: ['disabled', 'variant'],
		emits: ['click'],
		template: '<button :class="variant" :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
	NcNoteCard: { props: ['type'], template: '<div class="note" :data-type="type"><slot /></div>' },
	NcTextField: field('text'),
	NcPasswordField: field('password', 'password'),
	NcTextArea: field('area'),
}

/**
 * Mount the dialog for an application.
 *
 * @param {object} props Extra props.
 * @return {object} The wrapper.
 */
function mountDialog(props = {}) {
	return mount(WriteSecretForAppDialog, {
		props: { open: true, applicationId: 'app-1', applicationName: 'Billing', ...props },
		global: { stubs: STUBS },
	})
}

describe('WriteSecretForAppDialog controls', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		resetPolicyCache()
		store = useApplicationStore()
		store.writeSecretForApplication = vi.fn().mockResolvedValue({ id: 'sec-1' })
	})

	afterEach(() => {
		vi.restoreAllMocks()
		resetPolicyCache()
	})

	it('fills every field through its control and sends what was typed', async () => {
		vi.spyOn(axios, 'get').mockRejectedValue(new Error('no policy'))
		const wrapper = mountDialog()

		await wrapper.find('input[aria-label="Name"]').setValue('Ledger API')
		await wrapper.find('input[aria-label="URL (optional)"]').setValue('https://ledger.example')
		await wrapper.find('input[aria-label="Login (optional)"]').setValue('svc-ledger')
		await wrapper.find('input[aria-label="Secret value"]').setValue('tr0ub4dor&3-horse')
		await wrapper.find('input[aria-label="Additional fields (optional JSON)"]').setValue('{"region":"eu"}')
		await wrapper.find('[data-testid="write-secret-submit"]').trigger('click')
		await vi.waitFor(() => expect(wrapper.vm.success).toBe(true))

		expect(store.writeSecretForApplication).toHaveBeenCalledWith('app-1', {
			name: 'Ledger API',
			url: 'https://ledger.example',
			login: 'svc-ledger',
			key: 'tr0ub4dor&3-horse',
			additionalFields: { region: 'eu' },
		})
		expect(wrapper.find('[data-testid="write-secret-success"]').exists()).toBe(true)
	})

	it('names the dialog after the application, or plainly without one', () => {
		expect(mountDialog().find('.dialog').attributes('data-name')).toBe('Write secret for {app}')
		expect(mountDialog({ applicationName: '' }).find('.dialog').attributes('data-name')).toBe('Write secret')
	})

	it('resets the form and closes from the dialog and from Close, but not on an open update', async () => {
		const wrapper = mountDialog()
		await wrapper.setData({ name: 'X', url: 'u', login: 'l', value: 'v', additionalFields: 'a', error: 'e', success: true })

		await wrapper.find('.dialog-keep').trigger('click')
		expect(wrapper.emitted('close')).toBeUndefined()
		expect(wrapper.vm.name).toBe('X')

		await wrapper.find('.dialog-close').trigger('click')
		expect(wrapper.vm.$data).toMatchObject({ name: '', url: '', login: '', value: '', additionalFields: '', error: '', success: false })

		await wrapper.find('button.secondary').trigger('click')
		expect(wrapper.emitted('close')).toEqual([[], []])
	})

	it('refuses a value below the organisation strength floor before writing', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: { policy_enabled: true, min_zxcvbn_score: 4 } })
		const wrapper = mountDialog()
		await wrapper.setData({ name: 'Ledger API', value: 'password' })

		await wrapper.vm.submit()
		await wrapper.vm.$nextTick()

		expect(store.writeSecretForApplication).not.toHaveBeenCalled()
		expect(wrapper.find('.note[data-type="error"]').text()).toBe('Value strength {score} is below the org minimum of {floor}')
		expect(wrapper.vm.busy).toBe(false)
		expect(wrapper.vm.value).toBe('password')
	})

	it('does nothing when it cannot submit or is already busy', async () => {
		const wrapper = mountDialog()

		await wrapper.vm.submit()
		await wrapper.setData({ name: 'Ledger API', value: 'v', busy: true })
		await wrapper.vm.submit()

		expect(store.writeSecretForApplication).not.toHaveBeenCalled()
		expect(wrapper.find('[data-testid="write-secret-submit"]').text()).toBe('Encrypting…')
	})

	it('words a failed write from the server, the error, or its own text', () => {
		const wrapper = mountDialog()

		expect(wrapper.vm.writeFailureText({ response: { data: { message: 'Key revoked' } } })).toBe('Key revoked')
		expect(wrapper.vm.writeFailureText(new Error('offline'))).toBe('offline')
		expect(wrapper.vm.writeFailureText({})).toBe('Write failed')
	})

	it('sends additional fields as JSON when they parse, as text when not, and none when blank', async () => {
		const wrapper = mountDialog()

		await wrapper.setData({ additionalFields: ' {"a":1} ' })
		expect(wrapper.vm.parseAdditional()).toEqual({ a: 1 })
		await wrapper.setData({ additionalFields: 'region=eu' })
		expect(wrapper.vm.parseAdditional()).toBe('region=eu')
		await wrapper.setData({ additionalFields: '   ' })
		expect(wrapper.vm.parseAdditional()).toBeNull()
		expect(wrapper.vm.plainPayload().additionalFields).toBeNull()
		await wrapper.setData({ additionalFields: '' })
		expect(wrapper.vm.plainPayload()).toMatchObject({ url: null, login: null, additionalFields: null })
	})
})
