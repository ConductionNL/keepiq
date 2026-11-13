/** Item and folder writes for the active account. All encryption happens here, never in the popup (ADR-004). */
import { ApiError, createClient, type Client } from '@/src/api/client'
import type { CachedSuite, FolderRow, OpenSecretRow, SecretRow } from '@/src/api/types'
import { getAccount, getActiveAccountId, suiteKey, type AccountRecord } from '@/src/accounts/store'
import { rsaDecrypt, rsaEncrypt } from '@/src/crypto'
import type { EditableItem, FolderChildren, FolderDeletePlan, ItemDraft, ItemPatch, ShareSaveNote, WriteResult } from '@/src/messages'
import { provenKey } from './certificate'
import { blobText, fieldTexts, shownInFull } from './fields'
import { editOpenedKey, getKey, hasKey, sessionValues } from './key-store'
import { parseObject } from './payloads'
import { buildPasskeyCredential, passkeyEntries, storedArray, withCounter, withPasskey, type PasskeyCredential } from '@/src/passkey/credential'
import { isViewOnly, readWriteContext, sealValue, shareSave, VALUE_FIELDS, type Opened, type Values, type WriteContext } from './share-save'
import { readSnapshot } from './store'
import { isAuthenticatorKey, normaliseAuthenticatorKey } from '@/src/totp/authenticator-key'
import { recordOutcome, syncAfterWrite } from './sync'
import type { StoredSecretRow, VaultSnapshot } from './types'
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
	const account = await expectedAccount(expected)
	if (account?.appPassword === null) throw new WriteFailure({ code: 'session_revoked' })
	const privateKey = account ? await getKey(account.id) : null
	if (!account || !privateKey) throw new WriteFailure({ code: 'locked' })
	return { account, client: createClient(account), privateKey }
}

/** The active account, when it is the one the popup showed. */
async function expectedAccount(expected: string | undefined): Promise<AccountRecord | undefined> {
	const id = await getActiveAccountId()
	if (expected !== undefined && id !== expected) throw new WriteFailure({ code: 'account_changed' })
	return id ? await getAccount(id) : undefined
}

/** Certificates already proven to belong to the account's private key, per account. */
const publicKeys = new Map<string, Promise<CryptoKey>>()

/** The cached suite's certificate, once it proved to match the unlocked private key (`certificate.ts`). */
async function publicKey(ctx: Context): Promise<CryptoKey> {
	const { account } = ctx
	const suite = (await browser.storage.local.get(suiteKey(account.id)))[suiteKey(account.id)] as CachedSuite | undefined
	if (!suite) throw new WriteFailure({ code: 'locked' })
	await refuseReplacedSuite(ctx, suite.id)
	return provenPublicKey(ctx, suite.certificate)
}

/**
 * The server stamps a write with its active suite, whatever key it was encrypted to, so after a
 * key change on another device the item would open for no one. The sync locks on the new suite.
 */
async function refuseReplacedSuite({ account, client }: Context, suiteId: string): Promise<void> {
	const active = (await client.listSuites()).find((row) => row.status === 'active')
	if (active && active.id !== suiteId) {
		await syncAfterWrite(account.id)
		throw new WriteFailure({ code: 'locked' })
	}
}

function provenPublicKey({ account, privateKey }: Context, certificate: string): Promise<CryptoKey> {
	const cacheKey = `${account.id}\n${certificate}`
	let key = publicKeys.get(cacheKey)
	if (!key) {
		key = matchingKey(certificate, privateKey)
		publicKeys.set(cacheKey, key)
		key.catch(() => publicKeys.delete(cacheKey))
	}
	return key
}

async function matchingKey(certificate: string, privateKey: CryptoKey): Promise<CryptoKey> {
	const key = await provenKey(certificate, privateKey)
	if (!key) {
		console.error('[keepiq] the suite certificate does not match the unlocked key; refusing to encrypt to it')
		throw new WriteFailure({ code: 'key_mismatch' })
	}
	return key
}

/** Runs one request, records whether the server answered, and replies once the sync holds the change. */
export async function run<T>(write: (context: Context) => Promise<T>, then: 'sync' | 'reply', accountId: string | undefined): Promise<WriteResult<T>> {
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
	if (lastError) throw new WriteFailure({ code: syncFailureCode(lastError) })
	cached = await cachedRow(accountId, id)
	if (holds(cached, row)) return cached
	return refuseMismatch(cached, id)
}

function syncFailureCode(lastError: string): 'offline' | 'write_locked' | 'server_error' {
	if (lastError === 'network') return 'offline'
	return lastError === 'busy' ? 'write_locked' : 'server_error'
}

/** Why a fetched row the cache does not hold, even after a sync, is not opened. */
function refuseMismatch(cached: StoredSecretRow | undefined, id: string): never {
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

/** One fetch of an item for its edit or clone form. */
interface ItemFetch {
	ctx: Context
	id: string
	purpose: 'edit' | 'clone'
}

/** A fetched row on its way into the form, with the type names either side gave it. */
interface ItemOpening {
	fetch: ItemFetch
	row: OpenSecretRow
	typeNames: Array<string | undefined>
	passkey: boolean
}

/** The decrypted form an edit opened, with the version of the source it was made from. */
interface OpenedEdit {
	item: EditableItem
	fields: string | null
	sourceUpdatedAt: string | null
}

/**
 * Only what the form shows in full under the type it opens with and the cached one; an empty field hides nothing.
 * A key and passkeys are never shown, by design, so they count as opened: `holds()` checked them against the cache.
 */
async function recordOpened(opening: ItemOpening, edit: OpenedEdit): Promise<void> {
	const { fetch: { ctx, id }, row, typeNames, passkey } = opening
	const recordId = crypto.randomUUID()
	edit.item.recordId = recordId
	const plain: Values = { key: passkey ? undefined : edit.item.key, login: edit.item.login, additionalFields: edit.fields }
	const shown = VALUE_FIELDS.filter((field) => field === 'totp' || field === 'passkeys' || !row[field] || (plain[field] !== undefined && typeNames.every((name) => shownInFull(field, plain[field]!, name))))
	// Refused, the form opens without it, and a save of it that needs the record for partners fails with `unverified`.
	await swapRecord(ctx.account.id, id, recordId, { shown: Object.fromEntries(shown.map((field) => [field, row[field] || null])), updatedAt: row.updatedAt, sourceUpdatedAt: edit.sourceUpdatedAt }).catch(unrecorded)
}

/** Fresh from the server, not the cache: a stale `additionalFields` saved back would drop members (ADR-002). */
export function fetchItem(id: string, purpose: 'edit' | 'clone' = 'edit', accountId?: string): Promise<WriteResult<EditableItem>> {
	return run((ctx) => openItem({ ctx, id, purpose }), 'reply', accountId)
}

async function openItem(fetch: ItemFetch): Promise<EditableItem> {
	const { ctx, id, purpose } = fetch
	const row = await fetchRow(fetch)
	// A use-only value is never shown, a read-only copy never changed, and a clone of any
	// restricted copy would be a permanent, unrestricted one (Keepiq `use-only-shares`, `expiring-shares`).
	if (isRestricted(row, purpose)) throw new WriteFailure({ code: 'restricted' })
	const cached = await cachedMatch(ctx.account.id, id, row)
	const sourceUpdatedAt = await editSource(fetch)
	const typeNames = await typeNamesOf(ctx.account.id, [row.typeId, cached.typeId])
	// Either side naming a passkey withholds it, so a swapped type id does not open the credential.
	const passkey = typeNames.includes('passkey')
	// A clone would save a passkey item without its credential, which only a passkey ceremony writes.
	if (passkey && purpose === 'clone') throw new WriteFailure({ code: 'passkey_clone' })
	const opening: ItemOpening = { fetch, row, typeNames, passkey }
	const { item, fields } = await decryptedItem(opening)
	if (purpose === 'edit') await recordOpened(opening, { item, fields, sourceUpdatedAt })
	return item
}

async function fetchRow(fetch: ItemFetch): Promise<OpenSecretRow> {
	try {
		return await fetch.ctx.client.keepiq<OpenSecretRow>('GET', secretPath(fetch.id))
	} catch (error) {
		await failedFetch(fetch, error)
		throw error
	}
}

async function failedFetch(fetch: ItemFetch, error: unknown): Promise<void> {
	// The list still shows it until the next sync says otherwise.
	if (error instanceof ApiError && error.status === 404) void syncAfterWrite(fetch.ctx.account.id)
	// A row on a blocked suite is refused, not sent blocked; the sync marks it, with its reason as a code.
	if (error instanceof ApiError && error.refused) await refuseBlocked(fetch)
}

async function refuseBlocked(fetch: ItemFetch): Promise<void> {
	await syncAfterWrite(fetch.ctx.account.id)
	const cached = await cachedRow(fetch.ctx.account.id, fetch.id)
	if (cached?.blocked) throw new WriteFailure({ code: 'blocked', reason: cached.blockedReason })
}

/** The source version this edit is made from, which a save through a copy must name; a clone names none. */
async function editSource(fetch: ItemFetch): Promise<string | null> {
	if (fetch.purpose !== 'edit') return null
	const writeContext = await readWriteContext(fetch.ctx.client, fetch.id)
	if (isViewOnly(fetch.id, writeContext)) throw new WriteFailure({ code: 'view_only' })
	return writeContext.sourceUpdatedAt
}

async function decryptedItem(opening: ItemOpening): Promise<{ item: EditableItem; fields: string | null }> {
	const { fetch: { ctx, id }, row } = opening
	try {
		const fields = row.additionalFields ? await rsaDecrypt(row.additionalFields, ctx.privateKey) : null
		const additional = fields === null ? {} : parseObject(fields)
		if (!additional) throw new Error('additionalFields is not an object')
		return { item: await editableItem(opening, additional), fields }
	} catch (error) {
		console.error('[keepiq] could not open', id, 'for editing', error)
		throw new WriteFailure({ code: 'unreadable' })
	}
}

async function editableItem(opening: ItemOpening, additional: Record<string, unknown>): Promise<EditableItem> {
	const { fetch: { id }, row, typeNames, passkey } = opening
	return {
		// The id checked against the cache, since the form saves to this one.
		id,
		typeId: row.typeId ?? '',
		// So the form never offers the withheld key for editing.
		typeName: passkey ? 'passkey' : typeNames[0],
		name: row.name,
		url: row.url,
		folderId: row.folderId,
		...await secretValues(opening),
		additionalFields: fieldTexts(additional),
		updatedAt: row.updatedAt,
		...totpFlags(opening),
	}
}

async function secretValues({ fetch: { ctx }, row, passkey }: ItemOpening): Promise<Pick<EditableItem, 'key' | 'login'>> {
	return {
		key: passkey ? '' : await rsaDecrypt(row.key, ctx.privateKey),
		login: row.login ? await rsaDecrypt(row.login, ctx.privateKey) : null,
	}
}

/** Never decrypted for the form: a saved key is shown as saved, not as text (Keepiq `login-one-time-codes`). */
function totpFlags({ fetch: { purpose }, row }: ItemOpening): Pick<EditableItem, 'totpSaved' | 'totpLeftOut'> {
	return {
		totpSaved: purpose === 'edit' && !!row.totp,
		...(purpose === 'clone' && !!row.totp && { totpLeftOut: true as const }),
	}
}

function isDraft(draft: unknown): draft is ItemDraft {
	const d = draft as Partial<ItemDraft> | null
	return typeof d?.name === 'string' && typeof d.typeId === 'string' && typeof d.key === 'string' && hasDraftExtras(d)
}

/** Additional fields as an object, and a `totp` that is text when given. */
function hasDraftExtras(d: Partial<ItemDraft>): boolean {
	return typeof d.additionalFields === 'object' && d.additionalFields !== null && (d.totp === undefined || typeof d.totp === 'string')
}

/** The key as stored, checked again here since this is what writes it; only a login takes a new one (Keepiq `login-one-time-codes`). */
async function authenticatorKey(accountId: string, raw: unknown, typeId: string | null): Promise<string> {
	if (typeof raw !== 'string') throw new WriteFailure({ code: 'invalid' })
	const key = normaliseAuthenticatorKey(raw)
	if (key && (!isAuthenticatorKey(key) || await typeNameOf(accountId, typeId) !== 'login')) throw new WriteFailure({ code: 'invalid' })
	return key
}

/** A new item's fields, its values encrypted to the account's key; no username or key without one. */
async function sealedBody(draft: ItemDraft, sealing: { key: CryptoKey; totp: string }): Promise<Record<string, unknown>> {
	const { key, totp } = sealing
	const body: Record<string, unknown> = {
		name: draft.name,
		url: draft.url || null,
		typeId: draft.typeId,
		folderId: draft.folderId,
		key: await rsaEncrypt(draft.key, key),
		additionalFields: await rsaEncrypt(blobText(draft.additionalFields), key),
	}
	if (draft.login) body.login = await rsaEncrypt(draft.login, key)
	if (totp) body.totp = await rsaEncrypt(totp, key)
	return body
}

export function createItem(draft: ItemDraft, accountId?: string): Promise<WriteResult<{ id: string }>> {
	return run(async (ctx) => {
		if (!isDraft(draft)) throw new WriteFailure({ code: 'invalid' })
		const totp = draft.totp === undefined ? '' : await authenticatorKey(ctx.account.id, draft.totp, draft.typeId)
		const body = await sealedBody(draft, { key: await publicKey(ctx), totp })
		const row = await ctx.client.keepiq<SecretRow>('POST', '/api/v1/secrets', body)
		return { id: row.id }
	}, 'sync', accountId)
}

/** One sparse save of an item, as `updateItem` was asked for it. */
interface ItemUpdate {
	id: string
	patch: ItemPatch
	baseUpdatedAt?: string
	recordId?: string
	withoutUnreachable: readonly string[]
}

/** The state of one item save: who saves, what was asked, and the cached row its limits and type come from. */
interface ItemSaveRun {
	ctx: Context
	update: ItemUpdate
	cached: StoredSecretRow
	moveOnly: boolean
}

/** What a value change sends through share-save: the request body, the values as typed, and the grade read for the save. */
interface ItemValues {
	body: Record<string, unknown>
	plain: Values
	writeContext: WriteContext | undefined
}

/**
 * A sparse save: only what the patch holds is encrypted and sent. A changed password, username or field goes through share-save, which writes every copy in the same request or nothing (`share-save.ts`);
 * anything else is a plain `PUT`.
 */
export function updateItem(id: string, patch: ItemPatch, baseUpdatedAt?: string, accountId?: string, recordId?: string, withoutUnreachable: readonly string[] = []): Promise<WriteResult<{ id: string; shareSave?: ShareSaveNote }>> {
	return run((ctx) => saveItem(ctx, { id, patch, baseUpdatedAt, recordId, withoutUnreachable }), 'sync', accountId)
}

async function saveItem(ctx: Context, update: ItemUpdate): Promise<{ id: string; shareSave?: ShareSaveNote }> {
	const save = await startItemSave(ctx, update)
	const body = metadataBody(update.patch)
	// Kept as typed for the copies, which get it encrypted to each holder.
	const plain = await plainValues(save)
	const valueChange = VALUE_FIELDS.some((field) => plain[field] !== undefined)
	// share-save refuses a save without the version it was made from.
	if (valueChange && !update.baseUpdatedAt) throw new WriteFailure({ code: 'invalid' })
	const writeContext = await writableContext(save)
	Object.assign(body, await sealedValues(ctx, plain))
	const note = valueChange ? await saveThroughCopies(save, { body, plain, writeContext }) : await putItem(save, body)
	return finishItemSave(save, note)
}

/** Its limits and type decide what may be written, so an item the cache does not hold is not. */
async function startItemSave(ctx: Context, update: ItemUpdate): Promise<ItemSaveRun> {
	const cached = await cachedRow(ctx.account.id, update.id)
	if (!cached) throw new WriteFailure({ code: 'not_found' })
	// What the server enforces too, refused here so no ciphertext is made for it.
	if (cached.useOnly) throw new WriteFailure({ code: 'restricted' })
	const moveOnly = Object.keys(update.patch).every((field) => field === 'folderId')
	if (cached.readOnly && !moveOnly) throw new WriteFailure({ code: 'restricted' })
	return { ctx, update, cached, moveOnly }
}

/** The fields sent as they are: the name, type, address and folder. */
function metadataBody(patch: ItemPatch): Record<string, unknown> {
	return { ...namingFields(patch), ...placementFields(patch) }
}

function namingFields(patch: ItemPatch): Record<string, unknown> {
	const body: Record<string, unknown> = {}
	if (patch.name !== undefined) body.name = checkedName(patch.name)
	if (patch.typeId) body.typeId = patch.typeId
	return body
}

function checkedName(name: unknown): string {
	if (typeof name !== 'string' || !name.trim()) throw new WriteFailure({ code: 'invalid' })
	return name
}

function placementFields(patch: ItemPatch): Record<string, unknown> {
	const body: Record<string, unknown> = {}
	if (patch.url !== undefined) body.url = patch.url || null
	if (patch.folderId !== undefined) body.folderId = patch.folderId || null
	return body
}

async function plainValues(save: ItemSaveRun): Promise<Values> {
	const { patch } = save.update
	const plain: Values = await keyValue(save)
	if (patch.login !== undefined) plain.login = patch.login || null
	if (patch.additionalFields) plain.additionalFields = blobText(patch.additionalFields)
	// `''` removes the saved key, on any type.
	if (patch.totp !== undefined) plain.totp = await authenticatorKey(save.ctx.account.id, patch.totp, save.cached.typeId)
	return plain
}

/** A passkey's credential is only ever written by a passkey ceremony. */
async function keyValue(save: ItemSaveRun): Promise<Values> {
	const passkey = await typeNameOf(save.ctx.account.id, save.cached.typeId) === 'passkey'
	const { key } = save.update.patch
	return typeof key === 'string' && !passkey ? { key } : {}
}

/** The grade may have been lowered since the form opened; a move files the holder's own copy, so it needs none. */
async function writableContext(save: ItemSaveRun): Promise<WriteContext | undefined> {
	if (save.moveOnly) return undefined
	const writeContext = await readWriteContext(save.ctx.client, save.update.id)
	if (isViewOnly(save.update.id, writeContext)) throw new WriteFailure({ code: 'view_only' })
	return writeContext
}

/** Each value the patch holds, encrypted to the account's own key, which is fetched once and only when there is one. */
async function sealedValues(ctx: Context, plain: Values): Promise<Record<string, unknown>> {
	const sealed: Record<string, unknown> = {}
	let key: Promise<CryptoKey> | undefined
	for (const field of VALUE_FIELDS) {
		const value = plain[field]
		if (value !== undefined) sealed[field] = await sealValue(field, value, await (key ??= publicKey(ctx)))
	}
	return sealed
}

async function saveThroughCopies(save: ItemSaveRun, values: ItemValues): Promise<ShareSaveNote | undefined> {
	const { ctx: { account, client, privateKey }, update } = save
	const opened = update.recordId ? await findRecord(account.id, update.id, update.recordId) : undefined
	return shareSave(client, privateKey, {
		accountId: account.id, id: update.id, patch: values.body, plain: values.plain, baseUpdatedAt: update.baseUpdatedAt!, context: values.writeContext!, opened,
		withoutUnreachable: new Set(update.withoutUnreachable),
	})
}

async function putItem(save: ItemSaveRun, body: Record<string, unknown>): Promise<undefined> {
	const { baseUpdatedAt, id } = save.update
	await save.ctx.client.keepiq('PUT', secretPath(id), baseUpdatedAt ? { ...body, baseUpdatedAt } : body)
	return undefined
}

/** Its form closes; a failed save keeps it, so saving again still sends what the form showed. */
async function finishItemSave(save: ItemSaveRun, note: ShareSaveNote | undefined): Promise<{ id: string; shareSave?: ShareSaveNote }> {
	const { ctx, update: { id, recordId } } = save
	if (recordId) await swapRecord(ctx.account.id, id, recordId, undefined).catch(unrecorded)
	return note ? { id, shareSave: note } : { id }
}

/** For the item's detail to show Edit as unavailable up front; `fetchItem` and `updateItem` check again. */
export function itemEditable(id: string, accountId?: string): Promise<WriteResult<{ editable: boolean }>> {
	return run(async ({ client }) => ({ editable: !isViewOnly(id, await readWriteContext(client, id)) }), 'reply', accountId)
}

/** A change to a login's `passkeys`; an existing entry is named by its `credentialId`. */
export type PasskeysChange =
	| { kind: 'add'; credential: PasskeyCredential }
	| { kind: 'replace'; credentialId: string; credential: PasskeyCredential }
	/** Raises the entry's counter past both the stored one and `seen`, the one the caller read, so a save after another client's still counts up. */
	| { kind: 'counter'; credentialId: string; seen: number }

export interface PasskeysSaved {
	id: string
	/** The entry the change wrote, with its counter as saved. */
	credentialId: string
	counter: number
	shareSave?: ShareSaveNote
}

/** Authenticator data holds the counter in four bytes. */
const MAX_COUNTER = 0xffffffff

const isCounter = (value: unknown): value is number => Number.isInteger(value) && (value as number) >= 0 && (value as number) <= MAX_COUNTER
const isText = (value: unknown): value is string => typeof value === 'string' && value !== ''

type ChangeShape = Partial<Record<'kind' | 'credentialId' | 'credential' | 'seen', unknown>>

/** What each kind of passkey change must carry. */
const CHANGE_SHAPES: Record<PasskeysChange['kind'], (c: ChangeShape) => boolean> = {
	add: (c) => withPasskey(null, c.credential as PasskeyCredential) !== null,
	replace: (c) => isText(c.credentialId) && withPasskey(null, c.credential as PasskeyCredential) !== null,
	counter: (c) => isText(c.credentialId) && isCounter(c.seen),
}

function isChange(change: PasskeysChange): boolean {
	const c = change as ChangeShape | null
	const kind = c?.kind
	return typeof kind === 'string' && Object.hasOwn(CHANGE_SHAPES, kind) && CHANGE_SHAPES[kind as PasskeysChange['kind']](c!)
}

type Changed = { value: string; credentialId: string; counter: number }

/** The stored plaintext with its entries, looked up by credential id. */
function storedPasskeys(stored: string | null) {
	const entries = passkeyEntries(stored)
	const named = (credentialId: string) => entries.filter(({ credential }) => credential.credentialId === credentialId)
	const only = (credentialId: string) => {
		const [entry, ...more] = named(credentialId)
		if (!entry) throw new WriteFailure({ code: 'not_found' })
		if (more.length > 0) throw new WriteFailure({ code: 'unreadable' })
		return entry
	}
	return { raw: stored, named, only }
}

type StoredPasskeys = ReturnType<typeof storedPasskeys>

function counterChanged(stored: StoredPasskeys, change: Extract<PasskeysChange, { kind: 'counter' }>): Changed | null {
	const { index, credential } = stored.only(change.credentialId)
	const counter = Math.max(isCounter(credential.counter) ? credential.counter : 0, change.seen) + 1
	if (counter > MAX_COUNTER) throw new WriteFailure({ code: 'invalid' })
	const value = withCounter(stored.raw ?? '', index, counter)
	return value === null ? null : { value, credentialId: change.credentialId, counter }
}

function passkeyChanged(stored: StoredPasskeys, change: Exclude<PasskeysChange, { kind: 'counter' }>): Changed | null {
	const { credentialId, counter } = buildPasskeyCredential(change.credential)!
	const index = change.kind === 'replace' ? stored.only(change.credentialId).index : undefined
	if (stored.named(credentialId).some((entry) => entry.index !== index)) throw new WriteFailure({ code: 'invalid' })
	const value = withPasskey(stored.raw, change.credential, index)
	return value === null ? null : { value, credentialId, counter }
}

/** The new plaintext, every other entry as stored; refuses rather than write a value that would lose one. */
function changed(stored: string | null, change: PasskeysChange): Changed {
	if (stored && !storedArray(stored)) throw new WriteFailure({ code: 'unreadable' })
	const passkeys = storedPasskeys(stored)
	const next = change.kind === 'counter' ? counterChanged(passkeys, change) : passkeyChanged(passkeys, change)
	if (next) return next
	throw new WriteFailure({ code: 'invalid' })
}

/** True when a field holds nothing to show: no ciphertext, `''`, or fields `{}`. */
async function holdsNothing(field: 'key' | 'login' | 'additionalFields', ciphertext: string | null, privateKey: CryptoKey): Promise<boolean> {
	if (!ciphertext) return true
	try {
		return isEmptyPlain(field, await rsaDecrypt(ciphertext, privateKey))
	} catch {
		return false
	}
}

function isEmptyPlain(field: 'key' | 'login' | 'additionalFields', plain: string): boolean {
	const fields = field === 'additionalFields' ? parseObject(plain) : null
	return plain === '' || (!!fields && Object.keys(fields).length === 0)
}

/**
 * Partner shares get the whole value, and a passkey save never showed the login's other fields, so it vouches only for those holding nothing; any other fails partners as `unverified` (design.md § Open Questions).
 * `totp` and `passkeys` count as opened, as for an edit form: `cachedMatch` checked them against the cache.
 */
async function passkeySaveOpened(row: OpenSecretRow, privateKey: CryptoKey, sourceUpdatedAt: string | null): Promise<Opened> {
	const shown: Values = checkedValues(row)
	for (const field of ['key', 'login', 'additionalFields'] as const) {
		if (await holdsNothing(field, row[field], privateKey)) shown[field] = row[field] || null
	}
	return { shown, updatedAt: row.updatedAt, sourceUpdatedAt }
}

/** `totp` and `passkeys`, which `cachedMatch` checked against the cache. */
function checkedValues(row: OpenSecretRow): Values {
	return { totp: row.totp || null, passkeys: row.passkeys || null }
}

/** What the server enforces too, refused here so nothing is fetched or decrypted for it. */
async function refuseUneditableCached(accountId: string, id: string): Promise<void> {
	const cached = await cachedRow(accountId, id)
	if (!cached) throw new WriteFailure({ code: 'not_found' })
	if (cached.blocked) throw new WriteFailure({ code: 'blocked', reason: cached.blockedReason })
	if (isRestricted(cached, 'edit')) throw new WriteFailure({ code: 'restricted' })
}

/** Fresh, since the whole field is written back and a stale cache would drop another client's passkey (ADR-002). */
async function freshRow(client: Client, id: string): Promise<OpenSecretRow> {
	const row = await client.keepiq<OpenSecretRow | null>('GET', secretPath(id))
	if (typeof row?.updatedAt !== 'string') throw new WriteFailure({ code: 'server_error' })
	if (isRestricted(row, 'edit')) throw new WriteFailure({ code: 'restricted' })
	return row
}

/** Only a login holds passkeys; a standalone passkey item is never written here. */
async function refuseNotLogin(accountId: string, typeIds: Array<string | null>): Promise<void> {
	if ((await typeNamesOf(accountId, typeIds)).some((name) => name !== 'login')) throw new WriteFailure({ code: 'invalid' })
}

async function openPasskeys(row: OpenSecretRow, opener: { id: string; privateKey: CryptoKey }): Promise<string | null> {
	try {
		return row.passkeys ? await rsaDecrypt(row.passkeys, opener.privateKey) : null
	} catch (error) {
		console.error('[keepiq] could not open the passkeys of', opener.id, error)
		throw new WriteFailure({ code: 'unreadable' })
	}
}

async function savePasskeys(ctx: Context, id: string, change: PasskeysChange): Promise<PasskeysSaved> {
	const { account, client, privateKey } = ctx
	await refuseUneditableCached(account.id, id)
	const row = await freshRow(client, id)
	const held = await cachedMatch(account.id, id, row)
	await refuseNotLogin(account.id, [row.typeId, held.typeId])
	const context = await readWriteContext(client, id)
	if (isViewOnly(id, context)) throw new WriteFailure({ code: 'view_only' })
	const next = changed(await openPasskeys(row, { id, privateKey }), change)
	const note = await shareSave(client, privateKey, {
		accountId: account.id, id, patch: { passkeys: await sealValue('passkeys', next.value, await publicKey(ctx)) }, plain: { passkeys: next.value },
		baseUpdatedAt: row.updatedAt, context, opened: await passkeySaveOpened(row, privateKey, context.sourceUpdatedAt), withoutUnreachable: new Set(),
	})
	return { id, credentialId: next.credentialId, counter: next.counter, ...(note && { shareSave: note }) }
}

const isStale = (error: unknown) => (error instanceof ApiError && error.status === 409 && error.code === 'stale') || (error instanceof WriteFailure && error.error.code === 'stale_source')

/**
 * Saves a login's `passkeys` whole, the only field changed, through share-save so every copy gets it (design.md § Writes). Pushes nothing to a page.
 * Replies once the server took it, not after the sync that follows.
 */
export function writeLoginPasskeys(id: string, change: PasskeysChange, accountId?: string): Promise<WriteResult<PasskeysSaved>> {
	return run(async (ctx) => {
		if (!isChange(change)) throw new WriteFailure({ code: 'invalid' })
		let saved: PasskeysSaved
		try {
			saved = await savePasskeys(ctx, id, change)
		} catch (error) {
			if (!isStale(error)) throw error
			// Another client saved first: the next fetch brings its value into the cache, and the change applies to it.
			saved = await savePasskeys(ctx, id, change).catch((again: unknown) => {
				// A second `stale` is `conflict`; a 409 `stale` already reads as one.
				throw again instanceof WriteFailure && again.error.code === 'stale_source' ? new WriteFailure({ code: 'conflict' }) : again
			})
		}
		void syncAfterWrite(ctx.account.id)
		return saved
	}, 'reply', accountId)
}

/** The counter to sign with. A stored 0, as on every passkey Keepiq made, stays 0 and writes nothing. */
export async function savePasskeyCounter(id: string, credentialId: string, stored: number, accountId?: string): Promise<WriteResult<{ counter: number; shareSave?: ShareSaveNote }>> {
	if (stored === 0) return { ok: true, value: { counter: 0 } }
	const result = await writeLoginPasskeys(id, { kind: 'counter', credentialId, seen: stored }, accountId)
	if (!result.ok) return result
	return { ok: true, value: { counter: result.value.counter, ...(result.value.shareSave && { shareSave: result.value.shareSave }) } }
}

export interface PasskeyLoginDraft {
	name: string
	/** The requesting frame's origin. */
	url: string
	folderId: string | null
	credential: PasskeyCredential
}

function isOrigin(url: unknown): boolean {
	try {
		const parsed = new URL(url as string)
		return (parsed.protocol === 'https:' || parsed.protocol === 'http:') && parsed.origin === url
	} catch {
		return false
	}
}

const isNamed = (draft: PasskeyLoginDraft) => typeof draft?.name === 'string' && draft.name.trim() !== '' && draft.name.length <= 255

const isFiled = (snapshot: VaultSnapshot | undefined, draft: PasskeyLoginDraft) =>
	draft?.folderId === null || snapshot?.folders.some((folder) => folder.id === draft?.folderId)

/** A name, the page's origin and a folder the vault holds. */
const isPlacedDraft = (snapshot: VaultSnapshot | undefined, draft: PasskeyLoginDraft) => isNamed(draft) && isOrigin(draft.url) && isFiled(snapshot, draft)

/** The login type and the passkeys of a new passkey login, or `invalid`. */
function newPasskeyLogin(snapshot: VaultSnapshot | undefined, draft: PasskeyLoginDraft): { typeId: string; passkeys: string } {
	const typeId = snapshot?.types.find((type) => type.name === 'login')?.id
	const passkeys = withPasskey(null, draft?.credential)
	if (!typeId || passkeys === null || !isPlacedDraft(snapshot, draft)) throw new WriteFailure({ code: 'invalid' })
	return { typeId, passkeys }
}

/**
 * A new login holding only the passkey, as the web app's import makes one: no username, `key` the encryption of `''`.
 * Replies once the server took it, not after the sync that follows.
 */
export function createPasskeyLogin(draft: PasskeyLoginDraft, accountId?: string): Promise<WriteResult<{ id: string }>> {
	return run(async (ctx) => {
		const snapshot = await readSnapshot(ctx.account.id)
		const { typeId, passkeys } = newPasskeyLogin(snapshot, draft)
		// Checks the server's active suite first, so the login opens for its owner.
		const key = await publicKey(ctx)
		const row = await ctx.client.keepiq<SecretRow | null>('POST', '/api/v1/secrets', {
			name: draft.name, url: draft.url, typeId, folderId: draft.folderId, key: await rsaEncrypt('', key), passkeys: await rsaEncrypt(passkeys, key),
		})
		if (!isText(row?.id)) throw new WriteFailure({ code: 'unknown' })
		void syncAfterWrite(ctx.account.id)
		return { id: row.id }
	}, 'reply', accountId)
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
