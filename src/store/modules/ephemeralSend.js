/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Pinia store for ephemeral sends (ephemeral-send §5).
 *
 * All crypto runs in the browser (ADR-003): the payload is encrypted
 * AES-256-GCM with a fresh content key; with no password the raw key
 * rides the URL fragment (never sent anywhere), with a password the key
 * is wrapped with an Argon2id-derived KEK and only the wrapped key +
 * salt reach the server.
 *
 * @spec openspec/specs/ephemeral-send/spec.md#requirement-create-a-standalone-ephemeral-send
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { defineStore } from 'pinia'
import { deriveAesKeyArgon2id } from '../../crypto/argon2.js'
import {
	aesDecrypt,
	aesEncrypt,
	fromBase64,
	fromBase64Url,
	sealPayload,
	sendLink,
	toBase64,
} from '../../send/sendCrypto.js'

/**
 * @param {string} token The URL token.
 * @param {string} action access, confirm or failure.
 * @return {string} The public send endpoint for the action.
 */
function publicSendUrl(token, action) {
	return generateUrl(
		`/apps/keepiq/api/v1/public/sends/${encodeURIComponent(token)}/${action}`,
	)
}

/**
 * The send's content key: unwrapped with a key derived from the password in
 * password mode, else the key the link's fragment carries.
 *
 * @param {object} data The access answer.
 * @param {{fragmentKey: string, password: string}} secret What the recipient holds.
 * @return {Promise<CryptoKey>}
 * @spec openspec/specs/ephemeral-send/spec.md#requirement-anonymous-recipient-access-with-no-account
 */
async function contentKeyOf(data, secret) {
	let rawKey
	if (data.hasPassword) {
		const kek = await deriveAesKeyArgon2id(
			secret.password,
			fromBase64(data.argon2idSalt),
		)
		rawKey = await aesDecrypt(kek, data.wrappedKey)
	} else {
		rawKey = fromBase64Url(secret.fragmentKey)
	}
	return crypto.subtle.importKey('raw', rawKey, { name: 'AES-GCM' }, false, [
		'decrypt',
	])
}

/**
 * The wrong-password error, with the fetches the server still allows.
 *
 * @param {object} data The served ciphertext envelope.
 * @return {Error} The wrong-password error.
 * @spec openspec/specs/ephemeral-send/spec.md#requirement-burn-after-read-and-optional-expiry
 */
function wrongPasswordError(data) {
	const err = new Error('wrong-password')
	err.attemptsLeft = data?.attemptsLeft ?? 0
	err.burned = false
	return err
}

export const useEphemeralSendStore = defineStore('ephemeralSend', {
	state: () => ({
		/** @type {Array<object>} The caller's sends (metadata only). */
		sends: [],
		/** @type {boolean} Whether a request is in flight. */
		loading: false,
		/** @type {string|null} The last error message. */
		error: null,
		/**
		 * Ciphertext already served on this page, per token. The server
		 * counts a view and an attempt for every serve, so a retried password
		 * is tried against the ciphertext in hand rather than fetched again.
		 *
		 * @type {Object<string, object>}
		 */
		served: {},
	}),

	actions: {
		/**
		 * Create a send: encrypt in the browser, POST ciphertext, and
		 * return the one-time link.
		 *
		 * @param {object} params The parameters.
		 * @param {string} params.payload The plaintext payload.
		 * @param {string} params.payloadType 'text' | 'credential'.
		 * @param {number} params.maxViews Max views (>=1).
		 * @param {number} params.ttlSeconds Optional TTL (0 = none).
		 * @param {string} params.password Optional password ('' = fragment mode).
		 * @return {Promise<string>} The full share URL (fragment included when keyless).
		 * @spec openspec/specs/ephemeral-send/spec.md#requirement-create-a-standalone-ephemeral-send
		 */
		async createSend({ payload, payloadType, maxViews, ttlSeconds, password }) {
			const { encryptedPayload, rawKey } = await sealPayload(payload)

			const body = {
				encryptedPayload,
				payloadType,
				maxViews,
				ttlSeconds: ttlSeconds || 0,
				hasPassword: password !== '',
			}
			if (password !== '') {
				const salt = crypto.getRandomValues(new Uint8Array(16))
				const kek = await deriveAesKeyArgon2id(password, salt)
				body.wrappedKey = await aesEncrypt(kek, rawKey)
				body.argon2idSalt = toBase64(salt)
			}

			const response = await axios.post(
				generateUrl('/apps/keepiq/api/v1/sends'),
				body,
			)
			const token = response.data?.token
			await this.fetchSends()

			// The /public shell serves the SPA as #[PublicPage] so an
			// account-less recipient reaches the access route. A PATH, not
			// a hash route: the router is createWebHistory and never reads
			// the fragment (the fragment is reserved for the key). The key
			// rides the fragment only without a password; it never reaches
			// the server either way.
			return sendLink(
				window.location.origin + generateUrl('/apps/keepiq/public'),
				token,
				password !== '' ? null : rawKey,
			)
		},

		/**
		 * Load the caller's sends.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/ephemeral-send/spec.md#requirement-manage-and-revoke-sends
		 */
		async fetchSends() {
			this.loading = true
			try {
				const response = await axios.get(
					generateUrl('/apps/keepiq/api/v1/sends'),
				)
				this.sends = response.data || []
			} finally {
				this.loading = false
			}
		},

		/**
		 * Revoke one of the caller's sends.
		 *
		 * @param {string} id The send id.
		 * @return {Promise<void>}
		 * @spec openspec/specs/ephemeral-send/spec.md#requirement-manage-and-revoke-sends
		 */
		async revoke(id) {
			await axios.delete(generateUrl(`/apps/keepiq/api/v1/sends/${id}`))
			this.sends = this.sends.filter((s) => s.id !== id)
		},

		/**
		 * Anonymous access: fetch + decrypt, then confirm the view.
		 *
		 * @param {string} token The URL token.
		 * @param {string} fragmentKey The base64url fragment key ('' in password mode).
		 * @param {string} password The password ('' in fragment mode).
		 * @return {Promise<{payload: string, payloadType: string, burned: boolean}>}
		 * @spec openspec/specs/ephemeral-send/spec.md#requirement-anonymous-recipient-access-with-no-account
		 * @spec openspec/specs/ephemeral-send/spec.md#requirement-burn-after-read-and-optional-expiry
		 */
		async accessSend(token, fragmentKey, password) {
			if (!this.served[token]) {
				const response = await axios.post(publicSendUrl(token, 'access'))
				this.served[token] = response.data
			}
			const data = this.served[token]
			try {
				const contentKey = await contentKeyOf(data, { fragmentKey, password })
				const plaintext = await aesDecrypt(contentKey, data.encryptedPayload)
				// The view was counted when the ciphertext was served; a
				// burned send is already gone, so its confirm is skipped.
				delete this.served[token]
				if (!data.burned) {
					await axios.post(publicSendUrl(token, 'confirm')).catch(() => null)
				}
				return {
					payload: new TextDecoder().decode(plaintext),
					payloadType: data.payloadType,
					burned: data.burned === true,
				}
			} catch (e) {
				if (data.hasPassword) {
					throw wrongPasswordError(data)
				}
				throw e
			}
		},
	},
})
