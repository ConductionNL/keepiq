/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The certificate lifecycle store: the inventory request and its error
 * state, parsing a stored PEM in the browser and submitting only the display
 * fields (never the PEM), the renewal checklist and the suite re-issue.
 *
 * @spec openspec/specs/certificate-lifecycle/spec.md#requirement-metadata-parsing-split-by-pem-readability
 */

import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useCertificateStore } from '../../src/store/modules/certificate.js'
import { useSecretStore } from '../../src/store/modules/secret.js'
import chain from '../fixtures/federation-chain.json'

const INVENTORY_URL = '/apps/keepiq/api/v1/certificates/inventory'
const INVENTORY = {
	stored: [{ secretId: 's-1', subject: 'CN=a' }],
	suites: [{ suiteId: 'suite-1' }],
	ca: [],
}

/**
 * An HTTP error shaped like an axios rejection.
 *
 * @param {object|undefined} data The response body.
 * @param {string} message The error message.
 * @return {Error}
 */
function httpError(data, message = 'Request failed with status code 500') {
	return Object.assign(new Error(message), { response: { data } })
}

describe('certificate store', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
	})

	it('starts empty and idle', () => {
		const store = useCertificateStore()
		expect(store.inventory).toBeNull()
		expect(store.loading).toBe(false)
		expect(store.error).toBeNull()
	})

	describe('fetchInventory', () => {
		it('stores and returns the inventory and toggles loading', async () => {
			const store = useCertificateStore()
			let loadingDuringRequest = null
			const get = vi.spyOn(axios, 'get').mockImplementation(async () => {
				loadingDuringRequest = store.loading
				return { data: INVENTORY }
			})
			store.error = 'stale'

			const result = await store.fetchInventory()

			expect(get).toHaveBeenCalledTimes(1)
			expect(get).toHaveBeenCalledWith(INVENTORY_URL)
			expect(loadingDuringRequest).toBe(true)
			expect(result).toEqual(INVENTORY)
			expect(store.inventory).toEqual(INVENTORY)
			expect(store.loading).toBe(false)
			expect(store.error).toBeNull()
		})

		it('prefers the server message, returns null and clears loading', async () => {
			vi.spyOn(axios, 'get').mockRejectedValue(
				httpError({ message: 'Not allowed' }),
			)
			const store = useCertificateStore()

			const result = await store.fetchInventory()

			expect(result).toBeNull()
			expect(store.error).toBe('Not allowed')
			expect(store.inventory).toBeNull()
			expect(store.loading).toBe(false)
		})

		it('falls back to the error message without a server body', async () => {
			vi.spyOn(axios, 'get').mockRejectedValue(new Error('Network Error'))
			const store = useCertificateStore()

			expect(await store.fetchInventory()).toBeNull()
			expect(store.error).toBe('Network Error')
		})
	})

	describe('parseAndSubmit', () => {
		it('submits the parsed display fields, never the PEM, then reloads', async () => {
			const fetchSecret = vi
				.spyOn(useSecretStore(), 'fetchSecret')
				.mockResolvedValue({ id: 's-1', key: chain.bob })
			const put = vi
				.spyOn(axios, 'put')
				.mockResolvedValue({ data: { secretId: 's-1', stored: true } })
			const get = vi
				.spyOn(axios, 'get')
				.mockResolvedValue({ data: INVENTORY })
			const store = useCertificateStore()

			const result = await store.parseAndSubmit('s-1')

			expect(fetchSecret).toHaveBeenCalledWith('s-1')
			expect(put).toHaveBeenCalledTimes(1)
			const [url, payload] = put.mock.calls[0]
			expect(url).toBe('/apps/keepiq/api/v1/certificates/s-1/metadata')
			expect(payload).toEqual({
				subject: 'C=NL, O=Keepiq, CN=bob@cloud.partner.example',
				issuer: 'C=NL, O=Keepiq, CN=Keepiq Intermediate CA',
				serial: '4DF919AEA64486AFCCADDCA174067B1DE2039DC2',
				fingerprintSha256:
					'sha256:9e94ff76b37d6d2eac7edf7d68f047a5e28407cf300cec072331a5eafaa044da',
				notBefore: '2026-10-15T09:31:51Z',
				notAfter: '2126-09-21T09:31:51Z',
			})
			expect(JSON.stringify(payload)).not.toContain('BEGIN CERTIFICATE')
			expect(get).toHaveBeenCalledWith(INVENTORY_URL)
			expect(store.inventory).toEqual(INVENTORY)
			expect(result).toEqual({ secretId: 's-1', stored: true })
			expect(store.error).toBeNull()
		})

		it('sets an error and sends nothing for a value that is not a PEM', async () => {
			vi.spyOn(useSecretStore(), 'fetchSecret').mockResolvedValue({
				id: 's-2',
				key: 'hunter2',
			})
			const put = vi.spyOn(axios, 'put')
			const store = useCertificateStore()

			const result = await store.parseAndSubmit('s-2')

			expect(result).toBeNull()
			expect(put).not.toHaveBeenCalled()
			expect(store.error).toBe(
				'The secret value is not a parseable PEM certificate.',
			)
		})

		it('treats a secret that could not be fetched as unparseable', async () => {
			vi.spyOn(useSecretStore(), 'fetchSecret').mockResolvedValue(null)
			const put = vi.spyOn(axios, 'put')
			const store = useCertificateStore()

			expect(await store.parseAndSubmit('s-3')).toBeNull()
			expect(put).not.toHaveBeenCalled()
			expect(store.error).toBe(
				'The secret value is not a parseable PEM certificate.',
			)
		})

		it('lets a failed metadata PUT reject without reloading', async () => {
			vi.spyOn(useSecretStore(), 'fetchSecret').mockResolvedValue({
				key: chain.bob,
			})
			vi.spyOn(axios, 'put').mockRejectedValue(new Error('boom'))
			const get = vi.spyOn(axios, 'get')
			const store = useCertificateStore()

			await expect(store.parseAndSubmit('s-1')).rejects.toThrow('boom')
			expect(get).not.toHaveBeenCalled()
		})
	})

	describe('renewalChecklist', () => {
		it('posts to the checklist URL and returns the checklist', async () => {
			const checklist = {
				renewable: true,
				reason: null,
				steps: ['Generate CSR'],
			}
			const post = vi
				.spyOn(axios, 'post')
				.mockResolvedValue({ data: checklist })
			const store = useCertificateStore()
			store.error = 'stale'

			const result = await store.renewalChecklist('s-9')

			expect(post).toHaveBeenCalledWith(
				'/apps/keepiq/api/v1/certificates/s-9/renewal-checklist',
			)
			expect(result).toEqual(checklist)
			expect(store.error).toBeNull()
		})

		it('returns null and keeps the server message on failure', async () => {
			vi.spyOn(axios, 'post').mockRejectedValue(
				httpError({ message: 'Unknown certificate' }),
			)
			const store = useCertificateStore()

			expect(await store.renewalChecklist('s-9')).toBeNull()
			expect(store.error).toBe('Unknown certificate')
		})

		it('falls back to the error message without a server body', async () => {
			vi.spyOn(axios, 'post').mockRejectedValue(httpError(undefined, 'Timeout'))
			const store = useCertificateStore()

			expect(await store.renewalChecklist('s-9')).toBeNull()
			expect(store.error).toBe('Timeout')
		})
	})

	describe('reissueSuite', () => {
		it('posts to the reissue URL, reloads the inventory and returns the suite', async () => {
			const post = vi
				.spyOn(axios, 'post')
				.mockResolvedValue({ data: { id: 'suite-1', status: 'active' } })
			const get = vi
				.spyOn(axios, 'get')
				.mockResolvedValue({ data: INVENTORY })
			const store = useCertificateStore()

			const result = await store.reissueSuite('suite-1')

			expect(post).toHaveBeenCalledWith(
				'/apps/keepiq/api/v1/certificates/suites/suite-1/reissue',
			)
			expect(get).toHaveBeenCalledWith(INVENTORY_URL)
			expect(store.inventory).toEqual(INVENTORY)
			expect(result).toEqual({ id: 'suite-1', status: 'active' })
		})

		it('returns null, records the error and does not reload on failure', async () => {
			vi.spyOn(axios, 'post').mockRejectedValue(
				httpError({ message: 'CA unavailable' }),
			)
			const get = vi.spyOn(axios, 'get')
			const store = useCertificateStore()

			expect(await store.reissueSuite('suite-1')).toBeNull()
			expect(store.error).toBe('CA unavailable')
			expect(get).not.toHaveBeenCalled()
		})

		it('falls back to the error message without a server body', async () => {
			vi.spyOn(axios, 'post').mockRejectedValue(new Error('offline'))
			const store = useCertificateStore()

			expect(await store.reissueSuite('suite-1')).toBeNull()
			expect(store.error).toBe('offline')
		})
	})
})
