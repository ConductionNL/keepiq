/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `src/modals/NewSendDialog.vue`: the form hands exactly
 * what was entered to the ephemeral-send store (hours as seconds), shows the
 * one-time link in place of the form, forgets the plaintext once the link is
 * minted, and reports a refusal.
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
		template: '<div v-if="open" data-testid="dialog"><slot /><slot name="actions" /></div>',
	},
	NcNoteCard: { template: '<div v-bind="$attrs" class="note"><slot /></div>' },
	NcSelect: {
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

function mountDialog() {
	return mount(NewSendDialog, { props: { open: true }, global: { stubs } })
}

describe('NewSendDialog', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		store = useEphemeralSendStore()
		store.createSend = vi.fn().mockResolvedValue('https://cloud.example/s/abc#key')
	})

	it('keeps Create disabled until there is content', async () => {
		const wrapper = mountDialog()
		const create = wrapper.find('[data-testid="send-create"]')

		expect(create.attributes('disabled')).toBeDefined()
		await wrapper.find('[data-testid="send-payload"]').setValue('hello')
		expect(create.attributes('disabled')).toBeUndefined()
	})

	it('creates the send with the entered values, the time limit in seconds', async () => {
		const wrapper = mountDialog()
		await wrapper.find('[data-testid="send-payload"]').setValue('s3cret')
		await wrapper.find('[data-testid="send-max-views"]').setValue('3')
		await wrapper.find('[data-testid="send-ttl"]').setValue('2')
		await wrapper.find('[data-testid="send-password"]').setValue('pw')

		await wrapper.find('[data-testid="send-create"]').trigger('click')
		await flush()

		expect(store.createSend).toHaveBeenCalledWith({
			payload: 's3cret',
			payloadType: 'text',
			maxViews: 3,
			ttlSeconds: 7200,
			password: 'pw',
		})
	})

	it('sends no time limit when the hours are empty', async () => {
		const wrapper = mountDialog()
		wrapper.vm.payload = 'x'
		wrapper.vm.ttlHours = ''

		await wrapper.vm.onCreate()

		expect(store.createSend).toHaveBeenCalledWith(expect.objectContaining({ ttlSeconds: 0 }))
	})

	it('shows the link once, hides the form and forgets the plaintext', async () => {
		const wrapper = mountDialog()
		wrapper.vm.payload = 'top secret'
		wrapper.vm.password = 'pw'

		await wrapper.vm.onCreate()
		await flush()

		expect(wrapper.find('[data-testid="send-link"]').element.value).toBe('https://cloud.example/s/abc#key')
		expect(wrapper.find('[data-testid="send-link-once"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="send-payload"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="send-create"]').exists()).toBe(false)
		expect(wrapper.vm.payload).toBe('')
		expect(wrapper.vm.password).toBe('')
		expect(wrapper.vm.busy).toBe(false)
	})

	it.each([
		[{ response: { data: { message: 'Too many views' } } }, 'Too many views'],
		[new Error('Network down'), 'Network down'],
		[{}, 'Failed to create send'],
	])('reports a refusal and keeps the form', async (failure, message) => {
		store.createSend = vi.fn().mockRejectedValue(failure)
		const wrapper = mountDialog()
		wrapper.vm.payload = 'keep me'

		await wrapper.vm.onCreate()
		await flush()

		expect(wrapper.vm.error).toBe(message)
		expect(wrapper.find('.note').text()).toBe(message)
		expect(wrapper.vm.payload).toBe('keep me')
		expect(wrapper.vm.link).toBe('')
		expect(wrapper.vm.busy).toBe(false)
	})

	it('copies the link to the clipboard and tolerates a refused clipboard', async () => {
		const writeText = vi.fn().mockResolvedValue()
		Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true })
		const wrapper = mountDialog()
		await wrapper.vm.onCreate()
		await flush()

		await wrapper.find('[data-testid="send-copy-link"]').trigger('click')
		expect(writeText).toHaveBeenCalledWith('https://cloud.example/s/abc#key')

		writeText.mockRejectedValueOnce(new Error('denied'))
		await expect(wrapper.vm.copyLink()).resolves.toBeUndefined()
	})

	it('emits close from the Close button and from the dialog', async () => {
		const wrapper = mountDialog()

		await wrapper.findAll('button')[0].trigger('click')
		wrapper.findComponent(stubs.NcDialog).vm.$emit('update:open', false)

		expect(wrapper.emitted('close')).toHaveLength(2)
	})
})
