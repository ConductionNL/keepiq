/** Item and folder writes for the active account. All encryption happens here, never in the popup (ADR-004). */
import { ApiError, createClient, Offline, SessionRevoked, VaultWriteLocked, type Client } from '@/src/api/client'
import type { CachedSuite, FolderRow, OpenSecretRow, SecretRow } from '@/src/api/types'
import { getAccount, getActiveAccountId, suiteKey, type AccountRecord } from '@/src/accounts/store'
import { importPublicKey, rsaDecrypt, rsaEncrypt, toBase64 } from '@/src/crypto'
import type { EditableItem, FolderChildren, FolderDeletePlan, ItemDraft, ItemPatch, VaultWriteError, WriteResult } from '@/src/messages'
import { getKey } from './key-store'
import { parseObject } from './payloads'
import { readSnapshot } from './store'
import { recordOutcome, syncAfterWrite } from './sync'

class WriteFailure extends Error {
	constructor(readonly error: VaultWriteError) {
		super(error.code)
	}
}

interface Context {
	account: AccountRecord
	client: Client
	privateKey: CryptoKey
}

/**
 * Writes need an unlocked vault, like reads: the lock is what guards a walk-up user (ADR-002). They
 * also need the account the popup showed to still be the active one, or a form filled for one account
 * would save into another's vault after a switch in another window.
 */
async function context(expected: string | undefined): Promise<Context> {
	const id = await getActiveAccountId()
	if (expected !== undefined && id !== expected) throw new WriteFailure({ code: 'account_changed' })
	const account = id ? await getAccount(id) : undefined
	if (account?.appPassword === null) throw new WriteFailure({ code: 'session_revoked' })
	const privateKey = account ? await getKey(account.id) : null
	if (!account || !privateKey) throw new WriteFailure({ code: 'locked' })
	return { account, client: createClient(account), privateKey }
}

/** Certificates already proven to belong to the account's private key, per account. */
const publicKeys = new Map<string, Promise<CryptoKey>>()

/**
 * The cached suite's certificate, once it proved to match the unlocked private key. Sync takes the
 * certificate from the server, so a compromised server could swap in its own; only the private key,
 * which only the master password opens, cannot be swapped.
 */
async function publicKey({ account, client, privateKey }: Context): Promise<CryptoKey> {
	const suite = (await browser.storage.local.get(suiteKey(account.id)))[suiteKey(account.id)] as CachedSuite | undefined
	if (!suite) throw new WriteFailure({ code: 'locked' })
	// The server stamps a write with its active suite, whatever key it was encrypted to, so after a
	// key change on another device the item would open for no one. The sync locks on the new suite.
	const active = (await client.listSuites()).find((row) => row.status === 'active')
	if (active && active.id !== suite.id) {
		await syncAfterWrite(account.id)
		throw new WriteFailure({ code: 'locked' })
	}
	const cacheKey = `${account.id}\n${suite.certificate}`
	let key = publicKeys.get(cacheKey)
	if (!key) {
		key = matchingKey(suite.certificate, privateKey)
		publicKeys.set(cacheKey, key)
		key.catch(() => publicKeys.delete(cacheKey))
	}
	return key
}

async function matchingKey(certificate: string, privateKey: CryptoKey): Promise<CryptoKey> {
	const key = await importPublicKey(certificate)
	const probe = toBase64(crypto.getRandomValues(new Uint8Array(32)))
	const opened = await rsaDecrypt(await rsaEncrypt(probe, key), privateKey).catch(() => null)
	if (opened !== probe) {
		console.error('[keepiq] the suite certificate does not match the unlocked key; refusing to encrypt to it')
		throw new WriteFailure({ code: 'key_mismatch' })
	}
	return key
}

function writeError(error: unknown): VaultWriteError {
	if (error instanceof WriteFailure) return error.error
	if (error instanceof SessionRevoked) return { code: 'session_revoked' }
	if (error instanceof VaultWriteLocked) return { code: 'write_locked' }
	if (error instanceof Offline) return { code: 'offline' }
	if (error instanceof ApiError) {
		if (error.status === 400) return { code: 'invalid' }
		// A blocked suite, a folder someone else owns and a read-only copy are all a bare refusal; only a policy names itself.
		if (error.refused) return { code: error.code ? 'policy' : 'forbidden' }
		if (error.status === 404) return { code: 'not_found' }
		if (error.status === 409) return { code: 'conflict' }
		if (error.status >= 500) return { code: 'server_error' }
	}
	console.error('[keepiq] write failed', error)
	return { code: 'unknown' }
}

/** Runs one request, records whether the server answered, and replies once the sync holds the change. */
async function run<T>(write: (context: Context) => Promise<T>, then: 'sync' | 'reply', accountId: string | undefined): Promise<WriteResult<T>> {
	let ctx: Context
	try {
		ctx = await context(accountId)
	} catch (error) {
		return { ok: false, error: writeError(error) }
	}
	try {
		const value = await write(ctx)
		await recordOutcome(ctx.account.id, null)
		if (then === 'sync') await syncAfterWrite(ctx.account.id)
		return { ok: true, value }
	} catch (error) {
		// A refusal of our own may come before any request, so it says nothing about the server.
		if (!(error instanceof WriteFailure)) await recordOutcome(ctx.account.id, error)
		return { ok: false, error: writeError(error) }
	}
}

const secretPath = (id: string) => `/api/v1/secrets/${encodeURIComponent(id)}`
const folderPath = (id: string) => `/api/v1/folders/${encodeURIComponent(id)}`

async function typeNameOf(accountId: string, typeId: string | null): Promise<string | undefined> {
	return (await readSnapshot(accountId))?.types.find((type) => type.id === typeId)?.name
}

function isRestricted(row: Pick<SecretRow, 'useOnly' | 'readOnly' | 'accessExpiresAt'>, purpose: 'edit' | 'clone'): boolean {
	return row.useOnly === true || row.readOnly === true || (purpose === 'clone' && !!row.accessExpiresAt)
}

/** Fresh from the server, not the cache: a stale `additionalFields` saved back would drop members (ADR-002). */
export function fetchItem(id: string, purpose: 'edit' | 'clone' = 'edit', accountId?: string): Promise<WriteResult<EditableItem>> {
	return run(async ({ account, client, privateKey }) => {
		let row: OpenSecretRow
		try {
			row = await client.keepiq<OpenSecretRow>('GET', secretPath(id))
		} catch (error) {
			// The list still shows it until the next sync says otherwise.
			if (error instanceof ApiError && error.status === 404) void syncAfterWrite(account.id)
			// A row on a blocked suite is refused, not sent blocked; the sync marks it, with its reason as a code.
			if (error instanceof ApiError && error.refused) {
				await syncAfterWrite(account.id)
				const cached = (await readSnapshot(account.id))?.secrets.find((secret) => secret.id === id)
				if (cached?.blocked) throw new WriteFailure({ code: 'blocked', reason: cached.blockedReason })
			}
			throw error
		}
		// A use-only value is never shown, a read-only copy never changed, and a clone of any
		// restricted copy would be a permanent, unrestricted one (Keepiq `use-only-shares`, `expiring-shares`).
		if (isRestricted(row, purpose)) throw new WriteFailure({ code: 'restricted' })
		const passkey = await typeNameOf(account.id, row.typeId) === 'passkey'
		try {
			const additional = row.additionalFields ? parseObject(await rsaDecrypt(row.additionalFields, privateKey)) : {}
			if (!additional) throw new Error('additionalFields is not an object')
			const item: EditableItem = {
				id: row.id,
				typeId: row.typeId ?? '',
				name: row.name,
				url: row.url,
				folderId: row.folderId,
				key: passkey ? '' : await rsaDecrypt(row.key, privateKey),
				login: row.login ? await rsaDecrypt(row.login, privateKey) : null,
				additionalFields: Object.fromEntries(Object.entries(additional).map(([name, value]) => [name, typeof value === 'string' ? value : JSON.stringify(value)])),
				updatedAt: row.updatedAt,
			}
			if (passkey) item.keyWithheld = true
			return item
		} catch (error) {
			console.error('[keepiq] could not open', id, 'for editing', error)
			throw new WriteFailure({ code: 'unreadable' })
		}
	}, 'reply', accountId)
}

function isDraft(draft: unknown): draft is ItemDraft {
	const d = draft as Partial<ItemDraft> | null
	return typeof d?.name === 'string' && typeof d.typeId === 'string' && typeof d.key === 'string'
		&& typeof d.additionalFields === 'object' && d.additionalFields !== null
}

export function createItem(draft: ItemDraft, accountId?: string): Promise<WriteResult<{ id: string }>> {
	return run(async (ctx) => {
		if (!isDraft(draft)) throw new WriteFailure({ code: 'invalid' })
		const key = await publicKey(ctx)
		const body: Record<string, unknown> = {
			name: draft.name,
			url: draft.url || null,
			typeId: draft.typeId,
			folderId: draft.folderId,
			key: await rsaEncrypt(draft.key, key),
			additionalFields: await rsaEncrypt(JSON.stringify(draft.additionalFields), key),
		}
		if (draft.login) body.login = await rsaEncrypt(draft.login, key)
		const row = await ctx.client.keepiq<SecretRow>('POST', '/api/v1/secrets', body)
		return { id: row.id }
	}, 'sync', accountId)
}

/** A sparse `PUT`: only what the patch holds is encrypted and sent. */
export function updateItem(id: string, patch: ItemPatch, baseUpdatedAt?: string, accountId?: string): Promise<WriteResult<{ id: string }>> {
	return run(async (ctx) => {
		const { account, client } = ctx
		const cached = (await readSnapshot(account.id))?.secrets.find((secret) => secret.id === id)
		// Its limits and type decide what may be written, so an item the cache does not hold is not.
		if (!cached) throw new WriteFailure({ code: 'not_found' })
		// What the server enforces too, refused here so no ciphertext is made for it.
		if (cached.useOnly) throw new WriteFailure({ code: 'restricted' })
		if (cached.readOnly && Object.keys(patch).some((field) => field !== 'folderId')) throw new WriteFailure({ code: 'restricted' })
		const body: Record<string, unknown> = {}
		if (patch.name !== undefined) {
			if (typeof patch.name !== 'string' || !patch.name.trim()) throw new WriteFailure({ code: 'invalid' })
			body.name = patch.name
		}
		if (patch.typeId) body.typeId = patch.typeId
		if (patch.url !== undefined) body.url = patch.url || null
		if (patch.folderId !== undefined) body.folderId = patch.folderId || null
		let key: Promise<CryptoKey> | undefined
		const encrypt = async (value: string) => rsaEncrypt(value, await (key ??= publicKey(ctx)))
		// A passkey's credential is only ever written by a passkey ceremony.
		const passkey = await typeNameOf(account.id, cached.typeId) === 'passkey'
		if (typeof patch.key === 'string' && !passkey) body.key = await encrypt(patch.key)
		if (patch.login !== undefined) body.login = patch.login ? await encrypt(patch.login) : null
		if (patch.additionalFields) body.additionalFields = await encrypt(JSON.stringify(patch.additionalFields))
		if (baseUpdatedAt) body.baseUpdatedAt = baseUpdatedAt
		await client.keepiq('PUT', secretPath(id), body)
		return { id }
	}, 'sync', accountId)
}

/** Moves the item to Keepiq's trash, which also revokes its shares; restoring is the web app's. */
export function deleteItem(id: string, accountId?: string): Promise<WriteResult<null>> {
	return run(async ({ client }) => {
		await client.keepiq('DELETE', secretPath(id))
		return null
	}, 'sync', accountId)
}

/** The server's own rules (`FolderTreeService`), checked first so a typo costs no round trip. */
function folderName(name: string): string {
	const trimmed = typeof name === 'string' ? name.trim() : ''
	if (!trimmed || trimmed.includes('/')) throw new WriteFailure({ code: 'invalid' })
	return trimmed
}

export function createFolder(name: string, parentId: string | null, accountId?: string): Promise<WriteResult<{ id: string }>> {
	return run(async ({ client }) => {
		const row = await client.keepiq<FolderRow>('POST', '/api/v1/folders', { name: folderName(name), parentId })
		return { id: row.id }
	}, 'sync', accountId)
}

export function renameFolder(id: string, name: string, accountId?: string): Promise<WriteResult<null>> {
	return run(async ({ client }) => {
		await client.keepiq('PUT', folderPath(id), { name: folderName(name) })
		return null
	}, 'sync', accountId)
}

export function folderChildren(id: string, accountId?: string): Promise<WriteResult<FolderChildren>> {
	return run(({ client }) => client.keepiq<FolderChildren>('GET', `${folderPath(id)}/children`), 'reply', accountId)
}

/** Keepiq's three delete modes (`FolderDeletionService::delete`): empty, a leaf with items, or a folder with subfolders. */
export function deleteFolder(id: string, plan: FolderDeletePlan, accountId?: string): Promise<WriteResult<null>> {
	return run(async ({ client }) => {
		if (plan.mode === 'cascade') await client.keepiq('DELETE', `${folderPath(id)}?cascade=${plan.cascade === 'delete' ? 'delete' : 'move'}`)
		else if (plan.mode === 'resolution') await client.keepiq('DELETE', folderPath(id), { directSecrets: plan.directSecrets, subfolders: plan.subfolders })
		else await client.keepiq('DELETE', folderPath(id))
		return null
	}, 'sync', accountId)
}
