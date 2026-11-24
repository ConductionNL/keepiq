/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Re-pinning a listed partner's root (keepiq#1283): the published
 * fingerprint is read again and shown next to the pinned one; it is pinned
 * only after the administrator compared it and confirmed their password, and
 * a partner that still publishes the pinned root offers no confirm.
 *
 * @spec openspec/changes/partner-share-temporary-refusals/specs/federated-sharing/spec.md#requirement-administrators-approve-and-pin-partner-instances
 */

import axios from '@nextcloud/axios'
import { confirmPassword } from '@nextcloud/password-confirmation'
import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import FederationPartnersSection from '../../src/components/settings/FederationPartnersSection.vue'

vi.mock('@nextcloud/password-confirmation', () => ({
	confirmPassword: vi.fn(async () => {}),
}))

const PINNED = 'ab'.repeat(32)
const RENEWED = 'cd'.repeat(32)
const PARTNER = {
	id: 'p 1',
	baseUrl: 'https://cloud.partner.example',
	rootFingerprint: PINNED,
	allowOutbound: true,
	allowInbound: true,
}

const stubs = {
	CnSettingsSection: { template: '<section><slot /></section>' },
	NcButton: {
		props: ['disabled'],
		emits: ['click'],
		template:
			'<button v-bind="$attrs" :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
	NcNoteCard: { template: '<div v-bind="$attrs"><slot /></div>' },
	NcTextField: {
		props: ['modelValue', 'label'],
		emits: ['update:modelValue'],
		template:
			'<input v-bind="$attrs" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
	},
	NcCheckboxRadioSwitch: {
		props: ['modelValue'],
		emits: ['update:modelValue'],
		template:
			'<label v-bind="$attrs"><input type="checkbox" :checked="modelValue" @change="$emit(\'update:modelValue\', $event.target.checked)" /><slot /></label>',
	},
}

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

/**
 * Mount the section over one partner and open its re-pin, with the partner
 * publishing the given fingerprint.
 *
 * @param {string} published The fingerprint the partner publishes now.
 * @return {Promise<{wrapper: object, post: object, get: object}>}
 */
async function openRepin(published) {
	const get = vi
		.spyOn(axios, 'get')
		.mockResolvedValue({ data: { supported: true, partners: [PARTNER] } })
	const post = vi.spyOn(axios, 'post').mockResolvedValue({
		data: {
			baseUrl: PARTNER.baseUrl,
			host: 'cloud.partner.example',
			rootFingerprint: published,
		},
	})
	const wrapper = mount(FederationPartnersSection, { global: { stubs } })
	await flush()
	await wrapper.find('[data-testid="federation-repin"]').trigger('click')
	await flush()
	return { wrapper, post, get }
}

/**
 * Tick the comparison and confirm the re-pin.
 *
 * @param {object} wrapper The mounted section.
 * @return {Promise<void>}
 */
async function compareAndConfirm(wrapper) {
	await wrapper
		.find('[data-testid="federation-repin-compared"] input')
		.setValue(true)
	await wrapper.find('[data-testid="federation-repin-confirm"]').trigger('click')
	await flush()
}

/**
 * @param {object} wrapper The mounted section.
 * @return {boolean} Whether the re-pin comparison is shown.
 */
function comparing(wrapper) {
	return wrapper.find('[data-testid="federation-repin-preview"]').exists()
}

describe('re-pinning a partner root', () => {
	beforeEach(() => {
		vi.restoreAllMocks()
		confirmPassword.mockReset()
	})

	it('shows the renewed fingerprint next to the pinned one and pins it only after the comparison and the password', async () => {
		const { wrapper, post, get } = await openRepin(RENEWED)
		const put = vi.spyOn(axios, 'put').mockResolvedValue({
			data: {
				partner: { ...PARTNER, rootFingerprint: RENEWED },
				changed: true,
			},
		})

		expect(post).toHaveBeenCalledWith(
			'/apps/keepiq/api/v1/federation/partners/preview',
			{ url: PARTNER.baseUrl },
		)
		expect(
			wrapper.find('[data-testid="federation-repin-pinned"]').text(),
		).toMatch(/^AB:AB:/)
		expect(
			wrapper.find('[data-testid="federation-repin-published"]').text(),
		).toMatch(/^CD:CD:/)
		expect(
			wrapper
				.find('[data-testid="federation-repin-confirm"]')
				.attributes('disabled'),
		).toBeDefined()

		await compareAndConfirm(wrapper)

		expect(confirmPassword).toHaveBeenCalledTimes(1)
		expect(put).toHaveBeenCalledWith(
			'/apps/keepiq/api/v1/federation/partners/p%201/root',
			{ fingerprint: RENEWED },
		)
		expect(confirmPassword.mock.invocationCallOrder[0]).toBeLessThan(
			put.mock.invocationCallOrder[0],
		)
		expect(get).toHaveBeenCalledTimes(2)
		expect(comparing(wrapper)).toBe(false)
	})

	it('says the partner still publishes the pinned root and offers no confirm', async () => {
		const { wrapper } = await openRepin(PINNED)
		const put = vi.spyOn(axios, 'put')

		expect(
			wrapper.find('[data-testid="federation-repin-unchanged"]').text(),
		).toContain('nothing to re-pin')
		expect(
			wrapper.find('[data-testid="federation-repin-confirm"]').exists(),
		).toBe(false)
		await wrapper.vm.confirmRepin()
		expect(put).not.toHaveBeenCalled()
		expect(confirmPassword).not.toHaveBeenCalled()
	})

	it('keeps the comparison open and shows why when the re-pin is refused', async () => {
		const refusal = 'The partner root fingerprint is not the one you confirmed'
		const { wrapper } = await openRepin(RENEWED)
		vi.spyOn(axios, 'put').mockRejectedValue({
			response: { status: 400, data: { message: refusal } },
		})

		await compareAndConfirm(wrapper)

		expect(wrapper.vm.error).toBe(refusal)
		expect(comparing(wrapper)).toBe(true)
	})

	it('sends nothing when the password is not confirmed', async () => {
		const { wrapper } = await openRepin(RENEWED)
		const put = vi.spyOn(axios, 'put')
		confirmPassword.mockRejectedValue(
			new Error('Password confirmation cancelled'),
		)

		await compareAndConfirm(wrapper)

		expect(put).not.toHaveBeenCalled()
		expect(wrapper.vm.error).toBe('Password confirmation cancelled')
	})

	it('shows why the partner could not be read and offers no comparison', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: { supported: true, partners: [PARTNER] },
		})
		vi.spyOn(axios, 'post').mockRejectedValue({
			response: {
				status: 400,
				data: { message: 'The partner could not be reached' },
			},
		})
		const wrapper = mount(FederationPartnersSection, { global: { stubs } })
		await flush()
		await wrapper.find('[data-testid="federation-repin"]').trigger('click')
		await flush()

		expect(wrapper.vm.error).toBe('The partner could not be reached')
		expect(comparing(wrapper)).toBe(false)
	})
})
