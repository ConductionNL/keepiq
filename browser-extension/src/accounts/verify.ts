import { createClient, KeepiqNotInstalled, NotNextcloud, Offline, SessionRevoked } from '@/src/api/client'
import type { CachedSuite, SuiteRow } from '@/src/api/types'
import { Failure } from '@/src/failure'

export interface Verified {
	uid: string
	loginName: string
	displayName: string
	email: string | null
	avatarDataUrl: string | null
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

/** Identity first, so "not Nextcloud" and "wrong password" are told apart from Keepiq errors. */
export async function verifyCredentials(origin: string, loginName: string, appPassword: string): Promise<Verified> {
	const probe = createClient({ origin, uid: loginName, loginName, appPassword })
	try {
		const identity = await probe.fetchIdentity()
		const client = createClient({ origin, uid: identity.id, loginName, appPassword })
		const suite = activeSuite(await client.listSuites())
		return {
			uid: identity.id,
			loginName,
			displayName: identity.displayname || identity.id,
			email: identity.email || null,
			avatarDataUrl: await client.fetchAvatarDataUrl(),
			suite,
		}
	} catch (error) {
		if (error instanceof Failure) throw error
		if (error instanceof Offline) throw new Failure('unreachable')
		if (error instanceof NotNextcloud) throw new Failure('not_nextcloud')
		if (error instanceof SessionRevoked) throw new Failure('unauthorized')
		if (error instanceof KeepiqNotInstalled) throw new Failure('keepiq_missing')
		throw new Failure('unknown')
	}
}
