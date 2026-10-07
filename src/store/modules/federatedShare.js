// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { defineStore } from 'pinia'
import {
	FederatedCertificateError,
	verifyFederatedCertificate,
} from '../../crypto/federatedCertificate.js'
import { PROOF_PURPOSE, sessionKeyProofHeaders } from '../../crypto/keyProof.js'
import { useShareStore } from './share.js'

/**
 * The fields of a secret that are encrypted for a recipient; name and URL
 * travel in the clear, as for local shares.
 *
 * @type {string[]}
 */
const ENCRYPTED_FIELDS = ['key', 'login', 'additionalFields']

/**
 * The colon-separated, uppercase fingerprint the verifier shows, as the
 * lowercase hex the server stores.
 *
 * @param {string} fingerprint `AB:CD:...`
 * @return {string}
 * @spec openspec/specs/federated-sharing/spec.md#requirement-certificate-lookup-is-signed-allowlisted-and-verified-in-the-browser
 */
export function fingerprintHex(fingerprint) {
	return String(fingerprint).replaceAll(':', '').toLowerCase()
}

/**
 * The fields of a decrypted secret that are encrypted for a recipient, as
 * strings.
 *
 * @param {object} secret The decrypted secret.
 * @return {object}
 * @spec openspec/specs/federated-sharing/spec.md#requirement-federated-shares-carry-only-browser-made-ciphertext
 */
function snapshotOf(secret) {
	const snapshot = {}
	for (const field of ENCRYPTED_FIELDS) {
		const value = secret[field]
		if (value !== null && value !== undefined && value !== '') {
			snapshot[field] =
				typeof value === 'string' ? value : JSON.stringify(value)
		}
	}
	return snapshot
}

/**
 * Sharing a secret with a user of a partner organisation
 * (sharing-federated-recipients D3, D4). The owner's server fetches the
 * recipient's certificate from the partner; this browser verifies it
 * against the pinned partner root and the cloud id, and only then encrypts.
 * Only ciphertext goes to the server.
 */
export const useFederatedShareStore = defineStore('federatedShare', {
	state: () => ({
		/** @type {boolean|null} Whether any outbound partner exists; null until asked. */
		available: null,
		/** @type {Array<object>} The federated shares of the secret last listed. */
		shares: [],
	}),

	actions: {
		/**
		 * Ask whether the dialog may offer another organisation at all.
		 *
		 * @return {Promise<boolean>}
		 * @spec openspec/specs/federated-sharing/spec.md#scenario-no-partner-no-federation
		 */
		async checkAvailable() {
			try {
				const response = await axios.get(
					generateUrl('/apps/keepiq/api/v1/federation/status'),
				)
				this.available = response.data?.outbound === true
			} catch {
				this.available = false
			}
			return this.available
		},

		/**
		 * Fetch and verify a federated recipient's certificate. Rejects with a
		 * FederatedCertificateError when it does not chain to the pinned
		 * partner root or names someone else, or with the server's refusal.
		 *
		 * @param {string} cloudId What the owner typed.
		 * @return {Promise<{cloudId: string, certificate: string, fingerprint: string}>}
		 * @spec openspec/specs/federated-sharing/spec.md#scenario-a-verified-remote-certificate
		 */
		async lookup(cloudId) {
			const response = await axios.post(
				generateUrl('/apps/keepiq/api/v1/federation/recipient-certificate'),
				{ cloudId: cloudId.trim() },
			)
			const answer = response.data
			const { fingerprint } = await verifyFederatedCertificate({
				certificate: answer.certificate,
				chain: answer.chain,
				partnerRootFingerprint: answer.partnerRootFingerprint,
				cloudId: answer.cloudId,
			})
			return {
				cloudId: answer.cloudId,
				certificate: answer.certificate,
				fingerprint,
			}
		},

		/**
		 * The federated shares of one of the user's secrets, with their state.
		 *
		 * @param {string} secretId The owner's secret.
		 * @return {Promise<Array<object>>}
		 * @spec openspec/specs/federated-sharing/spec.md#requirement-owner-updates-reach-the-remote-copy-and-revocation-removes-it
		 */
		async listFor(secretId) {
			const response = await axios.get(
				generateUrl(
					`/apps/keepiq/api/v1/secrets/${secretId}/federated-shares`,
				),
			)
			this.shares = Array.isArray(response.data) ? response.data : []
			return this.shares
		},

		/**
		 * Revoke a federated share; the recipient's instance deletes its copy.
		 *
		 * @param {string} id The federated share.
		 * @return {Promise<void>}
		 * @spec openspec/specs/federated-sharing/spec.md#scenario-revocation-removes-bobs-copy
		 */
		async revoke(id) {
			await axios.delete(
				generateUrl(`/apps/keepiq/api/v1/federated-shares/${id}`),
			)
			this.shares = this.shares.filter((share) => share.id !== id)
		},

		/**
		 * After the owner changed a secret: encrypt the whole new value again for
		 * every live federated recipient, each with a certificate fetched and
		 * verified now, and send it. A recipient whose certificate no longer
		 * verifies, or whom the partner no longer knows, is suspended instead.
		 *
		 * @param {string} secretId The owner's secret.
		 * @return {Promise<{updated: number, suspended: number}>}
		 * @spec openspec/specs/federated-sharing/spec.md#scenario-a-password-change-reaches-bob
		 */
		async syncUpdate(secretId) {
			const live = (await this.listFor(secretId)).filter(
				(share) => share.status === 'active',
			)
			const result = { updated: 0, suspended: 0 }
			if (live.length === 0) {
				return result
			}
			const { useSecretStore } = await import('./secret.js')
			const secret = await useSecretStore().fetchSecret(secretId)
			for (const share of live) {
				let recipient
				try {
					recipient = await this.lookup(share.recipientCloudId)
				} catch (e) {
					const reason =
						e instanceof FederatedCertificateError
							? e.reason
							: e?.response?.data?.message
					if (
						e instanceof FederatedCertificateError
						|| reason === 'unknown_recipient'
						|| reason === 'not_a_partner'
					) {
						await axios.post(
							generateUrl(
								`/apps/keepiq/api/v1/federated-shares/${share.id}/suspend`,
							),
							{ reason },
						)
						result.suspended++
					}
					continue
				}
				const encrypted = await useShareStore().encryptForRecipient(
					snapshotOf(secret),
					recipient.certificate,
				)
				await axios.put(
					generateUrl(`/apps/keepiq/api/v1/federated-shares/${share.id}`),
					{
						certFingerprint: fingerprintHex(recipient.fingerprint),
						key: encrypted.key ?? '',
						login: encrypted.login ?? null,
						additionalFields: encrypted.additionalFields ?? null,
					},
				)
				result.updated++
			}
			return result
		},

		/**
		 * Encrypt a secret for a verified recipient and send the ciphertext.
		 *
		 * @param {string} secretId The owner's secret.
		 * @param {object} secret The decrypted secret (key, login, additionalFields).
		 * @param {{cloudId: string, certificate: string, fingerprint: string}} recipient From lookup().
		 * @return {Promise<object>} The federated share.
		 * @spec openspec/specs/federated-sharing/spec.md#requirement-federated-shares-carry-only-browser-made-ciphertext
		 */
		async share(secretId, secret, recipient) {
			const encrypted = await useShareStore().encryptForRecipient(
				snapshotOf(secret),
				recipient.certificate,
			)
			const url = generateUrl(
				`/apps/keepiq/api/v1/secrets/${secretId}/federated-shares`,
			)
			const body = {
				recipientCloudId: recipient.cloudId,
				certFingerprint: fingerprintHex(recipient.fingerprint),
				key: encrypted.key ?? '',
				login: encrypted.login ?? null,
				additionalFields: encrypted.additionalFields ?? null,
			}
			try {
				return (await axios.post(url, body)).data
			} catch (refusal) {
				// Every recipient at another organisation is a new party, so the
				// server asks for the vault key proof (keepiq#818).
				if (refusal?.response?.data?.error !== 'key_proof_required') {
					throw refusal
				}
				const { headers } = await sessionKeyProofHeaders({
					purpose: PROOF_PURPOSE.SHARE_NEW_RECIPIENT,
					reason: t(
						'keepiq',
						'You are sharing with someone new. Enter your master password to confirm.',
					),
					boundValues: [secretId, recipient.cloudId],
				})
				return (await axios.post(url, body, { headers })).data
			}
		},
	},
})
