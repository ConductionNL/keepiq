/**
 * Vault sync for the extension: a snapshot of the vault per account, kept in
 * `storage.local` and replaced in one write, so the Vault tab and autofill
 * keep working while the server is unreachable. The snapshot holds what the
 * server already stores (ciphertext and plaintext metadata); nothing
 * decrypted is ever written.
 *
 * One sync runs per account at a time; a trigger during a running sync gets
 * that sync. A suite change discards the snapshot and locks the vault.
 *
 * @spec openspec/changes/clients-extension-complete/specs/extension-vault-sync/spec.md#requirement-keep-a-snapshot-of-the-vault
 */

/** Minutes between scheduled syncs while unlocked. */
export const SYNC_INTERVAL_MINUTES = 15

const SNAPSHOT_KEY = (id) => 'vault-snapshot:' + id

/**
 * Whether an error means the server could not be reached (or failed).
 *
 * @param {{status?: number}} error The failure.
 * @return {boolean}
 */
export function isOffline(error) {
	return !error?.status || error.status >= 500
}

/**
 * Build the sync for the worker.
 *
 * @param {object} deps The collaborators.
 * @param {object} deps.api The API client.
 * @param {object} deps.local The persistent storage area.
 * @param {(accountId: string) => string|null} deps.activeSuiteId The suite the vault is unlocked with.
 * @param {(accountId: string) => void} deps.lock Lock an account.
 * @param {() => number} [deps.now] The clock, in ms.
 * @return {object}
 */
export function buildVaultSync({
	api,
	local,
	activeSuiteId,
	activeSuiteEpoch = () => null,
	lock,
	now = () => Date.now(),
}) {
	const inFlight = new Map()
	const status = new Map()

	/**
	 * The sync status of an account.
	 *
	 * @param {string} id The account id.
	 * @return {{syncing: boolean, syncedAt: string|null, offline: boolean, lastError: string|null}}
	 */
	function statusOf(id) {
		return {
			syncing: false,
			syncedAt: null,
			offline: false,
			lastError: null,
			...status.get(id),
		}
	}

	/**
	 * The stored snapshot, or null.
	 *
	 * @param {string} id The account id.
	 * @return {Promise<object|null>}
	 */
	async function snapshotOf(id) {
		return (await local.get(SNAPSHOT_KEY(id)))[SNAPSHOT_KEY(id)] ?? null
	}

	/**
	 * Fetch the whole vault: the manifest, or page by page when the
	 * administrator switched offline caching off.
	 *
	 * @param {object} account The account.
	 * @return {Promise<{suite: object|null, secrets: Array<object>, folders: Array<object>, types: Array<object>}>}
	 */
	async function fetchVault(account) {
		try {
			const manifest = await api.fetchOfflineManifest(account)
			return {
				suite: manifest.suite ?? null,
				secrets: manifest.secrets ?? [],
				folders: manifest.folders ?? [],
				types: manifest.types ?? [],
			}
		} catch (e) {
			// Offline caching off: 403 on older servers, 428 since keepiq#673.
			if (e?.status !== 403 && e?.status !== 428 && e?.status !== 404) throw e
		}
		const [secrets, folders, types, suite] = await Promise.all([
			api.listSecrets(account),
			api.listFolders(account),
			api.listTypes(account),
			api.fetchActiveSuite(account),
		])
		return { suite: suite ?? null, secrets, folders, types }
	}

	/**
	 * Run one sync.
	 *
	 * @param {object} account The account.
	 * @param {boolean} force Skip the cheap check.
	 * @return {Promise<object>} The status after the sync.
	 */
	async function run(account, force) {
		const id = account.id
		status.set(id, { ...statusOf(id), syncing: true })
		try {
			const cached = await snapshotOf(id)
			if (!force && cached) {
				const latest = await api.latestSecret(account)
				const top = latest?.items?.[0]?.updatedAt ?? null
				const fresh =
					now() - Date.parse(cached.syncedAt)
					< SYNC_INTERVAL_MINUTES * 60_000
				if (
					top === cached.check?.top
					&& (latest?.total ?? 0) === cached.check?.total
					&& fresh
				) {
					const syncedAt = new Date(now()).toISOString()
					await local.set({ [SNAPSHOT_KEY(id)]: { ...cached, syncedAt } })
					status.set(id, {
						syncing: false,
						syncedAt,
						offline: false,
						lastError: null,
					})
					return statusOf(id)
				}
			}
			const vault = await fetchVault(account)
			// A new suite: the cached ciphertext and the key in memory belong to
			// the old one. Throw both away and ask for a fresh unlock.
			const unlockedWith = activeSuiteId(id)
			if (vault.suite?.id && unlockedWith && vault.suite.id !== unlockedWith) {
				await local.remove(SNAPSHOT_KEY(id))
				lock(id)
				status.set(id, {
					syncing: false,
					syncedAt: null,
					offline: false,
					lastError: 'suite-changed',
				})
				return statusOf(id)
			}
			// The same suite with a new unlock-key epoch: the master password
			// changed. The cached envelope is wrapped under the old one; drop
			// it and ask for the new password.
			const epochNow = vault.suite?.unlockKeyEpoch
			const epochThen = activeSuiteEpoch(id)
			if (
				Number.isInteger(epochNow)
				&& Number.isInteger(epochThen)
				&& epochNow !== epochThen
			) {
				await local.remove(SNAPSHOT_KEY(id))
				lock(id)
				status.set(id, {
					syncing: false,
					syncedAt: null,
					offline: false,
					lastError: 'master-password-changed',
				})
				return statusOf(id)
			}
			const sorted = [...vault.secrets].sort((a, b) =>
				String(b.updatedAt ?? '').localeCompare(String(a.updatedAt ?? '')),
			)
			const syncedAt = new Date(now()).toISOString()
			// One write: a reader never sees half a vault.
			await local.set({
				[SNAPSHOT_KEY(id)]: {
					suite: vault.suite,
					secrets: vault.secrets,
					folders: vault.folders,
					types: vault.types,
					syncedAt,
					check: {
						top: sorted[0]?.updatedAt ?? null,
						total: vault.secrets.length,
					},
				},
			})
			status.set(id, {
				syncing: false,
				syncedAt,
				offline: false,
				lastError: null,
			})
		} catch (e) {
			const prior = statusOf(id)
			if (e?.status === 401) {
				lock(id)
				status.set(id, { ...prior, syncing: false, lastError: 'auth' })
			} else {
				// Keep the snapshot; a later trigger tries again.
				status.set(id, {
					...prior,
					syncing: false,
					offline: isOffline(e),
					lastError:
						e?.status === 423
							? 'locked-for-migration'
							: e?.message || 'sync failed',
				})
			}
		}
		return statusOf(id)
	}

	return {
		/**
		 * Sync an account, reusing a sync that is already running.
		 *
		 * @param {object} account The account.
		 * @param {{force?: boolean}} [how] force: skip the cheap check.
		 * @return {Promise<object>} The status.
		 */
		sync(account, { force = false } = {}) {
			if (inFlight.has(account.id)) return inFlight.get(account.id)
			const running = run(account, force).finally(() =>
				inFlight.delete(account.id),
			)
			inFlight.set(account.id, running)
			return running
		},

		/**
		 * Whether the snapshot is older than the interval (or missing).
		 *
		 * @param {string} id The account id.
		 * @return {Promise<boolean>}
		 */
		async isStale(id) {
			const cached = await snapshotOf(id)
			return (
				!cached
				|| now() - Date.parse(cached.syncedAt)
					>= SYNC_INTERVAL_MINUTES * 60_000
			)
		},

		snapshotOf,
		statusOf,

		/**
		 * Forget an account's snapshot and status (logout, removal).
		 *
		 * @param {string} id The account id.
		 * @return {Promise<void>}
		 */
		async forget(id) {
			status.delete(id)
			await local.remove(SNAPSHOT_KEY(id))
		},
	}
}
