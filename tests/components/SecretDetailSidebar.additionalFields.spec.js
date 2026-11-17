/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * How the detail sidebar renders additional fields — including none.
 *
 * The empty case only became reachable with owner-editable additional fields.
 * Before that the sole writers were the application dialog, import, and a request
 * fill, all of which write at least one member; nobody could produce an EMPTY blob
 * from the UI. Removing the last member now does exactly that, and the spec says
 * re-opening the secret must show no additional fields and no error.
 *
 * @spec openspec/specs/secrets-write-ui/spec.md#requirement-edit-a-secret-from-the-ui
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import SecretDetailSidebar from '../../src/components/SecretDetailSidebar.vue'
import { useSecretStore } from '../../src/store/modules/secret.js'
import { useSecretTypeStore } from '../../src/store/modules/secretType.js'

const stubAll = {
	NcAppSidebar: { template: '<aside><slot /></aside>' },
	NcButton: {
		emits: ['click'],
		template: '<button @click="$emit(\'click\')"><slot /></button>',
	},
	NcEmptyContent: { template: '<div><slot /></div>' },
	NcNoteCard: { template: '<div><slot /></div>' },
	NcActions: { template: '<div><slot /></div>' },
	NcActionButton: { template: '<button><slot /></button>' },
	Delete: { template: '<span />' },
	Lock: { template: '<span />' },
	Pencil: { template: '<span />' },
	FolderMove: { template: '<span />' },
	ShareVariant: { template: '<span />' },
	CopyButton: {
		props: ['value', 'label'],
		template:
			'<button class="copy-stub" :aria-label="label" :data-value="value" />',
	},
	PasswordField: { template: '<input />' },
	ShareList: { template: '<div />' },
}

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

/**
 * Mount the detail sidebar over a secret with the given decrypted blob.
 *
 * @param {object|null} additionalFields The decrypted members.
 *
 * @return {Promise<object>} The mounted wrapper.
 */
async function mountDetail(additionalFields, typeId = 'login') {
	const secret = {
		id: 's-1',
		name: 'Supplier API',
		typeId,
		key: 'value',
		additionalFields,
	}

	useSecretStore().fetchSecret = vi.fn().mockResolvedValue(secret)
	useSecretTypeStore().fetchTypes = vi.fn().mockResolvedValue([])

	const wrapper = mount(SecretDetailSidebar, {
		props: { secretId: 's-1' },
		global: {
			stubs: stubAll,
		},
	})
	await flush()
	await flush()

	return wrapper
}

describe('SecretDetailSidebar — additional fields', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
		window.OC = { currentUser: 'alice' }
	})

	it('renders the members a secret has', async () => {
		const wrapper = await mountDetail({ 'client-id': 'acme-4711' })

		expect(wrapper.vm.hasAdditionalFields).toBe(true)
		expect(wrapper.text()).toContain('client-id')
		expect(wrapper.text()).toContain('acme-4711')
	})

	it('shows NO additional-fields section for an empty blob', async () => {
		// `{}` is truthy AND an object, so a guard testing only those two things
		// reports "has additional fields" and renders the heading with nothing
		// beneath it. The user removed the last field; the section should be gone,
		// not empty.
		const wrapper = await mountDetail({})

		expect(wrapper.vm.hasAdditionalFields).toBe(false)
		expect(wrapper.text()).not.toContain('Additional fields')
	})

	it('shows no section when the secret never had any', async () => {
		const wrapper = await mountDetail(null)

		expect(wrapper.vm.hasAdditionalFields).toBe(false)
	})

	it('shows no section when the blob could not be parsed', async () => {
		// The store hands over a string when the ciphertext was not JSON. Rendering
		// a heading over an unusable value tells the user nothing.
		const wrapper = await mountDetail('not json')

		expect(wrapper.vm.hasAdditionalFields).toBe(false)
	})

	/**
	 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-hidden-custom-fields
	 */
	describe('field kinds', () => {
		it('shows every field of an old blob without kinds as plain text', async () => {
			const wrapper = await mountDetail({
				'Recovery code': 'abc-123',
				tenant: 'acme',
			})

			expect(wrapper.text()).toContain('abc-123')
			expect(wrapper.text()).toContain('acme')
			expect(
				wrapper
					.find('[data-testid="secret-detail-field-reveal-Recovery code"]')
					.exists(),
			).toBe(false)
		})

		it('keeps a hidden value out of the DOM text until Show', async () => {
			const wrapper = await mountDetail({
				'Recovery code': { value: 'abc-123', kind: 'hidden' },
				tenant: 'acme',
			})

			expect(wrapper.text()).not.toContain('abc-123')
			expect(
				wrapper
					.find('[data-testid="secret-detail-field-Recovery code"]')
					.text(),
			).toBe('••••••••')

			await wrapper
				.find('[data-testid="secret-detail-field-reveal-Recovery code"]')
				.trigger('click')

			expect(
				wrapper
					.find('[data-testid="secret-detail-field-Recovery code"]')
					.text(),
			).toBe('abc-123')

			await wrapper
				.find('[data-testid="secret-detail-field-reveal-Recovery code"]')
				.trigger('click')
			expect(wrapper.text()).not.toContain('abc-123')
		})

		it('copies the real hidden value without revealing it', async () => {
			const wrapper = await mountDetail({
				PIN: { value: '4711', kind: 'hidden' },
			})

			const copy = wrapper.find('[data-testid="secret-detail-field-copy-PIN"]')
			expect(copy.attributes('data-value')).toBe('4711')
			expect(wrapper.text()).not.toContain('4711')
		})

		it('shows a yes or no field as Yes or No', async () => {
			const wrapper = await mountDetail({
				Active: { value: 'true', kind: 'boolean' },
				Archived: { value: 'false', kind: 'boolean' },
			})

			expect(
				wrapper.find('[data-testid="secret-detail-field-Active"]').text(),
			).toBe('Yes')
			expect(
				wrapper.find('[data-testid="secret-detail-field-Archived"]').text(),
			).toBe('No')
		})

		it('forgets a reveal when another secret opens', async () => {
			const wrapper = await mountDetail({
				PIN: { value: '4711', kind: 'hidden' },
			})
			await wrapper
				.find('[data-testid="secret-detail-field-reveal-PIN"]')
				.trigger('click')
			expect(wrapper.text()).toContain('4711')

			await wrapper.setProps({ secretId: 's-2' })
			await flush()
			await flush()

			expect(wrapper.text()).not.toContain('4711')
		})
	})

	/**
	 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-ssh-key-item/spec.md#requirement-ssh-key-item-form
	 */
	describe('SSH key', () => {
		const publicKey =
			'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIIBoJneOTtxFSNECPswfFsH3T+cckVsjDeknogABDGhI vector@keepiq'
		const fingerprint = 'SHA256:rJIGsSTIobhpdMcjRggss8LYJNg3rPUS2UTYAaW/hno'

		it('shows the public key with Copy and the fingerprint, not as additional fields', async () => {
			useSecretTypeStore().types = [{ id: 'ssh', name: 'ssh_key' }]
			const wrapper = await mountDetail({ publicKey, fingerprint }, 'ssh')

			expect(
				wrapper.find('[data-testid="secret-detail-ssh-public-key"]').text(),
			).toBe(publicKey)
			expect(
				wrapper.find('[data-testid="secret-detail-ssh-fingerprint"]').text(),
			).toBe(fingerprint)
			expect(
				wrapper
					.find('[data-testid="secret-detail-ssh-copy-public-key"]')
					.attributes('data-value'),
			).toBe(publicKey)
			expect(wrapper.vm.hasAdditionalFields).toBe(false)
		})

		it('leaves a publicKey member of another type in the additional fields', async () => {
			const wrapper = await mountDetail({ publicKey })

			expect(wrapper.find('[data-testid="secret-detail-ssh"]').exists()).toBe(
				false,
			)
			expect(wrapper.vm.hasAdditionalFields).toBe(true)
		})
	})
})
