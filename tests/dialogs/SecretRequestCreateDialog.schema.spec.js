/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The request dialog sends the schema of each requested custom field: the
 * preset picked for a name typed in the dialog, the one the secret stores for
 * a field it already has, and none for plain text.
 *
 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-a-request-carries-the-schemas-of-its-fields
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import SecretRequestCreateDialog from '../../src/dialogs/SecretRequestCreateDialog.vue'
import { useSecretRequestStore } from '../../src/store/modules/secretRequest.js'

const ncStubs = {
	NcDialog: {
		props: ['name', 'open', 'size'],
		template:
			'<div class="nc-dialog-stub"><slot /><slot name="actions" /></div>',
	},
}

describe('SecretRequestCreateDialog field schemas', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('sends the schemas of the requested custom fields', async () => {
		const store = useSecretRequestStore()
		store.createRequest = vi.fn().mockResolvedValue({ id: 'r', token: 't' })

		const wrapper = mount(SecretRequestCreateDialog, {
			propsData: {
				open: true,
				secret: {
					id: 'secret-1',
					additionalFields: {
						Account: '{}',
						Notes: 'x',
						'@schema': { Account: { type: 'object', hidden: true } },
					},
				},
			},
			global: { stubs: ncStubs },
		})

		// The stored members are offered; @schema is not a field.
		const keys = wrapper.vm.availableFields.map((f) => f.key)
		expect(keys).toContain('Account')
		expect(keys).not.toContain('@schema')

		wrapper.vm.customFieldInput = 'PIN'
		wrapper.vm.onCustomPreset('password')
		wrapper.vm.addCustomField()
		wrapper.vm.requestedFields = ['key', 'Account', 'Notes', 'PIN']
		await wrapper.vm.submit()

		expect(store.createRequest.mock.calls[0][0].fieldSchemas).toEqual({
			Account: { type: 'object', hidden: true },
			PIN: { type: 'string', hidden: true },
		})
	})

	it('refines a new field under Advanced and lists it as Custom', async () => {
		const store = useSecretRequestStore()
		store.createRequest = vi.fn().mockResolvedValue({ id: 'r', token: 't' })

		const wrapper = mount(SecretRequestCreateDialog, {
			propsData: { open: true, secret: { id: 'secret-1' } },
			global: { stubs: ncStubs },
		})

		expect(
			wrapper.find('[data-testid="secret-request-custom-schema"]').exists(),
		).toBe(false)
		await wrapper
			.findComponent('[data-testid="secret-request-custom-advanced"]')
			.trigger('click')
		const advanced = wrapper.findComponent({ name: 'SchemaAdvanced' })
		expect(advanced.exists()).toBe(true)

		wrapper.vm.onCustomPreset('password')
		await advanced.vm.$emit('update:schema', {
			type: 'string',
			hidden: true,
			pattern: '^[0-9]{4,6}$',
		})
		expect(
			wrapper.vm.presetSelectOptions.map((option) => option.value),
		).toContain('custom')

		wrapper.vm.customFieldInput = 'PIN'
		wrapper.vm.addCustomField()
		await wrapper.vm.$nextTick()
		expect(wrapper.find('[data-testid="secret-request-kind-PIN"]').text()).toBe(
			'Custom',
		)
		// The next field starts over as plain text with Advanced closed.
		expect(wrapper.vm.customFieldSchema).toEqual({ type: 'string' })
		expect(wrapper.vm.customAdvancedOpen).toBe(false)

		wrapper.vm.requestedFields = ['key', 'PIN']
		await wrapper.vm.submit()
		expect(store.createRequest.mock.calls[0][0].fieldSchemas).toEqual({
			PIN: { type: 'string', pattern: '^[0-9]{4,6}$', hidden: true },
		})
	})

	describe('a name typed but not added', () => {
		/**
		 * Mount the dialog with "PIN" typed under "Also ask for another field".
		 *
		 * @return {object} The wrapper and the mocked createRequest.
		 */
		function mountTyped() {
			const store = useSecretRequestStore()
			store.createRequest = vi.fn().mockResolvedValue({ id: 'r', token: 't' })
			const wrapper = mount(SecretRequestCreateDialog, {
				propsData: { open: true, secret: { id: 'secret-1' } },
				global: { stubs: ncStubs },
			})
			wrapper.vm.customFieldInput = 'PIN'
			wrapper.vm.onCustomPreset('password')
			return { wrapper, createRequest: store.createRequest }
		}

		it('asks before creating, and Back creates nothing', async () => {
			const { wrapper, createRequest } = mountTyped()

			wrapper.vm.onCreate()
			expect(wrapper.vm.unaddedOpen).toBe(true)
			expect(createRequest).not.toHaveBeenCalled()

			await wrapper.vm.$nextTick()
			await wrapper
				.findComponent('[data-testid="secret-request-unadded-back"]')
				.trigger('click')
			expect(wrapper.vm.unaddedOpen).toBe(false)
			expect(wrapper.vm.customFieldInput).toBe('PIN')
			expect(createRequest).not.toHaveBeenCalled()
		})

		it('Add and create asks for the field with its kind', async () => {
			const { wrapper, createRequest } = mountTyped()

			wrapper.vm.onCreate()
			await wrapper.vm.addAndCreate()
			await wrapper.vm.$nextTick()

			const sent = createRequest.mock.calls[0][0]
			expect(sent.requestedFields).toContain('PIN')
			expect(sent.fieldSchemas).toEqual({
				PIN: { type: 'string', hidden: true },
			})
		})

		it('Create without it leaves the field out', async () => {
			const { wrapper, createRequest } = mountTyped()

			wrapper.vm.onCreate()
			await wrapper.vm.createWithout()
			await wrapper.vm.$nextTick()

			expect(createRequest.mock.calls[0][0].requestedFields).not.toContain(
				'PIN',
			)
		})

		it('does not ask when nothing is typed', async () => {
			const { wrapper, createRequest } = mountTyped()
			wrapper.vm.customFieldInput = '  '

			wrapper.vm.onCreate()
			await wrapper.vm.$nextTick()

			expect(wrapper.vm.unaddedOpen).toBe(false)
			expect(createRequest).toHaveBeenCalledTimes(1)
		})
	})
})
