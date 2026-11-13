/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The fill page with a request's field schemas: a field is entered and checked
 * by its schema, Submit stays blocked while a value does not fit and its
 * input names the reason, and only the values travel in the encrypted blob.
 *
 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-a-request-carries-the-schemas-of-its-fields
 */

import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../../src/crypto/index.js', () => ({
	importPublicKey: vi.fn(async () => 'PUBKEY_HANDLE'),
	rsaEncrypt: vi.fn(async (value) => `ENC(${value})`),
}))

import SecretRequestFill from '../../src/views/SecretRequestFill.vue'

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

/**
 * Mount the fill page over a pending request for a PIN with a schema.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mountPinRequest() {
	vi.spyOn(axios, 'get').mockResolvedValue({
		data: {
			token: 'tok-1',
			status: 'pending',
			requested_fields: ['PIN', 'Active'],
			field_schemas: {
				PIN: { type: 'string', pattern: '^[0-9]{4,6}$', hidden: true },
				Active: { type: 'boolean' },
			},
			public_certificate: 'PEM',
		},
	})
	const wrapper = mount(SecretRequestFill, { propsData: { token: 'tok-1' } })
	await flush()
	return wrapper
}

describe('SecretRequestFill field schemas', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
	})

	it('masks a hidden field, offers a yes/no box and blocks a PIN that does not fit', async () => {
		const wrapper = await mountPinRequest()

		expect(
			wrapper.find('[data-testid="fill-field-PIN"]').attributes('type'),
		).toBe('password')
		expect(
			wrapper.find('[data-testid="fill-field-Active"]').attributes('type'),
		).toBe('checkbox')

		await wrapper.find('[data-testid="fill-field-PIN"]').setValue('12a4')
		const error = wrapper.find('[data-testid="fill-error-PIN"]')
		expect(error.exists()).toBe(true)
		// A screen reader reads the reason, not only that the value is invalid.
		expect(
			wrapper
				.find('[data-testid="fill-field-PIN"]')
				.attributes('aria-describedby'),
		).toBe(error.attributes('id'))
		expect(
			wrapper.findComponent('[data-testid="fill-submit"]').props('disabled'),
		).toBe(true)

		await wrapper.find('[data-testid="fill-field-PIN"]').setValue('1234')
		expect(wrapper.find('[data-testid="fill-error-PIN"]').exists()).toBe(false)
		expect(
			wrapper
				.find('[data-testid="fill-field-PIN"]')
				.attributes('aria-describedby'),
		).toBeUndefined()
	})

	it('masks a field that looks secret when its schema only sets limits', async () => {
		vi.spyOn(axios, 'post').mockResolvedValue({ data: {} })
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: {
				token: 'tok-1',
				status: 'pending',
				requested_fields: ['Recovery key', 'Port'],
				field_schemas: {
					'Recovery key': { type: 'string', maxLength: 40 },
					Port: { type: 'integer' },
				},
				public_certificate: 'PEM',
			},
		})
		const wrapper = mount(SecretRequestFill, { propsData: { token: 'tok-1' } })
		await flush()

		const recovery = wrapper.find('[data-testid="fill-field-Recovery key"]')
		expect(recovery.attributes('type')).toBe('password')
		expect(
			wrapper.find('[data-testid="fill-field-Port"]').attributes('type'),
		).toBe('number')

		await recovery.setValue('abc-123')
		await wrapper.find('[data-testid="fill-field-Port"]').setValue('22')
		await wrapper.find('[data-testid="fill-form"]').trigger('submit')
		await flush()

		expect(
			wrapper.find('[data-testid="fill-summary-Recovery key"]').text(),
		).not.toContain('abc-123')
		expect(wrapper.find('[data-testid="fill-summary-Port"]').text()).toBe('22')
	})

	// The owner wrote the schemas into the secret when asking; whoever holds the
	// link seals the blob, so a schema in it would be theirs, not the owner's.
	it('sends only the values, an unticked box as "false"', async () => {
		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: {} })
		const wrapper = await mountPinRequest()

		await wrapper.find('[data-testid="fill-field-PIN"]').setValue('1234')
		await wrapper.find('[data-testid="fill-form"]').trigger('submit')
		await flush()

		const blob = post.mock.calls[0][1].encryptedFields.additionalFields
		const sent = JSON.parse(blob.slice('ENC('.length, -1))
		expect(sent).toEqual({ PIN: '1234', Active: 'false' })
	})

	it('edits an object in the code editor and asks for it when left empty', async () => {
		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: {} })
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: {
				token: 'tok-1',
				status: 'pending',
				requested_fields: ['Config'],
				field_schemas: { Config: { type: 'object' } },
				public_certificate: 'PEM',
			},
		})
		const wrapper = mount(SecretRequestFill, { propsData: { token: 'tok-1' } })
		await flush()

		const editor = wrapper.findComponent({ name: 'CnJsonViewer' })
		expect(editor.exists()).toBe(true)
		expect(editor.props('language')).toBe('json')
		// A group, not a label: a click in the editor must not press Format.
		expect(
			wrapper.find('.keepiq-secret-request-fill__field').element.tagName,
		).toBe('DIV')

		await wrapper.find('[data-testid="fill-form"]').trigger('submit')
		await flush()
		expect(wrapper.find('[data-testid="fill-error-Config"]').text()).toBe(
			'Required',
		)
		expect(post).not.toHaveBeenCalled()

		await editor.vm.$emit('update:value', '{"a":1}')
		expect(wrapper.find('[data-testid="fill-error-Config"]').exists()).toBe(
			false,
		)
		await wrapper.find('[data-testid="fill-form"]').trigger('submit')
		await flush()
		expect(post).toHaveBeenCalledTimes(1)
	})
})
