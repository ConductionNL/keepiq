/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Pinia store for secret version history (secret-version-history §7).
 *
 * Versions are ciphertext server-side; viewing decrypts a version's
 * blobs with the in-session CryptoKey exactly like a head read. Restore
 * decrypts the version and saves it as a share save, so the row and every
 * shared recipient receive it in one transaction (ADR-003).
 *
 * @spec openspec/specs/secret-version-history/spec.md#requirement-list-view-and-restore-versions
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { defineStore } from 'pinia'
import { rsaDecrypt } from '../../crypto/index.js'
import {
	encryptSparse,
	notifyOwnerOnly,
	plainOf,
	saveShared,
	shareSaveErrorText,
} from '../../services/shareSave.js'
import { useSecretStore } from './secret.js'
import { useSessionStore } from './session.js'

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
		 * Restore a version: its whole value is saved for the row and every
		 * holder in one share save, which records the restore. It is based on
		 * the head after any merge write-back, so that one is not mistaken for
		 * a concurrent change.
		 *
		 * @param {string} versionId The version id.
		 * @param {string} secretId The owning secret id.
		 * @return {Promise<void>}
		 * @spec openspec/changes/sharing-atomic-share-save/specs/secret-version-history/spec.md#requirement-a-restore-is-one-share-save-of-the-version
		 */
		async restore(versionId, secretId) {
			this.loading = true
			this.error = null
			try {
				const secretStore = useSecretStore()
				const baseUpdatedAt = await secretStore.settledBase(
					secretId,
					secretStore.baseOf(secretId),
				)
				const version = (
					await axios.get(
						generateUrl(`/apps/keepiq/api/v1/versions/${versionId}`),
					)
				).data
				const plain = await plainOf(version)
				const plainDiff = { ...plain, key: plain.key ?? '' }
				const result = await saveShared({
					id: secretId,
					plainDiff,
					patch: {
						name: version.name,
						url: version.url ?? null,
						...(await encryptSparse(
							plainDiff,
							useSessionStore().certificate,
						)),
					},
					baseUpdatedAt,
					// The version is the whole value; the server checks the base.
					loadFull: async () => ({
						...plainDiff,
						updatedAt: baseUpdatedAt,
					}),
					restoredFrom: versionId,
				})
				await notifyOwnerOnly(result)
				await secretStore.fetchSecret(secretId)
				await this.fetchVersions(secretId)
			} catch (e) {
				this.error =
					shareSaveErrorText(e)
					|| e?.response?.data?.message
					|| e?.message
					|| 'Restore failed'
				throw e
			} finally {
				this.loading = false
			}
		},

		/**
		 * Reset the store (secret detail unmount).
		 *
		 * @return {void}
		 *
		 * @spec exclude Store lifecycle: clears the version list and error on detail unmount.
		 */
		reset() {
			this.versions = []
			this.error = null
		},
	},
})
