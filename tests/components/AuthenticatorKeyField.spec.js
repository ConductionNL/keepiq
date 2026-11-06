/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The authenticator key field of a login: an entry with an inline error and
 * a QR image reader, or, for a saved key, a state with Replace and Remove
 * that never shows the seed.
 *
 * @spec openspec/changes/vault-login-totp-codes/specs/login-one-time-codes/spec.md#requirement-a-login-can-carry-its-own-totp-seed
 */

import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import AuthenticatorKeyField from '../../src/components/AuthenticatorKeyField.vue'
import { decodeQrImage, QrImageError } from '../../src/totp/qrImage.js'

vi.mock('../../src/totp/qrImage.js', async (importOriginal) => ({
	...(await importOriginal()),
	decodeQrImage: vi.fn(),
}))

const LINK = 'otpauth://totp/Bank:alice?secret=JBSWY3DPEHPK3PXP'

const stubs = {
	NcPasswordField: {
		props: ['modelValue', 'label', 'error', 'disabled'],
		emits: ['update:modelValue'],
		template:
			'<input :value="modelValue" :data-error="String(error)" @input="$emit(\'update:modelValue\', $event.target.value)" />',
	},
	NcButton: {
		props: ['disabled'],
		emits: ['click'],
		template:
			'<button :disabled="disabled" @click="$emit(\'click\')"><slot name="icon" /><slot /></button>',
	},
	NcLoadingIcon: true,
}

const find = (wrapper, id) => wrapper.find(`[data-testid="${id}"]`)

beforeEach(() => {
	vi.mocked(decodeQrImage).mockReset()
})

describe('an entry', () => {
	it('shows an inline error for a key it cannot use', async () => {
		const wrapper = mount(AuthenticatorKeyField, {
			props: { value: 'not a key!' },
			global: { stubs },
		})
		expect(find(wrapper, 'authenticator-key-error').exists()).toBe(true)
		expect(
			find(wrapper, 'authenticator-key-input').attributes('data-error'),
		).toBe('true')

		await wrapper.setProps({ value: LINK })
		expect(find(wrapper, 'authenticator-key-error').exists()).toBe(false)
	})

	it('emits what is typed', async () => {
		const wrapper = mount(AuthenticatorKeyField, { global: { stubs } })
		await find(wrapper, 'authenticator-key-input').setValue('JBSWY3DPEHPK3PXP')
		expect(wrapper.emitted('update:value').at(-1)).toEqual(['JBSWY3DPEHPK3PXP'])
	})

	it('puts the link a QR image holds in the field', async () => {
		vi.mocked(decodeQrImage).mockResolvedValue(LINK)
		const wrapper = mount(AuthenticatorKeyField, { global: { stubs } })
		const input = find(wrapper, 'authenticator-key-file')
		Object.defineProperty(input.element, 'files', {
			value: [{ type: 'image/png' }],
		})
		await input.trigger('change')
		await new Promise((resolve) => setTimeout(resolve, 0))

		expect(wrapper.emitted('update:value').at(-1)).toEqual([LINK])
		expect(find(wrapper, 'authenticator-key-error').exists()).toBe(false)
	})

	it('shows why a QR image could not be used', async () => {
		vi.mocked(decodeQrImage).mockRejectedValue(
			new QrImageError('No QR code was found in this image.'),
		)
		const wrapper = mount(AuthenticatorKeyField, { global: { stubs } })
		const input = find(wrapper, 'authenticator-key-file')
		Object.defineProperty(input.element, 'files', {
			value: [{ type: 'image/png' }],
		})
		await input.trigger('change')
		await new Promise((resolve) => setTimeout(resolve, 0))

		expect(find(wrapper, 'authenticator-key-error').text()).toBe(
			'No QR code was found in this image.',
		)
		expect(wrapper.emitted('update:value')).toBeUndefined()
	})

	it('never shows an error that was not worded for the user', async () => {
		vi.mocked(decodeQrImage).mockRejectedValue(
			new Error('Loading chunk qr-decoder failed.'),
		)
		const wrapper = mount(AuthenticatorKeyField, { global: { stubs } })
		const input = find(wrapper, 'authenticator-key-file')
		Object.defineProperty(input.element, 'files', {
			value: [{ type: 'image/png' }],
		})
		await input.trigger('change')
		await new Promise((resolve) => setTimeout(resolve, 0))

		expect(find(wrapper, 'authenticator-key-error').text()).toBe(
			'This image could not be read.',
		)
	})

	it('ties the error to the input for assistive technology', async () => {
		const wrapper = mount(AuthenticatorKeyField, {
			props: { value: 'not a key!' },
			global: { stubs },
		})
		const input = find(wrapper, 'authenticator-key-input')
		const error = find(wrapper, 'authenticator-key-error')
		expect(input.attributes('aria-invalid')).toBe('true')
		expect(error.attributes('id')).toBeTruthy()
		expect(input.attributes('aria-describedby')).toBe(error.attributes('id'))

		await wrapper.setProps({ value: '' })
		expect(input.attributes('aria-invalid')).toBeUndefined()
		expect(input.attributes('aria-describedby')).toBeUndefined()
	})
})

describe('focus after a saved-key choice', () => {
	/**
	 * Mount attached to the document and follow the field's own mode events,
	 * as a parent's v-model does.
	 *
	 * @return {object} The wrapper.
	 */
	function mountSaved() {
		const wrapper = mount(AuthenticatorKeyField, {
			props: {
				saved: true,
				mode: 'keep',
				'onUpdate:mode': (mode) => wrapper.setProps({ mode }),
			},
			global: { stubs },
			attachTo: document.body,
		})
		return wrapper
	}

	const settle = async (wrapper) => {
		await wrapper.vm.$nextTick()
		await wrapper.vm.$nextTick()
	}

	it('moves to the new entry on Replace and back to Replace on cancel', async () => {
		const wrapper = mountSaved()
		await find(wrapper, 'authenticator-key-replace').trigger('click')
		await settle(wrapper)
		expect(document.activeElement).toBe(
			find(wrapper, 'authenticator-key-input').element,
		)

		await find(wrapper, 'authenticator-key-cancel').trigger('click')
		await settle(wrapper)
		expect(document.activeElement).toBe(
			find(wrapper, 'authenticator-key-replace').element,
		)
		wrapper.unmount()
	})

	it('moves to the undo button on Remove', async () => {
		const wrapper = mountSaved()
		await find(wrapper, 'authenticator-key-remove').trigger('click')
		await settle(wrapper)
		expect(document.activeElement).toBe(
			find(wrapper, 'authenticator-key-undo').element,
		)
		wrapper.unmount()
	})
})

describe('a saved key', () => {
	it('offers only Remove where no key may be entered', () => {
		const wrapper = mount(AuthenticatorKeyField, {
			props: { saved: true, mode: 'keep', removeOnly: true },
			global: { stubs },
		})
		expect(find(wrapper, 'authenticator-key-replace').exists()).toBe(false)
		expect(find(wrapper, 'authenticator-key-remove').exists()).toBe(true)
	})

	it('says a key is saved, offers Replace and Remove, and has no entry', () => {
		const wrapper = mount(AuthenticatorKeyField, {
			props: { saved: true, mode: 'keep' },
			global: { stubs },
		})
		expect(find(wrapper, 'authenticator-key-state').text()).toBe(
			'Authenticator key saved',
		)
		expect(find(wrapper, 'authenticator-key-replace').exists()).toBe(true)
		expect(find(wrapper, 'authenticator-key-remove').exists()).toBe(true)
		expect(find(wrapper, 'authenticator-key-input').exists()).toBe(false)
	})

	it('switches to an empty entry on Replace and marks removal on Remove', async () => {
		const wrapper = mount(AuthenticatorKeyField, {
			props: { saved: true, mode: 'keep' },
			global: { stubs },
		})
		await find(wrapper, 'authenticator-key-remove').trigger('click')
		expect(wrapper.emitted('update:mode').at(-1)).toEqual(['remove'])

		await find(wrapper, 'authenticator-key-replace').trigger('click')
		expect(wrapper.emitted('update:mode').at(-1)).toEqual(['replace'])

		await wrapper.setProps({ mode: 'replace' })
		expect(find(wrapper, 'authenticator-key-input').element.value).toBe('')
		await find(wrapper, 'authenticator-key-cancel').trigger('click')
		expect(wrapper.emitted('update:mode').at(-1)).toEqual(['keep'])
	})

	it('offers to keep the key once Remove is chosen', async () => {
		const wrapper = mount(AuthenticatorKeyField, {
			props: { saved: true, mode: 'remove' },
			global: { stubs },
		})
		expect(find(wrapper, 'authenticator-key-state').text()).toBe(
			'The authenticator key is removed when you save.',
		)
		await find(wrapper, 'authenticator-key-undo').trigger('click')
		expect(wrapper.emitted('update:mode').at(-1)).toEqual(['keep'])
	})
})
