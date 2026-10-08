import { accountStatus, getAccount, getActiveAccountId, type AccountRecord } from '@/src/accounts/store'
import { scheduleClipboardClear } from '@/src/clipboard'
import { rsaDecrypt } from '@/src/crypto'
import type { AccountStatus, DecryptReply, DecryptedItem, PopupReplies, PopupRequest, PopupTab, VaultSnapshotReply } from '@/src/messages'
import { getKey, LAST_TAB_KEY, sessionValues } from '@/src/vault/key-store'
import { suggestionIds } from '@/src/vault/match'
import { readSnapshot, toItemMeta } from '@/src/vault/store'
import type { StoredSecretRow, VaultSnapshot } from '@/src/vault/types'
import { isStale, sync, syncStatus } from '@/src/vault/sync'
import { cancelPopout, enforce, expectPopout, touch } from '@/src/vault/timeout'

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
		suggestionIds: suggestionIds(items ?? [], tabUrl),
		sync: await syncStatus(account.id),
		webAppUrl: `${account.serverUrl}/index.php/apps/keepiq/`,
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

async function decrypt(ids: string[], fields: Array<keyof DecryptedItem>): Promise<DecryptReply> {
	const account = await activeUnlocked()
	const key = account ? await getKey(account.id) : null
	if (!account || !key) return { ok: false, error: 'locked' }
	const rows = rowsById(await readSnapshot(account.id))
	const results = await Promise.all(ids.map(async (id): Promise<[string, DecryptedItem | 'blocked' | 'decrypt_failed'] | null> => {
		const row = rows.get(id)
		if (!row) return null
		if (row.blocked) return [id, 'blocked']
		try {
			const item: DecryptedItem = {}
			for (const field of fields) {
				const ciphertext = row[field]
				if (typeof ciphertext === 'string') item[field] = await rsaDecrypt(ciphertext, key)
			}
			return [id, item]
		} catch (error) {
			console.error('[keepiq] decrypt failed for', id, error)
			return [id, 'decrypt_failed']
		}
	}))
	const items: Record<string, DecryptedItem> = {}
	let failure: 'blocked' | 'decrypt_failed' | null = null
	for (const [id, result] of results.filter((r) => r !== null)) {
		if (typeof result === 'string') failure = result
		else items[id] = result
	}
	// One bad row must not blank the rest of a batch.
	return ids.length === 1 && failure ? { ok: false, error: failure } : { ok: true, items }
}

async function popout(tabId: number | undefined): Promise<null> {
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

async function perform(message: PopupRequest): Promise<PopupReplies[PopupRequest['kind']]> {
	switch (message.kind) {
		case 'vault.sync': {
			const account = await activeUnlocked()
			return account ? sync(account.id, 'full') : { syncing: false, syncedAt: null, offline: false, lastError: null }
		}
		case 'vault.snapshot':
			return snapshot(message.tabUrl, message.opened === true)
		case 'item.decrypt':
			return decrypt(message.ids, message.fields)
		case 'clipboard.copied': {
			const accountId = await getActiveAccountId()
			if (accountId) await scheduleClipboardClear(accountId)
			return null
		}
		case 'popup.popout':
			return popout(message.tabId)
		case 'popup.lastTab.set':
			if (TABS.includes(message.tab)) await sessionValues.set({ [LAST_TAB_KEY]: message.tab })
			return null
	}
}

/** Re-reads and re-decrypts after a broadcast are the popup reacting to the background, not the user. */
function isInteraction(message: PopupRequest): boolean {
	if (message.kind === 'vault.snapshot') return message.opened === true
	if (message.kind === 'item.decrypt') return message.passive !== true
	return true
}

/** Like account messages, the timeout is enforced first so a sleeping worker can't serve a vault that should be locked. */
export async function handlePopupRequest(message: PopupRequest): Promise<unknown> {
	try {
		await enforce()
		if (isInteraction(message)) await touch()
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
