/**
 * Saving a passkey for a `create` (spec § Save a passkey). The generated key lives only in `savePasskey`, encrypted
 * into the login, and the page gets the attestation only once the server took the save.
 */
import type { OpenSecretRow } from '@/src/api/types'
import { readSettings } from '@/src/accounts/settings'
import { candidatesFor } from '@/src/autofill/matcher'
import { rsaDecrypt } from '@/src/crypto'
import { fromBase64Url, toBase64Url } from '@/src/crypto/base64'
import { readLastUsed } from '@/src/last-used'
import type { PasskeyCredentialJSON, PasskeyResult, PasskeySaveTarget, PasskeyView, VaultWriteError } from '@/src/messages'
import { flattenFolders, folderPath } from '@/src/vault/list'
import { readSnapshot } from '@/src/vault/store'
import { syncAfterWrite } from '@/src/vault/sync'
import { createPasskeyLogin, itemEditable, writeLoginPasskeys } from '@/src/vault/write'
import type { PasskeyCredential } from './credential'
import { credentialIdKey, findPasskeys, type PasskeyEntry } from './match'
import type { CeremonyRequest } from './request'
import { createCredential, ES256, type Registration } from './webauthn'

export type SaveCeremony = Pick<CeremonyRequest, 'rpId' | 'origin' | 'url' | 'challenge' | 'credentialIds' | 'rpName' | 'user' | 'credProps'> & { id: string; accountId: string; verified: boolean }

export type SaveRequest = { itemId: string; replace: boolean } | { name: string; folderId: string | null }

/** The passkeys failed tries of a save wrote, and their login when known, so Retry can find one the server stored anyway. */
export interface SaveAttempt {
	credentialIds: string[]
	itemId: string | null
}

/** `passkey.save`'s target before it is checked. */
interface RawTarget {
	itemId?: unknown
	replace?: unknown
	name?: unknown
	folderId?: unknown
}

/** A login of the vault: `itemId`, and `replace` when given, without a new login's members. */
function existingLogin(t: RawTarget): SaveRequest | null {
	if (typeof t.itemId !== 'string' || t.name !== undefined || t.folderId !== undefined) return null
	if (t.replace !== undefined && typeof t.replace !== 'boolean') return null
	return { itemId: t.itemId, replace: t.replace === true }
}

/** A new login: `name` and `folderId`, without an existing login's members. */
function newLogin(t: RawTarget): SaveRequest | null {
	if (typeof t.name !== 'string' || t.itemId !== undefined || t.replace !== undefined) return null
	if (t.folderId !== null && typeof t.folderId !== 'string') return null
	return { name: t.name, folderId: t.folderId }
}

/** `passkey.save`'s target, which names a login or a new one, never both. */
export function readSaveRequest(raw: unknown): SaveRequest | null {
	if (typeof raw !== 'object' || raw === null) return null
	return existingLogin(raw) ?? newLogin(raw)
}

/** Whether `excludeCredentials` names a passkey the vault holds for the RP ID. */
export async function alreadySaved(ceremony: SaveCeremony, key: CryptoKey): Promise<boolean> {
	if (ceremony.credentialIds.length === 0) return false
	return (await findPasskeys(ceremony.accountId, key, { rpId: ceremony.rpId, allowCredentials: ceremony.credentialIds })).length > 0
}

/** Same RP ID and user handle, compared as bytes. */
function sameUser(ceremony: SaveCeremony, entry: PasskeyEntry): boolean {
	const handle = ceremony.user ? credentialIdKey(ceremony.user.id) : null
	return !!handle && credentialIdKey(entry.credential.userHandle) === handle
}

/** The frame's logins under the URI match rule, as autofill matches them, without use-only and read-only copies. */
async function savableLogins(ceremony: SaveCeremony): Promise<OpenSecretRow[]> {
	const vault = await readSnapshot(ceremony.accountId)
	if (!vault) return []
	const rule = (await readSettings(ceremony.accountId)).uriMatch
	const rows = candidatesFor(ceremony.url, vault, rule, await readLastUsed(ceremony.accountId))
	return rows.filter((row): row is OpenSecretRow => !row.blocked && !row.useOnly && !row.readOnly)
}

async function username(row: OpenSecretRow, key: CryptoKey): Promise<string> {
	try {
		return row.login ? await rsaDecrypt(row.login, key) : ''
	} catch {
		return ''
	}
}

/** `write-context` answers by ceremony id and login id, so a Save view shown again asks nothing; the save checks again. */
const editableAnswers = new Map<string, Map<string, boolean>>()
/** Ceremonies end without telling this module, so only the latest few keep theirs. */
const ANSWERS_KEPT = 4

function answersFor(ceremonyId: string): Map<string, boolean> {
	let answers = editableAnswers.get(ceremonyId)
	if (!answers) {
		editableAnswers.set(ceremonyId, answers = new Map())
		for (const old of [...editableAnswers.keys()].slice(0, -ANSWERS_KEPT)) editableAnswers.delete(old)
	}
	return answers
}

/** The Save view's targets and folders; unlocked only. */
export async function saveView(ceremony: SaveCeremony, key: CryptoKey): Promise<Pick<PasskeyView, 'targets' | 'folders'>> {
	const vault = await readSnapshot(ceremony.accountId)
	if (!vault) return { targets: [], folders: [] }
	const existing = (await findPasskeys(ceremony.accountId, key, { rpId: ceremony.rpId, allowCredentials: [] })).filter((entry) => sameUser(ceremony, entry))
	const answers = answersFor(ceremony.id)
	const targets = await Promise.all((await savableLogins(ceremony)).map(async (row): Promise<PasskeySaveTarget | null> => {
		// A view-only copy is left out; when the check fails the row stays and is asked again, since the save asks too.
		let editable = answers.get(row.id)
		if (editable === undefined) {
			const asked = await itemEditable(row.id, ceremony.accountId)
			if (asked.ok) answers.set(row.id, editable = asked.value.editable)
		}
		if (editable === false) return null
		const same = existing.find((entry) => entry.row.id === row.id)
		const replace = same ? { userName: same.credential.userName || same.credential.userDisplayName } : null
		return { itemId: row.id, name: row.name, username: await username(row, key), replace }
	}))
	const folders = flattenFolders(vault.folders).map((folder) => ({ id: folder.id, name: folderPath(vault.folders, folder.id) ?? folder.name }))
	return { targets: targets.filter((target) => target !== null), folders }
}

function toJSON(registration: Registration, credProps: boolean): PasskeyCredentialJSON {
	const id = toBase64Url(registration.rawId)
	return {
		id,
		rawId: id,
		type: 'public-key',
		authenticatorAttachment: 'platform',
		clientExtensionResults: credProps ? { credProps: { rk: true } } : {},
		response: {
			clientDataJSON: toBase64Url(registration.clientDataJSON),
			attestationObject: toBase64Url(registration.attestationObject),
			authenticatorData: toBase64Url(registration.authenticatorData),
			publicKey: toBase64Url(registration.publicKey),
			publicKeyAlgorithm: ES256,
			transports: [...registration.credential.transports],
		},
	}
}

export interface Save {
	ceremony: SaveCeremony
	key: CryptoKey
	request: SaveRequest
	/** Retry's: the earlier try of this save. */
	attempt?: SaveAttempt
	/** Ends the ceremony with the result; false when it had ended already. */
	deliver: (result: PasskeyResult) => Promise<boolean>
}

/** `stale`: the login is no longer one to save to. `replace_required`: it holds this user's passkey and Replace was not confirmed. */
export type SaveOutcome = 'delivered' | 'ended' | 'stale' | 'replace_required' | { error: VaultWriteError; attempt: SaveAttempt }

/** The entry an earlier try wrote, read after a sync, since a lost reply does not mean nothing was stored. Each try replaced the one found before it, so there is one at most. */
async function landed(ceremony: SaveCeremony, key: CryptoKey, attempt: SaveAttempt): Promise<{ entry: PasskeyEntry | undefined } | { error: VaultWriteError }> {
	// An empty list would match every passkey for the RP ID.
	if (attempt.credentialIds.length === 0) return { entry: undefined }
	const { lastError } = await syncAfterWrite(ceremony.accountId)
	// Without a sync it cannot tell, and saving again could make a second login.
	if (lastError) return { error: { code: lastError === 'network' ? 'offline' : lastError === 'busy' ? 'write_locked' : 'server_error' } }
	const query = { rpId: ceremony.rpId, allowCredentials: attempt.credentialIds, ...(attempt.itemId !== null && { itemId: attempt.itemId }) }
	return { entry: (await findPasskeys(ceremony.accountId, key, query))[0] }
}

/** The login the passkey goes into, and the entry of this user it takes the place of. */
interface Destination {
	into: { itemId: string } | { name: string; folderId: string | null }
	same: PasskeyEntry | undefined
}

/** Where a save goes, or why it does not: a Retry's earlier try that was stored decides first, then the chosen login. */
async function destination({ ceremony, key, request, attempt }: Save): Promise<Destination | Exclude<SaveOutcome, 'delivered' | 'ended'>> {
	if (attempt) {
		const earlier = await landed(ceremony, key, attempt)
		if ('error' in earlier) return { error: earlier.error, attempt }
		// The earlier try was stored: this one takes its place, so no second login or entry appears.
		if (earlier.entry) return { into: { itemId: earlier.entry.row.id }, same: earlier.entry }
	}
	if (!('itemId' in request)) return { into: request, same: undefined }
	if (!(await savableLogins(ceremony)).some((row) => row.id === request.itemId)) return 'stale'
	const same = (await findPasskeys(ceremony.accountId, key, { rpId: ceremony.rpId, allowCredentials: [], itemId: request.itemId })).find((entry) => sameUser(ceremony, entry))
	if (same && !request.replace) return 'replace_required'
	return { into: request, same }
}

/** Adds the passkey to the login, in place of `same` when there is one, or creates the new login with it. */
function saveInto({ into, same }: Destination, credential: PasskeyCredential, ceremony: SaveCeremony) {
	if ('itemId' in into) return writeLoginPasskeys(into.itemId, same ? { kind: 'replace', credentialId: same.credential.credentialId, credential } : { kind: 'add', credential }, ceremony.accountId)
	return createPasskeyLogin({ name: into.name.trim(), url: ceremony.origin, folderId: into.folderId, credential }, ceremony.accountId)
}

export async function savePasskey(save: Save): Promise<SaveOutcome> {
	const { ceremony, attempt, deliver } = save
	const { user } = ceremony
	if (!user) return 'stale'
	const target = await destination(save)
	if (typeof target === 'string' || 'error' in target) return target
	const registration = await createCredential({
		origin: ceremony.origin, rpId: ceremony.rpId, rpName: ceremony.rpName, challenge: fromBase64Url(ceremony.challenge), verified: ceremony.verified,
		user: { id: fromBase64Url(user.id), name: user.name, displayName: user.displayName },
	})
	const { credential } = registration
	const saved = await saveInto(target, credential, ceremony)
	if (!saved.ok) {
		return { error: saved.error, attempt: { credentialIds: [...attempt?.credentialIds ?? [], credential.credentialId], itemId: 'itemId' in target.into ? target.into.itemId : null } }
	}
	return (await deliver({ type: 'credential', credential: toJSON(registration, ceremony.credProps) })) ? 'delivered' : 'ended'
}
