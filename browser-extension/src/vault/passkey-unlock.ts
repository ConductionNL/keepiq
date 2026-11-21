import { fromBase64, fromBase64Url, toBase64, toBase64Url } from '@/src/crypto/base64'
import { decodeEnvelope } from '@/src/crypto/envelope'
import { deriveUnlockKeyBits, InvalidMasterPassword } from '@/src/crypto/kdf'

/**
 * The vault's own passkey unlock from an extension window (extension-biometric-unlock). The PRF output, the key it
 * derives and the raw unlock key stay in that window; the server gets the ceremonies and the wrapped key only.
 */

/** The web app's KEK input (src/crypto/passkey.js); a changed byte derives another key, so it never changes. */
const HKDF_INFO = new TextEncoder().encode('doriath-passkey-kek-v1')
const IV_LENGTH = 12

/** `GET /api/v1/passkeys/challenge?client=extension`, with the cached suite's private-key envelope. */
export interface EnrolOptions {
	challenge: string
	assertionChallenge: string
	rpId: string
	userHandle: string
	privateKey: string
}

/** `GET /api/v1/passkeys/login-options?client=extension`: no challenge while the server offers none. */
export interface UnlockOptions {
	challenge: string | null
	rpId: string
	credentials: Array<{ credentialId: string; prfSalt: string }>
}

export interface ReleasedKey {
	credentialId: string
	wrappedUnlockKey: string
}

/** What `POST /api/v1/passkeys` receives: never the master password, the raw unlock key or the PRF output. */
export interface EnrolmentBody {
	credentialId: string
	wrappedUnlockKey: string
	prfSalt: string
	attestation: CredentialJson
	assertion: CredentialJson
	label: string
	transports: string
	clientKind: 'extension'
}

export type CredentialJson = { id: string; rawId: string; type: 'public-key'; response: Record<string, string | null> }

/** The authenticator gave no PRF output, so it cannot hold a vault key. */
export class PrfUnavailable extends Error {}

/** What a ceremony needs from the page: WebAuthn, and the background for every server call. */
export interface CeremonyDeps {
	credentials: Pick<CredentialsContainer, 'create' | 'get'>
	enrolOptions: () => Promise<EnrolOptions>
	enrol: (body: EnrolmentBody) => Promise<void>
	unlockOptions: () => Promise<UnlockOptions>
	release: (assertion: CredentialJson) => Promise<ReleasedKey>
}

const bytes = (value: string) => fromBase64Url(value.replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, ''))
const encoded = (buffer: ArrayBuffer | null | undefined) => (buffer ? toBase64Url(new Uint8Array(buffer)) : null)

function credentialJson(credential: PublicKeyCredential): CredentialJson {
	const id = toBase64Url(new Uint8Array(credential.rawId))
	const { response } = credential
	const fields: Record<string, string | null> = { clientDataJSON: encoded(response.clientDataJSON) }
	if ('attestationObject' in response) {
		fields.attestationObject = encoded((response as AuthenticatorAttestationResponse).attestationObject)
	} else {
		const assertion = response as AuthenticatorAssertionResponse
		Object.assign(fields, { authenticatorData: encoded(assertion.authenticatorData), signature: encoded(assertion.signature), userHandle: encoded(assertion.userHandle) })
	}
	return { id, rawId: id, type: 'public-key', response: fields }
}

function prfOutput(credential: PublicKeyCredential): ArrayBuffer {
	const output = (credential.getClientExtensionResults() as { prf?: { results?: { first?: ArrayBuffer } } }).prf?.results?.first
	if (!output) throw new PrfUnavailable()
	return output
}

/** HKDF-SHA256 salted by the credential id, as the web app, so one wrapped key format serves both. */
export async function deriveKek(output: ArrayBuffer, credentialId: string): Promise<CryptoKey> {
	const base = await crypto.subtle.importKey('raw', output, 'HKDF', false, ['deriveKey'])
	return crypto.subtle.deriveKey(
		{ name: 'HKDF', hash: 'SHA-256', salt: new TextEncoder().encode(credentialId), info: HKDF_INFO },
		base, { name: 'AES-GCM', length: 256 }, false, ['encrypt', 'decrypt'],
	)
}

/** base64(IV ‖ ciphertext ‖ tag). */
export async function wrapUnlockKey(kek: CryptoKey, raw: Uint8Array<ArrayBuffer>): Promise<string> {
	const iv = crypto.getRandomValues(new Uint8Array(IV_LENGTH))
	const sealed = new Uint8Array(await crypto.subtle.encrypt({ name: 'AES-GCM', iv }, kek, raw))
	const framed = new Uint8Array(IV_LENGTH + sealed.length)
	framed.set(iv)
	framed.set(sealed, IV_LENGTH)
	return toBase64(framed)
}

export async function unwrapUnlockKey(kek: CryptoKey, wrapped: string): Promise<Uint8Array<ArrayBuffer>> {
	const framed = fromBase64(wrapped)
	if (framed.length <= IV_LENGTH) throw new Error('Wrapped unlock key too short')
	return new Uint8Array(await crypto.subtle.decrypt({ name: 'AES-GCM', iv: framed.slice(0, IV_LENGTH) }, kek, framed.slice(IV_LENGTH)))
}

/** The raw unlock key the master password derives, checked by opening the private-key envelope with it. */
export async function rawUnlockKey(masterPassword: string, privateKey: string): Promise<Uint8Array<ArrayBuffer>> {
	const { salt, iv, ciphertextWithTag } = decodeEnvelope(privateKey)
	const raw = await deriveUnlockKeyBits(masterPassword, salt)
	const key = await crypto.subtle.importKey('raw', raw, 'AES-GCM', false, ['decrypt'])
	try {
		await crypto.subtle.decrypt({ name: 'AES-GCM', iv }, key, ciphertextWithTag)
	} catch {
		raw.fill(0)
		throw new InvalidMasterPassword()
	}
	return raw
}

export function creationOptions(options: EnrolOptions, label: string): CredentialCreationOptions {
	return {
		publicKey: {
			rp: { id: options.rpId, name: 'Keepiq' },
			user: { id: bytes(options.userHandle), name: label, displayName: label },
			challenge: bytes(options.challenge),
			pubKeyCredParams: [{ type: 'public-key', alg: -7 }, { type: 'public-key', alg: -257 }],
			// Not discoverable, so it is never offered as a Nextcloud sign-in.
			authenticatorSelection: { authenticatorAttachment: 'platform', residentKey: 'discouraged', requireResidentKey: false, userVerification: 'required' },
			attestation: 'none',
			extensions: { prf: {} } as AuthenticationExtensionsClientInputs,
		},
	}
}

function assertionRequest(rpId: string, challenge: string, credentials: Array<{ id: Uint8Array<ArrayBuffer>; salt: Uint8Array<ArrayBuffer> }>): CredentialRequestOptions {
	const evalByCredential = Object.fromEntries(credentials.map(({ id, salt }) => [toBase64Url(id), { first: salt }]))
	return {
		publicKey: {
			challenge: bytes(challenge),
			rpId,
			allowCredentials: credentials.map(({ id }) => ({ type: 'public-key', id })),
			userVerification: 'required',
			extensions: { prf: { evalByCredential } } as AuthenticationExtensionsClientInputs,
		},
	}
}

/** Enrols a passkey for this extension: create(), a proof get() that also yields the PRF output, then the wrapped key. */
export async function enrolPasskey(deps: CeremonyDeps, masterPassword: string, label: string): Promise<void> {
	const options = await deps.enrolOptions()
	const raw = await rawUnlockKey(masterPassword, options.privateKey)
	try {
		const created = await deps.credentials.create(creationOptions(options, label)) as PublicKeyCredential | null
		if (!created) throw new PrfUnavailable()
		if (!(created.getClientExtensionResults() as { prf?: { enabled?: boolean } }).prf?.enabled) throw new PrfUnavailable()
		const salt = crypto.getRandomValues(new Uint8Array(32))
		const id = new Uint8Array(created.rawId)
		const proof = await deps.credentials.get(assertionRequest(options.rpId, options.assertionChallenge, [{ id, salt }])) as PublicKeyCredential | null
		if (!proof) throw new PrfUnavailable()
		const credentialId = toBase64Url(id)
		const kek = await deriveKek(prfOutput(proof), credentialId)
		await deps.enrol({
			credentialId,
			wrappedUnlockKey: await wrapUnlockKey(kek, raw),
			prfSalt: toBase64(salt),
			attestation: credentialJson(created),
			assertion: credentialJson(proof),
			label,
			transports: ((created.response as AuthenticatorAttestationResponse).getTransports?.() ?? []).join(','),
			clientKind: 'extension',
		})
	} finally {
		raw.fill(0)
	}
}

/** The raw unlock key, unwrapped only from the server's answer to a verified assertion; `null` when no passkey is offered. */
export async function unlockWithPasskey(deps: CeremonyDeps): Promise<Uint8Array<ArrayBuffer> | null> {
	const options = await deps.unlockOptions()
	if (!options.challenge || options.credentials.length === 0) return null
	const offered = options.credentials.map((c) => ({ id: bytes(c.credentialId), salt: fromBase64(c.prfSalt) }))
	const assertion = await deps.credentials.get(assertionRequest(options.rpId, options.challenge, offered)) as PublicKeyCredential | null
	if (!assertion) throw new PrfUnavailable()
	const output = prfOutput(assertion)
	const released = await deps.release(credentialJson(assertion))
	return unwrapUnlockKey(await deriveKek(output, released.credentialId), released.wrappedUnlockKey)
}
