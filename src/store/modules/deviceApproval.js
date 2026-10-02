/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * New device approval (crypto-new-device-approval). Two sides in one store:
 * the locked device that asks (request, poll, unlock) and the unlocked
 * device that answers (pending list, approve, deny). The one-time private
 * key lives only in this store's memory and is dropped after use.
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { defineStore } from 'pinia'
import {
	openUnlockKey,
	sealUnlockKey,
	toBase64,
	unlockKeyFromPassword,
} from '../../crypto/deviceApproval.js'
import { generateRecipientKeyPair } from '../../crypto/hpke.js'
import { buildKeyProofHeaders, PROOF_PURPOSE } from '../../crypto/keyProof.js'
import {
	verificationPhrase,
	verificationPhraseFromBase64,
} from '../../crypto/verificationPhrase.js'
import { useSessionStore } from './session.js'

/** The header the pickup carries the request secret in. */
export const REQUEST_SECRET_HEADER = 'X-Keepiq-Request-Secret'

/**
 * A short label for this browser and system, for the approving screen.
 *
 * @return {string}
 */
function deviceLabel() {
	const agent = typeof navigator !== 'undefined' ? navigator.userAgent : ''
	const browser =
		['Firefox', 'Edg', 'Chrome', 'Safari'].find((name) => agent.includes(name))
		?? 'Browser'
	const system =
		['Windows', 'Mac OS', 'Android', 'iPhone', 'Linux'].find((name) =>
			agent.includes(name),
		) ?? ''
	const name = browser === 'Edg' ? 'Edge' : browser
	return system ? `${name} on ${system}` : name
}

export const useDeviceApprovalStore = defineStore('deviceApproval', {
	state: () => ({
		/** Whether an administrator left the feature on. */
		enabled: false,
		/** This device's open request: { id, phrase, status, expiresAt }. */
		request: null,
		/** The open requests of this user, for the approving device. */
		pending: [],
	}),

	actions: {
		/**
		 * Ask whether device approval is on.
		 *
		 * @return {Promise<boolean>}
		 * @spec openspec/changes/crypto-new-device-approval/specs/new-device-approval/spec.md#requirement-deny-expiry-audit-and-administrator-switch
		 */
		async fetchStatus() {
			try {
				const response = await axios.get(
					generateUrl('/apps/keepiq/api/v1/device-approvals/status'),
				)
				this.enabled = response.data?.enabled === true
			} catch {
				this.enabled = false
			}
			return this.enabled
		},

		/**
		 * Start a request from this locked device: a one-time key pair kept
		 * in memory, the public half sent, and the phrase to compare.
		 *
		 * @param {string} [clientKind] `web` or `extension`.
		 * @return {Promise<object>} The request as shown on this device.
		 * @spec openspec/changes/crypto-new-device-approval/specs/new-device-approval/spec.md#requirement-a-new-device-requests-approval-with-a-one-time-key
		 */
		async startRequest(clientKind = 'web') {
			const pair = await generateRecipientKeyPair()
			const response = await axios.post(
				generateUrl('/apps/keepiq/api/v1/device-approvals'),
				{
					publicKey: toBase64(pair.publicKeyRaw),
					clientKind,
					deviceLabel: deviceLabel(),
				},
			)
			// Kept outside reactive state: a CryptoKey and a secret have no
			// business in devtools snapshots.
			this._oneTime = {
				privateKey: pair.privateKey,
				publicKeyRaw: pair.publicKeyRaw,
				secret: response.data.requestSecret,
			}
			this.request = {
				id: response.data.id,
				expiresAt: response.data.expiresAt,
				phrase: await verificationPhrase(pair.publicKeyRaw),
				status: 'pending',
			}
			return this.request
		},

		/**
		 * Poll once. On approval, open the sealed key, unlock this session
		 * and drop the one-time key.
		 *
		 * @return {Promise<string>} The request status.
		 * @spec openspec/changes/crypto-new-device-approval/specs/new-device-approval/spec.md#requirement-pickup-is-one-time-and-unlocks-one-session
		 */
		async pollOnce() {
			if (!this.request || !this._oneTime) {
				return 'none'
			}
			const response = await axios.get(
				generateUrl(
					`/apps/keepiq/api/v1/device-approvals/${this.request.id}`,
				),
				{ headers: { [REQUEST_SECRET_HEADER]: this._oneTime.secret } },
			)
			const status = response.data?.status ?? 'pending'
			if (status === 'approved' && response.data?.sealedUnlockKey) {
				const raw = await openUnlockKey(
					response.data.sealedUnlockKey,
					this._oneTime.privateKey,
					this._oneTime.publicKeyRaw,
					this.request.id,
				)
				this._oneTime = null
				await useSessionStore().unlockWithRawKey(raw)
				raw.fill(0)
				this.request = { ...this.request, status: 'unlocked' }
				return 'unlocked'
			}
			if (status !== 'pending') {
				this._oneTime = null
			}
			this.request = { ...this.request, status }
			return status
		},

		/**
		 * Forget this device's request and its one-time key.
		 *
		 * @return {void}
		 * @spec openspec/changes/crypto-new-device-approval/specs/new-device-approval/spec.md#requirement-a-new-device-requests-approval-with-a-one-time-key
		 */
		cancelRequest() {
			this._oneTime = null
			this.request = null
		},

		/**
		 * Load this user's open requests, with their phrases.
		 *
		 * @return {Promise<Array<object>>}
		 * @spec openspec/changes/crypto-new-device-approval/specs/new-device-approval/spec.md#requirement-both-devices-show-the-same-verification-phrase
		 */
		async fetchPending() {
			const response = await axios.get(
				generateUrl('/apps/keepiq/api/v1/device-approvals/pending'),
			)
			const rows = Array.isArray(response.data) ? response.data : []
			const withPhrases = []
			for (const row of rows) {
				withPhrases.push({
					...row,
					phrase: await verificationPhraseFromBase64(row.requestPublicKey),
				})
			}
			this.pending = withPhrases
			return this.pending
		},

		/**
		 * Approve a request: rebuild the unlock key from the master password,
		 * seal it to the request key, and send only the sealed key with a
		 * vault-key proof bound to the request id and the sealed key.
		 *
		 * @param {object} request A pending request row.
		 * @param {string} masterPassword The master password, typed just now.
		 * @return {Promise<void>}
		 * @spec openspec/changes/crypto-new-device-approval/specs/new-device-approval/spec.md#requirement-approval-seals-the-unlock-key-and-needs-proof-of-the-master-password
		 */
		async approve(request, masterPassword) {
			const session = useSessionStore()
			const envelope = session.encryptedPrivateKey
			if (!envelope || !session.suiteId) {
				throw new Error('Vault is locked')
			}
			const raw = await unlockKeyFromPassword(masterPassword, envelope)
			let sealed
			try {
				sealed = await sealUnlockKey(
					raw,
					request.requestPublicKey,
					request.id,
				)
			} finally {
				raw.fill(0)
			}
			const headers = await buildKeyProofHeaders({
				suiteId: session.suiteId,
				purpose: PROOF_PURPOSE.APPROVE_DEVICE,
				encryptedPrivateKey: envelope,
				masterPassword,
				boundValues: [request.id, sealed],
			})
			await axios.post(
				generateUrl(
					`/apps/keepiq/api/v1/device-approvals/${request.id}/approve`,
				),
				{ sealedUnlockKey: sealed },
				{ headers },
			)
			this.pending = this.pending.filter((row) => row.id !== request.id)
		},

		/**
		 * Deny a request.
		 *
		 * @param {string} id The request id.
		 * @return {Promise<void>}
		 * @spec openspec/changes/crypto-new-device-approval/specs/new-device-approval/spec.md#requirement-deny-expiry-audit-and-administrator-switch
		 */
		async deny(id) {
			await axios.post(
				generateUrl(`/apps/keepiq/api/v1/device-approvals/${id}/deny`),
			)
			this.pending = this.pending.filter((row) => row.id !== id)
		},
	},
})
