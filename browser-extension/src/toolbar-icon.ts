/**
 * The toolbar icon, its tooltip and the per-tab login count (openspec/changes/ext-toolbar-icon/). The only caller of
 * `setIcon`, `setBadgeText` and `setTitle`. Counts are never stored, and only the active account counts.
 */
import { i18n } from '#i18n'
import { readSettings } from '@/src/accounts/settings'
import { accountStatus, getAccount, getActiveAccountId } from '@/src/accounts/store'
import { candidatesFor } from '@/src/autofill/matcher'
import { onBroadcast } from '@/src/background/broadcast'
import { action } from '@/src/browser-action'
import type { AccountStatus } from '@/src/messages'
import { createQueue } from '@/src/queue'
import { readGlobalSettings } from '@/src/settings/global'
import { sessionEpoch } from '@/src/vault/key-store'
import { readSnapshot } from '@/src/vault/store'
import { enforce } from '@/src/vault/timeout'

/** `'state'`: the icon, the tooltip and every badge; a tab id: that tab's badge. */
export type ToolbarScope = 'state' | number

const BADGE_COLOR = '#21468B'
const BADGE_TEXT_COLOR = '#FFFFFF'
/** `tabs.onUpdated` fires several times per page load. */
const COALESCE_MS = 100

const ICON_FOLDERS: Record<AccountStatus, string> = { unlocked: 'toolbar', locked: 'toolbar-locked', logged_out: 'toolbar-logged-out' }

/** The storage keys a 'state' refresh follows; the lock state is not among them, it arrives by broadcast or popup reply. */
const WATCHED = /^(accounts|activeAccountId|settings\..+|vaultCache\..+)$/

interface View {
	accountId: string | null
	status: AccountStatus
	showCount: boolean
	title: string
	epoch: number
	generation: number
}

/** Bumped by every 'state' request, so a paint computed before one gives way to it. */
let generation = 0
const timers = new Map<ToolbarScope, ReturnType<typeof setTimeout>>()
const queued = createQueue()

/** From the manifest, so a dev build keeps its " (DEV)". */
const extensionName = () => browser.runtime.getManifest().name

export function badgeText(count: number): string {
	return count <= 0 ? '' : count > 9 ? '9+' : String(count)
}

function stateTitle(status: AccountStatus): string {
	const name = extensionName()
	if (status === 'locked') return i18n.t('toolbar.locked', { name })
	if (status === 'logged_out') return i18n.t('toolbar.loggedOut', { name })
	return name
}

async function readView(): Promise<View> {
	const seen = generation
	const accountId = await getActiveAccountId()
	// Read before the status, so a lock landing while it is read still counts.
	const epoch = accountId ? sessionEpoch(accountId) : 0
	const account = accountId ? await getAccount(accountId) : undefined
	const status = account ? await accountStatus(account) : 'logged_out'
	const showCount = status === 'unlocked' && (await readGlobalSettings()).showBadgeCounter
	return { accountId: account?.id ?? null, status, showCount, title: stateTitle(status), epoch, generation: seen }
}

async function loginCount(accountId: string, url: string | undefined): Promise<number> {
	const vault = await readSnapshot(accountId)
	return vault ? candidatesFor(url, vault, (await readSettings(accountId)).uriMatch, {}).length : 0
}

async function paintTab(tab: { id?: number; url?: string }, view: View): Promise<void> {
	if (tab.id === undefined) return
	const count = view.showCount && view.accountId ? await loginCount(view.accountId, tab.url) : 0
	// A switch reaches `storage.onChanged` only after its write.
	const switched = count > 0 && (await getActiveAccountId()) !== view.accountId
	// Checked with no await before the calls, so a count never lands after a lock or switch.
	if (view.generation !== generation) return
	if (switched || (view.accountId && sessionEpoch(view.accountId) !== view.epoch)) {
		refreshToolbar('state')
		return
	}
	const title = count > 0 ? i18n.t('toolbar.count', count, { name: extensionName() }) : view.title
	await setTab(tab.id, badgeText(count), title)
}

/** The tab may have closed since it was queried. */
async function setTab(tabId: number, text: string, title: string): Promise<void> {
	await Promise.all([action.setBadgeText({ tabId, text }), action.setTitle({ tabId, title })]).catch(() => {})
}

/** A per-tab value overrides the global one, so every tab is reset; background tabs are counted when activated. */
async function paintState(): Promise<void> {
	const view = await readView()
	const tabs = await browser.tabs.query({})
	if (view.generation !== generation) return
	const folder = ICON_FOLDERS[view.status]
	// Settled apart, so a failed icon call still resets the tabs.
	const results = await Promise.allSettled([
		action.setIcon({ path: { 16: `/icon/${folder}/16.png`, 32: `/icon/${folder}/32.png` } }),
		action.setTitle({ title: view.title }),
		action.setBadgeText({ text: '' }),
		...tabs.map((tab) => (view.showCount && tab.active ? paintTab(tab, view) : tab.id === undefined ? undefined : setTab(tab.id, '', view.title))),
	])
	const failed = results.find((result) => result.status === 'rejected')
	if (failed) throw failed.reason
}

/**
 * Outside the queue, so a lock drops every count from the badges and tooltips at once; the repaint then sets the state
 * title. No count lands after it, as every paint checks the generation with no await before its calls.
 */
async function clearCounts(): Promise<void> {
	const tabs = await browser.tabs.query({})
	await Promise.allSettled([action.setBadgeText({ text: '' }), ...tabs.map((tab) => (tab.id === undefined ? undefined : setTab(tab.id, '', extensionName())))])
}

async function paintTabById(tabId: number): Promise<void> {
	const tab = await browser.tabs.get(tabId).catch(() => undefined)
	if (tab) await paintTab(tab, await readView())
}

/** Coalesced per scope; the state is read when the refresh runs, never when it was asked for. */
export function refreshToolbar(scope: ToolbarScope): void {
	if (scope === 'state') generation++
	if (timers.has(scope)) return
	timers.set(scope, setTimeout(() => {
		timers.delete(scope)
		void queued(async () => {
			// A tab switch after sleep must not count for a vault past its timeout; a lock here bumps the generation before the read.
			await enforce()
			await (scope === 'state' ? paintState() : paintTabById(scope))
		}).catch((error: unknown) => console.error('[keepiq] toolbar icon', error))
	}, COALESCE_MS))
}

export function registerToolbarListeners(): void {
	void action.setBadgeBackgroundColor({ color: BADGE_COLOR }).catch(() => {})
	// Chrome 110 and later.
	void action.setBadgeTextColor?.({ color: BADGE_TEXT_COLOR })?.catch(() => {})
	const state = () => refreshToolbar('state')
	browser.runtime.onStartup.addListener(state)
	browser.runtime.onInstalled.addListener(state)
	onBroadcast((message) => {
		if (message.kind !== 'vault.locked') return
		state()
		void clearCounts().catch(() => {})
	})
	browser.storage.onChanged.addListener((changes, area) => {
		if (area === 'local' && Object.keys(changes).some((key) => WATCHED.test(key))) state()
	})
	browser.tabs.onActivated.addListener(({ tabId }) => refreshToolbar(tabId))
	browser.tabs.onUpdated.addListener((tabId, change, tab) => {
		if ((change.url !== undefined || change.status === 'complete') && tab.active) refreshToolbar(tabId)
	})
	browser.windows.onFocusChanged.addListener((windowId) => {
		if (windowId === browser.windows.WINDOW_ID_NONE) return
		void browser.tabs.query({ active: true, windowId }).then(([tab]) => {
			if (tab?.id !== undefined) refreshToolbar(tab.id)
		}).catch(() => {})
	})
	// Re-enabling the extension, or a worker stopped before its paint ran, fires neither event above.
	state()
}
