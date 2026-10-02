/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Organisation account recovery in the web app
 * (crypto-organisation-account-recovery): the user's enrolment, the
 * officer's key, approvals and handoff, and the lock-screen recovery.
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { defineStore } from 'pinia'
import { certificateFingerprint, chainsTo } from '../../certificates/x509.js'
import {
	buildEnrolmentEnvelope,
	createRecoveryKey,
	generateRequestKeyPair,
	openHandoff,
	sealHandoff,
} from '../../crypto/accountRecovery.js'
import { decryptPrivateKey, encryptPrivateKey } from '../../crypto/aes.js'
import { toBase64 } from '../../crypto/deviceApproval.js'
import { buildKeyProofHeaders, PROOF_PURPOSE } from '../../crypto/keyProof.js'
import {
	verificationPhrase,
	verificationPhraseFromBase64,
} from '../../crypto/verificationPhrase.js'
import { useSessionStore } from './session.js'

const API = '/apps/keepiq/api/v1/recovery'

/**
 * Where the request's one-time key waits between filing and completion: in
 * this browser's IndexedDB, as a non-extractable CryptoKey (D3). Replaceable
 * for tests.
 */
export const requestKeyStore = {
	/**
	 * @return {Promise<IDBDatabase>}
	 */
	open() {
		return new Promise((resolve, reject) => {
			const request = indexedDB.open('keepiq-account-recovery', 1)
			request.onupgradeneeded = () =>
				request.result.createObjectStore('requests')
			request.onsuccess = () => resolve(request.result)
			request.onerror = () => reject(request.error)
		})
	},

	/**
	 * @param {string} id The request id.
	 * @param {object} value { privateKey, publicKeyRaw }.
	 * @return {Promise<void>}
	 */
	async put(id, value) {
		const db = await this.open()
		await new Promise((resolve, reject) => {
			const tx = db.transaction('requests', 'readwrite')
			tx.objectStore('requests').put(value, id)
			tx.oncomplete = resolve
			tx.onerror = () => reject(tx.error)
		})
	},

	/**
	 * @param {string} id The request id.
	 * @return {Promise<object|undefined>}
	 */
	async get(id) {
		const db = await this.open()
		return new Promise((resolve, reject) => {
			const request = db
				.transaction('requests')
				.objectStore('requests')
				.get(id)
			request.onsuccess = () => resolve(request.result)
			request.onerror = () => reject(request.error)
		})
	},

	/**
	 * @param {string} id The request id.
	 * @return {Promise<void>}
	 */
	async delete(id) {
		const db = await this.open()
		await new Promise((resolve) => {
			const tx = db.transaction('requests', 'readwrite')
			tx.objectStore('requests').delete(id)
			tx.oncomplete = resolve
			tx.onerror = resolve
		})
	},
}

export const useAccountRecoveryStore = defineStore('accountRecovery', {
	state: () => ({
		/** { policy, enrolled, current, key } */
		status: null,
		/** The officer view, or { officer: false }. */
		officer: null,
		/** The user's own latest request, with its phrase. */
		myRequest: null,
	}),

	actions: {
		/**
		 * Load the user's enrolment status.
		 *
		 * @return {Promise<object>}
		 * @spec openspec/changes/crypto-organisation-account-recovery/specs/organisation-account-recovery/spec.md#requirement-users-enrol-by-wrapping-their-own-key-to-the-recovery-certificate
		 */
		async fetchStatus() {
			const response = await axios.get(generateUrl(`${API}/enrolment`))
			this.status = response.data
			return this.status
		},

		/**
		 * Check the active recovery certificate: it must chain to the
		 * instance CA, and its fingerprint must be the one the server shows.
		 *
		 * @param {object} key { certificate, fingerprint, caChain }.
		 * @return {Promise<string>} The fingerprint to show the user.
		 * @spec openspec/changes/crypto-organisation-account-recovery/specs/organisation-account-recovery/spec.md#requirement-users-enrol-by-wrapping-their-own-key-to-the-recovery-certificate
		 */
		async verifyKey(key) {
			if (!key || !(await chainsTo(key.certificate, key.caChain ?? []))) {
				throw new Error(
					t(
						'keepiq',
						'The recovery certificate is not issued by this Keepiq. Do not enrol and tell your administrator.',
					),
				)
			}
			const fingerprint = await certificateFingerprint(key.certificate)
			if (fingerprint !== key.fingerprint) {
				throw new Error(
					t(
						'keepiq',
						'The recovery certificate is not issued by this Keepiq. Do not enrol and tell your administrator.',
					),
				)
			}
			return fingerprint
		},

		/**
		 * Enrol: wrap this user's private key to the recovery certificate in
		 * this browser and send only the envelope.
		 *
		 * @param {string} masterPassword The master password, typed just now.
		 * @return {Promise<object>} The new status.
		 * @spec openspec/changes/crypto-organisation-account-recovery/specs/organisation-account-recovery/spec.md#requirement-users-enrol-by-wrapping-their-own-key-to-the-recovery-certificate
		 */
		async enrol(masterPassword) {
			const status = this.status ?? (await this.fetchStatus())
			await this.verifyKey(status.key)
			const session = useSessionStore()
			const pem = await decryptPrivateKey(
				session.encryptedPrivateKey,
				masterPassword,
			)
			const envelope = await buildEnrolmentEnvelope(
				pem,
				status.key.certificate,
			)
			const response = await axios.put(generateUrl(`${API}/enrolment`), {
				recoveryKeyId: status.key.id,
				envelope,
			})
			this.status = response.data
			return this.status
		},

		/**
		 * At unlock: enrol under the required policy, and re-enrol whenever an
		 * enrolment no longer matches the active key or suite (a key rotation
		 * or a suite rotation, D7). Best effort: never blocks the unlock.
		 *
		 * @param {string} masterPassword The master password just used to unlock.
		 * @return {Promise<string|null>} 'enrolled' when it enrolled, else null.
		 * @spec openspec/changes/crypto-organisation-account-recovery/specs/organisation-account-recovery/spec.md#requirement-enrolments-and-officer-copies-follow-the-suite
		 */
		async enrolAtUnlock(masterPassword) {
			try {
				const status = await this.fetchStatus()
				const due =
					status.key
					&& !status.current
					&& (status.policy === 'required'
						|| (status.policy === 'optional' && status.enrolled))
				if (!due) {
					return null
				}
				await this.enrol(masterPassword)
				return 'enrolled'
			} catch {
				return null
			}
		},

		/**
		 * Withdraw (refused under the required policy).
		 *
		 * @return {Promise<object>}
		 * @spec openspec/changes/crypto-organisation-account-recovery/specs/organisation-account-recovery/spec.md#requirement-users-enrol-by-wrapping-their-own-key-to-the-recovery-certificate
		 */
		async withdraw() {
			const response = await axios.delete(generateUrl(`${API}/enrolment`))
			this.status = response.data
			return this.status
		},

		/**
		 * Load the officer view and each request's phrase.
		 *
		 * @return {Promise<object>}
		 * @spec openspec/changes/crypto-organisation-account-recovery/specs/organisation-account-recovery/spec.md#requirement-recovery-needs-a-threshold-of-proven-officer-approvals
		 */
		async fetchOfficer() {
			const response = await axios.get(generateUrl(`${API}/officer`))
			const data = response.data ?? { officer: false }
			const requests = []
			for (const request of data.requests ?? []) {
				requests.push({
					...request,
					phrase: await verificationPhraseFromBase64(
						request.requestPublicKey,
					),
				})
			}
			this.officer = { ...data, requests }
			return this.officer
		},

		/**
		 * Create the recovery key: generate it here, wrap it for every
		 * officer, post the wrapped copies and the public key, keep nothing.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/crypto-organisation-account-recovery/specs/organisation-account-recovery/spec.md#requirement-the-recovery-private-key-is-generated-and-held-by-officers-only
		 */
		async createKey() {
			const officers = this.officer?.officers ?? []
			const lookup = await axios.post(
				generateUrl('/apps/keepiq/api/v1/shares/recipient-certificates'),
				{ userIds: officers },
			)
			const certificates = {}
			for (const row of lookup.data?.recipients ?? []) {
				if (!row.shareable) {
					throw new Error(
						t('keepiq', 'Officer {user} has no encryption set up yet.', {
							user: row.userId,
						}),
					)
				}
				certificates[row.userId] = row.certificate
			}
			const { publicKey, copies } = await createRecoveryKey(certificates)
			await axios.post(generateUrl(`${API}/officer/keys`), {
				publicKey,
				copies,
			})
			await this.fetchOfficer()
		},

		/**
		 * Approve a request with a vault-key proof from the master password.
		 *
		 * @param {object} request The request row.
		 * @param {string} masterPassword The officer's master password, typed just now.
		 * @return {Promise<string>} The request status afterwards.
		 * @spec openspec/changes/crypto-organisation-account-recovery/specs/organisation-account-recovery/spec.md#requirement-recovery-needs-a-threshold-of-proven-officer-approvals
		 */
		async approve(request, masterPassword) {
			const session = useSessionStore()
			const headers = await buildKeyProofHeaders({
				suiteId: session.suiteId,
				purpose: PROOF_PURPOSE.APPROVE_ACCOUNT_RECOVERY,
				encryptedPrivateKey: session.encryptedPrivateKey,
				masterPassword,
				boundValues: [request.id],
			})
			const response = await axios.post(
				generateUrl(`${API}/requests/${request.id}/approve`),
				{},
				{ headers },
			)
			await this.fetchOfficer()
			return response.data?.status
		},

		/**
		 * Decline a request.
		 *
		 * @param {string} id The request id.
		 * @return {Promise<void>}
		 * @spec openspec/changes/crypto-organisation-account-recovery/specs/organisation-account-recovery/spec.md#requirement-recovery-needs-a-threshold-of-proven-officer-approvals
		 */
		async decline(id) {
			await axios.post(generateUrl(`${API}/requests/${id}/decline`))
			await this.fetchOfficer()
		},

		/**
		 * Hand the user's key over: fetch the material, seal in this browser,
		 * post only the sealed result.
		 *
		 * @param {object} request The approved request row.
		 * @return {Promise<void>}
		 * @spec openspec/changes/crypto-organisation-account-recovery/specs/organisation-account-recovery/spec.md#requirement-the-recovered-key-reaches-only-the-requesting-browser
		 */
		async handOff(request) {
			const material = await axios.get(
				generateUrl(`${API}/requests/${request.id}/handoff`),
			)
			const sealedResult = await sealHandoff(
				material.data,
				useSessionStore().cryptoKey,
				request.id,
			)
			await axios.post(generateUrl(`${API}/requests/${request.id}/sealed`), {
				sealedResult,
			})
			await this.fetchOfficer()
		},

		/**
		 * File a request from the lock screen. The one-time private key stays
		 * in this browser's IndexedDB, bound to the request id.
		 *
		 * @return {Promise<object>} The request with its phrase.
		 * @spec openspec/changes/crypto-organisation-account-recovery/specs/organisation-account-recovery/spec.md#requirement-a-recovery-request-carries-a-one-time-key-and-a-verification-phrase
		 */
		async startRequest() {
			const pair = await generateRequestKeyPair()
			const response = await axios.post(generateUrl(`${API}/requests`), {
				publicKey: toBase64(pair.publicKeyRaw),
			})
			await requestKeyStore.put(response.data.id, pair)
			this.myRequest = {
				...response.data,
				phrase: await verificationPhrase(pair.publicKeyRaw),
			}
			return this.myRequest
		},

		/**
		 * Load the user's latest request.
		 *
		 * @return {Promise<object|null>}
		 * @spec openspec/changes/crypto-organisation-account-recovery/specs/organisation-account-recovery/spec.md#requirement-the-recovered-key-reaches-only-the-requesting-browser
		 */
		async fetchMyRequest() {
			const response = await axios.get(generateUrl(`${API}/requests/mine`))
			const request = response.data?.request ?? null
			const stored = request
				? await requestKeyStore.get(request.id)
				: undefined
			this.myRequest = request
				? {
						...request,
						fromThisBrowser: stored !== undefined,
						phrase: stored
							? await verificationPhrase(stored.publicKeyRaw)
							: null,
					}
				: null
			return this.myRequest
		},

		/**
		 * Complete: open the sealed result, wrap the private key under a new
		 * master password, replace the suite's wrapping with a proof by the
		 * recovered key, mark the request fulfilled, unlock, and forget the
		 * one-time key.
		 *
		 * @param {string} newMasterPassword The new master password.
		 * @return {Promise<string>} The officer who handled the recovery.
		 * @spec openspec/changes/crypto-organisation-account-recovery/specs/organisation-account-recovery/spec.md#requirement-the-recovered-key-reaches-only-the-requesting-browser
		 */
		async complete(newMasterPassword) {
			const request = this.myRequest
			const stored = request
				? await requestKeyStore.get(request.id)
				: undefined
			if (!request?.sealedResult || !stored) {
				throw new Error(
					t(
						'keepiq',
						'Finish the recovery in the browser you asked from.',
					),
				)
			}
			const pem = await openHandoff(
				request.sealedResult,
				stored.privateKey,
				stored.publicKeyRaw,
				request.id,
			)
			const envelope = await encryptPrivateKey(pem, newMasterPassword)
			const headers = await buildKeyProofHeaders({
				suiteId: request.suiteId,
				purpose: PROOF_PURPOSE.UPDATE_PRIVATE_KEY,
				encryptedPrivateKey: envelope,
				masterPassword: newMasterPassword,
				boundValues: [envelope],
			})
			await axios.put(
				generateUrl(
					`/apps/keepiq/api/v1/suites/${request.suiteId}/private-key`,
				),
				{ encryptedPrivateKey: envelope },
				{ headers },
			)
			const done = await axios.post(
				generateUrl(`${API}/requests/${request.id}/complete`),
			)
			await requestKeyStore.delete(request.id)
			await useSessionStore().unlock(newMasterPassword)
			this.myRequest = {
				...request,
				status: 'fulfilled',
				sealedResult: undefined,
			}
			return done.data?.handledBy ?? ''
		},
	},
})
