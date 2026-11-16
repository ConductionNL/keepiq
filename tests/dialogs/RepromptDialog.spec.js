/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `src/dialogs/RepromptDialog.vue` and the shared
 * `src/dialogs/MasterPasswordPromptDialog.vue` it renders.
 *
 * The re-prompt for flagged items: it renders only while the re-prompt store
 * asks, names the item, submits the typed password through the store, shows
 * the store's error, and cancels through the store on dismissal.
 *
 * @spec openspec/changes/crypto-item-reprompt/tasks.md#task-2.1
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import RepromptDialog from '../../src/dialogs/RepromptDialog.vue'
import { useRepromptPromptStore } from '../../src/store/modules/repromptPrompt.js'

const stubs = {
	NcDialog: {
		name: 'NcDialog',
		props: ['name', 'open', 'size'],
		emits: ['update:open'],
		template:
			'<div v-bind="$attrs" :data-open="String(open)"><h1 data-testid="title">{{ name }}</h1><slot /><slot name="actions" /></div>',
	},
	NcNoteCard: {
		props: ['type'],
		template: '<div :class="`note note--${type}`" v-bind="$attrs"><slot /></div>',
	},
	NcButton: {
		props: ['variant', 'disabled'],
		emits: ['click'],
		template: '<button v-bind="$attrs" :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
	NcPasswordField: {
		props: ['modelValue', 'label', 'disabled'],
		emits: ['update:modelValue'],
		template:
			'<input v-bind="$attrs" :aria-label="label" :disabled="disabled" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)">',
	},
}

/**
 * A translation mock that fills the `{name}` placeholders, so the rendered
 * text shows which values the component passes.
 *
 * @param {string} _app The app id
 * @param {string} key The source string
 * @param {object} vars The placeholder values
 * @return {string}
 */
const t = (_app, key, vars = {}) => key.replace(/\{(\w+)\}/g, (match, name) => (name in vars ? String(vars[name]) : match))

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

/**
 * Mount the dialog with the Nextcloud components stubbed.
 *
 * @return {import('@vue/test-utils').VueWrapper}
 */
function mountDialog() {
	return mount(RepromptDialog, { global: { stubs, mixins: [{ methods: { t } }] } })
}

/**
 * Find an element by its data-testid.
 *
 * @param {import('@vue/test-utils').VueWrapper} wrapper The mounted dialog
 * @param {string} id The data-testid
 * @return {import('@vue/test-utils').DOMWrapper}
 */
function byTestId(wrapper, id) {
	return wrapper.find(`[data-testid="${id}"]`)
}

describe('RepromptDialog', () => {
	let prompt

	beforeEach(() => {
		setActivePinia(createPinia())
		prompt = useRepromptPromptStore()
		vi.spyOn(prompt, 'submit').mockResolvedValue(true)
		vi.spyOn(prompt, 'cancel').mockImplementation(() => {})
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('renders nothing until the store asks', () => {
		const wrapper = mountDialog()

		expect(byTestId(wrapper, 'reprompt-dialog').exists()).toBe(false)
		expect(byTestId(wrapper, 'reprompt-dialog-password').exists()).toBe(false)
	})

	it('names the item, labels the field and shows the on-device hint', async () => {
		prompt.$patch({ open: true, itemName: 'Production database' })
		const wrapper = mountDialog()
		await wrapper.vm.$nextTick()

		expect(byTestId(wrapper, 'title').text()).toBe('Enter your master password')
		expect(wrapper.find('form').classes()).toEqual(['master-password-prompt', 'reprompt-dialog'])
		expect(wrapper.find('form p').text()).toBe(
			'"Production database" asks for your master password before it is shown, copied or filled.',
		)
		expect(wrapper.find('.reprompt-dialog__hint').text()).toContain('checked on this device and never sent')
		expect(byTestId(wrapper, 'reprompt-dialog-password').attributes('aria-label')).toBe('Your master password')
		expect(byTestId(wrapper, 'reprompt-dialog-error').exists()).toBe(false)
	})

	it('keeps confirm disabled until a password is typed', async () => {
		prompt.$patch({ open: true, itemName: 'Mail' })
		const wrapper = mountDialog()
		await wrapper.vm.$nextTick()

		expect(byTestId(wrapper, 'reprompt-dialog-confirm').attributes('disabled')).toBeDefined()

		await byTestId(wrapper, 'reprompt-dialog-password').setValue('secret')

		expect(byTestId(wrapper, 'reprompt-dialog-confirm').attributes('disabled')).toBeUndefined()
		expect(byTestId(wrapper, 'reprompt-dialog-cancel').attributes('disabled')).toBeUndefined()
	})

	it('submits the typed password through the store and clears it when accepted', async () => {
		prompt.$patch({ open: true, itemName: 'Mail' })
		const wrapper = mountDialog()
		await wrapper.vm.$nextTick()
		await byTestId(wrapper, 'reprompt-dialog-password').setValue('secret')

		await byTestId(wrapper, 'reprompt-dialog-confirm').trigger('click')
		await flush()

		expect(prompt.submit).toHaveBeenCalledWith('secret')
		expect(byTestId(wrapper, 'reprompt-dialog-password').element.value).toBe('')
	})

	it('shows the store error and keeps the password when it is refused', async () => {
		prompt.submit.mockResolvedValue(false)
		prompt.$patch({ open: true, itemName: 'Mail' })
		const wrapper = mountDialog()
		await wrapper.vm.$nextTick()
		await byTestId(wrapper, 'reprompt-dialog-password').setValue('wrong')

		await wrapper.find('form').trigger('submit')
		await flush()
		prompt.error = 'That master password is not right.'
		await wrapper.vm.$nextTick()

		expect(prompt.submit).toHaveBeenCalledWith('wrong')
		expect(byTestId(wrapper, 'reprompt-dialog-password').element.value).toBe('wrong')
		expect(byTestId(wrapper, 'reprompt-dialog-error').text()).toBe('That master password is not right.')
	})

	it('disables the field and both buttons while the store checks', async () => {
		prompt.$patch({ open: true, itemName: 'Mail', checking: true })
		const wrapper = mountDialog()
		await wrapper.vm.$nextTick()

		expect(byTestId(wrapper, 'reprompt-dialog-password').attributes('disabled')).toBeDefined()
		expect(byTestId(wrapper, 'reprompt-dialog-cancel').attributes('disabled')).toBeDefined()
		expect(byTestId(wrapper, 'reprompt-dialog-confirm').attributes('disabled')).toBeDefined()
	})

	it('cancels through the store from the cancel button', async () => {
		prompt.$patch({ open: true, itemName: 'Mail' })
		const wrapper = mountDialog()
		await wrapper.vm.$nextTick()
		await byTestId(wrapper, 'reprompt-dialog-password').setValue('secret')

		await byTestId(wrapper, 'reprompt-dialog-cancel').trigger('click')

		expect(prompt.cancel).toHaveBeenCalledTimes(1)
		expect(prompt.submit).not.toHaveBeenCalled()
		expect(byTestId(wrapper, 'reprompt-dialog-password').element.value).toBe('')
	})

	it('cancels through the store when the dialog closes itself', async () => {
		prompt.$patch({ open: true, itemName: 'Mail' })
		const wrapper = mountDialog()
		await wrapper.vm.$nextTick()

		wrapper.findComponent({ name: 'NcDialog' }).vm.$emit('update:open', false)

		expect(prompt.cancel).toHaveBeenCalledTimes(1)
	})
})
