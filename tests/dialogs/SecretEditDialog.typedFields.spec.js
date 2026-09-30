/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The edit dialog shows the fields of an administrator-defined type in their
 * own form and writes them back into the encrypted blob (admin-18).
 *
 * @spec openspec/specs/admin-secret-types/spec.md#requirement-typed-fields-storage
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
		props: ['modelValue', 'label', 'placeholder', 'disabled', 'required'],
		emits: ['update:modelValue'],
		template:
			'<input :value="modelValue" :disabled="disabled" @input="$emit(\'update:modelValue\', $event.target.value)" />',
	},
	NcPasswordField: {
		props: ['modelValue', 'label'],
		template: '<input type="password" :value="modelValue" />',
	},
	NcNoteCard: { props: ['type'], template: '<div><slot /></div>' },
	NcLoadingIcon: { template: '<span />' },
	Plus: { template: '<i />' },
	Dice5: { template: '<i />' },
	KeyGeneratorModal: { props: ['open'], template: '<div />' },
}

/**
 * Mount the edit dialog over a secret whose decrypted blob holds `members`.
 *
 * @param {object|null} members The decrypted additionalFields, or null.
 *
 * @return {Promise<object>} The mounted wrapper.
 */
async function mountOver(members) {
	const store = useSecretStore()
	store.fetchSecret = vi.fn().mockResolvedValue({
		id: 's1',
		name: 'Supplier API',
		typeId: 'type-server',
		key: 'Xk9#mQ2$vL7@pR4!zT6&',
		url: 'https://supplier.example',
		login: 'svc-acct',
		additionalFields: members,
	})

	const wrapper = mount(SecretEditDialog, {
		propsData: { secretId: 's1' },
		global: { stubs },
	})
	// mounted() → fetchPolicy + fetchTypes + load(); two ticks let all of it settle.
	await wrapper.vm.$nextTick()
	await new Promise((resolve) => setTimeout(resolve, 0))
	await wrapper.vm.$nextTick()

	return wrapper
}

const SERVER_ACCESS = {
	id: 'type-server',
	name: 'server-access',
	label: 'Server access',
	scope: 'global',
	fields: [
		{ key: 'host', label: 'Host', kind: 'url', required: true },
		{
			key: 'root-password',
			label: 'Root password',
			kind: 'hidden',
			required: false,
		},
	],
}

describe('SecretEditDialog typed fields', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
		const typeStore = useSecretTypeStore()
		typeStore.types = [SERVER_ACCESS]
		typeStore.fetchTypes = vi.fn().mockResolvedValue()
		const session = useSessionStore()
		session.cryptoKey = 'UNLOCKED'
		session.certificate = 'PEM'
	})

	it('loads typed values into their own form and the rest into the free fields', async () => {
		const wrapper = await mountOver({ Host: 'https://db-01', notes: 'rack 4' })

		expect(wrapper.vm.typedValues).toEqual({ host: 'https://db-01' })
		expect(wrapper.vm.additionalFields).toEqual([
			{ name: 'notes', value: 'rack 4' },
		])
		expect(wrapper.find('[data-testid="typed-fields"]').exists()).toBe(true)
	})

	it('sends no blob when nothing changed', async () => {
		const wrapper = await mountOver({ Host: 'https://db-01', notes: 'rack 4' })
		const update = vi
			.spyOn(useSecretStore(), 'updateSecret')
			.mockResolvedValue({ id: 's1' })

		wrapper.vm.name = 'Renamed'
		await wrapper.vm.submit()

		expect(update.mock.calls[0][1].additionalFields).toBeUndefined()
	})

	it('writes a changed typed value back under its label', async () => {
		const wrapper = await mountOver({ Host: 'https://db-01', notes: 'rack 4' })
		const update = vi
			.spyOn(useSecretStore(), 'updateSecret')
			.mockResolvedValue({ id: 's1' })

		wrapper.vm.typedValues = { host: 'https://db-02', 'root-password': 'pw' }
		await wrapper.vm.submit()

		expect(update.mock.calls[0][1].additionalFields).toEqual({
			notes: 'rack 4',
			Host: 'https://db-02',
			'Root password': 'pw',
		})
	})

	it('blocks the save while a required field is empty', async () => {
		const wrapper = await mountOver({ notes: 'rack 4' })
		const update = vi
			.spyOn(useSecretStore(), 'updateSecret')
			.mockResolvedValue({ id: 's1' })

		await wrapper.vm.submit()

		expect(update).not.toHaveBeenCalled()
		expect(wrapper.vm.typedMissing).toEqual(['host'])
	})
})
