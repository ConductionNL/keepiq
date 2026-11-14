import { ApiError, createClient, Offline, SessionRevoked, VaultWriteLocked, type Client } from '@/src/api/client'
import type { BlockedSecretRow, FolderRow, OpenSecretRow, Page, SecretRow, SecretTypeRow, SuiteRow } from '@/src/api/types'
import { clearAccountPolicy, isMaximum, patchAccountPolicy } from '@/src/accounts/policy'
import { getAccount, suiteKey, updateAccount, type AccountRecord } from '@/src/accounts/store'
import { broadcast } from '@/src/background/broadcast'
import type { GeneratorPolicy } from '@/src/generator/options'
import { toPolicy } from '@/src/generator/policy'
import { clearPolicy, writePolicy } from '@/src/generator/store'
import type { BlockReason, SyncStatus } from '@/src/messages'
import { syncNeverLockKey } from './key-store'
import { checkedAt, clearSnapshot, markChecked, readSnapshot, writeSnapshot } from './store'
import type { StoredBlockedRow, StoredSecretRow, VaultSnapshot } from './types'
import { blockUnlock, checkSuite } from './unlock'

export const SYNC_INTERVAL_MINUTES = 15
export const SYNC_ALARM = 'vault-sync'
const INTERVAL_MS = SYNC_INTERVAL_MINUTES * 60_000
/** Longer than the sync interval, or the probe would almost never spare a download. */
export const LISTS_MAX_AGE_MINUTES = 60
const PAGE_LIMIT = 100

/** `probe` asks whether anything changed first; unlock, writes and "Sync now" go straight to `full`. */
export type SyncMode = 'probe' | 'full'

const outcomes = new Map<string, Pick<SyncStatus, 'offline' | 'lastError'>>()
const inFlight = new Map<string, Promise<SyncStatus>>()

export async function syncStatus(accountId: string): Promise<SyncStatus> {
	const outcome = outcomes.get(accountId)
	return {
		syncing: inFlight.has(accountId),
		syncedAt: await checkedAt(accountId),
		offline: outcome?.offline ?? false,
		lastError: outcome?.lastError ?? null,
	}
}

export async function isStale(accountId: string, now = Date.now()): Promise<boolean> {
	const at = await checkedAt(accountId)
	return at === null || now - Date.parse(at) >= INTERVAL_MS
}

/** At most one sync per account; a trigger during a running sync joins it. */
export function sync(accountId: string, mode: SyncMode = 'full'): Promise<SyncStatus> {
	const running = inFlight.get(accountId)
	if (running) return running
	const started = (async () => {
		try {
			await run(accountId, mode)
			outcomes.set(accountId, { offline: false, lastError: null })
		} catch (error) {
			outcomes.set(accountId, outcomeOf(error))
		} finally {
			inFlight.delete(accountId)
		}
		const status = await syncStatus(accountId)
		broadcast({ kind: 'vault.changed', sync: status })
		return status
	})()
	inFlight.set(accountId, started)
	return started
}

/** A sync already running may have read the server before the write landed, so it is not joined. */
export async function syncAfterWrite(accountId: string): Promise<SyncStatus> {
	await inFlight.get(accountId)
	return sync(accountId, 'full')
}

/** Any request that got an answer proves the server reachable, so writes keep the offline flag current too. */
export async function recordOutcome(accountId: string, error: unknown): Promise<void> {
	if (error instanceof SessionRevoked) return
	const before = outcomes.get(accountId)?.offline ?? false
	const unreachable = error instanceof Offline || (error instanceof ApiError && error.status >= 500)
	outcomes.set(accountId, unreachable ? outcomeOf(error) : { offline: false, lastError: null })
	if (before !== unreachable) broadcast({ kind: 'vault.changed', sync: await syncStatus(accountId) })
}

function outcomeOf(error: unknown): Pick<SyncStatus, 'offline' | 'lastError'> {
	// The account layer already purged and logged out.
	if (error instanceof SessionRevoked) return { offline: false, lastError: null }
	if (error instanceof VaultWriteLocked) return { offline: false, lastError: 'busy' }
	if (error instanceof Offline) return { offline: true, lastError: 'network' }
	if (error instanceof ApiError && error.status >= 500) return { offline: true, lastError: 'server' }
	console.error('[keepiq] sync failed', error)
	return { offline: false, lastError: 'server' }
}

/** Kept current for `keepiqSupports` by every full sync, which every write starts; a read that names none leaves the stored one. */
async function refreshVersion(client: Client, account: AccountRecord): Promise<void> {
	const version = await client.fetchVersion().catch(() => null)
	if (version !== null && version !== account.keepiqVersion) await updateAccount(account.id, { keepiqVersion: version })
}

/** Each `undefined` keeps what is cached: the server gave no answer of that part's shape. */
interface FetchedPolicies {
	generator: GeneratorPolicy | null | undefined
	exportDisabled: boolean | undefined
	maxIdleMinutes: number | undefined
}

async function fetchPolicy(client: Client): Promise<Pick<FetchedPolicies, 'generator' | 'exportDisabled'>> {
	try {
		const body = await client.keepiq<{ policy_enabled?: unknown; vault_export_disabled?: unknown } | null>('GET', '/api/settings/policy')
		return {
			generator: typeof body?.policy_enabled === 'boolean' ? toPolicy(body) : undefined,
			// Applies to the calling user whatever `policy_enabled` says (ADR-003).
			exportDisabled: typeof body?.vault_export_disabled === 'boolean' ? body.vault_export_disabled : undefined,
		}
	} catch {
		return { generator: undefined, exportDisabled: undefined }
	}
}

async function fetchMaxIdleMinutes(client: Client): Promise<number | undefined> {
	try {
		const max = (await client.keepiq<{ maxIdleMinutes?: unknown } | null>('GET', '/api/v1/extension/policy'))?.maxIdleMinutes
		return isMaximum(max) ? max : undefined
	} catch {
		return undefined
	}
}

async function fetchPolicies(client: Client): Promise<FetchedPolicies> {
	const [settings, maxIdleMinutes] = await Promise.all([fetchPolicy(client), fetchMaxIdleMinutes(client)])
	return { ...settings, maxIdleMinutes }
}

/** Written only while the account stays logged in, like the vault (see `run`). */
async function storePolicy(accountId: string, pending: Promise<FetchedPolicies>): Promise<void> {
	const { generator, exportDisabled, maxIdleMinutes } = await pending
	if (!(await loggedIn(accountId))) return
	if (generator !== undefined) await writePolicy(accountId, generator)
	if (exportDisabled !== undefined || maxIdleMinutes !== undefined) {
		await patchAccountPolicy(accountId, { exportDisabled, maxIdleMinutes })
		// A maximum rules out Never, so its copy of the key leaves the disk at once (ADR-002).
		await syncNeverLockKey(accountId)
	}
	if (!(await loggedIn(accountId))) await undoWrites(accountId)
}

/** After a login, so the lock screen's generator and the timeout follow the policies before the first unlock. */
export async function refreshPolicy(accountId: string): Promise<void> {
	try {
		const account = await getAccount(accountId)
		if (account && account.appPassword !== null) await storePolicy(accountId, fetchPolicies(createClient(account)))
	} catch (error) {
		console.error('[keepiq] policy refresh failed', error)
	}
}

async function run(accountId: string, mode: SyncMode): Promise<void> {
	const account = await getAccount(accountId)
	if (!account || account.appPassword === null) return
	const client = createClient(account)
	const policy = fetchPolicies(client)
	// The policy does not depend on the vault, so a failed vault sync keeps a policy that arrived.
	try {
		await runVault({ accountId, mode, client, account })
	} finally {
		await storePolicy(accountId, policy)
	}
}

/** One vault sync: the account, how far it goes, and the client it asks with. */
interface VaultSync {
	accountId: string
	mode: SyncMode
	client: Client
	account: AccountRecord
}

async function runVault(sync: VaultSync): Promise<void> {
	const cached = await readSnapshot(sync.accountId)
	if (await settledByProbe(sync, cached)) return
	const fetched = await fetchFresh(sync, cached)
	// A logout during the fetch must not bring the suite or the vault back.
	if (!fetched) return
	if (fetched.unlockBlocked) return blockUnlock(sync.accountId)
	return storeFetched(sync.accountId, fetched)
}

/** A probe of a cached vault ends the sync when the vault is unchanged, or the two-factor policy now blocks the unlock. */
async function settledByProbe(sync: VaultSync, cached: VaultSnapshot | undefined): Promise<boolean> {
	if (sync.mode !== 'probe' || !cached) return false
	return settleProbe(sync, cached)
}

async function settleProbe({ accountId, client }: VaultSync, cached: VaultSnapshot): Promise<boolean> {
	const [same, suite] = await Promise.all([unchanged(client, cached), client.listSuites().then(activeRow)])
	// An unchanged vault skips the manifest, so the two-factor policy and a new key are read here.
	if (!isUnlockable(suite)) {
		await blockUnlock(accountId)
		return true
	}
	if (!(same && sameSuiteAs(suite, cached))) return false
	await markChecked(accountId, new Date().toISOString())
	if (!(await loggedIn(accountId))) await undoWrites(accountId)
	return true
}

const sameSuiteAs = (suite: SuiteRow, cached: VaultSnapshot) => suite.id === cached.suite.id && suite.unlockKeyEpoch === cached.suite.unlockKeyEpoch

/** The fetched vault, or `null` when the account logged out meanwhile. */
async function fetchFresh({ accountId, client, account }: VaultSync, cached: VaultSnapshot | undefined): Promise<Fetched | null> {
	const [fetched] = await Promise.all([fetchSnapshot(client, (cached?.total ?? 0) >= MANIFEST_ROW_CAP), refreshVersion(client, account)])
	return (await loggedIn(accountId)) ? fetched : null
}

/** Logout marks the account before it purges, so checking after each write catches one that lands in between. */
async function storeFetched(accountId: string, { suite, snapshot }: Extract<Fetched, { unlockBlocked: false }>): Promise<void> {
	const changed = await checkSuite(accountId, { id: suite.id, status: suite.status, certificate: suite.certificate, privateKey: suite.privateKey, unlockKeyEpoch: suite.unlockKeyEpoch })
	if (!(await loggedIn(accountId))) return undoWrites(accountId)
	if (changed) return
	await writeSnapshot(accountId, snapshot)
	if (!(await loggedIn(accountId))) return undoWrites(accountId)
}

async function undoWrites(accountId: string): Promise<void> {
	await clearSnapshot(accountId)
	await browser.storage.local.remove(suiteKey(accountId))
	await clearPolicy(accountId)
	// The organisation policy outlives a logout, but not the account.
	if (!(await getAccount(accountId))) await clearAccountPolicy(accountId)
}

async function loggedIn(accountId: string): Promise<boolean> {
	return typeof (await getAccount(accountId))?.appPassword === 'string'
}

/** Deletions change `total`, edits and creates change the newest `updatedAt` (ADR-002). */
async function unchanged(client: Client, cached: VaultSnapshot): Promise<boolean> {
	const probe = await client.keepiq<Page<FetchedRow>>('GET', '/api/v1/secrets?sort=updated_at&direction=desc&limit=1')
	const listsFresh = Date.now() - Date.parse(cached.listsSyncedAt) < LISTS_MAX_AGE_MINUTES * 60_000
	return listsFresh && probe.total === cached.total && (probe.items[0]?.updatedAt ?? null) === cached.newestUpdatedAt
}

function activeRow(rows: SuiteRow[]): SuiteRow {
	const row = rows.find((r) => r.status === 'active')
	if (!row) throw new ApiError(404, 'No active suite')
	return row
}

/** The manifest stops at the server's page limit by design (keepiq#1233); bigger vaults page. */
const MANIFEST_ROW_CAP = 1000
/** Suite statuses whose rows the server withholds (`SecretService::BLOCKING_STATUSES`). */
const BLOCKING_STATUSES = ['revoked', 'compromised']

/** A row as the server sends it; only the fallback list sends the blocked shape and `migrationError`. */
type FetchedRow = SecretRow & { migrationError?: string | null }

interface Manifest {
	suite: SuiteRow | null
	secrets: FetchedRow[]
	folders: FolderRow[]
	types: SecretTypeRow[]
	unlockBlocked?: string | null
}

type Fetched =
	| { unlockBlocked: true }
	| { unlockBlocked: false; suite: SuiteRow & { privateKey: string }; snapshot: VaultSnapshot }

/** The vault's rows and lists, with the server's count of the rows. */
interface VaultLists {
	secrets: FetchedRow[]
	folders: FolderRow[]
	types: SecretTypeRow[]
	total: number
}

/** A suite with its key, which neither the two-factor policy nor a missing key blocks. */
type UnlockableSuite = SuiteRow & { privateKey: string }

function isUnlockable(suite: SuiteRow): suite is UnlockableSuite {
	return typeof suite.privateKey === 'string' && !suite.unlockBlocked
}

async function fetchSnapshot(client: Client, skipManifest: boolean): Promise<Fetched> {
	const manifest = await readManifest(client, skipManifest)
	// The two-factor policy withholds the suite; the web app drops its snapshot on this signal.
	if (manifest?.unlockBlocked) return { unlockBlocked: true }
	const listSuites = suitesOnce(client)
	const lists = await vaultLists(client, manifest)
	const suite = manifest?.suite ?? activeRow(await listSuites())
	if (!isUnlockable(suite)) return { unlockBlocked: true }
	// The manifest never sends the blocked shape, so rows on another suite are checked here.
	const stored = lists.secrets.some((row) => row.blocked || row.encryptionSuiteId !== suite.id)
		? withheld(lists.secrets, await listSuites())
		: lists.secrets as OpenSecretRow[]
	return { unlockBlocked: false, suite: { ...suite, privateKey: suite.privateKey }, snapshot: snapshotOf(suite, { ...lists, secrets: stored }) }
}

/** Refused: the admin disabled offline caching. 404: no active suite. Either way the lists are fetched one by one. */
const isNoManifest = (error: unknown) => error instanceof ApiError && (error.refused || error.status === 404)

async function readManifest(client: Client, skipManifest: boolean): Promise<Manifest | null> {
	try {
		return skipManifest ? null : await client.keepiq<Manifest>('GET', '/api/v1/offline/manifest')
	} catch (error) {
		if (!isNoManifest(error)) throw error
		return null
	}
}

/** The suites, fetched at most once per sync and only when asked for. */
function suitesOnce(client: Client): () => Promise<SuiteRow[]> {
	let suites: Promise<SuiteRow[]> | undefined
	return () => (suites ??= client.listSuites())
}

/** The manifest's lists when it holds every row; otherwise the rows paged, and what the manifest lacks fetched. */
async function vaultLists(client: Client, manifest: Manifest | null): Promise<VaultLists> {
	if (manifest && manifest.secrets.length < MANIFEST_ROW_CAP) return { secrets: manifest.secrets, folders: manifest.folders, types: manifest.types, total: manifest.secrets.length }
	const [paged, folders, types] = await Promise.all([
		fetchAllSecrets(client),
		manifest?.folders ?? client.keepiq<FolderRow[]>('GET', '/api/v1/folders'),
		manifest?.types ?? client.keepiq<SecretTypeRow[]>('GET', '/api/v1/secret-types'),
	])
	return { secrets: paged.secrets, folders, types, total: paged.total }
}

function snapshotOf(suite: SuiteRow, lists: Omit<VaultLists, 'secrets'> & { secrets: StoredSecretRow[] }): VaultSnapshot {
	const now = new Date().toISOString()
	return {
		suite: { id: suite.id, unlockKeyEpoch: suite.unlockKeyEpoch },
		secrets: lists.secrets,
		folders: lists.folders,
		types: lists.types,
		// Client time, so staleness never depends on the server's clock.
		syncedAt: now,
		listsSyncedAt: now,
		newestUpdatedAt: newest(lists.secrets),
		// The server's count, so a row lost to paging does not make every probe download again.
		total: lists.total,
	}
}

/** The server's block rule (`SecretService::suiteBlockReason`) as a reason code, for every row. */
function withheld(rows: FetchedRow[], suites: SuiteRow[]): StoredSecretRow[] {
	const statuses = new Map(suites.map((suite) => [suite.id, suite.status]))
	return rows.map((row) => withheldRow(row, statuses.get(row.encryptionSuiteId)))
}

/** The row as sent while its suite is known and not blocking, else its metadata blocked with the reason. */
function withheldRow(row: FetchedRow, status: SuiteRow['status'] | undefined): StoredSecretRow {
	if (!row.blocked && status !== undefined && !BLOCKING_STATUSES.includes(status)) return row
	return blockedWithout(row, blockReasonOf(row, status))
}

const SUITE_BLOCK_REASONS = new Map<string, BlockReason>([['compromised', 'suite_compromised'], ['revoked', 'suite_revoked']])

function blockReasonOf(row: FetchedRow, status: SuiteRow['status'] | undefined): BlockReason | undefined {
	if (status === undefined) return 'suite_missing'
	// Only the fallback list sends `migrationError`; without it a compromised row may still be one recovery could not save.
	if (row.migrationError) return 'migration_failed'
	return SUITE_BLOCK_REASONS.get(status)
}

/** The row's metadata alone: no ciphertext of any value field. */
function blockedWithout(row: FetchedRow, blockedReason: BlockReason | undefined): StoredBlockedRow {
	const meta: Partial<Omit<OpenSecretRow, 'blocked'> & Omit<BlockedSecretRow, 'blocked'>> = { ...row }
	delete meta.key
	delete meta.login
	delete meta.additionalFields
	delete meta.totp
	delete meta.passkeys
	delete meta.blockedReason
	return { ...(meta as Omit<StoredBlockedRow, 'blocked' | 'blockedReason'>), blocked: true, blockedReason }
}

interface Paged {
	secrets: FetchedRow[]
	total: number
}

/**
 * The server's sort has no unique tiebreaker (keepiq#1234), so rows that tie can move between
 * pages. A row seen twice is kept once; a short count gets one more pass in another order.
 */
async function fetchAllSecrets(client: Client): Promise<Paged> {
	const first = new Map<string, FetchedRow>()
	const firstTotal = await pageThrough(client, 'sort=created_at&direction=asc', first)
	if (first.size >= firstTotal) return { secrets: [...first.values()], total: firstTotal }
	const second = new Map<string, FetchedRow>()
	const total = await pageThrough(client, 'sort=updated_at&direction=desc', second)
	// A complete second pass is the whole vault: a row only the first saw was deleted in between.
	const rows = second.size >= total ? second : new Map([...first, ...second])
	return { secrets: [...rows.values()], total }
}

async function pageThrough(client: Client, order: string, rows: Map<string, FetchedRow>): Promise<number> {
	for (let page = 1; ; page++) {
		const result = await client.keepiq<Page<FetchedRow>>('GET', `/api/v1/secrets?${order}&limit=${PAGE_LIMIT}&page=${page}`)
		for (const row of result.items) rows.set(row.id, row)
		if (page * PAGE_LIMIT >= result.total || result.items.length === 0) return result.total
	}
}

function newest(rows: StoredSecretRow[]): string | null {
	let best: StoredSecretRow | undefined
	for (const row of rows) if (!best || Date.parse(row.updatedAt) > Date.parse(best.updatedAt)) best = row
	return best?.updatedAt ?? null
}
