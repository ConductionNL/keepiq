/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `src/dialogs/KeyProofPromptDialog.vue`.
 *
 * The app-wide master password prompt. It renders only while the store asks,
 * submits through the store, keeps the typed value on a refusal and drops it
 * on acceptance, cancel, or when the prompt closes.
 *
 * @spec openspec/specs/user-sharing/spec.md#requirement-sharing-with-a-new-party-requires-a-verified-key-proof
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import KeyProofPromptDialog from '../../src/dialogs/KeyProofPromptDialog.vue'
import { useKeyProofPromptStore } from '../../src/store/modules/keyProofPrompt.js'

const stubs = {
	NcDialog: {
		name: 'NcDialog',
		props: ['name', 'open'],
		emits: ['update:open'],
		template:
			'<div :data-open="String(open)"><h1 data-testid="title">{{ name }}</h1><slot /><slot name="actions" /></div>',
	},
	NcNoteCard: {
		props: ['type'],
		template: '<div :class="`note note--${type}`" v-bind="$attrs"><slot /></div>',
	},
	NcButton: {
		emits: ['click'],
		template: '<button v-bind="$attrs" @click="$emit(\'click\')"><slot /></button>',
	},
	NcPasswordField: {
		props: ['modelValue'],
		emits: ['update:modelValue'],
		template:
			'<input v-bind="$attrs" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)">',
	},
	NcSelect: {
		name: 'NcSelect',
		props: ['modelValue', 'options'],
		emits: ['update:modelValue'],
		template: '<select v-bind="$attrs"></select>',
	},
}

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

function mountDialog() {
	return mount(KeyProofPromptDialog, { global: { stubs } })
}

function confirmButton (wrapper) {
  return wrapper.find('[data-testid="key-proof-prompt-confirm"]')
}

describe('KeyProofPromptDialog', () => {
	let prompt

	beforeEach(() => {
		setActivePinia(createPinia())
		prompt = useKeyProofPromptStore()
		vi.spyOn(prompt, 'submit').mockResolvedValue(true)
		vi.spyOn(prompt, 'cancel')
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('renders nothing until the store asks', () => {
		const wrapper = mountDialog()

		expect(wrapper.find('[data-testid="key-proof-prompt-password"]').exists()).toBe(
			false,
		)
	})

	it('shows the reason and enables confirm once a password is typed', async () => {
		prompt.$patch({ open: true, reason: 'Share with Bob' })
		const wrapper = mountDialog()
		await wrapper.vm.$nextTick()

		expect(wrapper.text()).toContain('Share with Bob')
		expect(confirmButton(wrapper).attributes('disabled')).toBeDefined()

		await wrapper
			.find('[data-testid="key-proof-prompt-password"]')
			.setValue('secret')
		expect(confirmButton(wrapper).attributes('disabled')).toBeUndefined()
	})

	it('submits the password and clears it when accepted', async () => {
		prompt.$patch({ open: true })
		const wrapper = mountDialog()
		wrapper.vm.password = 'secret'

		await wrapper.vm.onSubmit()

		expect(prompt.submit).toHaveBeenCalledWith('secret')
		expect(wrapper.vm.password).toBe('')
	})

	it('keeps the password and shows the store error when refused', async () => {
		prompt.submit.mockResolvedValue(false)
		prompt.$patch({ open: true, error: 'That master password is not right.' })
		const wrapper = mountDialog()
		wrapper.vm.password = 'wrong'

		await wrapper.vm.onSubmit()
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.password).toBe('wrong')
		expect(wrapper.find('[data-testid="key-proof-prompt-error"]').text()).toBe(
			'That master password is not right.',
		)
	})

	it('does not submit an empty password or while checking', async () => {
		prompt.$patch({ open: true })
		const wrapper = mountDialog()

		await wrapper.vm.onSubmit()
		wrapper.vm.password = 'secret'
		prompt.checking = true
		await wrapper.vm.onSubmit()

		expect(prompt.submit).not.toHaveBeenCalled()
	})

	it('submits on form submit', async () => {
		prompt.$patch({ open: true })
		const wrapper = mountDialog()
		await wrapper.vm.$nextTick()
		await wrapper
			.find('[data-testid="key-proof-prompt-password"]')
			.setValue('secret')

		await wrapper.find('form').trigger('submit')
		await flush()

		expect(prompt.submit).toHaveBeenCalledWith('secret')
	})

	it('cancels through the store and clears the password on dismissal', async () => {
		prompt.$patch({ open: true })
		const wrapper = mountDialog()
		wrapper.vm.password = 'secret'

		wrapper.findComponent({ name: 'NcDialog' }).vm.$emit('update:open', false)

		expect(prompt.cancel).toHaveBeenCalledTimes(1)
		expect(wrapper.vm.password).toBe('')
	})

	it('ignores the dialog reporting itself open', async () => {
		prompt.$patch({ open: true })
		const wrapper = mountDialog()

		wrapper.vm.onOpenChange(true)

		expect(prompt.cancel).not.toHaveBeenCalled()
	})

	it('drops the password when the store closes the prompt', async () => {
		prompt.$patch({ open: true })
		const wrapper = mountDialog()
		wrapper.vm.password = 'secret'

		prompt.open = false
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.password).toBe('')
	})
})
