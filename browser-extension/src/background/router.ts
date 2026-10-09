import { normalizeServerUrl } from '@/src/accounts/normalize-server-url'
import {
	accountStatus, addAccount, AccountLimitReached, DuplicateAccount, getAccount, getActiveAccountId,
	listAccounts, MAX_ACCOUNTS, removeAccount, removeAllAccounts, setActive, updateAccount,
} from '@/src/accounts/store'
import { verifyCredentials } from '@/src/accounts/verify'
import { readLastTab } from '@/src/background/requests'
import { Failure } from '@/src/failure'
import type { AccountSummary, NoticeCode, PopupScreen, PopupState, PopupToBackground, Result } from '@/src/messages'
import { enforce, syncAlarm, touch } from '@/src/vault/timeout'
import { sync } from '@/src/vault/sync'
import { checkSuite, lock, lockAll, unlock } from '@/src/vault/unlock'

export async function buildState(): Promise<PopupState> {
	const activeId = await getActiveAccountId()
	const accounts: AccountSummary[] = []
	for (const account of await listAccounts()) {
		accounts.push({
			id: account.id,
			serverUrl: account.serverUrl,
			host: new URL(account.serverUrl).host,
			uid: account.uid,
			displayName: account.displayName,
			avatarDataUrl: account.avatarDataUrl,
			status: await accountStatus(account),
			active: account.id === activeId,
		})
	}
	const active = accounts.find((a) => a.active) ?? null
	const screens: Record<AccountSummary['status'], PopupScreen> = { logged_out: 'reauthenticate', locked: 'unlock', unlocked: 'unlocked' }
	const record = active ? await getAccount(active.id) : undefined
	let notice: NoticeCode | null = null
	if (active?.status === 'logged_out' && record?.revoked) notice = 'session_revoked'
	if (active?.status === 'locked' && record?.keyChanged) notice = 'key_changed'
	return {
		screen: active ? screens[active.status] : 'add_account',
		accounts,
		active,
		notice,
		canAddAccount: accounts.length < MAX_ACCOUNTS,
		lastTab: await readLastTab(),
	}
}

async function add(serverUrl: string, username: string, appPassword: string): Promise<void> {
	const normalized = normalizeServerUrl(serverUrl)
	if (!normalized.ok) throw new Failure(normalized.code)
	// The popup asks for the permission; nothing is sent to a server the user declined.
	if (!(await browser.permissions.contains({ origins: [`${normalized.origin}/*`] }))) throw new Failure('permission_denied')
	// Checked again by `addAccount`; this one saves the round trip.
	if ((await listAccounts()).length >= MAX_ACCOUNTS) throw new Failure('limit_reached')
	const { suite, ...identity } = await verifyCredentials(normalized.serverUrl, username.trim(), appPassword)
	try {
		await addAccount({ serverUrl: normalized.serverUrl, ...identity, appPassword }, suite)
	} catch (error) {
		if (error instanceof DuplicateAccount) throw new Failure('duplicate')
		if (error instanceof AccountLimitReached) throw new Failure('limit_reached')
		throw error
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
	})
	await checkSuite(accountId, verified.suite)
}

async function perform(message: PopupToBackground): Promise<void> {
	switch (message.kind) {
		case 'vault.status':
		case 'accounts.list':
			return
		case 'accounts.add':
			return add(message.serverUrl, message.username, message.appPassword)
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
		if (error instanceof Failure) return { ok: false, code: error.code }
		console.error('[keepiq]', error)
		return { ok: false, code: 'unknown' }
	}
}
