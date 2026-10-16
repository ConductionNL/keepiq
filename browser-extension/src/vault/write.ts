/** Item and folder writes for the active account. All encryption happens here, never in the popup (ADR-004). */
import { ApiError, createClient, type Client } from '@/src/api/client'
import type { CachedSuite, FolderRow, OpenSecretRow, SecretRow } from '@/src/api/types'
import { getAccount, getActiveAccountId, suiteKey, type AccountRecord } from '@/src/accounts/store'
import { importPublicKey, rsaDecrypt, rsaEncrypt, toBase64 } from '@/src/crypto'
import type { EditableItem, FolderChildren, FolderDeletePlan, ItemDraft, ItemPatch, ShareSaveNote, WriteResult } from '@/src/messages'
import { fieldTexts, shownInFull } from './fields'
import { editOpenedKey, getKey, hasKey, sessionValues } from './key-store'
import { parseObject } from './payloads'
import { isViewOnly, readWriteContext, shareSave, VALUE_FIELDS, type Opened, type Values, type WriteContext } from './share-save'
import { readSnapshot } from './store'
import { recordOutcome, syncAfterWrite } from './sync'
import type { StoredSecretRow } from './types'
import { WriteFailure, writeError } from './write-error'

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

/** From one snapshot read, since a sync may rename types in between. */
async function typeNamesOf(accountId: string, typeIds: Array<string | null>): Promise<Array<string | undefined>> {
	const types = (await readSnapshot(accountId))?.types
	return typeIds.map((typeId) => types?.find((type) => type.id === typeId)?.name)
}

const typeNameOf = async (accountId: string, typeId: string | null) => (await typeNamesOf(accountId, [typeId]))[0]

function isRestricted(row: Pick<SecretRow, 'useOnly' | 'readOnly' | 'accessExpiresAt'>, purpose: 'edit' | 'clone'): boolean {
	return row.useOnly === true || row.readOnly === true || (purpose === 'clone' && !!row.accessExpiresAt)
}

const cachedRow = async (accountId: string, id: string) => (await readSnapshot(accountId))?.secrets.find((secret) => secret.id === id)

const holds = (cached: StoredSecretRow | undefined, row: OpenSecretRow): cached is OpenSecretRow =>
	!!cached && !cached.blocked && VALUE_FIELDS.every((field) => (row[field] || null) === (cached[field] || null))

/**
 * Every item is encrypted to the same key, so a fetched ciphertext the cache does not hold for this item may be another's, and the form would save it back and share it as typed (ADR-002).
 * One sync lets a row written since the last one through.
 */
async function cachedMatch(accountId: string, id: string, row: OpenSecretRow): Promise<OpenSecretRow> {
	let cached = await cachedRow(accountId, id)
	if (holds(cached, row)) return cached
	// A sync never throws, so an outage would otherwise read as a swapped ciphertext.
	const { lastError } = await syncAfterWrite(accountId)
	if (lastError) throw new WriteFailure({ code: lastError === 'network' ? 'offline' : lastError === 'busy' ? 'write_locked' : 'server_error' })
	cached = await cachedRow(accountId, id)
	if (holds(cached, row)) return cached
	if (!cached) throw new WriteFailure({ code: 'not_found' })
	if (cached.blocked) throw new WriteFailure({ code: 'blocked', reason: cached.blockedReason })
	console.error('[keepiq] the server answered', id, 'with ciphertext the vault does not hold for it; not opening it')
	throw new WriteFailure({ code: 'conflict' })
}

/**
 * What each edit form opened, by its `recordId`, for its save to send partners exactly that (`share-save.ts`).
 * Not the cache, since a sync in between may put another item's ciphertext in the row (ADR-002). In session storage, since the MV3 worker stops while a form is open.
 */
type FormRecord = Opened & { id: string; recordId: string }

/** A form closed without saving leaves its entry behind, and a ciphertext may be large. */
const RECORD_LIMIT = 10

/** The list is read, changed and written back, so two forms opening at once must not interleave. */
let records: Promise<unknown> = Promise.resolve()
function queued<T>(task: () => Promise<T>): Promise<T> {
	const next = records.then(task)
	records = next.catch(() => undefined)
	return next
}

async function readRecords(key: string): Promise<FormRecord[]> {
	try {
		const list: unknown = JSON.parse(await sessionValues.get(key) ?? '[]')
		return Array.isArray(list) ? list as FormRecord[] : []
	} catch {
		return []
	}
}

/** Sets the record of one edit form, or drops it for `undefined`. */
function swapRecord(accountId: string, id: string, recordId: string, value: Opened | undefined): Promise<void> {
	return queued(async () => {
		const key = editOpenedKey(accountId)
		const list = await readRecords(key)
		const others = list.filter((entry) => entry.id !== id || entry.recordId !== recordId)
		if (!value && others.length === list.length) return
		await sessionValues.set({ [key]: JSON.stringify((value ? [...others, { ...value, id, recordId }] : others).slice(-RECORD_LIMIT)) })
		// A lock that cleared the list while this ran must not find it back.
		if (!(await hasKey(accountId))) await sessionValues.remove([key])
	})
}

async function findRecord(accountId: string, id: string, recordId: string): Promise<Opened | undefined> {
	const entry = (await readRecords(editOpenedKey(accountId))).find((candidate) => candidate.id === id && candidate.recordId === recordId)
	if (typeof entry?.shown !== 'object' || entry.shown === null || typeof entry.updatedAt !== 'string') return undefined
	return { shown: entry.shown, updatedAt: entry.updatedAt, sourceUpdatedAt: typeof entry.sourceUpdatedAt === 'string' ? entry.sourceUpdatedAt : null }
}

/** Session storage may refuse a write, as when full; the form then opens or saves without its record. */
function unrecorded(error: unknown): undefined {
	console.error('[keepiq] could not update what an edit form opened; going without', error)
	return undefined
}

/** Only what the form shows in full under the type it opens with and the cached one; an empty field hides nothing. */
async function recordOpened(accountId: string, id: string, recordId: string, row: OpenSecretRow, typeNames: Array<string | undefined>, plain: Values, sourceUpdatedAt: string | null): Promise<void> {
	const shown = VALUE_FIELDS.filter((field) => !row[field] || (plain[field] !== undefined && typeNames.every((name) => shownInFull(field, plain[field]!, name))))
	// Refused, the form opens without it, and a save of it that needs the record for partners fails with `unverified`.
	await swapRecord(accountId, id, recordId, { shown: Object.fromEntries(shown.map((field) => [field, row[field] || null])), updatedAt: row.updatedAt, sourceUpdatedAt }).catch(unrecorded)
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
				const cached = await cachedRow(account.id, id)
				if (cached?.blocked) throw new WriteFailure({ code: 'blocked', reason: cached.blockedReason })
			}
			throw error
		}
		// A use-only value is never shown, a read-only copy never changed, and a clone of any
		// restricted copy would be a permanent, unrestricted one (Keepiq `use-only-shares`, `expiring-shares`).
		if (isRestricted(row, purpose)) throw new WriteFailure({ code: 'restricted' })
		const cached = await cachedMatch(account.id, id, row)
		let sourceUpdatedAt: string | null = null
		if (purpose === 'edit') {
			const writeContext = await readWriteContext(client, id)
			if (isViewOnly(id, writeContext)) throw new WriteFailure({ code: 'view_only' })
			// The source version this edit is made from, which a save through a copy must name.
			sourceUpdatedAt = writeContext.sourceUpdatedAt
		}
		const typeNames = await typeNamesOf(account.id, [row.typeId, cached.typeId])
		// Either side naming a passkey withholds it, so a swapped type id does not open the credential.
		const passkey = typeNames.includes('passkey')
		let item: EditableItem
		let fields: string | null
		try {
			fields = row.additionalFields ? await rsaDecrypt(row.additionalFields, privateKey) : null
			const additional = fields === null ? {} : parseObject(fields)
			if (!additional) throw new Error('additionalFields is not an object')
			item = {
				// The id checked against the cache, since the form saves to this one.
				id,
				typeId: row.typeId ?? '',
				// So the form never offers the withheld key for editing.
				typeName: passkey ? 'passkey' : typeNames[0],
				name: row.name,
				url: row.url,
				folderId: row.folderId,
				key: passkey ? '' : await rsaDecrypt(row.key, privateKey),
				login: row.login ? await rsaDecrypt(row.login, privateKey) : null,
				additionalFields: fieldTexts(additional),
				updatedAt: row.updatedAt,
			}
		} catch (error) {
			console.error('[keepiq] could not open', id, 'for editing', error)
			throw new WriteFailure({ code: 'unreadable' })
		}
		if (purpose === 'edit') {
			item.recordId = crypto.randomUUID()
			await recordOpened(account.id, id, item.recordId, row, typeNames, { key: passkey ? undefined : item.key, login: item.login, additionalFields: fields }, sourceUpdatedAt)
		}
		return item
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

/**
 * A sparse save: only what the patch holds is encrypted and sent. A changed password, username or field goes through share-save, which writes every copy in the same request or nothing (`share-save.ts`);
 * anything else is a plain `PUT`.
 */
export function updateItem(id: string, patch: ItemPatch, baseUpdatedAt?: string, accountId?: string, recordId?: string, withoutUnreachable: readonly string[] = []): Promise<WriteResult<{ id: string; shareSave?: ShareSaveNote }>> {
	return run(async (ctx) => {
		const { account, client, privateKey } = ctx
		const cached = await cachedRow(account.id, id)
		// Its limits and type decide what may be written, so an item the cache does not hold is not.
		if (!cached) throw new WriteFailure({ code: 'not_found' })
		// What the server enforces too, refused here so no ciphertext is made for it.
		if (cached.useOnly) throw new WriteFailure({ code: 'restricted' })
		const moveOnly = Object.keys(patch).every((field) => field === 'folderId')
		if (cached.readOnly && !moveOnly) throw new WriteFailure({ code: 'restricted' })
		const body: Record<string, unknown> = {}
		if (patch.name !== undefined) {
			if (typeof patch.name !== 'string' || !patch.name.trim()) throw new WriteFailure({ code: 'invalid' })
			body.name = patch.name
		}
		if (patch.typeId) body.typeId = patch.typeId
		if (patch.url !== undefined) body.url = patch.url || null
		if (patch.folderId !== undefined) body.folderId = patch.folderId || null
		// Kept as typed for the copies, which get it encrypted to each holder.
		const plain: Values = {}
		// A passkey's credential is only ever written by a passkey ceremony.
		const passkey = await typeNameOf(account.id, cached.typeId) === 'passkey'
		if (typeof patch.key === 'string' && !passkey) plain.key = patch.key
		if (patch.login !== undefined) plain.login = patch.login || null
		if (patch.additionalFields) plain.additionalFields = JSON.stringify(patch.additionalFields)
		const valueChange = VALUE_FIELDS.some((field) => plain[field] !== undefined)
		// share-save refuses a save without the version it was made from.
		if (valueChange && !baseUpdatedAt) throw new WriteFailure({ code: 'invalid' })
		// The grade may have been lowered since the form opened; a move files the holder's own copy, so it needs none.
		let writeContext: WriteContext | undefined
		if (!moveOnly) {
			writeContext = await readWriteContext(client, id)
			if (isViewOnly(id, writeContext)) throw new WriteFailure({ code: 'view_only' })
		}
		let key: Promise<CryptoKey> | undefined
		for (const field of VALUE_FIELDS) {
			const value = plain[field]
			if (value !== undefined) body[field] = value === null ? null : await rsaEncrypt(value, await (key ??= publicKey(ctx)))
		}
		let note: ShareSaveNote | undefined
		if (valueChange) {
			const opened = recordId ? await findRecord(account.id, id, recordId) : undefined
			note = await shareSave(client, privateKey, { accountId: account.id, id, patch: body, plain, baseUpdatedAt: baseUpdatedAt!, context: writeContext!, opened, withoutUnreachable: new Set(withoutUnreachable) })
		} else {
			await client.keepiq('PUT', secretPath(id), baseUpdatedAt ? { ...body, baseUpdatedAt } : body)
		}
		// Its form closes; a failed save keeps it, so saving again still sends what the form showed.
		if (recordId) await swapRecord(account.id, id, recordId, undefined).catch(unrecorded)
		return note ? { id, shareSave: note } : { id }
	}, 'sync', accountId)
}

/** For the item's detail to show Edit as unavailable up front; `fetchItem` and `updateItem` check again. */
export function itemEditable(id: string, accountId?: string): Promise<WriteResult<{ editable: boolean }>> {
	return run(async ({ client }) => ({ editable: !isViewOnly(id, await readWriteContext(client, id)) }), 'reply', accountId)
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
