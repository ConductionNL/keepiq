/**
 * Which passkeys a sign-in offers (spec § Which passkeys are offered). The decrypted `rpId` decides, never the `url`,
 * which the user may have edited; standalone `passkey` items are not used. Background only, while unlocked.
 */
import type { OpenSecretRow } from '@/src/api/types'
import { rsaDecrypt } from '@/src/crypto'
import { fromBase64Url, toBase64Url } from '@/src/crypto/base64'
import type { PasskeyRow } from '@/src/messages'
import { readSnapshot } from '@/src/vault/store'
import { buildPasskeyCredential, passkeyEntries, type PasskeyCredential } from './credential'
import { ES256 } from './webauthn'

export interface PasskeyQuery {
	rpId: string
	/** The request's `allowCredentials`; empty allows any. */
	allowCredentials: readonly string[]
	/** Decrypts this login only, for the one that was picked. */
	itemId?: string
}

/** A matched entry with its decrypted credential, for the handler that signs and nothing beyond it. */
export interface PasskeyEntry {
	row: OpenSecretRow
	/** The entry's position in the stored array. */
	index: number
	credential: PasskeyCredential
}

/** Ids compare as bytes, so padding or the base64 alphabet a client wrote cannot hide a match. */
export function credentialIdKey(id: string): string | null {
	try {
		return toBase64Url(fromBase64Url(id.replace(/=+$/, '').replaceAll('+', '-').replaceAll('/', '_')))
	} catch {
		return null
	}
}

/** An `accessExpiresAt` that does not parse counts as passed. */
function accessEnded(row: OpenSecretRow, now: number): boolean {
	if (!row.accessExpiresAt) return false
	const at = Date.parse(row.accessExpiresAt)
	return !Number.isFinite(at) || at <= now
}

async function decrypted(row: OpenSecretRow, key: CryptoKey): Promise<string | null> {
	if (typeof row.passkeys !== 'string' || row.passkeys === '') return null
	try {
		return await rsaDecrypt(row.passkeys, key)
	} catch {
		return null
	}
}

export async function findPasskeys(accountId: string, key: CryptoKey, query: PasskeyQuery, now = Date.now()): Promise<PasskeyEntry[]> {
	const vault = await readSnapshot(accountId)
	if (!vault) return []
	const loginTypes = new Set(vault.types.filter((type) => type.name === 'login').map((type) => type.id))
	const allowed = new Set(query.allowCredentials.map(credentialIdKey))
	const rows = vault.secrets.filter((row): row is OpenSecretRow =>
		!row.blocked && row.typeId !== null && loginTypes.has(row.typeId) && !accessEnded(row, now) && (query.itemId === undefined || row.id === query.itemId))
	const found = await Promise.all(rows.map(async (row) => {
		const json = await decrypted(row, key)
		return passkeyEntries(json).flatMap(({ index, credential: raw }) => {
			const credential = buildPasskeyCredential(raw)
			if (!credential || credential.rpId !== query.rpId || credential.algorithm !== ES256) return []
			const id = credentialIdKey(credential.credentialId)
			if (id === null || (query.allowCredentials.length > 0 && !allowed.has(id))) return []
			return [{ row, index, credential }]
		})
	}))
	return found.flat()
}

/** What the window and the inline list may hold of a match. */
export const toPasskeyRow = ({ row, credential }: PasskeyEntry): PasskeyRow => ({
	itemId: row.id,
	credentialId: credentialIdKey(credential.credentialId)!,
	name: row.name,
	userName: credential.userName || credential.userDisplayName,
})

export async function matchPasskeys(accountId: string, key: CryptoKey, query: PasskeyQuery): Promise<PasskeyRow[]> {
	return (await findPasskeys(accountId, key, query)).map(toPasskeyRow)
}
