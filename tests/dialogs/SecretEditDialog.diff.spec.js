/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The edit dialog's save diff for the fields the other specs leave out: the
 * reprompt switch, the URL (a cleared one is sent as null), the additional
 * URLs, the holder's tags (stored apart from the value; a tags-only save
 * sends no update and hands back the opened secret), the onSaved
 * callback, and the error a failed load shows.
 *
 * @spec openspec/specs/secrets-write-ui/spec.md#requirement-edit-a-secret-from-the-ui
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import SecretEditDialog from '../../src/dialogs/SecretEditDialog.vue'
import { useSecretStore } from '../../src/store/modules/secret.js'
import { useSecretTypeStore } from '../../src/store/modules/secretType.js'
import { useSessionStore } from '../../src/store/modules/session.js'

const stubs = {
	NcDialog: {
		props: ['name', 'open', 'size'],
		template: '<div><slot /><slot name="actions" /></div>',
	},
	NcButton: {
		props: ['disabled', 'variant', 'ariaLabel', 'title'],
		template:
			'<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
	NcSelect: {
		props: ['options', 'reduce', 'inputLabel', 'clearable', 'modelValue'],
		template: '<div />',
	},
	NcTextField: {
		name: 'NcTextField',
		props: ['modelValue', 'label', 'placeholder', 'disabled', 'required'],
		emits: ['update:modelValue'],
		template: '<input :value="modelValue" :disabled="disabled" />',
	},
	NcPasswordField: {
		props: ['modelValue', 'label'],
		template: '<input type="password" :value="modelValue" />',
	},
	NcNoteCard: { props: ['type'], template: '<div class="note"><slot /></div>' },
	NcLoadingIcon: { template: '<span />' },
	Plus: { template: '<i />' },
	Dice5: { template: '<i />' },
	KeyGeneratorModal: { props: ['open'], template: '<div />' },
}

const SECRET = {
	id: 's1',
	name: 'Bank',
	typeId: 'login',
	key: 'value',
	url: 'https://bank.test',
	additionalUrls: ['https://a.test'],
	login: 'alice',
	reprompt: false,
	tags: ['work'],
}

/**
 * Mount the dialog over one secret and let mounted() finish loading.
 *
 * @param {object} secret The secret fetchSecret answers.
 * @param {object} props Extra props.
 * @return {Promise<object>} The wrapper.
 */
async function mountOver(secret, props = {}) {
	useSecretStore().fetchSecret = vi.fn().mockResolvedValue(secret)

	const wrapper = mount(SecretEditDialog, {
		props: { secretId: secret.id, ...props },
		global: { stubs },
	})
	await wrapper.vm.$nextTick()
	await new Promise((resolve) => setTimeout(resolve, 0))
	await wrapper.vm.$nextTick()

	return wrapper
}

describe('SecretEditDialog — save diff of the remaining fields', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()

		const typeStore = useSecretTypeStore()
		typeStore.types = [{ id: 'login', name: 'login', label: 'Login' }]
		typeStore.fetchTypes = vi.fn().mockResolvedValue()

		const session = useSessionStore()
		session.cryptoKey = 'UNLOCKED'
		session.certificate = 'PEM'
	})

	it('sends the reprompt switch when it was turned on', async () => {
		const wrapper = await mountOver({ ...SECRET })
		const update = vi
			.spyOn(useSecretStore(), 'updateSecret')
			.mockResolvedValue({ id: 's1' })

		wrapper.vm.reprompt = true
		await wrapper.vm.submit()

		expect(update.mock.calls[0][1]).toEqual({ reprompt: true })
	})

	it('sends a cleared URL as null and a changed URL as typed', async () => {
		const wrapper = await mountOver({ ...SECRET })
		const update = vi
			.spyOn(useSecretStore(), 'updateSecret')
			.mockResolvedValue({ id: 's1' })

		wrapper.vm.url = ''
		await wrapper.vm.submit()
		expect(update.mock.calls[0][1]).toEqual({ url: null })

		const again = await mountOver({ ...SECRET })
		again.vm.url = 'https://new.test'
		await again.vm.submit()
		expect(update.mock.calls[1][1]).toEqual({ url: 'https://new.test' })
	})

	it('sends the additional URLs when they changed', async () => {
		const wrapper = await mountOver({ ...SECRET })
		const update = vi
			.spyOn(useSecretStore(), 'updateSecret')
			.mockResolvedValue({ id: 's1' })

		wrapper.vm.additionalUrls = ['https://a.test', 'https://b.test']
		await wrapper.vm.submit()

		expect(update.mock.calls[0][1]).toEqual({
			additionalUrls: ['https://a.test', 'https://b.test'],
		})
	})

	it('stores changed tags apart from the value and calls onSaved', async () => {
		const onSaved = vi.fn()
		const wrapper = await mountOver({ ...SECRET }, { onSaved })
		const update = vi
			.spyOn(useSecretStore(), 'updateSecret')
			.mockResolvedValue({ id: 's1' })
		const setTags = vi.spyOn(useSecretStore(), 'setTags').mockResolvedValue()

		wrapper.vm.tags = ['work', 'bank']
		await wrapper.vm.submit()

		expect(update).not.toHaveBeenCalled()
		expect(setTags).toHaveBeenCalledWith('s1', ['work', 'bank'])
		expect(onSaved).toHaveBeenCalledWith(
			expect.objectContaining({ id: 's1', name: 'Bank' }),
		)
		expect(wrapper.emitted('saved')).toHaveLength(1)
	})

	it('does not store tags that did not change', async () => {
		const wrapper = await mountOver({ ...SECRET })
		const setTags = vi.spyOn(useSecretStore(), 'setTags').mockResolvedValue()

		await wrapper.vm.submit()

		expect(setTags).not.toHaveBeenCalled()
	})

	it('binds the URL and login fields to the form', async () => {
		const wrapper = await mountOver({ ...SECRET })
		const fields = wrapper.findAllComponents({ name: 'NcTextField' })
		const byLabel = (label) => fields.find((f) => f.props('label') === label)

		byLabel('URL (optional)').vm.$emit('update:modelValue', 'https://typed.test')
		byLabel('Login (optional)').vm.$emit('update:modelValue', 'bob')

		expect(wrapper.vm.url).toBe('https://typed.test')
		expect(wrapper.vm.login).toBe('bob')
	})

	it('shows the server message when loading fails, else the error message', async () => {
		useSecretStore().fetchSecret = vi
			.fn()
			.mockRejectedValue({ response: { data: { message: 'Not yours' } } })
		const wrapper = mount(SecretEditDialog, {
			props: { secretId: 's9' },
			global: { stubs },
		})
		await new Promise((resolve) => setTimeout(resolve, 0))
		expect(wrapper.vm.error).toBe('Not yours')
		expect(wrapper.vm.loading).toBe(false)

		useSecretStore().fetchSecret = vi
			.fn()
			.mockRejectedValue(new Error('offline'))
		const other = mount(SecretEditDialog, {
			props: { secretId: 's9' },
			global: { stubs },
		})
		await new Promise((resolve) => setTimeout(resolve, 0))
		expect(other.vm.error).toBe('offline')
	})
})
