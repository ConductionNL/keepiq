/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A login's authenticator key in the edit dialog (keepiq#1326): the saved
 * seed is never put in the form; untouched sends nothing, Remove sends '',
 * Replace sends the new key, and a key that cannot be used blocks the save.
 * The change goes through the same diff and base versions as any other.
 *
 * @spec openspec/changes/vault-login-totp-codes/specs/login-one-time-codes/spec.md#requirement-a-login-can-carry-its-own-totp-seed
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import SecretEditDialog from '../../src/dialogs/SecretEditDialog.vue'
import { useSecretStore } from '../../src/store/modules/secret.js'
import { useSecretTypeStore } from '../../src/store/modules/secretType.js'
import { useSessionStore } from '../../src/store/modules/session.js'
import { useShareStore } from '../../src/store/modules/share.js'
import { valueFieldsSupported } from '../../src/utils/valueFields.js'

vi.mock('../../src/utils/valueFields.js', async (importOriginal) => ({
	...(await importOriginal()),
	valueFieldsSupported: vi.fn(() => true),
}))

const BASE = '2026-10-01T10:00:00+00:00'
const SAVED_SEED = 'otpauth://totp/Bank:alice?secret=GEZDGNBVGY3TQOJQ'
const NEW_SEED = 'JBSWY3DPEHPK3PXP'

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
	Dice5: { template: '<i />' },
	KeyGeneratorModal: { props: ['open'], template: '<div />' },
}

/**
 * Mount the dialog over a login.
 *
 * @param {object} fields Fields of the secret beyond the defaults.
 * @param {object} [context] The write-context the dialog reads on open.
 * @return {Promise<object>} The wrapper.
 */
async function mountOver(fields = {}, context = null) {
	useShareStore().fetchWriteContext = vi
		.fn()
		.mockResolvedValue(context ?? { sourceSecretId: 's1' })
	useSecretStore().fetchSecret = vi.fn().mockResolvedValue({
		id: 's1',
		name: 'Bank',
		typeId: 'login',
		key: 'value',
		url: null,
		login: 'alice',
		updatedAt: BASE,
		...fields,
	})
	const wrapper = mount(SecretEditDialog, {
		props: { secretId: 's1' },
		global: { stubs },
	})
	await wrapper.vm.$nextTick()
	await new Promise((resolve) => setTimeout(resolve, 0))
	await wrapper.vm.$nextTick()
	return wrapper
}

/**
 * Spy on the store save.
 *
 * @return {object} The spy.
 */
function spyUpdate() {
	return vi.spyOn(useSecretStore(), 'updateSecret').mockResolvedValue({ id: 's1' })
}

beforeEach(() => {
	setActivePinia(createPinia())
	vi.restoreAllMocks()
	vi.mocked(valueFieldsSupported).mockReturnValue(true)

	const typeStore = useSecretTypeStore()
	typeStore.types = [
		{ id: 'login', name: 'login', label: 'Login' },
		{ id: 'note', name: 'note', label: 'Note' },
	]
	typeStore.fetchTypes = vi.fn().mockResolvedValue()
	const session = useSessionStore()
	session.cryptoKey = 'UNLOCKED'
	session.certificate = 'PEM'
})

describe('a login with a saved authenticator key', () => {
	it('never puts the saved seed in the form', async () => {
		const wrapper = await mountOver({ totp: SAVED_SEED })

		expect(wrapper.html()).not.toContain('GEZDGNBVGY3TQOJQ')
		expect(wrapper.vm.totpInput).toBe('')
		expect(wrapper.find('[data-testid="authenticator-key-state"]').text()).toBe(
			'Authenticator key saved',
		)
	})

	it('sends no totp when the key is left alone', async () => {
		const wrapper = await mountOver({ totp: SAVED_SEED })
		const update = spyUpdate()

		wrapper.vm.name = 'Bank NL'
		await wrapper.vm.submit()
		expect('totp' in update.mock.calls[0][1]).toBe(false)
	})

	it('sends an empty totp on Remove, with the base version', async () => {
		const wrapper = await mountOver({ totp: SAVED_SEED })
		const update = spyUpdate()

		await wrapper
			.find('[data-testid="authenticator-key-remove"]')
			.trigger('click')
		await wrapper.vm.submit()

		expect(update.mock.calls[0][1]).toEqual({ totp: '' })
		expect(update.mock.calls[0][2].baseUpdatedAt).toBe(BASE)
	})

	it('sends the new key on Replace', async () => {
		const wrapper = await mountOver({ totp: SAVED_SEED })
		const update = spyUpdate()

		await wrapper
			.find('[data-testid="authenticator-key-replace"]')
			.trigger('click')
		wrapper.vm.totpInput = NEW_SEED
		await wrapper.vm.submit()

		expect(update.mock.calls[0][1]).toEqual({ totp: NEW_SEED })
	})

	it('sends nothing when Replace is left empty', async () => {
		const wrapper = await mountOver({ totp: SAVED_SEED })
		const update = spyUpdate()

		wrapper.vm.totpMode = 'replace'
		await wrapper.vm.submit()
		expect(update).not.toHaveBeenCalled()
	})

	it('blocks the save on a replacement it cannot use', async () => {
		const wrapper = await mountOver({ totp: SAVED_SEED })
		const update = spyUpdate()

		wrapper.vm.totpMode = 'replace'
		wrapper.vm.totpInput = 'not a key!'
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.canSubmit).toBe(false)
		expect(
			wrapper.find('[data-testid="authenticator-key-error"]').exists(),
		).toBe(true)
		await wrapper.vm.submit()
		expect(update).not.toHaveBeenCalled()
	})
})

describe('a login without an authenticator key', () => {
	it('sends an entered key, and nothing when none is entered', async () => {
		const wrapper = await mountOver({ totp: '' })
		const update = spyUpdate()

		wrapper.vm.name = 'Bank NL'
		await wrapper.vm.submit()
		expect('totp' in update.mock.calls[0][1]).toBe(false)

		const again = await mountOver({ totp: '' })
		again.vm.totpInput = NEW_SEED
		await again.vm.submit()
		expect(update.mock.calls[1][1]).toEqual({ totp: NEW_SEED })
	})

	it('leaves passkeys out of every save', async () => {
		const wrapper = await mountOver({ passkeys: '[{"credentialId":"c1"}]' })
		const update = spyUpdate()

		wrapper.vm.totpInput = NEW_SEED
		await wrapper.vm.submit()
		expect('passkeys' in update.mock.calls[0][1]).toBe(false)
	})
})

describe('another type that holds a key', () => {
	it('offers Remove only, and sends an empty totp', async () => {
		const wrapper = await mountOver({ typeId: 'note', totp: SAVED_SEED })
		const update = spyUpdate()

		expect(wrapper.html()).not.toContain('GEZDGNBVGY3TQOJQ')
		expect(
			wrapper.find('[data-testid="authenticator-key-replace"]').exists(),
		).toBe(false)
		await wrapper
			.find('[data-testid="authenticator-key-remove"]')
			.trigger('click')
		await wrapper.vm.submit()
		expect(update.mock.calls[0][1]).toEqual({ totp: '' })
	})

	it('sends nothing when the key is left alone', async () => {
		const wrapper = await mountOver({ typeId: 'note', totp: SAVED_SEED })
		const update = spyUpdate()

		wrapper.vm.name = 'Renamed'
		await wrapper.vm.submit()
		expect('totp' in update.mock.calls[0][1]).toBe(false)
	})
})

describe('where the field shows', () => {
	it('not on another type, nor when the server does not store it', async () => {
		const note = await mountOver({ typeId: 'note' })
		expect(note.find('[data-testid="authenticator-key"]').exists()).toBe(false)

		vi.mocked(valueFieldsSupported).mockReturnValue(false)
		const older = await mountOver({ totp: SAVED_SEED })
		expect(older.find('[data-testid="authenticator-key"]').exists()).toBe(false)
	})

	it('disabled on a view-only copy', async () => {
		const wrapper = await mountOver(
			{ totp: SAVED_SEED },
			{ sourceSecretId: 'src', effectiveGrade: 'read' },
		)
		expect(wrapper.vm.viewOnly).toBe(true)
		expect(
			wrapper
				.find('[data-testid="authenticator-key-remove"]')
				.attributes('disabled'),
		).toBeDefined()
		expect(wrapper.vm.canSubmit).toBe(false)
	})
})
