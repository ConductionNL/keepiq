/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The owner resumes a suspended partner share from the sharing sidebar
 * (sharing-atomic-share-save task 3.4, keepiq#1262). The recipient's
 * certificate is verified again with the real verifier on the openssl
 * fixtures, the current value is encrypted for it, and only ciphertext is
 * sent, with the version it was encrypted from. A secret changed meanwhile
 * is read and encrypted again; a certificate that does not verify sends
 * nothing. Only a suspended row offers Resume.
 *
 * @spec openspec/specs/federated-sharing/spec.md#requirement-the-owner-resumes-a-suspended-partner-share
 */

import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import FederatedShareForm from '../../src/components/share/FederatedShareForm.vue'
import { useSecretStore } from '../../src/store/modules/secret.js'

vi.mock('@nextcloud/initial-state', () => ({
	loadState: (app, key, fallback) =>
		key === 'loginValueFields' ? { since: '0.3.12' } : fallback,
}))

const F = JSON.parse(
	readFileSync(resolve(__dirname, '../fixtures/federation-chain.json'), 'utf8'),
)
const BOB = 'bob@cloud.partner.example'
const PASSWORD = 'correct horse battery staple'
const V1 = '2026-11-25T10:00:00+00:00'
const V2 = '2026-11-25T10:05:00+00:00'

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

const VERIFIED = {
	certificate: F.bob,
	chain: [F.intermediateA, F.rootA],
	partnerRootFingerprint: F.rootAFingerprint,
}

/**
 * Wait until the check passes; WebCrypto work takes a while.
 *
 * @param {Function} check Throws until done.
 * @return {Promise<void>}
 */
function until(check) {
	return vi.waitFor(check, { timeout: 15_000, interval: 20 })
}

/**
 * Mount the form with one share of the given status. The owner's server
 * answers the lookup with `answer` and each resume with `resumeAnswers`
 * in turn (an object with `reject` throws it).
 *
 * @param {object} options The setup.
 * @param {string} [options.status] The listed share's status.
 * @param {object} [options.answer] The certificate lookup answer.
 * @param {Array<object>} [options.resumeAnswers] Answers to the resume posts.
 * @param {Array<string>} [options.versions] The `updatedAt` of each read of the secret.
 * @return {Promise<{wrapper: object, post: object, fetchSecret: object}>}
 */
async function mountWith({
	status = 'suspended',
	answer = VERIFIED,
	resumeAnswers = [
		{ data: { id: 'fs-1', recipientCloudId: BOB, status: 'active' } },
	],
	versions = [V1],
} = {}) {
	vi.spyOn(axios, 'get').mockResolvedValue({
		data: [{ id: 'fs-1', recipientCloudId: BOB, status }],
	})
	const answers = [...resumeAnswers]
	const post = vi.spyOn(axios, 'post').mockImplementation(async (url, body) => {
		if (url.includes('/federation/recipient-certificate')) {
			return { data: { cloudId: body.cloudId, ...answer } }
		}
		const next = answers.shift()
		if (next.reject) {
			throw next.reject
		}
		return next
	})
	const reads = [...versions]
	const fetchSecret = vi
		.spyOn(useSecretStore(), 'fetchSecret')
		.mockImplementation(async () => ({
			name: 'Supplier portal',
			key: PASSWORD,
			login: 'alice',
			additionalFields: { pin: '1234' },
			updatedAt: reads.length > 1 ? reads.shift() : reads[0],
		}))
	const wrapper = mount(FederatedShareForm, {
		props: { secretId: 'src' },
		global: { stubs },
	})
	await until(() => {
		if (!wrapper.find('[data-testid="federated-share-state"]').exists()) {
			throw new Error('no list yet')
		}
	})
	return { wrapper, post, fetchSecret }
}

/**
 * The resume posts.
 *
 * @param {object} post The axios.post spy.
 * @return {Array<Array>}
 */
function resumeCalls(post) {
	return post.mock.calls.filter(([url]) => url.endsWith('/resume'))
}

describe('FederatedShareForm: resume a suspended share', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
	})

	it('verifies the certificate again and sends only ciphertext with its version', async () => {
		const { wrapper, post } = await mountWith()

		await wrapper.find('[data-testid="federated-share-resume"]').trigger('click')
		await until(() => {
			expect(
				wrapper.find('[data-testid="federated-share-state"]').text(),
			).toBe('Shared')
		})

		expect(post).toHaveBeenCalledWith(
			expect.stringContaining('/federation/recipient-certificate'),
			{ cloudId: BOB },
		)
		const calls = resumeCalls(post)
		expect(calls).toHaveLength(1)
		const [url, body] = calls[0]
		expect(url).toContain('/api/v1/federated-shares/fs-1/resume')
		expect(body.sourceUpdatedAt).toBe(V1)
		expect(body.certFingerprint).toMatch(/^[0-9a-f]{64}$/)
		expect(body.key).toBeTruthy()
		expect(body).not.toHaveProperty('recipientCloudId')
		const wire = JSON.stringify(body)
		for (const plain of [PASSWORD, 'alice', '1234']) {
			expect(wire).not.toContain(plain)
		}
		expect(wrapper.find('[data-testid="federated-share-resume"]').exists()).toBe(
			false,
		)
	})

	it('reads and encrypts the secret again when it changed meanwhile', async () => {
		const stale = {
			reject: {
				response: {
					status: 409,
					data: { error: 'stale_source', sourceUpdatedAt: V2 },
				},
			},
		}
		const { wrapper, post, fetchSecret } = await mountWith({
			resumeAnswers: [
				stale,
				{ data: { id: 'fs-1', recipientCloudId: BOB, status: 'active' } },
			],
			versions: [V1, V2],
		})

		await wrapper.find('[data-testid="federated-share-resume"]').trigger('click')
		await until(() => expect(resumeCalls(post)).toHaveLength(2))
		await until(() => {
			expect(
				wrapper.find('[data-testid="federated-share-state"]').text(),
			).toBe('Shared')
		})

		expect(fetchSecret).toHaveBeenCalledTimes(2)
		expect(resumeCalls(post).map(([, body]) => body.sourceUpdatedAt)).toEqual([
			V1,
			V2,
		])
	})

	it('gives up after three stale answers and says why', async () => {
		const stale = {
			reject: {
				response: { status: 409, data: { error: 'stale_source' } },
			},
		}
		const { wrapper, post } = await mountWith({
			resumeAnswers: [stale, stale, stale],
		})

		await wrapper.find('[data-testid="federated-share-resume"]').trigger('click')
		await until(() => {
			expect(
				wrapper.find('[data-testid="federated-share-error"]').exists(),
			).toBe(true)
		})

		expect(resumeCalls(post)).toHaveLength(3)
	})

	it('sends nothing when the certificate no longer verifies', async () => {
		const { wrapper, post, fetchSecret } = await mountWith({
			answer: { ...VERIFIED, certificate: F.bobOtherRoot },
		})

		await wrapper.find('[data-testid="federated-share-resume"]').trigger('click')
		await until(() => {
			expect(
				wrapper.find('[data-testid="federated-share-error"]').exists(),
			).toBe(true)
		})

		expect(resumeCalls(post)).toHaveLength(0)
		expect(fetchSecret).not.toHaveBeenCalled()
	})

	it.each(['active', 'declined', 'failed', 'revoked'])(
		'offers no Resume for a %s share',
		async (status) => {
			const { wrapper } = await mountWith({ status })

			expect(
				wrapper.find('[data-testid="federated-share-resume"]').exists(),
			).toBe(false)
		},
	)
})
