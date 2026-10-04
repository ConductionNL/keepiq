/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The share dialog's option for another organisation (keepiq#789,
 * sharing-federated-recipients task 2.3). It runs the real verifier on real
 * openssl certificates (tests/fixtures/generate-federation-chain.sh): a
 * chain that ends at another root, or a certificate that names someone
 * else, is refused, nothing is decrypted or encrypted, and nothing is sent.
 * A verified certificate shows its fingerprint and only ciphertext leaves.
 *
 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#scenario-a-certificate-from-another-root-is-refused
 */

import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import FederatedShareForm from '../../src/components/share/FederatedShareForm.vue'
import SecretShareDialog from '../../src/dialogs/SecretShareDialog.vue'
import { useSecretStore } from '../../src/store/modules/secret.js'

const F = JSON.parse(
	readFileSync(resolve(__dirname, '../fixtures/federation-chain.json'), 'utf8'),
)
const BOB = 'bob@cloud.partner.example'
const PASSWORD = 'correct horse battery staple'

const flush = () => new Promise((resolve) => setTimeout(resolve, 20))

const stubs = {
	NcButton: {
		props: ['disabled'],
		emits: ['click'],
		template:
			'<button v-bind="$attrs" :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
	NcTextField: {
		props: ['modelValue', 'label'],
		emits: ['update:modelValue'],
		template:
			'<input v-bind="$attrs" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
	},
	NcNoteCard: { template: '<div v-bind="$attrs"><slot /></div>' },
}

/**
 * Mount the form; the owner's server answers the lookup with `answer`.
 *
 * @param {object} answer The lookup answer, or an axios-style rejection under `reject`.
 * @return {Promise<{wrapper: object, post: object, fetchSecret: object}>}
 */
async function mountWith(answer) {
	const post = vi.spyOn(axios, 'post').mockImplementation(async (url, body) => {
		if (url.includes('/federation/recipient-certificate')) {
			if (answer.reject) {
				throw answer.reject
			}
			return { data: answer }
		}
		return {
			data: {
				id: 'fs-1',
				recipientCloudId: body.recipientCloudId,
				status: 'active',
			},
		}
	})
	const fetchSecret = vi.spyOn(useSecretStore(), 'fetchSecret').mockResolvedValue({
		name: 'Supplier portal',
		key: PASSWORD,
		login: 'alice',
		additionalFields: { pin: '1234' },
	})
	const wrapper = mount(FederatedShareForm, {
		props: { secretId: 'src' },
		global: { stubs },
	})
	await wrapper.find('[data-testid="federated-share-cloud-id"]').setValue(BOB)
	await wrapper.find('[data-testid="federated-share-check"]').trigger('click')
	await flush()
	return { wrapper, post, fetchSecret }
}

/**
 * The calls that sent a share (not the lookup).
 *
 * @param {object} post The axios.post spy.
 * @return {Array<Array>}
 */
function shareCalls(post) {
	return post.mock.calls.filter(([url]) => url.includes('/federated-shares'))
}

describe('FederatedShareForm', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
	})

	it('shows the fingerprint of a verified certificate and sends only ciphertext', async () => {
		const { wrapper, post } = await mountWith({
			cloudId: BOB,
			certificate: F.bob,
			chain: [F.intermediateA, F.rootA],
			partnerRootFingerprint: F.rootAFingerprint,
		})

		expect(post).toHaveBeenCalledWith(
			expect.stringContaining('/federation/recipient-certificate'),
			{ cloudId: BOB },
		)
		const fingerprint = wrapper
			.find('[data-testid="federated-share-fingerprint"]')
			.text()
		expect(fingerprint).toMatch(/^([0-9A-F]{2}:){31}[0-9A-F]{2}$/)

		await wrapper.find('[data-testid="federated-share-submit"]').trigger('click')
		await flush()

		const calls = shareCalls(post)
		expect(calls).toHaveLength(1)
		const [url, body] = calls[0]
		expect(url).toContain('/api/v1/secrets/src/federated-shares')
		expect(body.recipientCloudId).toBe(BOB)
		expect(body.certFingerprint).toBe(
			fingerprint.replaceAll(':', '').toLowerCase(),
		)
		// RSA-OAEP ciphertext for Bob, never the plaintext.
		expect(body.key).toBeTruthy()
		const wire = JSON.stringify(body)
		for (const plain of [PASSWORD, 'alice', '1234']) {
			expect(wire).not.toContain(plain)
		}
		expect(wrapper.find('[data-testid="federated-share-done"]').exists()).toBe(
			true,
		)
	})

	it('refuses a chain that ends at another root and encrypts nothing', async () => {
		const { wrapper, post, fetchSecret } = await mountWith({
			cloudId: BOB,
			certificate: F.bobOtherRoot,
			chain: [F.intermediateB, F.rootB],
			partnerRootFingerprint: F.rootAFingerprint,
		})

		expect(
			wrapper.find('[data-testid="federated-share-error"]').text(),
		).toContain('could not be verified')
		expect(wrapper.find('[data-testid="federated-share-submit"]').exists()).toBe(
			false,
		)
		expect(fetchSecret).not.toHaveBeenCalled()
		expect(shareCalls(post)).toHaveLength(0)
	})

	it('refuses a certificate that names someone else', async () => {
		const { wrapper, post, fetchSecret } = await mountWith({
			cloudId: BOB,
			certificate: F.mallory,
			chain: [F.intermediateA, F.rootA],
			partnerRootFingerprint: F.rootAFingerprint,
		})

		expect(
			wrapper.find('[data-testid="federated-share-error"]').text(),
		).toContain('could not be verified')
		expect(fetchSecret).not.toHaveBeenCalled()
		expect(shareCalls(post)).toHaveLength(0)
	})

	it('says when the organisation is not a partner', async () => {
		const { wrapper } = await mountWith({
			reject: { response: { data: { message: 'not_a_partner' } } },
		})

		expect(
			wrapper.find('[data-testid="federated-share-error"]').text(),
		).toContain('not one of your partners')
	})
})

describe('SecretShareDialog and another organisation', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
	})

	/**
	 * Mount the dialog with this federation status.
	 *
	 * @param {boolean} outbound Whether an outbound partner exists.
	 * @return {Promise<object>}
	 */
	async function dialog(outbound) {
		vi.spyOn(axios, 'get').mockImplementation(async (url) => ({
			data: url.includes('/federation/status') ? { outbound } : [],
		}))
		const wrapper = mount(SecretShareDialog, {
			props: { secretId: 'src' },
			global: {
				stubs: {
					...stubs,
					NcDialog: {
						template: '<div><slot /><slot name="actions" /></div>',
					},
					NcSelect: { template: '<div />' },
					NcLoadingIcon: { template: '<span />' },
					FederatedShareForm: {
						template: '<div data-testid="federated-share-form" />',
					},
				},
			},
		})
		await flush()
		return wrapper
	}

	it('offers no federated recipient without an outbound partner', async () => {
		expect(
			(await dialog(false))
				.find('[data-testid="federated-share-form"]')
				.exists(),
		).toBe(false)
	})

	it('offers it once an outbound partner exists', async () => {
		expect(
			(await dialog(true))
				.find('[data-testid="federated-share-form"]')
				.exists(),
		).toBe(true)
	})
})
