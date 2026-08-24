/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Pinia store for secret version history (secret-version-history §7).
 *
 * Versions are ciphertext server-side; viewing decrypts a version's
 * blobs with the in-session CryptoKey exactly like a head read. Restore
 * calls the server (which snapshots the current head first), then drives
 * the existing sync-on-update fan-out so shared recipients receive the
 * restored value re-encrypted for them (ADR-003).
 *
 * @spec openspec/specs/secret-version-history/spec.md#requirement-list-view-and-restore-versions
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { defineStore } from 'pinia'
import { rsaDecrypt } from '../../crypto/index.js'
import { useSecretStore } from './secret.js'
import { useSessionStore } from './session.js'
import { useShareStore } from './share.js'

export const useSecretVersionStore = defineStore('secretVersion', {
	state: () => ({
		/** @type {Array<object>} The focused secret's versions (metadata). */
		versions: [],
		/** @type {boolean} Whether a request is in flight. */
		loading: false,
		/** @type {string|null} The last error message. */
		error: null,
	}),

	actions: {
		/**
		 * Load a secret's version list (metadata only, newest first).
		 *
		 * @param {string} secretId The secret id.
		 * @return {Promise<void>}
		 * @spec openspec/specs/secret-version-history/spec.md#requirement-list-view-and-restore-versions
		 */
		async fetchVersions(secretId) {
			this.loading = true
			this.error = null
			try {
				const response = await axios.get(
					generateUrl(`/apps/keepiq/api/v1/secrets/${secretId}/versions`),
				)
				this.versions = response.data || []
			} catch (e) {
				this.error =
					e?.response?.data?.message
					|| e?.message
					|| 'Failed to load versions'
				throw e
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fetch one version's blobs and decrypt them with the session key.
		 *
		 * @param {string} versionId The version id.
		 * @return {Promise<object>} The version with plaintext fields.
		 * @spec openspec/specs/secret-version-history/spec.md#requirement-list-view-and-restore-versions
		 */
		async viewVersion(versionId) {
			const session = useSessionStore()
			if (!session.cryptoKey) {
				throw new Error('Vault is locked')
			}
			const response = await axios.get(
				generateUrl(`/apps/keepiq/api/v1/versions/${versionId}`),
			)
			const version = { ...response.data }
			if (version.key) {
				version.key = await rsaDecrypt(version.key, session.cryptoKey)
			}
			if (version.login) {
				version.login = await rsaDecrypt(version.login, session.cryptoKey)
			}
			if (version.additionalFields) {
				try {
					version.additionalFields = JSON.parse(
						await rsaDecrypt(
							version.additionalFields,
							session.cryptoKey,
						),
					)
				} catch {
					version.additionalFields = null
				}
			}
			return version
		},

		/**
		 * Restore a version, then propagate the restored value to shared
		 * recipients via the existing sync-on-update fan-out.
		 *
		 * @param {string} versionId The version id.
		 * @param {string} secretId The owning secret id.
		 * @return {Promise<void>}
		 * @spec openspec/specs/secret-version-history/spec.md#requirement-list-view-and-restore-versions
		 * @spec openspec/specs/secret-version-history/spec.md#requirement-restores-are-auditable
		 */
		async restore(versionId, secretId) {
			this.loading = true
			this.error = null
			try {
				const response = await axios.post(
					generateUrl(`/apps/keepiq/api/v1/versions/${versionId}/restore`),
				)
				// Propagate to recipients: decrypt the restored head with the
				// session key, then re-encrypt per recipient (share fan-out).
				const secretStore = useSecretStore()
				const plain = await secretStore.fetchSecret(secretId)
				const shareStore = useShareStore()
				await shareStore.syncUpdate(
					secretId,
					{
						key: plain.key ?? '',
						login: plain.login ?? '',
						additionalFields:
							typeof plain.additionalFields === 'object'
							&& plain.additionalFields !== null
								? JSON.stringify(plain.additionalFields)
								: (plain.additionalFields ?? ''),
					},
					response.data.updatedAt,
				)
				await this.fetchVersions(secretId)
			} catch (e) {
				this.error =
					e?.response?.data?.message || e?.message || 'Restore failed'
				throw e
			} finally {
				this.loading = false
			}
		},

		/**
		 * Reset the store (secret detail unmount).
		 *
		 * @return {void}
		 */
		reset() {
			this.versions = []
			this.error = null
		},
	},
})
