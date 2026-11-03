import { readAccountPolicy } from '@/src/accounts/policy'
import { AUTOFILL_FEATURES, clampTimeout, readSettings } from '@/src/accounts/settings'
import { accountStatus, folderNoticeKey, getAccount, getActiveAccountId, updateSettings, type AccountRecord } from '@/src/accounts/store'
import { disableDefaultManager, enableDefaultManager, readDefaultManager } from '@/src/autofill/default-manager'
import { isWebUrl } from '@/src/autofill/frames'
import { candidatesFor } from '@/src/autofill/matcher'
import { walletCandidates } from '@/src/autofill/wallet'
import { scheduleClipboardClear } from '@/src/clipboard'
import { rsaDecrypt } from '@/src/crypto'
import { addHistory, clearHistory, openHistory, sealHistory } from '@/src/generator/history'
import { GENERATOR_KINDS } from '@/src/generator/options'
import { readOptions, readPolicy, saveOptions } from '@/src/generator/store'
import {
	USE_ONLY_FIELDS, type AccountSettingsRequest, type AccountSettingsSnapshot, type AccountStatus, type DecryptReply, type DecryptedItem,
	type FillRequest, type FormKind, type GeneratorRequest, type PasskeyDetail, type PasskeysReply, type PopupReplies, type PopupRequest, type PopupTab, type SendRequest, type SyncStatus, type VaultSnapshotReply, type VaultWrite,
} from '@/src/messages'
import { createSendFor, deleteSendFor, listSendsFor } from '@/src/send/handlers'
import { readLastUsed } from '@/src/last-used'
import { buildPasskeyCredential, storedArray } from '@/src/passkey/credential'
import { forgetOfferedPasskeys } from '@/src/passkey/offered'
import { readGlobalSettings, updateGlobalSettings } from '@/src/settings/global'
import { getKey, LAST_TAB_KEY, sessionEpoch, sessionValues } from '@/src/vault/key-store'
import { forgetPin, hasPin, hasSeal } from '@/src/vault/pin'
import { readSnapshot, toItemMeta } from '@/src/vault/store'
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

async function snapshot(tabUrl: string | undefined, opened: boolean): Promise<VaultSnapshotReply> {
	const active = await activeAccount()
	if (active?.status !== 'unlocked') return { state: active?.status === 'locked' ? 'locked' : 'logged_out' }
	const { account } = active
	const cached = await readSnapshot(account.id)
	// Only on open: re-reads after `vault.changed` must not retry a failed sync in a loop.
	// Started before the status is read, so the popup sees `syncing` and waits for `vault.changed`.
	if (opened && !cached) void sync(account.id, 'full')
	else if (opened && await isStale(account.id)) void sync(account.id, 'probe')
	const items = cached?.secrets.map(toItemMeta) ?? null
	return {
		state: 'unlocked',
		items,
		folders: cached?.folders.map(({ id, name, parentId }) => ({ id, name, parentId })) ?? [],
		types: cached?.types.map(({ id, name, label }) => ({ id, name, label: typeof label === 'string' && label ? label : name })) ?? [],
		...await suggestions({ accountId: account.id, tabUrl, vault: cached }),
		sync: await syncStatus(account.id),
		webAppUrl: webAppUrl(account),
		defaultSecretType: await defaultSecretType(account.id, cached),
		folderNoticeDismissed: (await browser.storage.local.get(folderNoticeKey(account.id)))[folderNoticeKey(account.id)] === true,
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

async function decrypt(ids: string[], fields: Array<keyof DecryptedItem>): Promise<DecryptReply> {
	const account = await activeUnlocked()
	const key = account ? await getKey(account.id) : null
	if (!account || !key) return { ok: false, error: 'locked' }
	const rows = rowsById(await readSnapshot(account.id))
	const results = await Promise.all(ids.map(async (id): Promise<[string, DecryptedItem | RowFailure] | null> => {
		const row = rows.get(id)
		if (!row) return null
		if (row.blocked) return [id, 'blocked']
		if (row.useOnly === true && fields.some((field) => !USE_ONLY_FIELDS.includes(field))) return [id, 'use_only']
		try {
			const item: DecryptedItem = {}
			for (const field of fields) {
				const ciphertext = row[field]
				// `''` is no value, as for a login without a code.
				if (typeof ciphertext === 'string' && ciphertext !== '') item[field] = await rsaDecrypt(ciphertext, key)
			}
			return [id, item]
		} catch (error) {
			console.error('[keepiq] decrypt failed for', id, error)
			return [id, 'decrypt_failed']
		}
	}))
	const items: Record<string, DecryptedItem> = {}
	let failure: RowFailure | null = null
	for (const [id, result] of results.filter((r) => r !== null)) {
		if (typeof result === 'string') failure = result
		else items[id] = result
	}
	// One bad row must not blank the rest of a batch.
	return ids.length === 1 && failure ? { ok: false, error: failure } : { ok: true, items }
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
async function loginPasskeys(id: string): Promise<PasskeysReply> {
	const account = await activeUnlocked()
	const key = account ? await getKey(account.id) : null
	if (!account || !key) return { ok: false, error: 'locked' }
	const cached = await readSnapshot(account.id)
	const row = rowsById(cached).get(id)
	if (!row) return { ok: false, error: 'not_found' }
	if (row.blocked) return { ok: false, error: 'blocked' }
	const login = cached?.types.some((type) => type.id === row.typeId && type.name === 'login') === true
	if (!login || typeof row.passkeys !== 'string' || row.passkeys === '') return { ok: true, passkeys: [] }
	return decryptPasskeys(id, row.passkeys, key)
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

async function scheduleActiveClipboardClear(): Promise<null> {
	const accountId = await getActiveAccountId()
	if (accountId) await scheduleClipboardClear((await readSettings(accountId)).clearClipboardMs)
	return null
}

async function rememberLastTab(tab: PopupTab): Promise<null> {
	if (TABS.includes(tab)) await sessionValues.set({ [LAST_TAB_KEY]: tab })
	return null
}

/** The requests that name no account: they act on the active one, the popup itself or the whole browser. */
type UnscopedRequest = Exclude<HandledRequest, GeneratorRequest | VaultWrite | AccountSettingsRequest | SendRequest>
type UnscopedKind = UnscopedRequest['kind']

const UNSCOPED_REQUESTS: { [K in UnscopedKind]: (message: Extract<UnscopedRequest, { kind: K }>) => Promise<PopupReplies[K]> } = {
	'vault.sync': () => syncActiveAccount(),
	'vault.snapshot': (message) => snapshot(message.tabUrl, message.opened === true),
	'item.decrypt': (message) => decrypt(message.ids, message.fields),
	'item.passkeys': (message) => loginPasskeys(message.id),
	'clipboard.copied': () => scheduleActiveClipboardClear(),
	'popup.popout': (message) => popout(message.tabId),
	'popup.lastTab.set': (message) => rememberLastTab(message.tab),
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

async function perform(message: Exclude<HandledRequest, GeneratorRequest>): Promise<PopupReplies[PopupRequest['kind']]> {
	if (isUnscoped(message)) return performUnscoped(message)
	switch (message.kind) {
		case 'account.settings.get':
		case 'account.settings.set':
		case 'pin.set':
		case 'pin.remove':
			return performAccountSettings(message)
		case 'send.list':
		case 'send.create':
		case 'send.delete':
			return performSend(message)
		default:
			return performWrite(message)
	}
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

async function performGenerator(message: GeneratorRequest, target: { account: AccountRecord; status: AccountStatus } | null, epoch: number): Promise<PopupReplies[GeneratorRequest['kind']]> {
	const account = target?.account ?? null
	// The lock keeps the history from whoever walks up to the browser (ADR-002): a locked account only adds to it, sealed.
	const reader = target?.status === 'unlocked' ? target.account : null
	switch (message.kind) {
		case 'generator.context':
			return account ? { options: await readOptions(account.id), policy: await readPolicy(account.id), accountEmail: account.email } : null
		case 'generator.saveOptions':
			return account ? saveOptions(account.id, message.options) : null
		case 'generator.history.list':
			return reader ? openHistory(reader.id, epoch) : []
		case 'generator.history.add': {
			const { value, kind } = message.entry ?? {}
			if (!account || typeof value !== 'string' || !value || !GENERATOR_KINDS.includes(kind)) return reader ? openHistory(reader.id, epoch) : []
			if (!reader) {
				await sealHistory(account.id, { value, kind }, epoch)
				return []
			}
			await openHistory(reader.id, epoch)
			return addHistory(reader.id, { value, kind }, epoch)
		}
		case 'generator.history.clear':
			if (reader) await clearHistory(reader.id)
			return null
	}
}

const isString = (value: unknown): value is string => typeof value === 'string'
const isStrings = (value: unknown): value is string[] => Array.isArray(value) && value.every(isString)

/** A write without the account it was meant for is refused, so a stale window never writes elsewhere. */
async function performWrite(message: VaultWrite): Promise<PopupReplies[VaultWrite['kind']]> {
	const accountId = message.accountId
	if (typeof accountId !== 'string') return message.kind === 'folder.dismissNotice' ? null : { ok: false, error: { code: 'invalid' } }
	switch (message.kind) {
		case 'item.fetch':
			return fetchItem(message.id, message.purpose === 'clone' ? 'clone' : 'edit', accountId)
		case 'item.create':
			return createItem(message.draft, accountId)
		case 'item.update':
			return updateItem(message.id, message.patch ?? {}, message.baseUpdatedAt, accountId, isString(message.recordId) ? message.recordId : undefined,
				isStrings(message.withoutUnreachable) ? message.withoutUnreachable : undefined)
		case 'item.delete':
			return deleteItem(message.id, accountId)
		case 'item.editable':
			return itemEditable(message.id, accountId)
		case 'folder.create':
			return createFolder(message.name, message.parentId ?? null, accountId)
		case 'folder.rename':
			return renameFolder(message.id, message.name, accountId)
		case 'folder.children':
			return folderChildren(message.id, accountId)
		case 'folder.delete':
			return deleteFolder(message.id, message.plan ?? { mode: 'plain' }, accountId)
		case 'folder.dismissNotice': {
			const account = await activeUnlocked()
			if (account?.id === accountId) await browser.storage.local.set({ [folderNoticeKey(account.id)]: true })
			return null
		}
	}
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

/** Unlike writes, these act on the account they name whether or not it is active. */
async function performAccountSettings(message: AccountSettingsRequest): Promise<PopupReplies[AccountSettingsRequest['kind']]> {
	const { accountId } = message
	switch (message.kind) {
		case 'account.settings.get':
			return typeof accountId === 'string' ? accountSettings(accountId) : null
		case 'account.settings.set': {
			const account = typeof accountId === 'string' ? await getAccount(accountId) : undefined
			if (!account) return null
			// Settings are out of reach while locked, and Never set then would put the key on disk at the next unlock.
			if (await accountStatus(account) !== 'unlocked') return 'locked'
			// `writeSettings` drops what fails validation.
			await updateSettings(accountId, message.patch)
			if (message.patch?.askPasskeys === false) forgetOfferedPasskeys()
			return accountSettings(accountId)
		}
		case 'pin.set':
			return enablePin(accountId, message.pin, message.masterPassword)
		case 'pin.remove':
			if (typeof accountId === 'string') await forgetPin(accountId)
			return null
	}
}

/** Re-reads and re-decrypts after a broadcast are the popup reacting to the background, not the user. */
function isInteraction(message: Exclude<HandledRequest, GeneratorRequest>): boolean {
	// Every popup reads it on open, which `vault.status` already counts.
	if (message.kind === 'settings.get' || message.kind === 'account.settings.get' || message.kind === 'defaultManager.get') return false
	if (message.kind === 'vault.snapshot') return message.opened === true
	if (message.kind === 'item.decrypt' || message.kind === 'item.passkeys') return message.passive !== true
	return true
}

/** Like account messages, the timeout is enforced first so a sleeping worker can't serve a vault that should be locked. */
export async function handlePopupRequest(message: HandledRequest): Promise<unknown> {
	try {
		await enforce()
		if (isGeneratorRequest(message)) {
			// Read before any await, so a lock or logout while this request waits keeps its add from landing.
			const epoch = sessionEpoch(message.accountId)
			const target = await generatorTarget(message)
			// Context is read on open. The lock screen's generator must not keep another account's vault from timing out.
			if (message.kind !== 'generator.context' && target?.status === 'unlocked') await touch()
			return await performGenerator(message, target, epoch)
		}
		// Copy from the lock screen's generator must not keep another account's vault from timing out either.
		if (message.kind === 'clipboard.copied' ? await activeUnlocked() : isInteraction(message)) await touch()
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
