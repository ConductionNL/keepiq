/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The share dialog mounted, rather than its bare methods: it renders only
 * while open, the close (×) and cancel buttons emit close, the recipient
 * picker drives the submit button, a submit encrypts the snapshot for the
 * picked recipient and emits shared, an encryption failure is rendered,
 * and closing the dialog resets the picked recipient.
 *
 * @spec openspec/changes/implement-user-sharing/tasks.md#task-12.1
 * @spec openspec/specs/user-sharing/spec.md#requirement-share-a-secret
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const shareStore = { encryptForRecipient: vi.fn() }

vi.mock('../../src/store/modules/share.js', () => ({
	useShareStore: () => shareStore,
}))

const { default: ShareDialog } = await import(
	'../../src/components/share/ShareDialog.vue'
)

const RecipientPicker = {
	name: 'RecipientPicker',
	props: ['modelValue', 'disabled'],
	emits: ['update:modelValue'],
	template: '<div data-testid="picker" :data-disabled="String(disabled)" />',
}

/**
 * Mount the dialog.
 *
 * @param {object} props Extra props.
 * @return {object} The wrapper.
 */
function mountDialog(props = {}) {
	return mount(ShareDialog, {
		props: {
			open: true,
			secretId: 'sec-1',
			plaintextSnapshot: { password: 'pw' },
			recipientCertificate: 'cert-1',
			...props,
		},
		global: { stubs: { RecipientPicker } },
	})
}

/**
 * Pick a recipient through the picker.
 *
 * @param {object} wrapper The mounted dialog.
 * @param {string} uid The user id.
 * @return {Promise<void>}
 */
async function pick(wrapper, uid) {
	await wrapper.findComponent(RecipientPicker).vm.$emit('update:modelValue', uid)
}

describe('ShareDialog mounted', () => {
	beforeEach(() => {
		shareStore.encryptForRecipient = vi.fn().mockResolvedValue({ password: 'enc-1' })
	})

	it('renders nothing while closed', () => {
		const wrapper = mountDialog({ open: false })

		expect(wrapper.find('[data-testid="share-dialog"]').exists()).toBe(false)
	})

	it('emits close from the close button and from cancel', async () => {
		const wrapper = mountDialog()

		await wrapper.find('[data-testid="share-dialog-close"]').trigger('click')
		await wrapper.find('[data-testid="share-dialog-cancel"]').trigger('click')

		expect(wrapper.emitted('close')).toHaveLength(2)
		expect(wrapper.emitted('shared')).toBeUndefined()
	})

	it('enables submit once a recipient is picked, and shares the encrypted snapshot', async () => {
		const wrapper = mountDialog()
		const submit = wrapper.find('[data-testid="share-dialog-submit"]')
		expect(submit.attributes('disabled')).toBeDefined()
		expect(submit.text()).toBe('Share')

		await pick(wrapper, 'bob')
		expect(submit.attributes('disabled')).toBeUndefined()

		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(shareStore.encryptForRecipient).toHaveBeenCalledWith({ password: 'pw' }, 'cert-1')
		expect(wrapper.emitted('shared')).toEqual([
			[{ targetUserId: 'bob', encryptedFields: { password: 'enc-1' } }],
		])
		expect(wrapper.find('[data-testid="share-dialog-error"]').exists()).toBe(false)
	})

	it('encrypts an empty snapshot when the parent passes none', async () => {
		const wrapper = mount(ShareDialog, {
			props: { open: true, secretId: 'sec-1', recipientCertificate: 'cert-1' },
			global: { stubs: { RecipientPicker } },
		})
		await pick(wrapper, 'bob')

		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(shareStore.encryptForRecipient).toHaveBeenCalledWith({}, 'cert-1')
	})

	it('shows "Sharing…" and disables the picker while encrypting', async () => {
		let release
		shareStore.encryptForRecipient = vi.fn(
			() => new Promise((resolve) => { release = resolve }),
		)
		const wrapper = mountDialog()
		await pick(wrapper, 'bob')

		await wrapper.find('form').trigger('submit')

		const submit = wrapper.find('[data-testid="share-dialog-submit"]')
		expect(submit.text()).toBe('Sharing…')
		expect(submit.attributes('disabled')).toBeDefined()
		expect(wrapper.find('[data-testid="picker"]').attributes('data-disabled')).toBe('true')

		release({ password: 'enc-1' })
		await flushPromises()
		expect(submit.text()).toBe('Share')
	})

	it('renders an encryption failure and emits nothing', async () => {
		shareStore.encryptForRecipient = vi.fn().mockRejectedValue(new Error('bad cert'))
		const wrapper = mountDialog()
		await pick(wrapper, 'bob')

		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(wrapper.find('[data-testid="share-dialog-error"]').text()).toBe('bad cert')
		expect(wrapper.emitted('shared')).toBeUndefined()
	})

	it('renders the missing-suite refusal without encrypting', async () => {
		const wrapper = mountDialog({ recipientCertificate: '' })
		await pick(wrapper, 'bob')

		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(wrapper.find('[data-testid="share-dialog-error"]').text()).toBe(
			'Recipient has no active encryption suite',
		)
		expect(shareStore.encryptForRecipient).not.toHaveBeenCalled()
	})

	it('clears the picked recipient and the error when closed', async () => {
		shareStore.encryptForRecipient = vi.fn().mockRejectedValue(new Error('bad cert'))
		const wrapper = mountDialog()
		await pick(wrapper, 'bob')
		await wrapper.find('form').trigger('submit')
		await flushPromises()

		await wrapper.setProps({ open: false })
		await wrapper.setProps({ open: true })

		expect(wrapper.findComponent(RecipientPicker).props('modelValue')).toBe('')
		expect(wrapper.find('[data-testid="share-dialog-error"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="share-dialog-submit"]').attributes('disabled')).toBeDefined()
	})
})
