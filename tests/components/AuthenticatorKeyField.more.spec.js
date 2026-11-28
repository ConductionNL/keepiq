/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * AuthenticatorKeyField edges: the QR button opens the hidden file picker, a
 * change without a file reads nothing, a cleared entry emits '', a mode set
 * by the parent leaves focus alone, a Remove-only field returns focus to
 * Remove when the removal is undone, and a QR error clears once typing
 * starts.
 *
 * @spec openspec/specs/login-one-time-codes/spec.md#requirement-a-login-can-carry-its-own-totp-seed
 */

import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import AuthenticatorKeyField from '../../src/components/AuthenticatorKeyField.vue'
import { decodeQrImage, QrImageError } from '../../src/totp/qrImage.js'

vi.mock('../../src/totp/qrImage.js', async (importOriginal) => ({
	...(await importOriginal()),
	decodeQrImage: vi.fn(),
}))

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

async function settle(wrapper) {
	await wrapper.vm.$nextTick()
	await wrapper.vm.$nextTick()
}

beforeEach(() => {
	vi.mocked(decodeQrImage).mockReset()
})

describe('the QR image reader', () => {
	it('opens the hidden file picker from the QR button', async () => {
		const wrapper = mount(AuthenticatorKeyField, { global: { stubs } })
		const click = vi.spyOn(
			find(wrapper, 'authenticator-key-file').element,
			'click',
		)

		await find(wrapper, 'authenticator-key-qr').trigger('click')

		expect(click).toHaveBeenCalledTimes(1)
	})

	it('reads nothing when the picker closes without a file', async () => {
		const wrapper = mount(AuthenticatorKeyField, { global: { stubs } })
		const input = find(wrapper, 'authenticator-key-file')
		Object.defineProperty(input.element, 'files', { value: [] })

		await input.trigger('change')
		await settle(wrapper)

		expect(decodeQrImage).not.toHaveBeenCalled()
		expect(wrapper.emitted('update:value')).toBeUndefined()
		expect(wrapper.vm.reading).toBe(false)
	})

	it('clears a QR error as soon as a key is typed', async () => {
		vi.mocked(decodeQrImage).mockRejectedValue(
			new QrImageError('No QR code was found in this image.'),
		)
		const wrapper = mount(AuthenticatorKeyField, { global: { stubs } })
		const file = find(wrapper, 'authenticator-key-file')
		Object.defineProperty(file.element, 'files', {
			value: [{ type: 'image/png' }],
		})
		await file.trigger('change')
		await new Promise((resolve) => setTimeout(resolve, 0))
		expect(find(wrapper, 'authenticator-key-error').text()).toBe(
			'No QR code was found in this image.',
		)

		await find(wrapper, 'authenticator-key-input').setValue('')

		expect(find(wrapper, 'authenticator-key-error').exists()).toBe(false)
		expect(wrapper.emitted('update:value').at(-1)).toEqual([''])
	})
})

describe('the entry', () => {
	it("emits '' when the field clears its value to null", () => {
		const wrapper = mount(AuthenticatorKeyField, { global: { stubs } })

		wrapper
			.findComponent(stubs.NcPasswordField)
			.vm.$emit('update:modelValue', null)

		expect(wrapper.emitted('update:value')).toEqual([['']])
	})
})

describe('focus', () => {
	it('stays where it is when the parent changes the mode', async () => {
		const wrapper = mount(AuthenticatorKeyField, {
			props: { saved: true, mode: 'keep' },
			global: { stubs },
			attachTo: document.body,
		})
		const outside = document.createElement('button')
		document.body.appendChild(outside)
		outside.focus()

		await wrapper.setProps({ mode: 'remove' })
		await settle(wrapper)

		expect(document.activeElement).toBe(outside)
		outside.remove()
		wrapper.unmount()
	})

	it('falls back to Remove when there is no Replace to return to', async () => {
		const wrapper = mount(AuthenticatorKeyField, {
			props: {
				saved: true,
				mode: 'keep',
				removeOnly: true,
				'onUpdate:mode': (mode) => wrapper.setProps({ mode }),
			},
			global: { stubs },
			attachTo: document.body,
		})

		await find(wrapper, 'authenticator-key-remove').trigger('click')
		await settle(wrapper)
		expect(document.activeElement).toBe(
			find(wrapper, 'authenticator-key-undo').element,
		)

		await find(wrapper, 'authenticator-key-undo').trigger('click')
		await settle(wrapper)
		expect(find(wrapper, 'authenticator-key-replace').exists()).toBe(false)
		expect(document.activeElement).toBe(
			find(wrapper, 'authenticator-key-remove').element,
		)
		expect(wrapper.emitted('update:mode')).toEqual([['remove'], ['keep']])
		expect(wrapper.emitted('update:value')).toEqual([[''], ['']])
		wrapper.unmount()
	})
})
