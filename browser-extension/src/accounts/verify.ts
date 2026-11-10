import { ApiError, createClient, KeepiqNotInstalled, NotNextcloud, Offline, SessionRevoked } from '@/src/api/client'
import type { CachedSuite, SuiteRow } from '@/src/api/types'
import { supportedKeepiq } from '@/src/api/keepiq-version'
import { Failure } from '@/src/failure'
import type { ErrorCode } from '@/src/messages'

export interface Verified {
	uid: string
	loginName: string
	displayName: string
	email: string | null
	avatarDataUrl: string | null
	keepiqVersion: string | null
	suite: CachedSuite
}

/** The active suite with its key, or the reason it can't be unlocked. */
export function activeSuite(rows: SuiteRow[]): CachedSuite {
	const row = rows.find((r) => r.status === 'active')
	if (!row) throw new Failure('no_active_suite')
	// The server's two-factor policy withholds the key from this login.
	if (typeof row.privateKey !== 'string') throw new Failure('unlock_blocked')
	return { id: row.id, status: row.status, certificate: row.certificate, privateKey: row.privateKey, unlockKeyEpoch: row.unlockKeyEpoch }
}

/** The login being checked: the server, the login name and the app password. */
interface Login {
	serverUrl: string
	loginName: string
	appPassword: string
}

/** Identity first, so "not Nextcloud" and "wrong password" are told apart from Keepiq errors. */
export async function verifyCredentials(serverUrl: string, loginName: string, appPassword: string): Promise<Verified> {
	const probe = createClient({ serverUrl, uid: loginName, loginName, appPassword })
	try {
		return await verifiedWith(probe, { serverUrl, loginName, appPassword })
	} catch (error) {
		throw failureOf(error)
	}
}

async function verifiedWith(probe: ReturnType<typeof createClient>, { serverUrl, loginName, appPassword }: Login): Promise<Verified> {
	const identity = await probe.fetchIdentity()
	const client = createClient({ serverUrl, uid: identity.id, loginName, appPassword })
	const suite = activeSuite(await client.listSuites())
	const keepiqVersion = await client.fetchVersion()
	if (!supportedKeepiq(keepiqVersion)) throw new Failure('keepiq_outdated')
	return {
		uid: identity.id,
		loginName,
		...profileOf(identity),
		avatarDataUrl: await client.fetchAvatarDataUrl(),
		keepiqVersion,
		suite,
	}
}

/** The name shown for the account, its uid without one, and its email if it has one. */
function profileOf(identity: { id: string; displayname?: string | null; email?: string | null }): Pick<Verified, 'displayName' | 'email'> {
	return { displayName: identity.displayname || identity.id, email: identity.email || null }
}

/** What each failure of the client reads as, checked in order. */
const CLIENT_FAILURES: ReadonlyArray<readonly [new (...args: never[]) => Error, ErrorCode]> = [
	[Offline, 'unreachable'],
	[NotNextcloud, 'not_nextcloud'],
	[SessionRevoked, 'unauthorized'],
	[KeepiqNotInstalled, 'keepiq_missing'],
]

function failureOf(error: unknown): Failure {
	if (error instanceof Failure) return error
	const known = CLIENT_FAILURES.find(([type]) => error instanceof type)
	if (known) return new Failure(known[1])
	return new Failure(error instanceof ApiError && error.status >= 500 ? 'server_error' : 'unknown')
}
