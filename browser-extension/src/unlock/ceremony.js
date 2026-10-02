/**
 * The extension's own passkey ceremonies (extension-biometric-unlock), run in
 * the unlock window, an extension page: the OS fingerprint or face prompt takes
 * focus and would close the popup.
 *
 * The recipe is the web app's (`src/store/modules/passkey.js`), on the same
 * crypto modules: the raw unlock key is derived from the master password and
 * the suite envelope's salt, wrapped under a key derived from the WebAuthn PRF
 * output, and only the wrapped key, the PRF salt and the credential metadata go
 * to the server. The relying party is the extension's own origin and user
 * verification is required.
 *
 * Every dependency is passed in (`credentials` is `navigator.credentials`,
 * `send` messages the worker), so the ceremonies run against a fake
 * authenticator in tests.
 */

import {
	decodeEnvelope,
	decryptPrivateKeyWithRawKey,
	deriveKekFromPrf,
	deriveUnlockKeyRaw,
	fromBase64Url,
	toBase64Url,
	unwrapUnlockKey,
	wrapUnlockKey,
} from '../crypto/index.js'

/** Raised when the browser or authenticator cannot do a PRF passkey. */
export class BiometricUnavailable extends Error {}

/**
 * Whether this page can offer fingerprint or face unlock at all: WebAuthn is
 * exposed and a user-verifying platform authenticator is present. PRF support
 * is only known after an enrolment (D5).
 *
 * @param {object} win The window (globalThis in a page).
 * @return {Promise<boolean>}
 */
export async function platformAuthenticatorAvailable(win = globalThis) {
	const pkc = win.PublicKeyCredential
	if (
		!pkc
		|| typeof pkc.isUserVerifyingPlatformAuthenticatorAvailable !== 'function'
	) {
		return false
	}
	try {
		return (await pkc.isUserVerifyingPlatformAuthenticatorAvailable()) === true
	} catch {
		return false
	}
}

function challengeBytes(challenge) {
	return fromBase64Url(String(challenge).replace(/\+/g, '-').replace(/\//g, '_'))
}

function toBase64(bytes) {
	let s = ''
	for (const b of bytes) s += String.fromCharCode(b)
	return btoa(s)
}

/**
 * Worker answers carry `error` instead of throwing; turn one into a throw.
 *
 * @param {object} res The worker answer.
 * @return {object} The answer.
 */
function unwrap(res) {
	if (!res || res.error) throw new Error(res?.error || 'no answer from Keepiq')
	return res
}

/**
 * Enrol a platform passkey for one (unlocked) account.
 *
 * @param {object} deps The dependencies.
 * @param {object} deps.credentials navigator.credentials.
 * @param {function(string, object): Promise<object>} deps.send Messages the worker.
 * @param {string} deps.accountId The account.
 * @param {string} deps.masterPassword The master password (used only here).
 * @param {string} deps.label A name for the passkey.
 * @return {Promise<object>} The stored credential.
 */
export async function enrolBiometric({
	credentials,
	send,
	accountId,
	masterPassword,
	label,
}) {
	const ctx = unwrap(await send('biometric-enrol-context', { accountId }))

	// The raw unlock key, checked against the envelope before any prompt.
	const { salt } = decodeEnvelope(ctx.envelope)
	const rawUnlockKey = await deriveUnlockKeyRaw(masterPassword, salt)
	try {
		await decryptPrivateKeyWithRawKey(ctx.envelope, rawUnlockKey)
	} catch {
		rawUnlockKey.fill(0)
		throw new Error('That master password is not correct.')
	}

	try {
		const challenge = challengeBytes(ctx.challenge)
		const created = await credentials.create({
			publicKey: {
				rp: { id: ctx.rpId, name: 'Keepiq' },
				user: {
					id: new TextEncoder().encode(ctx.user || 'keepiq-user'),
					name: ctx.user || 'Keepiq',
					displayName: ctx.user || 'Keepiq',
				},
				challenge,
				pubKeyCredParams: [
					{ type: 'public-key', alg: -7 },
					{ type: 'public-key', alg: -257 },
				],
				authenticatorSelection: {
					authenticatorAttachment: 'platform',
					residentKey: 'preferred',
					userVerification: 'required',
				},
				extensions: { prf: {} },
			},
		})
		if (!created?.getClientExtensionResults?.()?.prf?.enabled) {
			throw new BiometricUnavailable(
				'This device cannot unlock Keepiq with a fingerprint or face.',
			)
		}

		const prfSalt = crypto.getRandomValues(new Uint8Array(32))
		const assertion = await credentials.get({
			publicKey: {
				challenge,
				rpId: ctx.rpId,
				allowCredentials: [{ type: 'public-key', id: created.rawId }],
				userVerification: 'required',
				extensions: { prf: { eval: { first: prfSalt } } },
			},
		})
		const prfOutput =
			assertion?.getClientExtensionResults?.()?.prf?.results?.first
		if (!prfOutput) {
			throw new BiometricUnavailable(
				'This device cannot unlock Keepiq with a fingerprint or face.',
			)
		}

		const credentialId = toBase64Url(created.rawId)
		const kek = await deriveKekFromPrf(prfOutput, credentialId)
		const wrappedUnlockKey = await wrapUnlockKey(kek, rawUnlockKey)
		return unwrap(
			await send('biometric-enrol', {
				accountId,
				body: {
					credentialId,
					wrappedUnlockKey,
					prfSalt: toBase64(prfSalt),
					label: label || 'Browser extension',
					transports: (created.response?.getTransports?.() || []).join(
						',',
					),
				},
			}),
		)
	} finally {
		rawUnlockKey.fill(0)
	}
}

/**
 * Unlock one account with its enrolled passkey: the PRF output unwraps the
 * raw unlock key here, which goes to the worker over extension messaging.
 *
 * @param {object} deps The dependencies.
 * @param {object} deps.credentials navigator.credentials.
 * @param {function(string, object): Promise<object>} deps.send Messages the worker.
 * @param {string} deps.accountId The account.
 * @return {Promise<boolean>} True when unlocked.
 */
export async function unlockWithBiometric({ credentials, send, accountId }) {
	const options = unwrap(await send('biometric-options', { accountId }))
	if (!options.credentials?.length) {
		throw new BiometricUnavailable('No fingerprint or face unlock is set up.')
	}
	const salts = {}
	for (const c of options.credentials) {
		salts[toBase64Url(fromBase64Url(c.credentialId))] = {
			first: Uint8Array.from(atob(c.prfSalt), (ch) => ch.charCodeAt(0)),
		}
	}
	const assertion = await credentials.get({
		publicKey: {
			challenge: challengeBytes(options.challenge),
			rpId: options.rpId,
			allowCredentials: options.credentials.map((c) => ({
				type: 'public-key',
				id: fromBase64Url(c.credentialId),
			})),
			userVerification: 'required',
			extensions: { prf: { evalByCredential: salts } },
		},
	})
	const usedId = toBase64Url(assertion.rawId)
	const cred = options.credentials.find(
		(c) => toBase64Url(fromBase64Url(c.credentialId)) === usedId,
	)
	const prfOutput = assertion.getClientExtensionResults?.()?.prf?.results?.first
	if (!cred || !prfOutput) throw new Error('The passkey did not unlock Keepiq.')

	const kek = await deriveKekFromPrf(prfOutput, cred.credentialId)
	const rawUnlockKey = await unwrapUnlockKey(kek, cred.wrappedUnlockKey)
	try {
		unwrap(
			await send('unlock-raw', {
				accountId,
				rawKey: Array.from(rawUnlockKey),
			}),
		)
	} finally {
		rawUnlockKey.fill(0)
	}
	await send('biometric-used', { accountId, id: cred.id })
	return true
}
