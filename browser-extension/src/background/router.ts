import { normalizeServerUrl, serverOrigins } from '@/src/accounts/normalize-server-url'
import {
	accountStatus, addAccount, AccountLimitReached, DuplicateAccount, getAccount, getActiveAccountId,
	listAccounts, MAX_ACCOUNTS, removeAccount, removeAllAccounts, setActive, updateAccount, type AccountRecord,
} from '@/src/accounts/store'
import {
	awaitLoginFlow, deleteAppPassword, LOGIN_FLOW_FAILURE, startLoginFlow, type LoginFlowCredentials, type LoginFlowStart,
} from '@/src/accounts/login-flow'
import { verifyCredentials } from '@/src/accounts/verify'
import { readLastTab } from '@/src/background/requests'
import { Failure } from '@/src/failure'
import type { AccountSummary, NoticeCode, PopupScreen, PopupState, PopupToBackground, Result } from '@/src/messages'
import { enforce, syncAlarm, touch } from '@/src/vault/timeout'
import { hasPin, hasSeal } from '@/src/vault/pin'
import { refreshPolicy, sync } from '@/src/vault/sync'
import { checkSuite, lock, lockAll, unlock } from '@/src/vault/unlock'

export async function buildState(): Promise<PopupState> {
	const activeId = await getActiveAccountId()
	const accounts: AccountSummary[] = []
	for (const account of await listAccounts()) {
		const pin = await hasPin(account.id)
		accounts.push({
			id: account.id,
			serverUrl: account.serverUrl,
			host: new URL(account.serverUrl).host,
			uid: account.uid,
			displayName: account.displayName,
			avatarDataUrl: account.avatarDataUrl,
			status: await accountStatus(account),
			active: account.id === activeId,
			pin,
			pinAfterRestart: !pin && await hasSeal(account.id),
			passkeyUnlock: account.passkeyUnlock === true,
		})
	}
	const active = accounts.find((a) => a.active) ?? null
	const screens: Record<AccountSummary['status'], PopupScreen> = { logged_out: 'reauthenticate', locked: 'unlock', unlocked: 'unlocked' }
	const record = active ? await getAccount(active.id) : undefined
	let notice: NoticeCode | null = null
	if (active?.status === 'logged_out' && record?.revoked) notice = 'session_revoked'
	if (active?.status === 'locked' && record?.fileAccessNote) notice = 'file_access'
	if (active?.status === 'locked' && record?.keyChanged) notice = 'key_changed'
	return {
		screen: active ? screens[active.status] : 'add_account',
		accounts,
		active,
		notice,
		// lizard 1.24.3 reads a bare `<` in a property value as generic parameters and merges the functions below into this one.
		canAddAccount: (accounts.length < MAX_ACCOUNTS),
		lastTab: await readLastTab(),
	}
}

/** The server URL an add may go to: valid, granted by the user, and with room for one more account. */
async function addableServer(serverUrl: string): Promise<string> {
	const normalized = normalizeServerUrl(serverUrl)
	if (!normalized.ok) throw new Failure(normalized.code)
	// The popup asks for the permission; nothing is sent to a server the user declined.
	if (!(await browser.permissions.contains({ origins: serverOrigins(normalized.origin) }))) throw new Failure('permission_denied')
	// Checked again by `addAccount`; this one saves the round trip.
	if ((await listAccounts()).length >= MAX_ACCOUNTS) throw new Failure('limit_reached')
	return normalized.serverUrl
}

async function add(serverUrl: string, username: string, appPassword: string, fileAccessNote = false): Promise<void> {
	const server = await addableServer(serverUrl)
	const { suite, ...identity } = await verifyCredentials(server, username.trim(), appPassword)
	let added: AccountRecord
	try {
		added = await addAccount({ serverUrl: server, ...identity, appPassword, ...(fileAccessNote && { fileAccessNote }) }, suite)
	} catch (error) {
		if (error instanceof DuplicateAccount) throw new Failure('duplicate')
		if (error instanceof AccountLimitReached) throw new Failure('limit_reached')
		throw error
	}
	void refreshPolicy(added.id)
}

let pendingFlow: AbortController | null = null

/** Opens Nextcloud's login page in a tab; the popup closes then, so the background polls and adds the account. */
async function startFlow(serverUrl: string): Promise<void> {
	const server = await addableServer(serverUrl)
	const flow = await startLoginFlow(server)
	pendingFlow?.abort()
	const controller = new AbortController()
	pendingFlow = controller
	await browser.storage.session?.remove(LOGIN_FLOW_FAILURE)
	const tab = await browser.tabs.create({ url: flow.loginUrl })
	void finishFlow(server, flow, tab.id, controller)
}

async function finishFlow(server: string, flow: LoginFlowStart, tabId: number | undefined, controller: AbortController): Promise<void> {
	// Each `tabs.get` is an extension API call, which also keeps an MV3 worker awake while it waits.
	const tabOpen = () => (tabId === undefined ? Promise.resolve(false) : browser.tabs.get(tabId).then(() => true, () => false))
	const wait = (ms: number) => new Promise<void>((resolve) => setTimeout(resolve, ms))
	let credentials: LoginFlowCredentials | null = null
	try {
		credentials = await awaitLoginFlow(flow, { tabOpen, wait, now: Date.now, signal: controller.signal })
		if (credentials) await add(server, credentials.loginName, credentials.appPassword, true)
	} catch (error) {
		if (credentials) await deleteAppPassword(server, credentials)
		await browser.storage.session?.set({ [LOGIN_FLOW_FAILURE]: { code: error instanceof Failure ? error.code : 'unknown', host: new URL(server).host } })
	} finally {
		if (pendingFlow === controller) pendingFlow = null
	}
}

async function reauthenticate(accountId: string, appPassword: string): Promise<void> {
	const account = await getAccount(accountId)
	if (!account) throw new Failure('unknown', 'Unknown account')
	const verified = await verifyCredentials(account.serverUrl, account.loginName, appPassword)
	if (verified.uid !== account.uid) throw new Failure('unauthorized')
	await updateAccount(accountId, {
		appPassword,
		revoked: false,
		displayName: verified.displayName,
		email: verified.email,
		// Keep the old picture when this fetch failed; it retries on the next login.
		avatarDataUrl: verified.avatarDataUrl ?? account.avatarDataUrl,
		keepiqVersion: verified.keepiqVersion,
	})
	await checkSuite(accountId, verified.suite)
	void refreshPolicy(accountId)
}

async function perform(message: PopupToBackground): Promise<void> {
	switch (message.kind) {
		case 'vault.status':
		case 'accounts.list':
			return
		case 'accounts.add':
			return add(message.serverUrl, message.username, message.appPassword)
		case 'accounts.loginFlow':
			return startFlow(message.serverUrl)
		case 'accounts.reauthenticate':
			return reauthenticate(message.accountId, message.appPassword)
		case 'accounts.remove':
			return removeAccount(message.accountId)
		case 'accounts.removeAll':
			return removeAllAccounts()
		case 'accounts.switch':
			return setActive(message.accountId)
		case 'vault.unlock':
			await unlock(message.accountId, message.method)
			// Not awaited: the vault shows the cached snapshot while this runs.
			void sync(message.accountId, 'full')
			return
		case 'vault.lock':
			return lock(message.accountId)
		case 'vault.lockAll':
			return lockAll()
		default:
			throw new Failure('unknown', `Unknown message ${(message as { kind?: unknown }).kind}`)
	}
}

/** Every popup message: enforce the timeout first, so a sleeping worker can't extend a session, then count it as interaction. */
export async function handlePopupMessage(message: PopupToBackground): Promise<Result> {
	try {
		await enforce()
		if (!(message.kind === 'vault.status' && message.passive)) await touch()
		await perform(message)
		await syncAlarm()
		return { ok: true, state: await buildState() }
	} catch (error) {
		if (error instanceof Failure) {
			return error.attemptsLeft === undefined ? { ok: false, code: error.code } : { ok: false, code: error.code, attemptsLeft: error.attemptsLeft }
		}
		console.error('[keepiq]', error)
		return { ok: false, code: 'unknown' }
	}
}
