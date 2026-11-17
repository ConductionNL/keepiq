/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `src/components/SshKeyFields.vue`: the SSH key section
 * derives the public key and fingerprint from what the user pastes, imports
 * or generates, and shows a save refusal on its field. Key handling is real
 * WebCrypto; nothing is mocked but the Nextcloud components.
 *
 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-ssh-key-item/spec.md#requirement-ssh-key-item-form
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import SshKeyFields from '../../src/components/SshKeyFields.vue'
import { generateKeyPair } from '../../src/ssh/sshKey.js'
import vectors from '../fixtures/ssh-key-vectors.json'

const field = {
	props: ['modelValue', 'label', 'disabled', 'error', 'helperText'],
	emits: ['update:modelValue'],
	template:
		'<label><span>{{ label }}</span><input :value="modelValue" :disabled="disabled" @input="$emit(\'update:modelValue\', $event.target.value)" /><small v-if="helperText">{{ helperText }}</small></label>',
}

const stubs = {
	NcTextField: field,
	NcTextArea: {
		...field,
		template: field.template
			.replace('<input', '<textarea')
			.replace(' />', '></textarea>'),
	},
	NcButton: {
		props: ['disabled', 'variant'],
		emits: ['click'],
		template:
			'<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
	NcLoadingIcon: { template: '<span />' },
}

async function settle() {
	for (let i = 0; i < 5; i++) {
		await new Promise((resolve) => setTimeout(resolve, 0))
	}
}

/**
 * Mount the section with the given props.
 *
 * @param {object} props The props.
 * @return {object}
 */
function mountFields(props = {}) {
	return mount(SshKeyFields, { props, global: { stubs } })
}

describe('SshKeyFields', () => {
	it('labels every field', () => {
		const wrapper = mountFields()

		expect(wrapper.find('[data-testid="ssh-private-key"]').text()).toContain(
			'Private key',
		)
		expect(wrapper.find('[data-testid="ssh-public-key"]').text()).toContain(
			'Public key',
		)
		expect(wrapper.find('[data-testid="ssh-fingerprint"]').text()).toContain(
			'Fingerprint',
		)
	})

	it('derives the fingerprint of a typed public key', async () => {
		const wrapper = mountFields()

		await wrapper
			.find('[data-testid="ssh-public-key"] input')
			.setValue(vectors.ed25519.publicKey)
		await settle()

		expect(wrapper.emitted('update:keys').at(-1)[0]).toEqual({
			publicKey: vectors.ed25519.publicKey,
			fingerprint: vectors.ed25519.fingerprint,
		})
	})

	it('clears the fingerprint when the public key is not a key', async () => {
		const wrapper = mountFields()

		await wrapper.find('[data-testid="ssh-public-key"] input').setValue('nope')
		await settle()

		expect(wrapper.emitted('update:keys').at(-1)[0]).toEqual({
			publicKey: 'nope',
			fingerprint: '',
		})
	})

	it('fills the public key and fingerprint from a pasted private key', async () => {
		const pair = await generateKeyPair()
		const wrapper = mountFields()

		await wrapper
			.find('[data-testid="ssh-private-key"] textarea')
			.setValue(pair.privateKey)
		await settle()

		expect(wrapper.emitted('update:privateKey')[0][0]).toBe(pair.privateKey)
		expect(wrapper.emitted('update:keys').at(-1)[0]).toEqual({
			publicKey: pair.publicKey,
			fingerprint: pair.fingerprint,
		})
	})

	it('imports a private key file and fills in all three fields before saving', async () => {
		const pair = await generateKeyPair()
		const wrapper = mountFields()
		const input = wrapper.find('[data-testid="ssh-import-file"]')
		Object.defineProperty(input.element, 'files', {
			value: [new File([pair.privateKey], 'id_ed25519')],
			configurable: true,
		})

		await input.trigger('change')
		await settle()

		expect(wrapper.emitted('update:privateKey')[0][0]).toBe(pair.privateKey)
		expect(wrapper.emitted('update:keys').at(-1)[0]).toEqual({
			publicKey: pair.publicKey,
			fingerprint: pair.fingerprint,
		})
		expect(wrapper.find('[data-testid="ssh-action-error"]').exists()).toBe(false)
	})

	it('says so when a chosen file is too large to be a key', async () => {
		const wrapper = mountFields()
		const input = wrapper.find('[data-testid="ssh-import-file"]')
		Object.defineProperty(input.element, 'files', {
			value: [new File(['x'.repeat(70 * 1024)], 'big.bin')],
			configurable: true,
		})

		await input.trigger('change')
		await settle()

		expect(wrapper.find('[data-testid="ssh-action-error"]').text()).toBe(
			'The file could not be read.',
		)
		expect(wrapper.emitted('update:privateKey')).toBeUndefined()
	})

	it('generates a key pair whose public key parses', async () => {
		const wrapper = mountFields()

		await wrapper.find('[data-testid="ssh-generate"]').trigger('click')
		await settle()

		const [privateKey] = wrapper.emitted('update:privateKey')[0]
		const [keys] = wrapper.emitted('update:keys').at(-1)
		expect(privateKey).toMatch(/^-----BEGIN OPENSSH PRIVATE KEY-----\n/)
		expect(keys.publicKey).toMatch(/^ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAI/)
		expect(keys.fingerprint).toMatch(/^SHA256:[A-Za-z0-9+/]{43}$/)
	})

	it('shows the save refusal on the private key field', () => {
		const wrapper = mountFields({ privateKeyError: 'Not a key.' })

		expect(wrapper.find('[data-testid="ssh-private-key"]').text()).toContain(
			'Not a key.',
		)
	})
})
