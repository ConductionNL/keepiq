import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Passkey vault-login store (passkey-vault-login §3/§4): the WebAuthn
 * enrollment + unlock ceremonies and credential management. All key
 * material stays client-side — the server only ever receives the
 * PRF-wrapped unlock-key envelope, never the master password, the PRF
 * output, or the plaintext unlock key.
 */
import { defineStore } from 'pinia'
import { deriveUnlockKeyRaw } from '../../crypto/aes.js'
import { decodeEnvelope } from '../../crypto/envelope.js'
import {
	deriveKekFromPrf,
	fromBase64Url,
	isPrfSupported,
	toBase64Url,
	unwrapUnlockKey,
	wrapUnlockKey,
} from '../../crypto/passkey.js'
import { useSessionStore } from './session.js'

const RP_ID = window.location.hostname

export const usePasskeyStore = defineStore('passkey', {
	state: () => ({
		/** @type {Array<object>} Enrolled passkeys (management view). */
		credentials: [],
		/** @type {boolean} Whether WebAuthn is present. */
		supported: isPrfSupported(),
		/** @type {boolean} Whether the caller has at least one active passkey. */
		hasActive: false,
		/** @type {string|null} Last error. */
		error: null,
	}),

	actions: {
		/**
		 * Load the caller's enrolled passkeys.
		 *
		 * @return {Promise<void>}
		 */
		async fetchCredentials() {
			this.error = null
			try {
				const response = await axios.get(
					generateUrl('/apps/doriath/api/v1/passkeys'),
				)
				this.credentials = response.data ?? []
				this.hasActive = this.credentials.some((c) => c.status === 'active')
			} catch (e) {
				this.error = e?.response?.data?.message || e?.message
			}
		},

		/**
		 * Whether the lock screen should offer the passkey option: WebAuthn
		 * present AND the caller has an active enrolled passkey.
		 *
		 * @return {Promise<boolean>}
		 */
		async isUnlockOffered() {
			if (!this.supported) {
				return false
			}
			try {
				const response = await axios.get(
					generateUrl('/apps/doriath/api/v1/passkeys/login-options'),
				)
				return (response.data?.credentials?.length ?? 0) > 0
			} catch (e) {
				return false
			}
		},

		/**
		 * Enroll a new passkey. Requires the vault unlocked + the master
		 * password (to derive the raw unlock key that gets PRF-wrapped). Runs
		 * create() → prf.enabled probe → get() for the PRF output → wrap →
		 * POST. Throws with a clear message when the authenticator lacks PRF.
		 *
		 * @param {string} masterPassword The master password (never sent).
		 * @param {string} label A nickname for the passkey.
		 * @return {Promise<object>} The enrolled credential.
		 */
		async enroll(masterPassword, label) {
			this.error = null
			const session = useSessionStore()
			if (!session.cryptoKey || !session.encryptedPrivateKey) {
				throw new Error('Unlock your vault before enrolling a passkey')
			}

			// 1. Creation options + challenge from the server.
			const challengeResp = await axios.get(
				generateUrl('/apps/doriath/api/v1/passkeys/challenge'),
			)
			const challenge = fromBase64Url(
				challengeResp.data.challenge.replace(/\+/g, '-').replace(/\//g, '_'),
			)

			const userId = new TextEncoder().encode(
				window.OC?.getCurrentUser?.()?.uid || 'doriath-user',
			)
			const created = await navigator.credentials.create({
				publicKey: {
					rp: { id: RP_ID, name: 'Doriath' },
					user: {
						id: userId,
						name: label || 'Doriath vault',
						displayName: label || 'Doriath vault',
					},
					challenge,
					pubKeyCredParams: [
						{ type: 'public-key', alg: -7 },
						{ type: 'public-key', alg: -257 },
					],
					authenticatorSelection: {
						residentKey: 'preferred',
						userVerification: 'preferred',
					},
					extensions: { prf: {} },
				},
			})

			// 2. PRF must be enabled by this authenticator.
			const ext = created.getClientExtensionResults()
			if (!ext?.prf?.enabled) {
				throw new Error(
					'Your authenticator does not support passkey unlock (PRF).',
				)
			}

			const credentialId = toBase64Url(created.rawId)
			// 3. Fresh 32-byte PRF salt; get() to obtain the PRF output.
			const prfSaltBytes = crypto.getRandomValues(new Uint8Array(32))
			const assertion = await navigator.credentials.get({
				publicKey: {
					challenge,
					rpId: RP_ID,
					allowCredentials: [{ type: 'public-key', id: created.rawId }],
					userVerification: 'preferred',
					extensions: { prf: { eval: { first: prfSaltBytes } } },
				},
			})
			const prfOutput =
				assertion.getClientExtensionResults()?.prf?.results?.first
			if (!prfOutput) {
				throw new Error('Your authenticator did not return a PRF result.')
			}

			// 4. Derive the raw vault unlock key from the master password + the
			//    private-key envelope's own salt, then wrap it under the KEK.
			const { salt } = decodeEnvelope(session.encryptedPrivateKey)
			const rawUnlockKey = await deriveUnlockKeyRaw(masterPassword, salt)
			const kek = await deriveKekFromPrf(prfOutput, credentialId)
			const wrappedUnlockKey = await wrapUnlockKey(kek, rawUnlockKey)

			// 5. Persist the envelope (never the raw key or PRF output).
			const response = await axios.post(
				generateUrl('/apps/doriath/api/v1/passkeys'),
				{
					credentialId,
					wrappedUnlockKey,
					prfSalt: btoa(String.fromCharCode(...prfSaltBytes)),
					publicKey: '',
					label: label || 'Passkey',
					transports: (created.response?.getTransports?.() || []).join(
						',',
					),
					aaguid: '',
				},
			)
			await this.fetchCredentials()
			return response.data
		},

		/**
		 * Unlock the vault with a passkey (no master password). Runs
		 * login-options → get() with the stored salt → PRF output → KEK →
		 * unwrap the raw unlock key → hand it to the session unlock path.
		 *
		 * @return {Promise<boolean>} Whether the unlock succeeded.
		 */
		async unlockWithPasskey() {
			this.error = null
			const optResp = await axios.get(
				generateUrl('/apps/doriath/api/v1/passkeys/login-options'),
			)
			const options = optResp.data
			if (!options.credentials?.length) {
				throw new Error('No passkey enrolled for unlock')
			}

			const challenge = fromBase64Url(
				options.challenge.replace(/\+/g, '-').replace(/\//g, '_'),
			)
			const allowCredentials = options.credentials.map((c) => ({
				type: 'public-key',
				id: fromBase64Url(c.credentialId),
			}))
			// Same PRF salt the envelope was wrapped with (from the first cred
			// the authenticator satisfies — allowCredentials narrows it).
			const prfSalt = Uint8Array.from(
				atob(options.credentials[0].prfSalt),
				(ch) => ch.charCodeAt(0),
			)

			const assertion = await navigator.credentials.get({
				publicKey: {
					challenge,
					rpId: RP_ID,
					allowCredentials,
					userVerification: 'preferred',
					extensions: { prf: { eval: { first: prfSalt } } },
				},
			})
			const usedId = toBase64Url(assertion.rawId)
			const cred =
				options.credentials.find((c) => c.credentialId === usedId)
				|| options.credentials[0]
			const prfOutput =
				assertion.getClientExtensionResults()?.prf?.results?.first
			if (!prfOutput) {
				throw new Error('Passkey unlock failed — no PRF result')
			}

			const kek = await deriveKekFromPrf(prfOutput, cred.credentialId)
			const rawUnlockKey = await unwrapUnlockKey(kek, cred.wrappedUnlockKey)

			const session = useSessionStore()
			await session.unlockWithRawKey(rawUnlockKey)

			// Best-effort last-used stamp.
			axios
				.post(generateUrl(`/apps/doriath/api/v1/passkeys/${cred.id}/used`))
				.catch(() => {})
			return true
		},

		/**
		 * Revoke (delete) a passkey.
		 *
		 * @param {string} id The credential id.
		 * @return {Promise<void>}
		 */
		async revoke(id) {
			this.error = null
			try {
				await axios.delete(
					generateUrl(`/apps/doriath/api/v1/passkeys/${id}`),
				)
				this.credentials = this.credentials.filter((c) => c.id !== id)
				this.hasActive = this.credentials.some((c) => c.status === 'active')
			} catch (e) {
				this.error = e?.response?.data?.message || e?.message
			}
		},
	},
})
