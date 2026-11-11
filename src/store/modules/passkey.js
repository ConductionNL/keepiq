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

/**
 * The session store, when the vault is unlocked.
 *
 * @return {object}
 */
function unlockedSession() {
	const session = useSessionStore()
	if (!session.cryptoKey || !session.encryptedPrivateKey) {
		throw new Error('Unlock your vault before enrolling a passkey')
	}
	return session
}

/**
 * Decode a server challenge to bytes.
 *
 * @param {string} value base64url (base64 is accepted too).
 * @return {Uint8Array}
 */
function challengeBytes(value) {
	return fromBase64Url(value.replace(/\+/g, '-').replace(/\//g, '_'))
}

/**
 * Fetch the enrolment challenge pair, which the server keeps for this one
 * enrolment: `challenge` for create(), `assertionChallenge` for the get()
 * that proves the new passkey holds its key.
 *
 * @return {Promise<{challenge: Uint8Array, assertionChallenge: Uint8Array}>}
 */
async function enrolmentChallenge() {
	const response = await axios.get(
		generateUrl('/apps/keepiq/api/v1/passkeys/challenge'),
	)
	return {
		challenge: challengeBytes(response.data.challenge),
		assertionChallenge: challengeBytes(response.data.assertionChallenge),
	}
}

/**
 * The WebAuthn user handle: the Nextcloud uid, or a fixed fallback.
 *
 * @return {Uint8Array}
 */
function userHandle() {
	return new TextEncoder().encode(
		window.OC?.getCurrentUser?.()?.uid || 'keepiq-user',
	)
}

/**
 * The passkeys that unlock the vault now, as create() exclusions. Every
 * enrolment uses the same user handle, so an authenticator that already holds
 * one would replace it and leave its earlier row unusable. Stale passkeys are
 * not listed, so re-enrolling after a master password change still works on
 * the same device.
 *
 * @return {Promise<Array<object>>}
 */
async function activePasskeyExclusions() {
	const response = await axios.get(
		generateUrl('/apps/keepiq/api/v1/passkeys/login-options'),
	)
	return (response.data?.credentials ?? []).map((c) => ({
		type: 'public-key',
		id: fromBase64Url(c.credentialId),
	}))
}

/**
 * The create() options for a new PRF-capable passkey.
 *
 * @param {string} label The nickname the user gave.
 * @param {object} ceremony The challenge, userId and excludeCredentials.
 * @return {object}
 */
function creationOptions(label, ceremony) {
	return {
		publicKey: {
			rp: { id: RP_ID, name: 'Keepiq' },
			user: {
				id: ceremony.userId,
				name: label || 'Keepiq vault',
				displayName: label || 'Keepiq vault',
			},
			challenge: ceremony.challenge,
			attestation: 'none',
			pubKeyCredParams: [
				{ type: 'public-key', alg: -7 },
				{ type: 'public-key', alg: -257 },
			],
			authenticatorSelection: {
				residentKey: 'preferred',
				userVerification: 'preferred',
			},
			excludeCredentials: ceremony.excludeCredentials,
			extensions: { prf: {} },
		},
	}
}

/**
 * Create the passkey, and require that the authenticator enabled PRF.
 *
 * @param {object} options The create() options.
 * @return {Promise<object>} The created credential.
 */
async function createPrfPasskey(options) {
	let created
	try {
		created = await navigator.credentials.create(options)
	} catch (e) {
		if (e?.name === 'InvalidStateError') {
			throw new Error(
				'This authenticator already unlocks your vault. Revoke its passkey first to enroll it again.',
				{ cause: e },
			)
		}
		throw e
	}
	const ext = created.getClientExtensionResults()
	if (!ext?.prf?.enabled) {
		throw new Error('Your authenticator does not support passkey unlock (PRF).')
	}
	return created
}

/**
 * Draw a fresh 32-byte PRF salt and ask the new passkey for its PRF output.
 * The assertion is also sent to the server as proof of possession.
 *
 * @param {object} created The created credential.
 * @param {Uint8Array} challenge The enrolment assertion challenge.
 * @return {Promise<{prfSaltBytes: Uint8Array, prfOutput: ArrayBuffer, assertion: object}>}
 */
async function prfOutputOf(created, challenge) {
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
	const prfOutput = assertion.getClientExtensionResults()?.prf?.results?.first
	if (!prfOutput) {
		throw new Error('Your authenticator did not return a PRF result.')
	}
	return { prfSaltBytes, prfOutput, assertion }
}

/**
 * base64url of an optional buffer.
 *
 * @param {ArrayBuffer|null|undefined} buffer The bytes.
 * @return {string|null}
 */
function optionalBase64Url(buffer) {
	return buffer ? toBase64Url(buffer) : null
}

/**
 * The create() credential as the JSON the server verifies.
 *
 * @param {object} created The created credential.
 * @return {object}
 */
function attestationJson(created) {
	const id = toBase64Url(created.rawId)
	return {
		id,
		rawId: id,
		type: 'public-key',
		response: {
			clientDataJSON: optionalBase64Url(created.response?.clientDataJSON),
			attestationObject: optionalBase64Url(created.response?.attestationObject),
		},
	}
}

/**
 * The proof get() credential as the JSON the server verifies.
 *
 * @param {object} assertion The get() credential.
 * @return {object}
 */
function assertionJson(assertion) {
	const id = optionalBase64Url(assertion.rawId)
	return {
		id,
		rawId: id,
		type: 'public-key',
		response: {
			clientDataJSON: optionalBase64Url(assertion.response?.clientDataJSON),
			authenticatorData: optionalBase64Url(assertion.response?.authenticatorData),
			signature: optionalBase64Url(assertion.response?.signature),
			userHandle: optionalBase64Url(assertion.response?.userHandle),
		},
	}
}

/**
 * Derive the raw vault unlock key from the master password and the
 * private-key envelope's own salt, then wrap it under the passkey's KEK.
 *
 * @param {string} masterPassword The master password (never sent).
 * @param {object} passkey The envelope, prfOutput and credentialId.
 * @return {Promise<string>} The wrapped unlock key.
 */
async function wrapUnlockKeyForPasskey(masterPassword, passkey) {
	const { salt } = decodeEnvelope(passkey.envelope)
	const rawUnlockKey = await deriveUnlockKeyRaw(masterPassword, salt)
	const kek = await deriveKekFromPrf(passkey.prfOutput, passkey.credentialId)
	return wrapUnlockKey(kek, rawUnlockKey)
}

/**
 * The enrolment request body: the wrapped envelope and the two ceremony
 * responses the server verifies, never the raw key or the PRF output.
 *
 * @param {object} enrolment The label, created credential, proof assertion,
 *  credentialId, prfSaltBytes and wrappedUnlockKey.
 * @return {object}
 */
function enrolmentBody(enrolment) {
	return {
		credentialId: enrolment.credentialId,
		wrappedUnlockKey: enrolment.wrappedUnlockKey,
		prfSalt: btoa(String.fromCharCode(...enrolment.prfSaltBytes)),
		attestation: attestationJson(enrolment.created),
		assertion: assertionJson(enrolment.assertion),
		label: enrolment.label || 'Passkey',
		transports: (enrolment.created.response?.getTransports?.() || []).join(','),
	}
}

/**
 * The login options of the user's unlock passkeys; at least one must be
 * enrolled.
 *
 * @return {Promise<{challenge: string, credentials: Array<object>}>}
 * @spec openspec/specs/passkey-vault-login/spec.md#requirement-passwordless-unlock-derives-the-unlock-key-client-side
 */
async function passkeyLoginOptions() {
	const optResp = await axios.get(
		generateUrl('/apps/keepiq/api/v1/passkeys/login-options'),
	)
	const options = optResp.data
	if (!options.credentials?.length) {
		throw new Error('No passkey enrolled for unlock')
	}
	return options
}

/**
 * The WebAuthn request for an unlock: every enrolled passkey, each with its
 * own PRF salt. Every enrolment drew its own salt, and the authenticator
 * picks which passkey answers, so each credential gets its salt through
 * evalByCredential, keyed by its base64url id; a single `eval` salt only fits
 * the first passkey (keepiq#744).
 *
 * @param {{challenge: string, credentials: Array<object>}} options The login options.
 * @return {object} The `publicKey` request.
 * @spec openspec/specs/passkey-vault-login/spec.md#requirement-passwordless-unlock-derives-the-unlock-key-client-side
 */
function passkeyRequest(options) {
	const challenge = fromBase64Url(
		options.challenge.replace(/\+/g, '-').replace(/\//g, '_'),
	)
	const allowCredentials = options.credentials.map((c) => ({
		type: 'public-key',
		id: fromBase64Url(c.credentialId),
	}))
	const evalByCredential = {}
	for (const credential of options.credentials) {
		evalByCredential[toBase64Url(fromBase64Url(credential.credentialId))] = {
			first: Uint8Array.from(atob(credential.prfSalt), (ch) => ch.charCodeAt(0)),
		}
	}
	return {
		challenge,
		rpId: RP_ID,
		allowCredentials,
		userVerification: 'preferred',
		extensions: { prf: { evalByCredential } },
	}
}

/**
 * The enrolled credential that answered, or the first one when the answer
 * names none of them.
 *
 * @param {Array<object>} credentials The enrolled unlock passkeys.
 * @param {{rawId: ArrayBuffer}} assertion The authenticator's answer.
 * @return {object}
 * @spec openspec/specs/passkey-vault-login/spec.md#requirement-passwordless-unlock-derives-the-unlock-key-client-side
 */
function answeringCredential(credentials, assertion) {
	const usedId = toBase64Url(assertion.rawId)
	return (
		credentials.find(
			(c) => toBase64Url(fromBase64Url(c.credentialId)) === usedId,
		) || credentials[0]
	)
}

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
		 * @spec openspec/specs/passkey-vault-login/spec.md#requirement-passkeys-are-manageable-revocable-and-owner-scoped
		 */
		async fetchCredentials() {
			this.error = null
			try {
				const response = await axios.get(
					generateUrl('/apps/keepiq/api/v1/passkeys'),
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
		 * @spec openspec/specs/passkey-vault-login/spec.md#requirement-prf-support-is-feature-detected-and-degrades-gracefully
		 * @spec openspec/specs/passkey-vault-login/spec.md#requirement-master-password-remains-the-canonical-fallback
		 */
		async isUnlockOffered() {
			if (!this.supported) {
				return false
			}
			try {
				const response = await axios.get(
					generateUrl('/apps/keepiq/api/v1/passkeys/login-options'),
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
		 * @spec openspec/specs/passkey-vault-login/spec.md#requirement-passkey-enrollment-requires-an-unlocked-vault
		 * @spec openspec/specs/passkey-vault-login/spec.md#requirement-prf-support-is-feature-detected-and-degrades-gracefully
		 */
		async enroll(masterPassword, label) {
			this.error = null
			const session = unlockedSession()
			const { challenge, assertionChallenge } = await enrolmentChallenge()
			const userId = userHandle()
			const excludeCredentials = await activePasskeyExclusions()
			const created = await createPrfPasskey(
				creationOptions(label, { challenge, userId, excludeCredentials }),
			)
			const credentialId = toBase64Url(created.rawId)
			const { prfSaltBytes, prfOutput, assertion } = await prfOutputOf(
				created,
				assertionChallenge,
			)
			const wrappedUnlockKey = await wrapUnlockKeyForPasskey(masterPassword, {
				envelope: session.encryptedPrivateKey,
				prfOutput,
				credentialId,
			})

			const response = await axios.post(
				generateUrl('/apps/keepiq/api/v1/passkeys'),
				enrolmentBody({
					label,
					created,
					assertion,
					credentialId,
					prfSaltBytes,
					wrappedUnlockKey,
				}),
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
		 * @spec openspec/specs/passkey-vault-login/spec.md#requirement-passwordless-unlock-derives-the-unlock-key-client-side
		 */
		async unlockWithPasskey() {
			this.error = null
			const options = await passkeyLoginOptions()
			const assertion = await navigator.credentials.get({
				publicKey: passkeyRequest(options),
			})
			const cred = answeringCredential(options.credentials, assertion)
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
				.post(generateUrl(`/apps/keepiq/api/v1/passkeys/${cred.id}/used`))
				.catch(() => {})
			return true
		},

		/**
		 * Revoke (delete) a passkey.
		 *
		 * @param {string} id The credential id.
		 * @return {Promise<void>}
		 * @spec openspec/specs/passkey-vault-login/spec.md#requirement-passkeys-are-manageable-revocable-and-owner-scoped
		 */
		async revoke(id) {
			this.error = null
			try {
				await axios.delete(generateUrl(`/apps/keepiq/api/v1/passkeys/${id}`))
				this.credentials = this.credentials.filter((c) => c.id !== id)
				this.hasActive = this.credentials.some((c) => c.status === 'active')
			} catch (e) {
				this.error = e?.response?.data?.message || e?.message
			}
		},
	},
})
