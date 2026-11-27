/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * An announced partner root renewal (keepiq#1283): a renewal the server
 * verified is shown on the partner row with the pinned and renewed
 * fingerprints and the note that the previous root signed the new one, and
 * "Accept renewed certificate" asks for the password before the server
 * re-pins. A refusal shows why and keeps the offer.
 *
 * @spec openspec/changes/partner-root-renewal-announcement/specs/federated-sharing/spec.md#requirement-an-announced-renewal-is-offered-to-administrators-after-its-signature-checks-out
 */

import axios from '@nextcloud/axios'
import { confirmPassword } from '@nextcloud/password-confirmation'
import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
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
	pendingRenewal: {
		fingerprint: RENEWED,
		verifiedAt: '2026-11-25T10:00:00+00:00',
		windowEndsAt: '2026-12-24T10:00:00+00:00',
	},
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
 * Mount the section over the given partner rows, served one list per load.
 *
 * @param {Array<Array<object>>} lists The partner list each load answers.
 * @return {Promise<{wrapper: object, get: object}>}
 */
async function mountWith(...lists) {
	const get = vi.spyOn(axios, 'get')
	for (const partners of lists) {
		get.mockResolvedValueOnce({ data: { supported: true, partners } })
	}
	const wrapper = mount(FederationPartnersSection, {
		attachTo: document.body,
		global: { stubs },
	})
	await flush()
	return { wrapper, get }
}

/**
 * @param {object} wrapper The mounted section.
 * @return {boolean} Whether a renewal is offered.
 */
function offered(wrapper) {
	return wrapper.find('[data-testid="federation-renewal"]').exists()
}

describe('an announced partner root renewal', () => {
	beforeEach(() => {
		vi.restoreAllMocks()
		confirmPassword.mockReset()
	})

	afterEach(() => {
		window.location.hash = ''
		document.body.innerHTML = ''
	})

	it('shows an offered renewal with both fingerprints and the note that the previous root signed it', async () => {
		const { wrapper } = await mountWith([PARTNER])

		expect(offered(wrapper)).toBe(true)
		expect(
			wrapper.find('[data-testid="federation-renewal-pinned"]').text(),
		).toMatch(/^AB:AB:/)
		expect(
			wrapper.find('[data-testid="federation-renewal-new"]').text(),
		).toMatch(/^CD:CD:/)
		expect(wrapper.find('[data-testid="federation-renewal"]').text()).toContain(
			'its previous root signed the new one',
		)
		expect(
			wrapper.find('[data-testid="federation-renewal-accept"]').text(),
		).toBe('Accept renewed certificate')
	})

	it('says until when the offer stands and that accepting replaces the trusted root', async () => {
		const { wrapper } = await mountWith([PARTNER])

		expect(
			wrapper.find('[data-testid="federation-renewal-deadline"]').text(),
		).toBe(
			'Accept before '
				+ new Date('2026-12-24T10:00:00+00:00').toLocaleString(),
		)
		expect(
			wrapper.find('[data-testid="federation-renewal-trust"]').text(),
		).toContain(
			'Accepting replaces the root this server trusts for https://cloud.partner.example.',
		)
	})

	it('offers nothing for a partner without an offered renewal', async () => {
		const { wrapper } = await mountWith([{ ...PARTNER, pendingRenewal: null }])

		expect(offered(wrapper)).toBe(false)
		expect(wrapper.find('[data-testid="federation-repin"]').exists()).toBe(true)
	})

	it('accepts the renewed certificate after the password, and the offer is gone once re-pinned', async () => {
		const { wrapper, get } = await mountWith(
			[PARTNER],
			[{ ...PARTNER, rootFingerprint: RENEWED, pendingRenewal: null }],
		)
		const put = vi.spyOn(axios, 'put').mockResolvedValue({
			data: {
				partner: { ...PARTNER, rootFingerprint: RENEWED },
				changed: true,
			},
		})

		await wrapper
			.find('[data-testid="federation-renewal-accept"]')
			.trigger('click')
		await flush()

		expect(confirmPassword).toHaveBeenCalledTimes(1)
		expect(put).toHaveBeenCalledWith(
			'/apps/keepiq/api/v1/federation/partners/p%201/root',
			{ fingerprint: RENEWED, acceptRenewal: true },
		)
		expect(confirmPassword.mock.invocationCallOrder[0]).toBeLessThan(
			put.mock.invocationCallOrder[0],
		)
		expect(get).toHaveBeenCalledTimes(2)
		expect(offered(wrapper)).toBe(false)
		expect(wrapper.vm.error).toBe(null)
		expect(wrapper.find('[role="status"]').text()).toBe(
			'Root re-pinned for https://cloud.partner.example',
		)
		expect(document.activeElement).toBe(
			wrapper.find('[data-testid="federation-repin"]').element,
		)
	})

	it('shows why a refused acceptance was refused and the offer the server has now', async () => {
		const refusal = 'The partner no longer announces this renewed certificate'
		const { wrapper, get } = await mountWith(
			[PARTNER],
			[{ ...PARTNER, pendingRenewal: null }],
		)
		vi.spyOn(axios, 'put').mockRejectedValue({
			response: { status: 400, data: { message: refusal } },
		})

		await wrapper
			.find('[data-testid="federation-renewal-accept"]')
			.trigger('click')
		await flush()

		expect(wrapper.vm.error).toBe(refusal)
		expect(get).toHaveBeenCalledTimes(2)
		expect(offered(wrapper)).toBe(false)
		expect(wrapper.vm.busy).toBe(false)
	})

	it('keeps the offer when the partner could not be reached', async () => {
		const refusal = 'The partner could not be reached'
		const { wrapper } = await mountWith([PARTNER], [PARTNER])
		vi.spyOn(axios, 'put').mockRejectedValue({
			response: { status: 400, data: { message: refusal } },
		})

		await wrapper
			.find('[data-testid="federation-renewal-accept"]')
			.trigger('click')
		await flush()

		expect(wrapper.vm.error).toBe(refusal)
		expect(offered(wrapper)).toBe(true)
		expect(wrapper.find('[role="status"]').text()).toBe('')
		expect(document.activeElement).toBe(
			wrapper.find('[data-testid="federation-renewal-accept"]').element,
		)
	})

	it('sends nothing when the password is not confirmed', async () => {
		const { wrapper, get } = await mountWith([PARTNER])
		const put = vi.spyOn(axios, 'put')
		confirmPassword.mockRejectedValue(
			new Error('Password confirmation cancelled'),
		)

		await wrapper
			.find('[data-testid="federation-renewal-accept"]')
			.trigger('click')
		await flush()

		expect(put).not.toHaveBeenCalled()
		expect(wrapper.vm.error).toBe('Password confirmation cancelled')
		expect(get).toHaveBeenCalledTimes(1)
	})

	it('scrolls to the partner a notification links to and focuses its Accept', async () => {
		window.location.hash = '#federation-partner-p%201'
		const original = Element.prototype.scrollIntoView
		const scroll = vi.fn()
		Element.prototype.scrollIntoView = scroll
		try {
			const { wrapper } = await mountWith([PARTNER])
			await flush()

			expect(scroll).toHaveBeenCalledTimes(1)
			expect(scroll.mock.contexts[0].id).toBe('federation-partner-p 1')
			expect(document.activeElement).toBe(
				wrapper.find('[data-testid="federation-renewal-accept"]').element,
			)
		} finally {
			Element.prototype.scrollIntoView = original
		}
	})

	it('focuses Re-pin root when the linked offer is gone', async () => {
		window.location.hash = '#federation-partner-p%201'
		const { wrapper } = await mountWith([{ ...PARTNER, pendingRenewal: null }])
		await flush()

		expect(document.activeElement).toBe(
			wrapper.find('[data-testid="federation-repin"]').element,
		)
	})

	it('ignores a malformed link', async () => {
		window.location.hash = '#federation-partner-%E0%A4%A'
		const { wrapper } = await mountWith([PARTNER])
		await flush()

		await expect(
			Promise.resolve().then(() => wrapper.vm.revealLinkedPartner()),
		).resolves.toBeUndefined()
		expect(offered(wrapper)).toBe(true)
		expect(document.activeElement).toBe(document.body)
	})
})
