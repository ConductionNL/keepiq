/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component tests for the key generator's controls: the password/passphrase
 * radios, the passphrase and password inputs reaching the generated key, the
 * regex disabling the basic options, and copying the key to the clipboard.
 *
 * Runs under jsdom with @nextcloud/vue stubs that emit their v-model updates.
 *
 * @spec openspec/specs/key-generator/spec.md
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import KeyGeneratorModal from '../../src/dialogs/KeyGeneratorModal.vue'
import { resetPolicyCache } from '../../src/policy/policy.js'

const ncStubs = {
	NcDialog: {
		props: ['name', 'open', 'size'],
		template: '<div><slot /><slot name="actions" /></div>',
	},
	NcButton: {
		props: ['variant', 'disabled'],
		template: '<button :disabled="disabled"><slot name="icon" /><slot /></button>',
	},
	NcCheckboxRadioSwitch: {
		name: 'NcCheckboxRadioSwitch',
		props: ['modelValue', 'type', 'value', 'disabled'],
		emits: ['update:modelValue'],
		template: '<label class="switch"><slot /></label>',
	},
	NcInputField: {
		name: 'NcInputField',
		props: ['modelValue', 'label', 'min', 'max', 'type', 'helperText', 'readOnly', 'showTrailingButton', 'trailingButtonLabel'],
		emits: ['update:modelValue', 'trailingButtonClick'],
		template: '<div class="input" :data-label="label">{{ modelValue }}</div>',
	},
	NcLoadingIcon: { template: '<span />' },
	NcNoteCard: { props: ['type'], template: '<div class="note"><slot /></div>' },
	ContentCopy: { template: '<i />' },
	Dice5: { template: '<i />' },
}

/**
 * Mount the open modal and let its policy load settle.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mounted() {
	const wrapper = mount(KeyGeneratorModal, { props: { open: true }, global: { stubs: ncStubs } })
	await flushPromises()
	return wrapper
}

/**
 * Emit a v-model update from the input with the given label.
 *
 * @param {object} wrapper The wrapper.
 * @param {string} label The input label.
 * @param {*} value The new value.
 * @return {Promise<void>}
 */
async function typeInto(wrapper, label, value) {
	const input = wrapper.findAllComponents({ name: 'NcInputField' }).find((c) => c.props('label') === label)
	input.vm.$emit('update:modelValue', value)
	await wrapper.vm.$nextTick()
}

/**
 * Emit a v-model update from the switch whose text is given.
 *
 * @param {object} wrapper The wrapper.
 * @param {string} text The switch text.
 * @param {*} value The new value.
 * @return {Promise<void>}
 */
async function flip(wrapper, text, value) {
	const control = wrapper.findAllComponents({ name: 'NcCheckboxRadioSwitch' }).find((c) => c.text() === text)
	control.vm.$emit('update:modelValue', value)
	await wrapper.vm.$nextTick()
}

describe('KeyGeneratorModal controls', () => {
	beforeEach(() => {
		resetPolicyCache()
		vi.spyOn(axios, 'get').mockResolvedValue({ data: null })
	})

	afterEach(() => {
		vi.restoreAllMocks()
		vi.unstubAllGlobals()
	})

	it('switches to passphrase mode and generates from the passphrase options', async () => {
		const wrapper = await mounted()
		expect(wrapper.find('[data-testid="passphrase-options"]').exists()).toBe(false)

		await flip(wrapper, 'Passphrase', 'passphrase')
		expect(wrapper.find('[data-testid="passphrase-options"]').exists()).toBe(true)
		expect(wrapper.findAllComponents({ name: 'NcInputField' }).map((c) => c.props('label'))).toEqual(['Number of words', 'Separator'])

		await typeInto(wrapper, 'Number of words', 6)
		await typeInto(wrapper, 'Separator', '_')
		await flip(wrapper, 'Capitalise each word', false)
		await flip(wrapper, 'Include a number', false)
		await wrapper.vm.generate()

		const words = wrapper.vm.generatedKey.split('_')
		expect(words).toHaveLength(6)
		expect(words.every((w) => /^[a-z]+$/.test(w))).toBe(true)

		await flip(wrapper, 'Password', 'password')
		expect(wrapper.find('[data-testid="passphrase-options"]').exists()).toBe(false)
	})

	it('passes the password inputs to the generated key', async () => {
		const wrapper = await mounted()

		await typeInto(wrapper, 'Length', 20)
		await flip(wrapper, 'Include special characters', false)
		await typeInto(wrapper, 'Exclude characters', 'abcdefghijklmnopqrstuvwxyz')
		await wrapper.vm.generate()

		expect(wrapper.vm.generatedKey).toMatch(/^[A-Z0-9]{20}$/)
	})

	it('disables the basic options while a regex is set', async () => {
		const wrapper = await mounted()
		const basic = () => wrapper.find('fieldset.key-generator-modal__basic')
		expect(basic().attributes('disabled')).toBeUndefined()

		await typeInto(wrapper, 'Regex pattern', '^[0-9]{10}$')

		expect(basic().attributes('disabled')).toBeDefined()
		await wrapper.vm.generate()
		expect(wrapper.vm.generatedKey).toMatch(/^[0-9]{10}$/)
	})

	it('copies the generated key from the preview, and does nothing without a clipboard', async () => {
		const writeText = vi.fn().mockResolvedValue(undefined)
		vi.stubGlobal('navigator', { clipboard: { writeText } })
		const wrapper = await mounted()

		await wrapper.vm.copyToClipboard()
		expect(writeText).not.toHaveBeenCalled()

		await wrapper.vm.generate()
		await wrapper.vm.$nextTick()
		const preview = wrapper.findAllComponents({ name: 'NcInputField' }).find((c) => c.props('label') === 'Generated key')
		expect(preview.props('modelValue')).toBe(wrapper.vm.generatedKey)
		preview.vm.$emit('trailingButtonClick')
		await flushPromises()
		expect(writeText).toHaveBeenCalledWith(wrapper.vm.generatedKey)

		vi.stubGlobal('navigator', {})
		await expect(wrapper.vm.copyToClipboard()).resolves.toBeUndefined()
		expect(writeText).toHaveBeenCalledTimes(1)
	})
})
