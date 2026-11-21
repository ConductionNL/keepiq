import { ApiError, createClient, Offline, SessionRevoked } from '@/src/api/client'
import { accountStatus, getAccount, suiteKey, updateAccount, type AccountRecord } from '@/src/accounts/store'
import type { CachedSuite } from '@/src/api/types'
import { Failure } from '@/src/failure'
import type { VaultPasskeyReplies, VaultPasskeyReply, VaultPasskeyRequest } from '@/src/messages'
import { enforce } from './timeout'
import type { CredentialJson, EnrolmentBody, EnrolOptions, ReleasedKey, UnlockOptions } from './passkey-unlock'

/** The server answers every refused ceremony with one generic 400; the window then offers the master password. */
function failureOf(error: unknown): Failure {
	if (error instanceof Failure) return error
	if (error instanceof Offline) return new Failure('unreachable')
	if (error instanceof SessionRevoked) return new Failure('session_revoked')
	if (error instanceof ApiError && error.status >= 500) return new Failure('server_error')
	return new Failure('passkey_failed')
}

async function signedIn(accountId: string): Promise<AccountRecord> {
	const account = await getAccount(accountId)
	if (!account || account.appPassword === null) throw new Failure('session_revoked')
	return account
}

const isString = (value: unknown): value is string => typeof value === 'string'

/** Only the fields the server takes, so nothing else a window adds can reach it. */
function enrolmentBody(body: EnrolmentBody): EnrolmentBody {
	const { credentialId, wrappedUnlockKey, prfSalt, attestation, assertion, label, transports } = body
	if (![credentialId, wrappedUnlockKey, prfSalt, label, transports].every(isString)) throw new Failure('passkey_failed')
	return { credentialId, wrappedUnlockKey, prfSalt, attestation, assertion, label, transports, clientKind: 'extension' }
}

async function enrolOptions(account: AccountRecord): Promise<EnrolOptions> {
	// Enrolment proves the master password against the vault the user has open, never a locked one.
	if (await accountStatus(account) !== 'unlocked') throw new Failure('passkey_failed')
	const suite = (await browser.storage.local.get(suiteKey(account.id)))[suiteKey(account.id)] as CachedSuite | undefined
	if (!suite) throw new Failure('passkey_failed')
	const options = await createClient(account).keepiq<Omit<EnrolOptions, 'privateKey'>>('GET', '/api/v1/passkeys/challenge?client=extension')
	return { ...options, privateKey: suite.privateKey }
}

async function unlockOptions(account: AccountRecord): Promise<UnlockOptions> {
	const options = await createClient(account).keepiq<UnlockOptions>('GET', '/api/v1/passkeys/login-options?client=extension')
	// A revoked or suspended passkey is no longer offered, so the unlock screen stops showing it.
	if (!options.credentials?.length && account.passkeyUnlock) await updateAccount(account.id, { passkeyUnlock: false })
	return { challenge: options.challenge ?? null, rpId: options.rpId, credentials: options.credentials ?? [] }
}

async function release(account: AccountRecord, assertion: CredentialJson): Promise<ReleasedKey> {
	const { credentialId, wrappedUnlockKey } = await createClient(account).keepiq<ReleasedKey>('POST', '/api/v1/passkeys/unlock', { assertion, client: 'extension' })
	if (!isString(credentialId) || !isString(wrappedUnlockKey)) throw new Failure('passkey_failed')
	return { credentialId, wrappedUnlockKey }
}

/** The extension window's server calls; the app password stays in the background. */
export async function handleVaultPasskey<K extends VaultPasskeyRequest['kind']>(message: Extract<VaultPasskeyRequest, { kind: K }>): Promise<VaultPasskeyReply<K>> {
	try {
		// A vault the timeout should have locked is locked before enrolment asks whether it is open.
		await enforce()
		const account = await signedIn(message.accountId)
		const value = await run(account, message)
		return { ok: true, value: value as VaultPasskeyReplies[K] }
	} catch (error) {
		return { ok: false, code: failureOf(error).code }
	}
}

async function run(account: AccountRecord, message: VaultPasskeyRequest): Promise<unknown> {
	switch (message.kind) {
		case 'vaultPasskey.enrolOptions':
			return enrolOptions(account)
		case 'vaultPasskey.enrol':
			await createClient(account).keepiq('POST', '/api/v1/passkeys', enrolmentBody(message.body))
			await updateAccount(account.id, { passkeyUnlock: true })
			return null
		case 'vaultPasskey.unlockOptions':
			return unlockOptions(account)
		case 'vaultPasskey.release':
			return release(account, message.assertion)
	}
}
