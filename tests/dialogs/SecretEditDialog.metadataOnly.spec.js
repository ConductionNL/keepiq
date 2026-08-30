/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A metadata-only edit must not touch the ciphertext.
 *
 * "Edit metadata only" is a declared scenario of "Edit a Secret from the UI" and had
 * no test of any kind. It matters beyond wasted work: re-encrypting on every rename
 * would rewrite the value, the login and the whole additional-fields blob, and
 * rewriting the blob is exactly what loses members another session added in the
 * meantime.
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
		props: ['modelValue', 'label', 'placeholder', 'disabled', 'required'],
		template: '<input :value="modelValue" :disabled="disabled" />',
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

async function mountOver(secret) {
	useSecretStore().fetchSecret = vi.fn().mockResolvedValue(secret)

	const wrapper = mount(SecretEditDialog, {
		propsData: { secretId: secret.id },
		global: { stubs },
	})
	// mounted() → fetchPolicy + fetchTypes + load()
	await wrapper.vm.$nextTick()
	await new Promise((resolve) => setTimeout(resolve, 0))
	await wrapper.vm.$nextTick()

	return wrapper
}

describe('SecretEditDialog — metadata-only edits', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()

		const typeStore = useSecretTypeStore()
		typeStore.types = [{ id: 'login', name: 'login', label: 'Login' }]
		typeStore.fetchTypes = vi.fn().mockResolvedValue()

		// isLocked is a getter over cryptoKey; assigning it is a no-op.
		const session = useSessionStore()
		session.cryptoKey = 'UNLOCKED'
		session.certificate = 'PEM'
	})

	it('sends only the name when only the name changed', async () => {
		const wrapper = await mountOver({
			id: 's1',
			name: 'Old name',
			typeId: 'login',
			key: 'Xk9#mQ2$vL7@pR4!zT6&',
			url: 'https://example.test',
			login: 'svc-acct',
		})
		const update = vi
			.spyOn(useSecretStore(), 'updateSecret')
			.mockResolvedValue({ id: 's1' })

		wrapper.vm.name = 'New name'
		await wrapper.vm.submit()

		const diff = update.mock.calls[0][1]
		expect(diff.name).toBe('New name')
		expect('key' in diff).toBe(false)
		expect('login' in diff).toBe(false)
	})

	it('sends nothing at all when nothing changed', async () => {
		// A save with no edits should not produce a version row or a re-encryption.
		const wrapper = await mountOver({
			id: 's1',
			name: 'Unchanged',
			typeId: 'login',
			key: 'value',
			url: null,
			login: '',
		})
		const update = vi
			.spyOn(useSecretStore(), 'updateSecret')
			.mockResolvedValue({ id: 's1' })

		await wrapper.vm.submit()

		expect(update).not.toHaveBeenCalled()
	})

	it('does re-encrypt when the value itself changes', async () => {
		// The counterpart: the rule is "only CHANGED sensitive fields", not "never".
		const wrapper = await mountOver({
			id: 's1',
			name: 'Same',
			typeId: 'login',
			key: 'old-value',
			url: null,
			login: '',
		})
		const update = vi
			.spyOn(useSecretStore(), 'updateSecret')
			.mockResolvedValue({ id: 's1' })

		wrapper.vm.value = 'Xk9#mQ2$vL7@pR4!zT6&'
		await wrapper.vm.submit()

		expect('key' in update.mock.calls[0][1]).toBe(true)
	})
})
