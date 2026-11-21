/**
 * A sign-in with the picked passkey (spec § The passkey key lives in background memory for one signature): the
 * decrypted entry and its non-extractable key live only in this call, and nothing of them is stored.
 */
import { fromBase64Url, toBase64Url } from '@/src/crypto/base64'
import { recordLastUsed } from '@/src/last-used'
import type { PasskeyCredentialJSON, PasskeyResult, VaultWriteError } from '@/src/messages'
import { savePasskeyCounter } from '@/src/vault/write'
import { credentialIdKey, findPasskeys } from './match'
import type { CeremonyRequest } from './request'
import { getAssertion, importSigningKey, type Assertion } from './webauthn'

export interface SignIn {
	ceremony: Pick<CeremonyRequest, 'rpId' | 'origin' | 'challenge' | 'credentialIds'> & { accountId: string; verified: boolean }
	key: CryptoKey
	itemId: string
	credentialId: string
	/** Ends the ceremony with the result; false when it had ended already. */
	deliver: (result: PasskeyResult) => Promise<boolean>
}

/** `stale`: the pick is no longer among the passkeys the request offers. `error`: the counter save failed, nothing was signed. */
export type SignOutcome = 'delivered' | 'ended' | 'stale' | { error: VaultWriteError }

function toJSON(assertion: Assertion): PasskeyCredentialJSON {
	const id = toBase64Url(assertion.rawId)
	return {
		id,
		rawId: id,
		type: 'public-key',
		authenticatorAttachment: 'platform',
		clientExtensionResults: {},
		response: {
			clientDataJSON: toBase64Url(assertion.clientDataJSON),
			authenticatorData: toBase64Url(assertion.authenticatorData),
			signature: toBase64Url(assertion.signature),
			userHandle: assertion.userHandle && toBase64Url(assertion.userHandle),
		},
	}
}

export async function signIn({ ceremony, key, itemId, credentialId, deliver }: SignIn): Promise<SignOutcome> {
	const picked = credentialIdKey(credentialId)
	const entries = await findPasskeys(ceremony.accountId, key, { rpId: ceremony.rpId, allowCredentials: ceremony.credentialIds, itemId })
	const entry = entries.find((candidate) => credentialIdKey(candidate.credential.credentialId) === picked)
	if (!entry || picked === null) return 'stale'
	// Imported first, so a key that does not import raises no counter.
	const signingKey = await importSigningKey(entry.credential.privateKey)
	// Saved before signing, and signed with the saved value, so a relying party never sees a counter the vault lacks.
	const saved = await savePasskeyCounter(entry.row.id, entry.credential.credentialId, entry.credential.counter, ceremony.accountId)
	if (!saved.ok) return { error: saved.error }
	const assertion = await getAssertion({
		credential: entry.credential, key: signingKey, origin: ceremony.origin, challenge: fromBase64Url(ceremony.challenge), counter: saved.value.counter, verified: ceremony.verified,
	})
	if (!(await deliver({ type: 'credential', credential: toJSON(assertion) }))) return 'ended'
	await recordLastUsed(ceremony.accountId, entry.row.id)
	return 'delivered'
}
