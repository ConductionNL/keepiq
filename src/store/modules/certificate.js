import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Certificate lifecycle store (certificate-lifecycle §5): inventory,
 * client-parsed metadata submission, renewal checklist, and suite
 * re-issue. Decrypted PEM never leaves the browser — only the parsed
 * non-secret display fields are submitted.
 */
import { defineStore } from 'pinia'
import { parseCertificatePem } from '../../certificates/x509.js'
import { useSecretStore } from './secret.js'

export const useCertificateStore = defineStore('certificate', {
	state: () => ({
		/** @type {object|null} {stored, suites, ca} inventory groups. */
		inventory: null,
		/** @type {boolean} Whether a request is in flight. */
		loading: false,
		/** @type {string|null} Last error message. */
		error: null,
	}),

	actions: {
		/**
		 * Load the certificate inventory.
		 *
		 * @return {Promise<object|null>} The inventory groups.
		 * @spec openspec/specs/certificate-lifecycle/spec.md#requirement-certificate-inventory-across-all-three-sources
		 */
		async fetchInventory() {
			this.loading = true
			this.error = null
			try {
				const response = await axios.get(
					generateUrl('/apps/keepiq/api/v1/certificates/inventory'),
				)
				this.inventory = response.data
				return this.inventory
			} catch (e) {
				this.error = e?.response?.data?.message || e?.message
				return null
			} finally {
				this.loading = false
			}
		},

		/**
		 * Decrypt a stored certificate secret in the browser, parse its
		 * X.509 display fields, and submit them as inventory metadata.
		 * The PEM itself is never sent.
		 *
		 * @param {string} secretId The secret ID.
		 * @return {Promise<object|null>} The stored metadata row.
		 * @spec openspec/specs/certificate-lifecycle/spec.md#requirement-metadata-parsing-split-by-pem-readability
		 */
		async parseAndSubmit(secretId) {
			this.error = null
			const secretStore = useSecretStore()
			const secret = await secretStore.fetchSecret(secretId)
			const parsed = await parseCertificatePem(secret?.key || '')
			if (!parsed) {
				this.error = t(
					'keepiq',
					'The secret value is not a parseable PEM certificate.',
				)
				return null
			}
			const response = await axios.put(
				generateUrl(`/apps/keepiq/api/v1/certificates/${secretId}/metadata`),
				parsed,
			)
			await this.fetchInventory()
			return response.data
		},

		/**
		 * Fetch the guided renewal checklist for a stored certificate.
		 *
		 * @param {string} secretId The secret ID.
		 * @return {Promise<object|null>} {renewable, reason, steps}.
		 * @spec openspec/specs/certificate-lifecycle/spec.md#requirement-guided-renewal-by-certificate-origin
		 */
		async renewalChecklist(secretId) {
			this.error = null
			try {
				const response = await axios.post(
					generateUrl(
						`/apps/keepiq/api/v1/certificates/${secretId}/renewal-checklist`,
					),
				)
				return response.data
			} catch (e) {
				this.error = e?.response?.data?.message || e?.message
				return null
			}
		},

		/**
		 * Re-issue a suite certificate from the private CA.
		 *
		 * @param {string} suiteId The suite ID.
		 * @return {Promise<object|null>} The refreshed suite row.
		 * @spec openspec/specs/certificate-lifecycle/spec.md#requirement-guided-renewal-by-certificate-origin
		 */
		async reissueSuite(suiteId) {
			this.error = null
			try {
				const response = await axios.post(
					generateUrl(
						`/apps/keepiq/api/v1/certificates/suites/${suiteId}/reissue`,
					),
				)
				await this.fetchInventory()
				return response.data
			} catch (e) {
				this.error = e?.response?.data?.message || e?.message
				return null
			}
		},
	},
})
