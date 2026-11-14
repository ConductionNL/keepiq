/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * `src/components/settings/VaultEncryptionDetails.vue` as rendered: the
 * protection lines come from the crypto code's own CRYPTO_PARAMETERS (and
 * follow a changed parameter), and the vault certificate's facts come from
 * the server-parsed inventory row of the user's suite. The certificate
 * store's fetch is faked; its state is real.
 *
 * @spec openspec/changes/crypto-vault-encryption-details/specs/vault-encryption-details/spec.md#requirement-the-user-sees-what-protects-the-vault
 */

import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import VaultEncryptionDetails from '../../src/components/settings/VaultEncryptionDetails.vue'
import { CRYPTO_PARAMETERS } from '../../src/crypto/parameters.js'
import { useCertificateStore } from '../../src/store/modules/certificate.js'

let store

/**
 * Mount the overview for a suite.
 *
 * @param {object} [props] Component props.
 * @return {Promise<object>} The wrapper.
 */
async function mountDetails(props = { suiteId: 'v1' }) {
	const wrapper = mount(VaultEncryptionDetails, { props })
	await flushPromises()
	return wrapper
}

/**
 * The text of a protection line.
 *
 * @param {object} wrapper The wrapper.
 * @param {string} id The line id.
 * @return {string} its definition text.
 */
function line(wrapper, id) {
	const dt = wrapper.find(`[data-testid="vault-encryption-${id}"]`)
	return dt.element.nextElementSibling.textContent.replace(/\s+/g, ' ').trim()
}

describe('VaultEncryptionDetails', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		store = useCertificateStore()
		store.fetchInventory = vi.fn()
		store.inventory = null
		store.loading = false
	})

	it('names the algorithms, key sizes and iteration counts of the code', async () => {
		const wrapper = await mountDetails()

		expect(line(wrapper, 'secret-values')).toContain('4096-bit key pair')
		expect(line(wrapper, 'secret-values')).toContain(
			'RSA-OAEP, SHA-256, 4096-bit keys',
		)
		expect(line(wrapper, 'private-key')).toContain(
			'AES-256-GCM, PBKDF2-SHA256, 600,000 iterations',
		)
		expect(line(wrapper, 'attachments')).toContain(
			'AES-256-GCM per file, key wrapped with RSA-OAEP 4096-bit',
		)
		expect(line(wrapper, 'password-protected')).toContain('64 MiB of memory')
		expect(line(wrapper, 'password-protected')).toContain(
			'Argon2id (memory 64 MiB, 3 iterations, parallelism 1), AES-256-GCM',
		)
	})

	it('follows a changed parameter without other edits', async () => {
		const wrapper = await mountDetails({
			suiteId: 'v1',
			parameters: {
				...CRYPTO_PARAMETERS,
				privateKey: { ...CRYPTO_PARAMETERS.privateKey, iterations: 1200000 },
			},
		})

		expect(line(wrapper, 'private-key')).toContain('1,200,000 iterations')
	})

	it('loads the inventory once when it is not there yet', async () => {
		await mountDetails()
		expect(store.fetchInventory).toHaveBeenCalledTimes(1)
	})

	it('does not load the inventory without a suite or when it is already loaded', async () => {
		await mountDetails({ suiteId: null })
		store.inventory = { stored: [], suites: [], ca: [] }
		await mountDetails()
		expect(store.fetchInventory).not.toHaveBeenCalled()
	})

	it("shows the user's suite certificate facts from the inventory", async () => {
		store.inventory = {
			stored: [],
			ca: [],
			suites: [
				{ id: 'other', metadata: { keyType: 'EC', keyBits: 256 } },
				{
					id: 'v1',
					metadata: {
						keyType: 'RSA',
						keyBits: 4096,
						signatureAlgorithm: 'sha256WithRSAEncryption',
						fingerprintSha256: 'sha256:AB:CD',
						issuer: 'CN=Keepiq Intermediate',
						notBefore: '2026-01-01T00:00:00+00:00',
						notAfter: '2027-01-01T00:00:00+00:00',
					},
				},
			],
		}

		const wrapper = await mountDetails()

		const facts = wrapper
			.find('[data-testid="vault-encryption-certificate"]')
			.findAll('dd')
			.map((dd) => dd.text())
		expect(facts.slice(0, 4)).toEqual([
			'RSA, 4096-bit',
			'sha256WithRSAEncryption',
			'sha256:AB:CD',
			'CN=Keepiq Intermediate',
		])
		expect(facts[4]).toContain(' to ')
		expect(facts[4]).not.toBe('—')
	})

	it('shows dashes for facts the server could not read', async () => {
		store.inventory = { stored: [], ca: [], suites: [{ id: 'v1', metadata: {} }] }

		const wrapper = await mountDetails()

		const facts = wrapper
			.find('[data-testid="vault-encryption-certificate"]')
			.findAll('dd')
			.map((dd) => dd.text())
		expect(facts).toEqual(['—', '—', '—', '—', '—'])
	})

	it('says when no certificate details are available', async () => {
		store.inventory = { stored: [], ca: [], suites: [] }

		const wrapper = await mountDetails()

		expect(wrapper.find('[data-testid="vault-encryption-certificate"]').exists()).toBe(false)
		expect(wrapper.text()).toContain(
			'No certificate details are available for this vault.',
		)
	})
})
