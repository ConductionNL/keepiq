import { readAccountPolicy } from '@/src/accounts/policy'
import { takeIntent } from '@/src/add-intent'
import { AUTOFILL_FEATURES, clampTimeout, readSettings } from '@/src/accounts/settings'
import { accountStatus, folderNoticeKey, getAccount, getActiveAccountId, updateSettings, type AccountRecord } from '@/src/accounts/store'
import { disableDefaultManager, enableDefaultManager, readDefaultManager } from '@/src/autofill/default-manager'
import { isWebUrl } from '@/src/autofill/frames'
import { candidatesFor } from '@/src/autofill/matcher'
import { walletCandidates } from '@/src/autofill/wallet'
import { scheduleClipboardClear } from '@/src/clipboard'
import { rsaDecrypt } from '@/src/crypto'
import { addHistory, clearHistory, openHistory, sealHistory } from '@/src/generator/history'
import { GENERATOR_KINDS, type GeneratorHistoryEntry } from '@/src/generator/options'
import { readOptions, readPolicy, saveOptions } from '@/src/generator/store'
import {
	USE_ONLY_FIELDS, type AccountSettingsRequest, type AddIntent, type AccountSettingsSnapshot, type AccountStatus, type DecryptReply, type DecryptedItem,
	type FillRequest, type FormKind, type GeneratorRequest, type PasskeyDetail, type PasskeysReply, type PopupReplies, type PopupRequest, type PopupTab, type SendRequest, type SyncStatus, type VaultSnapshotReply, type VaultWrite,
} from '@/src/messages'
import { createSendFor, deleteSendFor, listSendsFor } from '@/src/send/handlers'
import { readLastUsed } from '@/src/last-used'
import { buildPasskeyCredential, storedArray } from '@/src/passkey/credential'
import { forgetOfferedPasskeys } from '@/src/passkey/offered'
import { hasIdle } from '@/src/platform'
import { isChangeCount } from '@/src/safari-native'
import { readGlobalSettings, updateGlobalSettings } from '@/src/settings/global'
import { getKey, LAST_TAB_KEY, sessionEpoch, sessionValues } from '@/src/vault/key-store'
import { forgetPin, hasPin, hasSeal } from '@/src/vault/pin'
import { readSnapshot, toItemMeta } from '@/src/vault/store'
import type { OpenSecretRow } from '@/src/api/types'
import type { StoredSecretRow, VaultSnapshot } from '@/src/vault/types'
import { isStale, sync, syncStatus } from '@/src/vault/sync'
import { cancelPopout, enforce, expectPopout, touch } from '@/src/vault/timeout'
import { enablePin } from '@/src/vault/unlock'
import { createFolder, createItem, deleteFolder, deleteItem, fetchItem, folderChildren, itemEditable, renameFolder, updateItem } from '@/src/vault/write'

const TABS: readonly PopupTab[] = ['vault', 'generator', 'send', 'settings']

/** Vault unless another tab was left open in this browser session. */
export async function readLastTab(): Promise<PopupTab> {
	const tab = await sessionValues.get(LAST_TAB_KEY)
	return TABS.includes(tab as PopupTab) ? tab as PopupTab : 'vault'
}

async function activeAccount(): Promise<{ account: AccountRecord; status: AccountStatus } | null> {
	const id = await getActiveAccountId()
	const account = id ? await getAccount(id) : undefined
	return account ? { account, status: await accountStatus(account) } : null
}

async function activeUnlocked(): Promise<AccountRecord | null> {
	const active = await activeAccount()
	return active?.status === 'unlocked' ? active.account : null
}

const webAppUrl = (account: AccountRecord) => `${account.serverUrl}/index.php/apps/keepiq/`

/** The extension's Default item type, when the account's cached types hold it; passkeys are never made from New. */
async function defaultSecretType(accountId: string, cached: VaultSnapshot | undefined): Promise<string | null> {
	const { defaultItemType } = await readSettings(accountId)
	return defaultItemType !== 'passkey' && cached?.types.some((type) => type.name === defaultItemType) ? defaultItemType : null
}

interface SuggestionInput {
	accountId: string
	tabUrl: string | undefined
	vault: VaultSnapshot | undefined
}

const VAULT_VIEW: ReadonlyArray<[FormKind, 'vaultCards' | 'vaultIdentities']> = [['card', 'vaultCards'], ['identity', 'vaultIdentities']]

/** Cards and identities are not matched by site, so they are offered on every web page (spec `card-identity-fill`). */
async function suggestions({ accountId, tabUrl, vault }: SuggestionInput): Promise<{ suggestionIds: string[]; formIds: string[] }> {
	if (!vault) return { suggestionIds: [], formIds: [] }
	const settings = await readSettings(accountId)
	const lastUsed = await readLastUsed(accountId)
	const logins = candidatesFor(tabUrl, vault, settings.uriMatch, lastUsed)
	const web = tabUrl !== undefined && isWebUrl(tabUrl)
	const forms = (web ? VAULT_VIEW : []).map(([kind, setting]) => ({ rows: walletCandidates(kind, vault, lastUsed), shown: settings[setting] }))
	return {
		suggestionIds: [...logins, ...forms.flatMap((form) => (form.shown ? form.rows : []))].map((row) => row.id),
		formIds: forms.flatMap((form) => form.rows).map((row) => row.id),
	}
}

/**
 * Only on open: re-reads after `vault.changed` must not retry a failed sync in a loop.
 * Started before the status is read, so the popup sees `syncing` and waits for `vault.changed`.
 */
async function syncOnOpen(accountId: string, cached: VaultSnapshot | undefined): Promise<void> {
	if (!cached) return void sync(accountId, 'full')
	if (await isStale(accountId)) void sync(accountId, 'probe')
}

function folderMetas(cached: VaultSnapshot | undefined) {
	return cached?.folders.map(({ id, name, parentId }) => ({ id, name, parentId })) ?? []
}

function typeMetas(cached: VaultSnapshot | undefined) {
	return cached?.types.map(({ id, name, label }) => ({ id, name, label: typeof label === 'string' && label ? label : name })) ?? []
}

async function folderNoticeDismissed(accountId: string): Promise<boolean> {
	return (await browser.storage.local.get(folderNoticeKey(accountId)))[folderNoticeKey(accountId)] === true
}

async function snapshot(tabUrl: string | undefined, opened: boolean): Promise<VaultSnapshotReply> {
	const active = await activeAccount()
	if (active?.status !== 'unlocked') return { state: active?.status === 'locked' ? 'locked' : 'logged_out' }
	const { account } = active
	const cached = await readSnapshot(account.id)
	if (opened) await syncOnOpen(account.id, cached)
	const items = cached?.secrets.map(toItemMeta) ?? null
	return {
		state: 'unlocked',
		items,
		folders: folderMetas(cached),
		types: typeMetas(cached),
		...await suggestions({ accountId: account.id, tabUrl, vault: cached }),
		sync: await syncStatus(account.id),
		webAppUrl: webAppUrl(account),
		defaultSecretType: await defaultSecretType(account.id, cached),
		folderNoticeDismissed: await folderNoticeDismissed(account.id),
	}
}

/** Rows by id, built once per snapshot object rather than per decrypt batch. */
const indexes = new WeakMap<VaultSnapshot, Map<string, StoredSecretRow>>()

function rowsById(snapshot: VaultSnapshot | undefined): Map<string, StoredSecretRow> {
	if (!snapshot) return new Map()
	let index = indexes.get(snapshot)
	if (!index) {
		index = new Map(snapshot.secrets.map((row) => [row.id, row]))
		indexes.set(snapshot, index)
	}
	return index
}

type RowFailure = 'blocked' | 'use_only' | 'decrypt_failed'

/** The active unlocked account and its key, or null while there is none. */
async function unlockedKey(): Promise<{ account: AccountRecord; key: CryptoKey } | null> {
	const account = await activeUnlocked()
	const key = account ? await getKey(account.id) : null
	return account && key ? { account, key } : null
}

/** One decrypt batch: the vault's rows, the fields asked for and the key. */
interface DecryptRun {
	rows: Map<string, StoredSecretRow>
	fields: Array<keyof DecryptedItem>
	key: CryptoKey
}

type RowResult = [string, DecryptedItem | RowFailure] | null

/** A use-only copy gives only the fields it may, and none when asked for more. */
function asksBeyondUseOnly(row: OpenSecretRow, fields: Array<keyof DecryptedItem>): boolean {
	return row.useOnly === true && fields.some((field) => !USE_ONLY_FIELDS.includes(field))
}

async function decryptFields(row: OpenSecretRow, run: DecryptRun): Promise<DecryptedItem> {
	const item: DecryptedItem = {}
	for (const field of run.fields) {
		const ciphertext = row[field]
		// `''` is no value, as for a login without a code.
		if (typeof ciphertext === 'string' && ciphertext !== '') item[field] = await rsaDecrypt(ciphertext, run.key)
	}
	return item
}

async function decryptRow(id: string, run: DecryptRun): Promise<RowResult> {
	const row = run.rows.get(id)
	if (!row) return null
	if (row.blocked) return [id, 'blocked']
	if (asksBeyondUseOnly(row, run.fields)) return [id, 'use_only']
	try {
		return [id, await decryptFields(row, run)]
	} catch (error) {
		console.error('[keepiq] decrypt failed for', id, error)
		return [id, 'decrypt_failed']
	}
}

/** One bad row must not blank the rest of a batch. */
function batchReply(ids: string[], results: RowResult[]): DecryptReply {
	const items: Record<string, DecryptedItem> = {}
	let failure: RowFailure | null = null
	for (const [id, result] of results.filter((r) => r !== null)) {
		if (typeof result === 'string') failure = result
		else items[id] = result
	}
	return ids.length === 1 && failure ? { ok: false, error: failure } : { ok: true, items }
}

async function decrypt(ids: string[], fields: Array<keyof DecryptedItem>): Promise<DecryptReply> {
	const unlocked = await unlockedKey()
	if (!unlocked) return { ok: false, error: 'locked' }
	const run: DecryptRun = { rows: rowsById(await readSnapshot(unlocked.account.id)), fields, key: unlocked.key }
	const results = await Promise.all(ids.map((id) => decryptRow(id, run)))
	return batchReply(ids, results)
}

/** Built field by field, so nothing else of the credential can reach the popup. */
function passkeyDetail(entry: unknown): PasskeyDetail | null {
	const credential = buildPasskeyCredential(entry)
	if (!credential) return null
	// Not the credential's: `buildPasskeyCredential` makes up a date for an entry without one.
	const created = (entry as Record<string, unknown>).createdAt
	return {
		rpId: credential.rpId,
		rpName: credential.rpName,
		userName: credential.userName || credential.userDisplayName,
		createdAt: typeof created === 'string' && Number.isFinite(Date.parse(created)) ? created : null,
	}
}

/** A login's `passkeys` for the detail, a use-only copy's too, since it shows no key. */
function isLogin(row: OpenSecretRow, cached: VaultSnapshot | undefined): boolean {
	return cached?.types.some((type) => type.id === row.typeId && type.name === 'login') === true
}

/** A login row's stored passkeys, or null when it holds none. */
function storedPasskeys(row: OpenSecretRow, cached: VaultSnapshot | undefined): string | null {
	if (!isLogin(row, cached) || typeof row.passkeys !== 'string' || row.passkeys === '') return null
	return row.passkeys
}

async function loginPasskeys(id: string): Promise<PasskeysReply> {
	const unlocked = await unlockedKey()
	if (!unlocked) return { ok: false, error: 'locked' }
	const cached = await readSnapshot(unlocked.account.id)
	const row = rowsById(cached).get(id)
	if (!row) return { ok: false, error: 'not_found' }
	if (row.blocked) return { ok: false, error: 'blocked' }
	const passkeys = storedPasskeys(row, cached)
	if (passkeys === null) return { ok: true, passkeys: [] }
	return decryptPasskeys(id, passkeys, unlocked.key)
}

/** An empty `passkeys` holds none; one that does not decrypt or parse is unreadable. */
async function decryptPasskeys(id: string, ciphertext: string, key: CryptoKey): Promise<PasskeysReply> {
	let json: string
	try {
		json = await rsaDecrypt(ciphertext, key)
	} catch (error) {
		console.error('[keepiq] passkeys did not decrypt for', id, error)
		return { ok: false, error: 'unreadable' }
	}
	if (json.trim() === '') return { ok: true, passkeys: [] }
	const entries = storedArray(json)
	return entries ? { ok: true, passkeys: entries.map(passkeyDetail) } : { ok: false, error: 'unreadable' }
}

/** Bitwarden's popout: the popup in its own window, for the tab it came from. */
export async function popout(tabId: number | undefined): Promise<null> {
	const query = new URLSearchParams({ popout: '1' })
	if (tabId !== undefined) query.set('tabId', String(tabId))
	// The toolbar popup closes before the window connects; that gap is not "popup closed".
	expectPopout()
	try {
		await browser.windows.create({ type: 'popup', width: 380, height: 630, url: browser.runtime.getURL(`/popup.html?${query}`) })
	} catch (error) {
		cancelPopout()
		throw error
	}
	return null
}

/** `fill.request` goes to the fill orchestrator instead. */
export type HandledRequest = Exclude<PopupRequest, FillRequest>

async function syncActiveAccount(): Promise<SyncStatus> {
	const account = await activeUnlocked()
	return account ? sync(account.id, 'full') : { syncing: false, syncedAt: null, offline: false, lastError: null }
}

async function scheduleActiveClipboardClear(changeCount: unknown): Promise<null> {
	const accountId = await getActiveAccountId()
	if (accountId) await scheduleClipboardClear((await readSettings(accountId)).clearClipboardMs, Date.now(), isChangeCount(changeCount) ? changeCount : undefined)
	return null
}

async function rememberLastTab(tab: PopupTab): Promise<null> {
	if (TABS.includes(tab)) await sessionValues.set({ [LAST_TAB_KEY]: tab })
	return null
}

/** Taken before the await, so a popup that asks once while locked still uses it up. */
async function addIntent(): Promise<AddIntent | null> {
	const intent = takeIntent()
	return intent && await activeUnlocked() ? intent : null
}

/** The requests that name no account: they act on the active one, the popup itself or the whole browser. */
type UnscopedRequest = Exclude<HandledRequest, GeneratorRequest | VaultWrite | AccountSettingsRequest | SendRequest>
type UnscopedKind = UnscopedRequest['kind']

const UNSCOPED_REQUESTS: { [K in UnscopedKind]: (message: Extract<UnscopedRequest, { kind: K }>) => Promise<PopupReplies[K]> } = {
	'vault.sync': () => syncActiveAccount(),
	'vault.snapshot': (message) => snapshot(message.tabUrl, message.opened === true),
	'item.decrypt': (message) => decrypt(message.ids, message.fields),
	'item.passkeys': (message) => loginPasskeys(message.id),
	'clipboard.copied': (message) => scheduleActiveClipboardClear(message.changeCount),
	'popup.popout': (message) => popout(message.tabId),
	'popup.lastTab.set': (message) => rememberLastTab(message.tab),
	'popup.intent': () => addIntent(),
	'settings.get': () => readGlobalSettings(),
	'settings.set': (message) => updateGlobalSettings(message.patch),
	'defaultManager.get': () => readDefaultManager(),
	'defaultManager.enable': () => enableDefaultManager(),
	'defaultManager.disable': () => disableDefaultManager(),
}

function isUnscoped(message: Exclude<HandledRequest, GeneratorRequest>): message is UnscopedRequest {
	return Object.hasOwn(UNSCOPED_REQUESTS, message.kind)
}

function performUnscoped<K extends UnscopedKind>(message: Extract<UnscopedRequest, { kind: K }>): Promise<PopupReplies[K]> {
	return UNSCOPED_REQUESTS[message.kind](message)
}

const ACCOUNT_SETTINGS_KINDS: readonly string[] = ['account.settings.get', 'account.settings.set', 'pin.set', 'pin.remove'] satisfies Array<AccountSettingsRequest['kind']>
const SEND_KINDS: readonly string[] = ['send.list', 'send.create', 'send.delete'] satisfies Array<SendRequest['kind']>

const isAccountSettingsRequest = (message: HandledRequest): message is AccountSettingsRequest => ACCOUNT_SETTINGS_KINDS.includes(message.kind)
const isSendRequest = (message: HandledRequest): message is SendRequest => SEND_KINDS.includes(message.kind)

async function perform(message: Exclude<HandledRequest, GeneratorRequest>): Promise<PopupReplies[PopupRequest['kind']]> {
	if (isUnscoped(message)) return performUnscoped(message)
	if (isAccountSettingsRequest(message)) return performAccountSettings(message)
	if (isSendRequest(message)) return performSend(message)
	return performWrite(message)
}

const isGeneratorRequest = (message: HandledRequest): message is GeneratorRequest => message.kind.startsWith('generator.')

/**
 * The active account when the message names it, locked too for the lock screen's generator.
 * Never another or a logged-out one, so a stale window writes no options or history there.
 */
async function generatorTarget(message: GeneratorRequest): Promise<{ account: AccountRecord; status: AccountStatus } | null> {
	const active = await activeAccount()
	return active && active.account.id === message.accountId && active.status !== 'logged_out' ? active : null
}

/** Who a generator request acts for: the account, and the account whose history may be read, which only an unlocked one may. */
interface GeneratorRun {
	account: AccountRecord | null
	reader: AccountRecord | null
	epoch: number
}

type GeneratorOf<K extends GeneratorRequest['kind']> = Extract<GeneratorRequest, { kind: K }>
type GeneratorHandlers = { [K in GeneratorRequest['kind']]: (message: GeneratorOf<K>, run: GeneratorRun) => Promise<PopupReplies[K]> | PopupReplies[K] }

async function performGenerator(message: GeneratorRequest, target: { account: AccountRecord; status: AccountStatus } | null, epoch: number): Promise<PopupReplies[GeneratorRequest['kind']]> {
	// The lock keeps the history from whoever walks up to the browser (ADR-002): a locked account only adds to it, sealed.
	const run: GeneratorRun = { account: target?.account ?? null, reader: target?.status === 'unlocked' ? target.account : null, epoch }
	// A kind this background does not know gets no reply.
	if (!Object.hasOwn(GENERATOR_HANDLERS, message.kind)) return undefined as never
	const handle = GENERATOR_HANDLERS[message.kind] as (message: GeneratorRequest, run: GeneratorRun) => Promise<PopupReplies[GeneratorRequest['kind']]>
	return handle(message, run)
}

const listHistory = ({ reader, epoch }: GeneratorRun) => (reader ? openHistory(reader.id, epoch) : [])

/** The entry to add and the account it is for, or `null` for no account or an entry that is not a generated value of a known kind. */
function historyEntry(message: GeneratorOf<'generator.history.add'>, { account }: GeneratorRun): { accountId: string; entry: Omit<GeneratorHistoryEntry, 'createdAt'> } | null {
	const entry = generatedEntry(message.entry)
	return account && entry ? { accountId: account.id, entry } : null
}

function generatedEntry(entry: GeneratorOf<'generator.history.add'>['entry']): Omit<GeneratorHistoryEntry, 'createdAt'> | null {
	const { value, kind } = entry ?? {}
	return typeof value === 'string' && value && GENERATOR_KINDS.includes(kind) ? { value, kind } : null
}

async function addToHistory(message: GeneratorOf<'generator.history.add'>, run: GeneratorRun): Promise<GeneratorHistoryEntry[]> {
	const add = historyEntry(message, run)
	if (!add) return listHistory(run)
	if (!run.reader) {
		await sealHistory(add.accountId, add.entry, run.epoch)
		return []
	}
	await openHistory(run.reader.id, run.epoch)
	return addHistory(run.reader.id, add.entry, run.epoch)
}

async function clearHistoryOf({ reader }: GeneratorRun): Promise<null> {
	if (reader) await clearHistory(reader.id)
	return null
}

const GENERATOR_HANDLERS: GeneratorHandlers = {
	'generator.context': async (_, { account }) => (account ? { options: await readOptions(account.id), policy: await readPolicy(account.id), accountEmail: account.email } : null),
	'generator.saveOptions': (message, { account }) => (account ? saveOptions(account.id, message.options) : null),
	'generator.history.list': (_, run) => listHistory(run),
	'generator.history.add': addToHistory,
	'generator.history.clear': (_, run) => clearHistoryOf(run),
}

const isString = (value: unknown): value is string => typeof value === 'string'
const isStrings = (value: unknown): value is string[] => Array.isArray(value) && value.every(isString)

type WriteOf<K extends VaultWrite['kind']> = Extract<VaultWrite, { kind: K }>
type WriteHandlers = { [K in VaultWrite['kind']]: (message: WriteOf<K>, accountId: string) => Promise<PopupReplies[K]> }

/** A write without the account it was meant for is refused, so a stale window never writes elsewhere. */
async function performWrite(message: VaultWrite): Promise<PopupReplies[VaultWrite['kind']]> {
	const accountId = message.accountId
	if (typeof accountId !== 'string') return message.kind === 'folder.dismissNotice' ? null : { ok: false, error: { code: 'invalid' } }
	// A kind this background does not know gets no reply.
	if (!Object.hasOwn(WRITE_HANDLERS, message.kind)) return undefined as never
	const handle = WRITE_HANDLERS[message.kind] as (message: VaultWrite, accountId: string) => Promise<PopupReplies[VaultWrite['kind']]>
	return handle(message, accountId)
}

function updateFrom(message: WriteOf<'item.update'>, accountId: string): ReturnType<typeof updateItem> {
	const recordId = isString(message.recordId) ? message.recordId : undefined
	const withoutUnreachable = isStrings(message.withoutUnreachable) ? message.withoutUnreachable : undefined
	return updateItem(message.id, message.patch ?? {}, message.baseUpdatedAt, accountId, recordId, withoutUnreachable)
}

async function dismissFolderNotice(_: WriteOf<'folder.dismissNotice'>, accountId: string): Promise<null> {
	const account = await activeUnlocked()
	if (account?.id === accountId) await browser.storage.local.set({ [folderNoticeKey(account.id)]: true })
	return null
}

const WRITE_HANDLERS: WriteHandlers = {
	'item.fetch': (message, accountId) => fetchItem(message.id, message.purpose === 'clone' ? 'clone' : 'edit', accountId),
	'item.create': (message, accountId) => createItem(message.draft, accountId),
	'item.update': updateFrom,
	'item.delete': (message, accountId) => deleteItem(message.id, accountId),
	'item.editable': (message, accountId) => itemEditable(message.id, accountId),
	'folder.create': (message, accountId) => createFolder(message.name, message.parentId ?? null, accountId),
	'folder.rename': (message, accountId) => renameFolder(message.id, message.name, accountId),
	'folder.children': (message, accountId) => folderChildren(message.id, accountId),
	'folder.delete': (message, accountId) => deleteFolder(message.id, message.plan ?? { mode: 'plain' }, accountId),
	'folder.dismissNotice': dismissFolderNotice,
}

/** Like a write, a send without the account it was meant for is refused. */
async function performSend(message: SendRequest): Promise<PopupReplies[SendRequest['kind']]> {
	const accountId = message.accountId
	if (typeof accountId !== 'string') return { ok: false, error: { code: 'invalid' } }
	switch (message.kind) {
		case 'send.list':
			return listSendsFor(accountId)
		case 'send.create':
			return createSendFor(message.input, accountId)
		case 'send.delete':
			return deleteSendFor(message.id, accountId)
	}
}

/** `null` when the manifest declares no `autofill_login` (ext-autofill does) or the user removed its shortcut. */
async function autofillShortcut(): Promise<string | null> {
	try {
		return (await browser.commands.getAll()).find((command) => command.name === 'autofill_login')?.shortcut || null
	} catch {
		return null
	}
}

async function accountSettings(accountId: string): Promise<AccountSettingsSnapshot | null> {
	const account = await getAccount(accountId)
	if (!account) return null
	const values = await readSettings(accountId)
	const policy = await readAccountPolicy(accountId)
	return {
		accountId,
		values,
		effectiveTimeout: clampTimeout(values.vaultTimeout, policy.maxIdleMinutes),
		maxIdleMinutes: policy.maxIdleMinutes,
		exportDisabled: policy.exportDisabled,
		pin: await hasPin(accountId) || await hasSeal(accountId),
		autofillFeatures: [...AUTOFILL_FEATURES],
		shortcut: await autofillShortcut(),
		serverUrl: account.serverUrl,
		webAppUrl: webAppUrl(account),
		keepiqVersion: account.keepiqVersion ?? null,
	}
}

type SettingsOf<K extends AccountSettingsRequest['kind']> = Extract<AccountSettingsRequest, { kind: K }>

/** `writeSettings` drops what fails validation. Without `idle` nothing tells a system lock, so that one would never lock. */
function supportedPatch(patch: SettingsOf<'account.settings.set'>['patch']) {
	const unsupported = patch?.vaultTimeout === 'onSystemLock' && !hasIdle()
	return unsupported ? { ...patch, vaultTimeout: undefined } : patch
}

async function setAccountSettings(message: SettingsOf<'account.settings.set'>): Promise<PopupReplies['account.settings.set']> {
	const { accountId } = message
	const account = typeof accountId === 'string' ? await getAccount(accountId) : undefined
	if (!account) return null
	// Settings are out of reach while locked, and Never set then would put the key on disk at the next unlock.
	if (await accountStatus(account) !== 'unlocked') return 'locked'
	await updateSettings(accountId, supportedPatch(message.patch))
	if (message.patch?.askPasskeys === false) forgetOfferedPasskeys()
	return accountSettings(accountId)
}

async function removePin(message: SettingsOf<'pin.remove'>): Promise<null> {
	if (typeof message.accountId === 'string') await forgetPin(message.accountId)
	return null
}

/** Unlike writes, these act on the account they name whether or not it is active. */
const ACCOUNT_SETTINGS: { [K in AccountSettingsRequest['kind']]: (message: SettingsOf<K>) => Promise<PopupReplies[K]> } = {
	'account.settings.get': async (message) => (typeof message.accountId === 'string' ? accountSettings(message.accountId) : null),
	'account.settings.set': setAccountSettings,
	'pin.set': (message) => enablePin(message.accountId, message.pin, message.masterPassword),
	'pin.remove': removePin,
}

function performAccountSettings<K extends AccountSettingsRequest['kind']>(message: SettingsOf<K>): Promise<PopupReplies[K]> {
	return ACCOUNT_SETTINGS[message.kind](message)
}

/** Re-reads and re-decrypts after a broadcast are the popup reacting to the background, not the user. */
/** Every popup reads these on open, which `vault.status` already counts. */
const READ_ON_OPEN: readonly string[] = ['settings.get', 'account.settings.get', 'defaultManager.get', 'popup.intent'] satisfies Array<HandledRequest['kind']>

function isInteraction(message: Exclude<HandledRequest, GeneratorRequest>): boolean {
	if (READ_ON_OPEN.includes(message.kind)) return false
	if (message.kind === 'vault.snapshot') return message.opened === true
	if (message.kind === 'item.decrypt' || message.kind === 'item.passkeys') return message.passive !== true
	return true
}

/** Like account messages, the timeout is enforced first so a sleeping worker can't serve a vault that should be locked. */
async function handleGenerator(message: GeneratorRequest): Promise<unknown> {
	// Read before any await, so a lock or logout while this request waits keeps its add from landing.
	const epoch = sessionEpoch(message.accountId)
	const target = await generatorTarget(message)
	// Context is read on open. The lock screen's generator must not keep another account's vault from timing out.
	if (message.kind !== 'generator.context' && target?.status === 'unlocked') await touch()
	return performGenerator(message, target, epoch)
}

/** Copy from the lock screen's generator must not keep another account's vault from timing out either. */
async function countsAsInteraction(message: Exclude<HandledRequest, GeneratorRequest>): Promise<boolean> {
	if (message.kind === 'clipboard.copied') return Boolean(await activeUnlocked())
	return isInteraction(message)
}

export async function handlePopupRequest(message: HandledRequest): Promise<unknown> {
	try {
		await enforce()
		if (isGeneratorRequest(message)) return await handleGenerator(message)
		if (await countsAsInteraction(message)) await touch()
		return await perform(message)
	} catch (error) {
		console.error('[keepiq]', message.kind, error)
		return undefined
	}
}

/** The `vault-sync` alarm: a cheap probe for the active account, after the timeout had its say. */
export async function syncOnAlarm(): Promise<void> {
	await enforce()
	const account = await activeUnlocked()
	if (account) await sync(account.id, 'probe')
}
