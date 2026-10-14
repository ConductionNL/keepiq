/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The edit dialog saves from the version it opened and shows a failed share
 * save as a translated error instead of closing as if all went well
 * (keepiq#1253 bug 4).
 *
 * @spec openspec/specs/secrets-write-ui/spec.md#requirement-edit-a-secret-from-the-ui
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import SecretEditDialog from '../../src/dialogs/SecretEditDialog.vue'
import { ShareSaveError } from '../../src/services/shareSave.js'
import { useSecretStore } from '../../src/store/modules/secret.js'
import { useSecretTypeStore } from '../../src/store/modules/secretType.js'
import { useSessionStore } from '../../src/store/modules/session.js'
import { useShareStore } from '../../src/store/modules/share.js'
import { httpError } from '../fixtures/shareSaveServer.js'

const BASE = '2026-10-01T10:00:00+00:00'

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
 * Mount the dialog over a loaded secret with a changed value.
 *
 * @param {object} [options] Mount options.
 * @param {object} [options.stubs] Component stubs to use instead of the defaults.
 * @param {object} [options.context] The write-context the dialog reads on open.
 * @return {Promise<object>} The wrapper.
 */
async function mountEdited(options = {}) {
	useShareStore().fetchWriteContext = vi
		.fn()
		.mockResolvedValue(
			options.context ?? { sourceSecretId: 's1', sourceUpdatedAt: BASE },
		)
	useSecretStore().fetchSecret = vi.fn().mockResolvedValue({
		id: 's1',
		name: 'Router',
		typeId: 'login',
		key: 'old-value',
		url: null,
		login: '',
		updatedAt: BASE,
	})
	const wrapper = mount(SecretEditDialog, {
		propsData: { secretId: 's1' },
		global: { stubs: options.stubs ?? stubs },
	})
	await wrapper.vm.$nextTick()
	await new Promise((resolve) => setTimeout(resolve, 0))
	await wrapper.vm.$nextTick()
	wrapper.vm.value = 'Xk9#mQ2$vL7@pR4!zT6&'
	return wrapper
}

describe('SecretEditDialog — share save', () => {
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

	it('saves from the version it opened', async () => {
		const wrapper = await mountEdited()
		const update = vi
			.spyOn(useSecretStore(), 'updateSecret')
			.mockResolvedValue({ id: 's1' })

		await wrapper.vm.submit()

		expect(update.mock.calls[0][2]).toEqual({
			baseUpdatedAt: BASE,
			sourceBaseUpdatedAt: null,
			withoutUnreachable: false,
			onWait: expect.any(Function),
		})
		expect(wrapper.emitted('saved')).toBeTruthy()
	})

	it('saves a copy from the source version it opened on, not the one at save time', async () => {
		const opened = '2026-09-30T08:00:00+00:00'
		const wrapper = await mountEdited({
			context: { sourceSecretId: 'src', sourceUpdatedAt: opened },
		})
		// The owner changed the source while the dialog was open.
		useShareStore().fetchWriteContext.mockResolvedValue({
			sourceSecretId: 'src',
			sourceUpdatedAt: '2026-10-01T09:00:00+00:00',
		})
		const update = vi
			.spyOn(useSecretStore(), 'updateSecret')
			.mockResolvedValue({ id: 's1' })

		await wrapper.vm.submit()

		expect(update.mock.calls[0][2].sourceBaseUpdatedAt).toBe(opened)
	})

	it('says so while the save waits on the partner lookup limit', async () => {
		const wrapper = await mountEdited()
		let finish
		vi.spyOn(useSecretStore(), 'updateSecret').mockImplementation(
			(id, diff, options) => {
				options.onWait(60000)
				return new Promise((resolve) => (finish = resolve))
			},
		)
		const waiting = () => wrapper.find('[data-testid="secret-edit-waiting"]')

		const saving = wrapper.vm.submit()
		await new Promise((resolve) => setTimeout(resolve, 0))
		await wrapper.vm.$nextTick()
		expect(waiting().text()).toContain('waiting for the lookup limit')

		finish({ id: 's1' })
		await saving
		await wrapper.vm.$nextTick()
		expect(waiting().exists()).toBe(false)
		expect(wrapper.emitted('saved')).toBeTruthy()
	})

	it('shows a stale refusal as translated text and stays open', async () => {
		const wrapper = await mountEdited()
		vi.spyOn(useSecretStore(), 'updateSecret').mockRejectedValue(
			httpError(409, { error: 'stale', message: 'Secret changed' }),
		)

		await wrapper.vm.submit()
		await wrapper.vm.$nextTick()

		const card = wrapper.find('[data-testid="secret-edit-error"]')
		expect(card.text()).toContain('changed elsewhere')
		expect(wrapper.emitted('saved')).toBeFalsy()
		expect(
			wrapper.find('[data-testid="save-without-unreachable"]').exists(),
		).toBe(false)
	})

	it("shows a vault policy refusal with the server's own message", async () => {
		const wrapper = await mountEdited()
		const message =
			'Your organisation requires this type of secret to be kept in a team folder'
		vi.spyOn(useSecretStore(), 'updateSecret').mockRejectedValue(
			Object.assign(
				httpError(428, {
					error: 'org_ownership_required',
					code: 'org_ownership_required',
					message,
				}),
				{ fromShareSave: true },
			),
		)

		await wrapper.vm.submit()
		await wrapper.vm.$nextTick()

		expect(wrapper.find('[data-testid="secret-edit-error"]').text()).toBe(
			message,
		)
	})

	it('offers to save without an unreachable partner, and then pauses it', async () => {
		const wrapper = await mountEdited()
		const update = vi
			.spyOn(useSecretStore(), 'updateSecret')
			.mockRejectedValueOnce(
				new ShareSaveError('partner_unreachable', [
					{ shareId: 'fs-1', recipientCloudId: 'bob@partner.example' },
				]),
			)
			.mockResolvedValueOnce({ id: 's1' })

		await wrapper.vm.submit()
		await wrapper.vm.$nextTick()
		expect(wrapper.find('[data-testid="secret-edit-error"]').text()).toContain(
			'bob@partner.example',
		)

		await wrapper
			.find('[data-testid="save-without-unreachable"]')
			.trigger('click')
		await new Promise((resolve) => setTimeout(resolve, 0))

		expect(update.mock.calls[1][2].withoutUnreachable).toBe(true)
		expect(wrapper.emitted('saved')).toBeTruthy()
	})

	it('cannot be closed while it saves, and can again afterwards', async () => {
		// Like NcModal, which ignores Escape, its close button and an outside click under `noClose`.
		const NcDialog = {
			props: ['name', 'open', 'size', 'noClose'],
			emits: ['update:open'],
			template:
				'<div @keydown.esc="noClose || $emit(\'update:open\', false)"><slot /><slot name="actions" /></div>',
		}
		const wrapper = await mountEdited({ stubs: { ...stubs, NcDialog } })
		let fail
		vi.spyOn(useSecretStore(), 'updateSecret').mockImplementation(
			() => new Promise((resolve, reject) => (fail = reject)),
		)
		const cancel = () => wrapper.find('[data-testid="secret-edit-cancel"]')

		const saving = wrapper.vm.submit()
		await wrapper.vm.$nextTick()
		expect(cancel().attributes('disabled')).toBeDefined()
		await cancel().trigger('click')
		await wrapper.trigger('keydown', { key: 'Escape' })
		expect(wrapper.emitted('close')).toBeFalsy()

		fail(httpError(409, { error: 'stale', message: 'Secret changed' }))
		await saving
		await wrapper.vm.$nextTick()
		expect(cancel().attributes('disabled')).toBeUndefined()
		await wrapper.trigger('keydown', { key: 'Escape' })
		expect(wrapper.emitted('close')).toHaveLength(1)
		await cancel().trigger('click')
		expect(wrapper.emitted('close').length).toBeGreaterThan(1)
	})
})
