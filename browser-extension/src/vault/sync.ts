import { ApiError, createClient, Offline, SessionRevoked, VaultWriteLocked, type Client } from '@/src/api/client'
import type { FolderRow, Page, SecretTypeRow, SuiteRow } from '@/src/api/types'
import { getAccount, suiteKey } from '@/src/accounts/store'
import { broadcast } from '@/src/background/broadcast'
import type { SyncStatus } from '@/src/messages'
import { checkedAt, clearSnapshot, markChecked, readSnapshot, writeSnapshot } from './store'
import type { StoredSecretRow, VaultSnapshot } from './types'
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

function outcomeOf(error: unknown): Pick<SyncStatus, 'offline' | 'lastError'> {
	// The account layer already purged and logged out.
	if (error instanceof SessionRevoked) return { offline: false, lastError: null }
	if (error instanceof VaultWriteLocked) return { offline: false, lastError: 'busy' }
	if (error instanceof Offline) return { offline: true, lastError: 'network' }
	if (error instanceof ApiError && error.status >= 500) return { offline: true, lastError: 'server' }
	console.error('[keepiq] sync failed', error)
	return { offline: false, lastError: 'server' }
}

async function run(accountId: string, mode: SyncMode): Promise<void> {
	const account = await getAccount(accountId)
	if (!account || account.appPassword === null) return
	const client = createClient(account)
	const cached = await readSnapshot(accountId)
	if (mode === 'probe' && cached) {
		const [same, suite] = await Promise.all([unchanged(client, cached), client.listSuites().then(activeRow)])
		// An unchanged vault skips the manifest, so the two-factor policy and a new key are read here.
		if (typeof suite.privateKey !== 'string' || suite.unlockBlocked) return blockUnlock(accountId)
		if (same && suite.id === cached.suite.id && suite.unlockKeyEpoch === cached.suite.unlockKeyEpoch) {
			await markChecked(accountId, new Date().toISOString())
			if (!(await loggedIn(accountId))) await undoWrites(accountId)
			return
		}
	}
	const fetched = await fetchSnapshot(client, (cached?.total ?? 0) >= MANIFEST_ROW_CAP)
	// A logout during the fetch must not bring the suite or the vault back. Logout marks the
	// account before it purges, so checking after each write catches one that lands in between.
	if (!(await loggedIn(accountId))) return
	if (fetched.unlockBlocked) return blockUnlock(accountId)
	const { suite, snapshot } = fetched
	const changed = await checkSuite(accountId, { id: suite.id, status: suite.status, certificate: suite.certificate, privateKey: suite.privateKey, unlockKeyEpoch: suite.unlockKeyEpoch })
	if (!(await loggedIn(accountId))) return undoWrites(accountId)
	if (changed) return
	await writeSnapshot(accountId, snapshot)
	if (!(await loggedIn(accountId))) return undoWrites(accountId)
}

async function undoWrites(accountId: string): Promise<void> {
	await clearSnapshot(accountId)
	await browser.storage.local.remove(suiteKey(accountId))
}

async function loggedIn(accountId: string): Promise<boolean> {
	return typeof (await getAccount(accountId))?.appPassword === 'string'
}

/** Deletions change `total`, edits and creates change the newest `updatedAt` (ADR-002). */
async function unchanged(client: Client, cached: VaultSnapshot): Promise<boolean> {
	const probe = await client.keepiq<Page<StoredSecretRow>>('GET', '/api/v1/secrets?sort=updated_at&direction=desc&limit=1')
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

interface Manifest {
	suite: SuiteRow | null
	secrets: StoredSecretRow[]
	folders: FolderRow[]
	types: SecretTypeRow[]
	unlockBlocked?: string | null
}

type Fetched =
	| { unlockBlocked: true }
	| { unlockBlocked: false; suite: SuiteRow & { privateKey: string }; snapshot: VaultSnapshot }

async function fetchSnapshot(client: Client, skipManifest: boolean): Promise<Fetched> {
	let manifest: Manifest | null = null
	try {
		if (!skipManifest) manifest = await client.keepiq<Manifest>('GET', '/api/v1/offline/manifest')
	} catch (error) {
		// 403: the admin disabled offline caching. 404: no active suite.
		if (!(error instanceof ApiError) || (error.status !== 403 && error.status !== 404)) throw error
	}
	// The two-factor policy withholds the suite; the web app drops its snapshot on this signal.
	if (manifest?.unlockBlocked) return { unlockBlocked: true }

	let suites: Promise<SuiteRow[]> | undefined
	const listSuites = () => (suites ??= client.listSuites())
	let secrets: StoredSecretRow[]
	let folders: FolderRow[]
	let types: SecretTypeRow[]
	let total: number
	if (manifest && manifest.secrets.length < MANIFEST_ROW_CAP) {
		;({ secrets, folders, types } = manifest)
		total = secrets.length
	} else {
		let paged: Paged
		;[paged, folders, types] = await Promise.all([
			fetchAllSecrets(client),
			manifest?.folders ?? client.keepiq<FolderRow[]>('GET', '/api/v1/folders'),
			manifest?.types ?? client.keepiq<SecretTypeRow[]>('GET', '/api/v1/secret-types'),
		])
		;({ secrets, total } = paged)
	}
	const suite = manifest?.suite ?? activeRow(await listSuites())
	if (typeof suite.privateKey !== 'string' || suite.unlockBlocked) return { unlockBlocked: true }
	// The manifest never sends the blocked shape, so rows on another suite are checked here.
	if (secrets.some((row) => !row.blocked && row.encryptionSuiteId !== suite.id)) secrets = withheld(secrets, await listSuites())

	const now = new Date().toISOString()
	return {
		unlockBlocked: false,
		suite: { ...suite, privateKey: suite.privateKey },
		snapshot: {
			suite: { id: suite.id, unlockKeyEpoch: suite.unlockKeyEpoch },
			secrets,
			folders,
			types,
			// Client time, so staleness never depends on the server's clock.
			syncedAt: now,
			listsSyncedAt: now,
			newestUpdatedAt: newest(secrets),
			// The server's count, so a row lost to paging does not make every probe download again.
			total,
		},
	}
}

/** The server's block rule (`SecretService::suiteBlockReason`), for rows that arrived unblocked. */
function withheld(rows: StoredSecretRow[], suites: SuiteRow[]): StoredSecretRow[] {
	const statuses = new Map(suites.map((suite) => [suite.id, suite.status]))
	return rows.map((row) => {
		if (row.blocked) return row
		const status = statuses.get(row.encryptionSuiteId)
		let reason: string | null = null
		if (status === undefined) reason = 'Encryption suite not found'
		// The manifest leaves out `migrationError`, so a compromised row may also be one recovery could not save.
		else if (status === 'compromised') reason = 'Encrypted with a compromised key, so it cannot be opened here. The Keepiq web app shows whether it can be recovered.'
		else if (BLOCKING_STATUSES.includes(status)) reason = `Encryption suite is ${status}`
		if (reason === null) return row
		const meta: Partial<typeof row> = { ...row }
		delete meta.key
		delete meta.login
		delete meta.additionalFields
		return { ...(meta as Omit<typeof row, 'key' | 'login' | 'additionalFields' | 'blocked'>), blocked: true, blockedReason: reason }
	})
}

interface Paged {
	secrets: StoredSecretRow[]
	total: number
}

/**
 * The server's sort has no unique tiebreaker (keepiq#1234), so rows that tie can move between
 * pages. A row seen twice is kept once; a short count gets one more pass in another order.
 */
async function fetchAllSecrets(client: Client): Promise<Paged> {
	const first = new Map<string, StoredSecretRow>()
	const firstTotal = await pageThrough(client, 'sort=created_at&direction=asc', first)
	if (first.size >= firstTotal) return { secrets: [...first.values()], total: firstTotal }
	const second = new Map<string, StoredSecretRow>()
	const total = await pageThrough(client, 'sort=updated_at&direction=desc', second)
	// A complete second pass is the whole vault: a row only the first saw was deleted in between.
	const rows = second.size >= total ? second : new Map([...first, ...second])
	return { secrets: [...rows.values()], total }
}

async function pageThrough(client: Client, order: string, rows: Map<string, StoredSecretRow>): Promise<number> {
	for (let page = 1; ; page++) {
		const result = await client.keepiq<Page<StoredSecretRow>>('GET', `/api/v1/secrets?${order}&limit=${PAGE_LIMIT}&page=${page}`)
		for (const row of result.items) rows.set(row.id, row)
		if (page * PAGE_LIMIT >= result.total || result.items.length === 0) return result.total
	}
}

function newest(rows: StoredSecretRow[]): string | null {
	let best: StoredSecretRow | undefined
	for (const row of rows) if (!best || Date.parse(row.updatedAt) > Date.parse(best.updatedAt)) best = row
	return best?.updatedAt ?? null
}
