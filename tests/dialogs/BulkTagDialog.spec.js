/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `src/dialogs/BulkTagDialog.vue`.
 *
 * Adds or removes one normalised tag across the bulk selection: the buttons
 * stay disabled until a tag is chosen, the store is called with the selected
 * ids and the add/remove flag, and a failure keeps the dialog open with an
 * error instead of emitting done.
 *
 * @spec openspec/changes/vault-favourites-tags-and-last-used/specs/vault-list-organisation/spec.md#requirement-tags-per-holder
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import BulkTagDialog from '../../src/dialogs/BulkTagDialog.vue'
import { useBulkStore } from '../../src/store/modules/bulk.js'
import { useSecretStore } from '../../src/store/modules/secret.js'

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
	return mount(BulkTagDialog, { props: { open: true }, global: { stubs } })
}

const button = (wrapper, id) => wrapper.find(`[data-testid="bulk-tag-${id}"]`)

describe('BulkTagDialog', () => {
	let secrets

	beforeEach(() => {
		setActivePinia(createPinia())
		useBulkStore().setSelection(['a', 'b'])
		secrets = useSecretStore()
		secrets.tags = [{ tag: 'work' }, { tag: 'home' }]
		vi.spyOn(secrets, 'changeTagInBulk').mockResolvedValue(undefined)
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('offers the known tags and counts the selection', () => {
		const wrapper = mountDialog()

		expect(wrapper.findComponent({ name: 'NcSelect' }).props('options')).toEqual(
			['work', 'home'],
		)
		expect(wrapper.vm.count).toBe(2)
	})

	it('disables add and remove until a tag is chosen', async () => {
		const wrapper = mountDialog()
		expect(button(wrapper, 'add').attributes('disabled')).toBeDefined()
		expect(button(wrapper, 'remove').attributes('disabled')).toBeDefined()

		wrapper
			.findComponent({ name: 'NcSelect' })
			.vm.$emit('update:modelValue', 'Work')
		await wrapper.vm.$nextTick()

		expect(button(wrapper, 'add').attributes('disabled')).toBeUndefined()
		expect(button(wrapper, 'remove').attributes('disabled')).toBeUndefined()
	})

	it('treats a blank tag as no tag', async () => {
		const wrapper = mountDialog()
		wrapper.vm.tag = '   '
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.wanted).toBe('')
		expect(button(wrapper, 'add').attributes('disabled')).toBeDefined()
	})

	it('adds the normalised tag to the selected ids, then emits done and close', async () => {
		const wrapper = mountDialog()
		wrapper.vm.tag = '  Work '
		await wrapper.vm.$nextTick()

		await button(wrapper, 'add').trigger('click')
		await flush()

		expect(secrets.changeTagInBulk).toHaveBeenCalledWith(['a', 'b'], 'work', true)
		expect(wrapper.emitted('done')).toHaveLength(1)
		expect(wrapper.emitted('close')).toHaveLength(1)
		expect(wrapper.vm.running).toBe(false)
	})

	it('removes with the add flag off', async () => {
		const wrapper = mountDialog()
		wrapper.vm.tag = 'home'
		await wrapper.vm.$nextTick()

		await button(wrapper, 'remove').trigger('click')
		await flush()

		expect(secrets.changeTagInBulk).toHaveBeenCalledWith(['a', 'b'], 'home', false)
	})

	it('shows an error and stays open when the change fails', async () => {
		secrets.changeTagInBulk.mockRejectedValue(new Error('500'))
		const wrapper = mountDialog()
		wrapper.vm.tag = 'home'

		await wrapper.vm.run(true)
		await wrapper.vm.$nextTick()

		expect(wrapper.find('[data-testid="bulk-tag-error"]').text()).toBe(
			'Could not change the tags. Try again.',
		)
		expect(wrapper.emitted('done')).toBeUndefined()
		expect(wrapper.emitted('close')).toBeUndefined()
		expect(wrapper.vm.running).toBe(false)
	})

	it('emits close from cancel without touching the store', async () => {
		const wrapper = mountDialog()

		await button(wrapper, 'cancel').trigger('click')

		expect(wrapper.emitted('close')).toHaveLength(1)
		expect(secrets.changeTagInBulk).not.toHaveBeenCalled()
	})
})
