/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The template wiring of `src/modals/NewSendDialog.vue`: the type picker's
 * choice reaching the created send, and the one-time link input selecting
 * its whole text on focus so it can be copied by hand. The ephemeral-send
 * store is the mocked boundary; the Nextcloud components are stubbed.
 *
 * @spec openspec/specs/ephemeral-send/spec.md#requirement-create-a-standalone-ephemeral-send
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import NewSendDialog from '../../src/modals/NewSendDialog.vue'
import { useEphemeralSendStore } from '../../src/store/modules/ephemeralSend.js'

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

const stubs = {
	NcDialog: {
		props: ['open', 'name'],
		emits: ['update:open'],
		template: '<div v-if="open"><slot /><slot name="actions" /></div>',
	},
	NcNoteCard: { template: '<div class="note"><slot /></div>' },
	NcSelect: {
		name: 'NcSelect',
		props: ['modelValue', 'options'],
		emits: ['update:modelValue'],
		template: '<div class="nc-select" />',
	},
	NcButton: {
		props: ['disabled'],
		emits: ['click'],
		template: '<button v-bind="$attrs" :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
}

describe('NewSendDialog wiring', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useEphemeralSendStore()
		store.createSend = vi.fn().mockResolvedValue('https://cloud.example/s/abc#key')
	})

	function mountDialog() {
		return mount(NewSendDialog, { props: { open: true }, global: { stubs } })
	}

	it('sends the type picked in the type select', async () => {
		const wrapper = mountDialog()
		const select = wrapper.findComponent({ name: 'NcSelect' })
		expect(select.props('options')).toEqual(['text', 'credential'])

		select.vm.$emit('update:modelValue', 'credential')
		await wrapper.find('[data-testid="send-payload"]').setValue('user:pass')
		await wrapper.find('[data-testid="send-create"]').trigger('click')
		await flush()

		expect(store.createSend).toHaveBeenCalledTimes(1)
		expect(store.createSend.mock.calls[0][0]).toMatchObject({
			payload: 'user:pass',
			payloadType: 'credential',
		})
	})

	it('selects the whole link when its input is focused', async () => {
		const wrapper = mountDialog()
		await wrapper.find('[data-testid="send-payload"]').setValue('hello')
		await wrapper.find('[data-testid="send-create"]').trigger('click')
		await flush()

		const input = wrapper.find('[data-testid="send-link"]')
		expect(input.element.value).toBe('https://cloud.example/s/abc#key')
		const select = vi.spyOn(input.element, 'select')

		await input.trigger('focus')

		expect(select).toHaveBeenCalledTimes(1)
	})
})
