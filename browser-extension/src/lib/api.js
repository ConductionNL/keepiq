/**
 * Keepiq API client for the extension. Authenticates with the paired Nextcloud
 * app-password (HTTP Basic) — never a login password, never a new long-lived
 * Keepiq secret (browser-extension-autofill §"Pairing"). Every response is an
 * encrypted blob or plaintext index field; the server never returns a decrypted
 * value.
 *
 * Accounts ({ id, url, user, appPassword, label, idleMinutes }, up to five)
 * live in `storage.local`; each app-password is a device-scoped, NC-revocable
 * credential (extension-account-switching). No key material is stored here —
 * the master password and the derived CryptoKey never touch storage.
 */

import { isSecureServerUrl } from './server-url.js'

// The single pairing before several accounts (read once, then removed).
const LEGACY_CONFIG_KEY = 'keepiq.config'
const ACCOUNTS_KEY = 'keepiq.accounts'
const ACTIVE_KEY = 'keepiq.activeAccountId'

/** The most accounts one extension holds (extension-account-switching). */
export const MAX_ACCOUNTS = 5

/** The idle lock delays a user can pick, in minutes. */
export const IDLE_CHOICES = Object.freeze([1, 5, 15, 30, 60, 240])

/** The idle lock delay of a new account, in minutes. */
export const DEFAULT_IDLE_MINUTES = 15

function newAccountId() {
	return crypto.randomUUID()
}

/**
 * Move a pairing stored under the old single key into the account list, once.
 * An extension paired before several accounts keeps its pairing as the first
 * account and the old key is removed.
 *
 * @return {Promise<void>}
 */
export async function migrateLegacyConfig() {
	const data = await chrome.storage.local.get([LEGACY_CONFIG_KEY, ACCOUNTS_KEY])
	const legacy = data[LEGACY_CONFIG_KEY]
	if (!legacy) return
	const accounts = Array.isArray(data[ACCOUNTS_KEY]) ? data[ACCOUNTS_KEY] : []
	if (accounts.length === 0) {
		const account = {
			id: newAccountId(),
			url: legacy.url,
			user: legacy.user,
			appPassword: legacy.appPassword,
			label: '',
			idleMinutes: IDLE_CHOICES.includes(legacy.idleMinutes)
				? legacy.idleMinutes
				: DEFAULT_IDLE_MINUTES,
		}
		await chrome.storage.local.set({
			[ACCOUNTS_KEY]: [account],
			[ACTIVE_KEY]: account.id,
		})
	}
	await chrome.storage.local.remove(LEGACY_CONFIG_KEY)
}

/**
 * Every paired account, in pairing order.
 *
 * @return {Promise<Array<object>>} The accounts.
 */
export async function loadAccounts() {
	const data = await chrome.storage.local.get(ACCOUNTS_KEY)
	return Array.isArray(data[ACCOUNTS_KEY]) ? data[ACCOUNTS_KEY] : []
}

async function saveAccounts(accounts) {
	await chrome.storage.local.set({ [ACCOUNTS_KEY]: accounts })
}

/**
 * The id of the active account (the first one when none is set).
 *
 * @return {Promise<string|null>} The id, or null when nothing is paired.
 */
export async function activeAccountId() {
	const accounts = await loadAccounts()
	const data = await chrome.storage.local.get(ACTIVE_KEY)
	const id = data[ACTIVE_KEY]
	if (id && accounts.some((a) => a.id === id)) return id
	return accounts.length ? accounts[0].id : null
}

/**
 * One account by id.
 *
 * @param {string} id The account id.
 * @return {Promise<object|null>} The account, or null.
 */
export async function loadAccount(id) {
	return (await loadAccounts()).find((a) => a.id === id) || null
}

/**
 * The active account's config, or null when nothing is paired. Every API call
 * takes one account's config; matching and filling use the active one.
 *
 * @return {Promise<object|null>} The active account.
 */
export async function loadConfig() {
	const id = await activeAccountId()
	return id ? loadAccount(id) : null
}

/**
 * Add a paired account and make it active. Refuses a sixth account, and the
 * same user on the same server twice.
 *
 * @param {{url: string, user: string, appPassword: string, label?: string}} config The pairing.
 * @return {Promise<object>} The stored account.
 */
export async function addAccount(config) {
	const accounts = await loadAccounts()
	if (accounts.length >= MAX_ACCOUNTS) {
		throw new Error(
			'You can connect up to '
				+ MAX_ACCOUNTS
				+ ' accounts. Disconnect one first.',
		)
	}
	const sameServer = (a) =>
		String(a.url).replace(/\/+$/, '') === String(config.url).replace(/\/+$/, '')
		&& a.user === config.user
	if (accounts.some(sameServer)) {
		throw new Error('This account is already connected.')
	}
	const account = {
		id: newAccountId(),
		url: config.url,
		user: config.user,
		appPassword: config.appPassword,
		label: config.label || '',
		idleMinutes: DEFAULT_IDLE_MINUTES,
		serverVersion: config.serverVersion ?? null,
	}
	await saveAccounts([...accounts, account])
	await setActiveAccount(account.id)
	return account
}

/**
 * Change stored settings of one account: label, idle delay, server version,
 * and the app password and signed-out flag when a revoked password signs it
 * out or the user signs in again.
 *
 * @param {string} id The account id.
 * @param {{label?: string, idleMinutes?: number, serverVersion?: string|null, appPassword?: string, loggedOut?: boolean}} patch The changes.
 * @return {Promise<object>} The updated account.
 * @spec openspec/changes/clients-extension-gaps/specs/extension-pairing/spec.md#requirement-a-revoked-app-password-signs-the-account-out
 */
export async function updateAccount(id, patch) {
	const accounts = await loadAccounts()
	const account = accounts.find((a) => a.id === id)
	if (!account) throw new Error('unknown account')
	if (patch.idleMinutes !== undefined) {
		if (!IDLE_CHOICES.includes(patch.idleMinutes)) {
			throw new Error('unsupported idle delay')
		}
		account.idleMinutes = patch.idleMinutes
	}
	if (patch.label !== undefined) account.label = String(patch.label)
	if (patch.serverVersion !== undefined) {
		account.serverVersion = patch.serverVersion ?? null
	}
	// Signing out after a revoked app password, and signing in again.
	if (patch.appPassword !== undefined) {
		account.appPassword = String(patch.appPassword)
	}
	if (patch.loggedOut !== undefined) account.loggedOut = patch.loggedOut === true
	if (patch.loggedOutReason !== undefined) {
		account.loggedOutReason = String(patch.loggedOutReason)
	}
	await saveAccounts(accounts)
	return account
}

/**
 * Remove one account. The next remaining account becomes active.
 *
 * @param {string} id The account id.
 * @return {Promise<void>}
 */
export async function removeAccount(id) {
	const accounts = (await loadAccounts()).filter((a) => a.id !== id)
	await saveAccounts(accounts)
	const data = await chrome.storage.local.get(ACTIVE_KEY)
	if (data[ACTIVE_KEY] === id) {
		if (accounts.length) {
			await chrome.storage.local.set({ [ACTIVE_KEY]: accounts[0].id })
		} else {
			await chrome.storage.local.remove(ACTIVE_KEY)
		}
	}
}

/**
 * Make an account the active one.
 *
 * @param {string} id The account id.
 * @return {Promise<void>}
 */
export async function setActiveAccount(id) {
	if (!(await loadAccount(id))) throw new Error('unknown account')
	await chrome.storage.local.set({ [ACTIVE_KEY]: id })
}

function authHeader(config) {
	return 'Basic ' + btoa(`${config.user}:${config.appPassword}`)
}

function base(config) {
	return String(config.url).replace(/\/+$/, '')
}

// Called with the account when the server answers 401 to its app password.
const unauthorizedListeners = []

/**
 * Listen for a server refusing an account's app password (HTTP 401), which
 * means it was revoked or changed.
 *
 * @param {(config: object) => void} listener Called with the account.
 * @spec openspec/changes/clients-extension-gaps/specs/extension-pairing/spec.md#requirement-a-revoked-app-password-signs-the-account-out
 */
export function onUnauthorized(listener) {
	unauthorizedListeners.push(listener)
}

/**
 * Refuse to send an app password to a server address that is not https
 * (or http on a local host), such as one paired before that rule.
 *
 * @param {object} config The account.
 * @throws {Error} When the address is not secure.
 * @spec openspec/changes/clients-extension-gaps/specs/extension-pairing/spec.md#requirement-a-server-address-is-https-and-stored-clean
 */
function assertSecure(config) {
	if (!isSecureServerUrl(base(config))) {
		const err = new Error(
			'Keepiq needs an https address. Disconnect this account and connect it again over https.',
		)
		err.status = 0
		err.insecure = true
		throw err
	}
}

/**
 * Fetch from the server with the account's app password and no cookies, so
 * a Nextcloud browser session never rides along.
 *
 * @param {object} config The account.
 * @param {string} url The full address.
 * @param {object} init The fetch options.
 * @return {Promise<Response>}
 * @spec openspec/changes/clients-extension-gaps/specs/extension-pairing/spec.md#requirement-requests-carry-the-app-password-and-no-cookies
 */
function serverFetch(config, url, init = {}) {
	assertSecure(config)
	return fetch(url, { ...init, credentials: 'omit' })
}

async function request(config, method, path, body, extraHeaders = {}) {
	const res = await serverFetch(
		config,
		base(config) + '/index.php/apps/keepiq' + path,
		{
			method,
			headers: {
				...extraHeaders,
				Authorization: authHeader(config),
				'Content-Type': 'application/json',
				'OCS-APIRequest': 'true',
				Accept: 'application/json',
			},
			body: body ? JSON.stringify(body) : undefined,
		},
	)
	if (!res.ok) {
		const text = await res.text().catch(() => '')
		const err = new Error(`Keepiq ${method} ${path} failed (${res.status})`)
		err.status = res.status
		err.body = text
		// A stored account whose app password stopped working. A pairing
		// attempt has no id yet; its 401 is just a wrong password.
		if (res.status === 401 && config.id) {
			for (const listener of unauthorizedListeners) listener(config)
		}
		throw err
	}
	if (res.status === 204) return null
	return res.json()
}

/**
 * Confirm the app-password pairs; returns { ok, user, capabilities }.
 * @param config
 */
export function pair(config) {
	return request(config, 'POST', '/api/v1/extension/pair')
}

/**
 * Acknowledge unpairing to Keepiq.
 * @param config
 */
export function unpair(config) {
	return request(config, 'POST', '/api/v1/extension/unpair')
}

/**
 * Delete the app password this extension signs in with, through Nextcloud's
 * own endpoint for it (#748). Clearing local settings alone left the password
 * valid, so a copy of it kept working after Disconnect.
 *
 * Nextcloud refuses (403) when the credential is not an app password, which
 * leaves nothing to revoke.
 *
 * @param {object} config The paired config.
 * @return {Promise<boolean>} True when Nextcloud deleted the app password.
 * @spec openspec/specs/browser-extension-autofill/spec.md#requirement-pairing-against-the-nextcloud-session
 */
/**
 * The Nextcloud email address of the account's user, or '' when it has none
 * or the server does not answer. Seeds the plus-addressed username.
 *
 * @param {object} config The account.
 * @return {Promise<string>}
 * @spec openspec/changes/clients-extension-complete/specs/extension-generator/spec.md#requirement-username-generator
 */
export async function fetchAccountEmail(config) {
	const res = await serverFetch(
		config,
		base(config) + '/ocs/v2.php/cloud/user?format=json',
		{
			headers: {
				Authorization: authHeader(config),
				'OCS-APIRequest': 'true',
				Accept: 'application/json',
			},
		},
	)
	if (!res.ok) return ''
	const data = await res.json().catch(() => null)
	return typeof data?.ocs?.data?.email === 'string' ? data.ocs.data.email : ''
}

export async function revokeAppPassword(config) {
	const res = await serverFetch(
		config,
		base(config) + '/ocs/v2.php/core/apppassword',
		{
			method: 'DELETE',
			headers: {
				Authorization: authHeader(config),
				'OCS-APIRequest': 'true',
				Accept: 'application/json',
			},
		},
	)
	return res.ok
}

/**
 * Fetch the caller's active EncryptionSuite (private-key envelope + certificate).
 * @param config
 * @spec openspec/changes/admin-vault-policies/tasks.md#3.4
 */
export async function fetchActiveSuite(config) {
	const suites = await request(config, 'GET', '/api/v1/suites')
	const list = Array.isArray(suites) ? suites : suites.items || []
	const active = list.find((s) => s.status === 'active')
	if (!active) throw new Error('no active encryption suite')
	// The two-factor vault policy withholds the wrapped key
	// (admin-vault-policies D3): name the reason, never a decryption error.
	if (active.unlockBlocked) {
		const err = new Error(
			active.unlockBlocked === 'two_factor_required'
				? 'two_factor_required: your organisation requires two-factor login in Nextcloud before you can open your vault'
				: `vault unlock blocked: ${active.unlockBlocked}`,
		)
		err.code = active.unlockBlocked
		throw err
	}
	return active
}

/**
 * URL-match secrets for a host — returns blob rows (ciphertext key/login).
 * @param config
 * @param host
 */
export async function match(config, host) {
	const data = await request(
		config,
		'GET',
		'/api/v1/extension/match?host=' + encodeURIComponent(host),
	)
	return data.items || []
}

/**
 * Fetch one secret by id (blobs).
 * @param config
 * @param id
 */
export function getSecret(config, id) {
	return request(config, 'GET', '/api/v1/secrets/' + encodeURIComponent(id))
}

/**
 * Tell the server this secret was just filled, so the vault list can sort
 * by last used (vault-favourites-tags-and-last-used). Sends only the id.
 * @param config
 * @param id
 */
export function markUsed(config, id) {
	return request(
		config,
		'POST',
		'/api/v1/extension/used/' + encodeURIComponent(id),
	)
}

/**
 * Record a fill of a use-only copy for its owner's activity
 * (sharing-use-only-and-expiring-shares §3.3). Sends only the id.
 *
 * @param {object} config The paired config.
 * @param {string} id The copy that was filled.
 * @return {Promise<object>}
 */
export function reportUseOnlyFill(config, id) {
	return request(
		config,
		'POST',
		'/api/v1/secrets/' + encodeURIComponent(id) + '/used',
	)
}

/**
 * Create a secret from an already-encrypted body (blobs only).
 * @param config
 * @param body
 */
export function createSecret(config, body) {
	return request(config, 'POST', '/api/v1/secrets', body)
}

/**
 * Update a secret with an already-encrypted body (blobs only).
 * @param config
 * @param id
 * @param body
 */
export function updateSecret(config, id, body) {
	return request(config, 'PUT', '/api/v1/secrets/' + encodeURIComponent(id), body)
}

/**
 * The offline manifest: the active suite, every secret row (ciphertext and
 * plaintext metadata), the folders and the types, in one response.
 *
 * @param {object} config The account.
 * @return {Promise<object>}
 * @spec openspec/changes/clients-extension-complete/specs/extension-vault-sync/spec.md#requirement-keep-a-snapshot-of-the-vault
 */
export function fetchOfflineManifest(config) {
	return request(config, 'GET', '/api/v1/offline/manifest')
}

/**
 * The most recently updated secret and the total, for the cheap "did
 * anything change" check.
 *
 * @param {object} config The account.
 * @return {Promise<{items: Array<object>, total: number}>}
 * @spec openspec/changes/clients-extension-complete/specs/extension-vault-sync/spec.md#requirement-sync-when-it-matters-and-cheaply
 */
export function latestSecret(config) {
	return request(
		config,
		'GET',
		'/api/v1/secrets?sort=updated_at&direction=desc&limit=1',
	)
}

/** The largest page the secrets list serves (SecretService::MAX_LIMIT). */
export const SECRETS_PAGE_SIZE = 100

/** The most pages the page-by-page fallback reads before it gives up loudly. */
export const MAX_SECRET_PAGES = 1000

/**
 * Every secret the account can open, page by page (index fields and blobs).
 *
 * @param {object} config The account.
 * @return {Promise<Array<object>>}
 * @throws {Error} When the vault has more pages than the fallback reads.
 * @spec openspec/changes/clients-extension-generator-vault-send/specs/extension-vault/spec.md#requirement-browse-and-search-the-vault
 * @spec openspec/changes/clients-extension-gaps/specs/extension-clipboard/spec.md#requirement-a-large-vault-is-never-cut-off-in-silence
 */
export async function listSecrets(config) {
	const items = []
	for (let page = 1; page <= MAX_SECRET_PAGES; page++) {
		const data = await request(
			config,
			'GET',
			`/api/v1/secrets?page=${page}&limit=${SECRETS_PAGE_SIZE}`,
		)
		const batch = data?.items || []
		items.push(...batch)
		if (batch.length < SECRETS_PAGE_SIZE || items.length >= (data?.total ?? 0)) {
			return items
		}
	}
	// Never hand back part of a vault as if it were all of it.
	const err = new Error(
		`The vault has more than ${MAX_SECRET_PAGES * SECRETS_PAGE_SIZE} items, more than the extension can read page by page. Ask your administrator to switch on offline caching.`,
	)
	err.status = 413
	throw err
}

/**
 * The account's folders (names are plaintext on the server).
 *
 * @param {object} config The account.
 * @return {Promise<Array<object>>}
 * @spec openspec/changes/clients-extension-generator-vault-send/specs/extension-vault/spec.md#requirement-browse-and-search-the-vault
 */
export async function listFolders(config) {
	const data = await request(config, 'GET', '/api/v1/folders')
	return Array.isArray(data) ? data : data?.items || []
}

/**
 * Create a folder.
 *
 * @param {object} config The account.
 * @param {{name: string, parentId?: string|null}} body The folder.
 * @return {Promise<object>} The folder.
 * @spec openspec/changes/clients-extension-complete/specs/extension-vault/spec.md#requirement-manage-folders
 */
export function createFolder(config, body) {
	return request(config, 'POST', '/api/v1/folders', body)
}

/**
 * Rename a folder: only its name is sent.
 *
 * @param {object} config The account.
 * @param {string} id The folder id.
 * @param {string} name The new name.
 * @return {Promise<object>} The folder.
 * @spec openspec/changes/clients-extension-complete/specs/extension-vault/spec.md#requirement-manage-folders
 */
export function renameFolder(config, id, name) {
	return request(config, 'PUT', '/api/v1/folders/' + encodeURIComponent(id), {
		name,
	})
}

/**
 * A folder's direct item count and direct subfolders with their counts.
 *
 * @param {object} config The account.
 * @param {string} id The folder id.
 * @return {Promise<{directSecretCount: number, subfolders: Array<object>}>}
 * @spec openspec/changes/clients-extension-complete/specs/extension-vault/spec.md#requirement-manage-folders
 */
export function folderChildren(config, id) {
	return request(
		config,
		'GET',
		'/api/v1/folders/' + encodeURIComponent(id) + '/children',
	)
}

/**
 * Delete a folder: plain when empty, with a cascade for a leaf with items,
 * with a resolution plan when it has subfolders.
 *
 * @param {object} config The account.
 * @param {string} id The folder id.
 * @param {{cascade?: string, resolution?: object}} [how] From deleteRequest.
 * @return {Promise<object>}
 * @spec openspec/changes/clients-extension-complete/specs/extension-vault/spec.md#requirement-manage-folders
 */
export function deleteFolder(config, id, { cascade, resolution } = {}) {
	const query = cascade ? '?cascade=' + encodeURIComponent(cascade) : ''
	return request(
		config,
		'DELETE',
		'/api/v1/folders/' + encodeURIComponent(id) + query,
		resolution,
	)
}

/**
 * Move a secret to the trash (it can be restored from the web app).
 *
 * @param {object} config The account.
 * @param {string} id The secret id.
 * @return {Promise<object|null>}
 * @spec openspec/changes/clients-extension-generator-vault-send/specs/extension-vault/spec.md#requirement-add-edit-and-delete-items
 */
export function trashSecret(config, id) {
	return request(config, 'DELETE', '/api/v1/secrets/' + encodeURIComponent(id))
}

/**
 * Create an ephemeral send from an already-encrypted body.
 *
 * @param {object} config The account.
 * @param {object} body encryptedPayload, payloadType, maxViews, ttlSeconds, hasPassword.
 * @return {Promise<object>} The send, with its token.
 * @spec openspec/changes/clients-extension-generator-vault-send/specs/extension-send/spec.md#requirement-create-a-send-from-the-popup
 */
export function createSend(config, body) {
	return request(config, 'POST', '/api/v1/sends', body)
}

/**
 * The account's own sends (metadata only).
 *
 * @param {object} config The account.
 * @return {Promise<Array<object>>}
 * @spec openspec/changes/clients-extension-generator-vault-send/specs/extension-send/spec.md#requirement-list-and-end-my-sends
 */
export async function listSends(config) {
	const data = await request(config, 'GET', '/api/v1/sends')
	return Array.isArray(data) ? data : []
}

/**
 * End one of the account's sends.
 *
 * @param {object} config The account.
 * @param {string} id The send id.
 * @return {Promise<object|null>}
 * @spec openspec/changes/clients-extension-generator-vault-send/specs/extension-send/spec.md#requirement-list-and-end-my-sends
 */
export function revokeSend(config, id) {
	return request(config, 'DELETE', '/api/v1/sends/' + encodeURIComponent(id))
}

/**
 * The public base for recipient links on this account's server.
 *
 * @param {object} config The account.
 * @return {string}
 */
export function publicBase(config) {
	return base(config) + '/index.php/apps/keepiq/public'
}

/**
 * Read the org password policy, the same endpoint the web app reads
 * (keepiq#746). Resolves to null when it cannot be read: an unavailable
 * policy never blocks a save.
 * @param config
 */
export async function fetchPolicy(config) {
	try {
		return await request(config, 'GET', '/api/settings/policy')
	} catch {
		return null
	}
}

/**
 * Fetch the breach suffix list for a 5-character SHA-1 prefix through the
 * Keepiq proxy. Only the prefix leaves the browser, and it goes in the body,
 * never in the URL, which the server logs next to the user (keepiq#866).
 * @param config
 * @param prefix
 */
export async function breachRange(config, prefix) {
	const data = await request(config, 'POST', '/api/v1/breach-check/range', {
		prefix,
	})
	return data?.suffixes ?? ''
}

/**
 * Fetch the secret-type catalogue and return the id of a type by name/slug.
 * @param config
 * @param name
 */
/**
 * The secret types the account's server knows ({id, name}).
 *
 * @param {object} config The account.
 * @return {Promise<Array<object>>}
 */
export async function listTypes(config) {
	const types = await request(config, 'GET', '/api/v1/secret-types')
	return Array.isArray(types) ? types : types?.items || []
}

export async function typeIdByName(config, name) {
	const types = await request(config, 'GET', '/api/v1/secret-types')
	const list = Array.isArray(types) ? types : types.items || []
	const match = list.find((t) => t.name === name || t.slug === name)
	return match ? match.id : null
}

/**
 * The passkey type id (or null).
 * @param config
 */
export function passkeyTypeId(config) {
	return typeIdByName(config, 'passkey')
}

/**
 * The organisation's extension policy: `maxIdleMinutes`, the longest idle
 * lock delay a user may pick.
 * @param config
 */
export function extensionPolicy(config) {
	return request(config, 'GET', '/api/v1/extension/policy')
}

/**
 * The extension's own passkey unlock options (client `extension`, bound to
 * the extension's relying party id): PRF salts and wrapped unlock keys.
 * @param config
 * @param rpId
 */
export function passkeyLoginOptions(config, rpId) {
	return request(
		config,
		'GET',
		'/api/v1/passkeys/login-options?client=extension&rpId='
			+ encodeURIComponent(rpId),
	)
}

/**
 * A fresh WebAuthn challenge for an enrolment.
 * @param config
 */
export function passkeyChallenge(config) {
	return request(config, 'GET', '/api/v1/passkeys/challenge')
}

/**
 * Store an extension passkey: credential metadata, the PRF salt and the
 * PRF-wrapped unlock key, never the raw key or the PRF output.
 * @param config
 * @param body
 */
export function enrolPasskey(config, body) {
	return request(config, 'POST', '/api/v1/passkeys', {
		...body,
		clientKind: 'extension',
	})
}

/**
 * Stamp a passkey as just used.
 * @param config
 * @param id
 */
export function markPasskeyUsed(config, id) {
	return request(
		config,
		'POST',
		'/api/v1/passkeys/' + encodeURIComponent(id) + '/used',
	)
}

/** The header that carries a device approval request's one-time secret. */
export const REQUEST_SECRET_HEADER = 'X-Keepiq-Request-Secret'

/**
 * Whether an administrator left device approval on.
 *
 * @param {object} config The paired config.
 * @return {Promise<boolean>}
 * @spec openspec/changes/crypto-new-device-approval/specs/new-device-approval/spec.md#requirement-deny-expiry-audit-and-administrator-switch
 */
export async function deviceApprovalEnabled(config) {
	try {
		const res = await request(config, 'GET', '/api/v1/device-approvals/status')
		return res?.enabled === true
	} catch {
		return false
	}
}

/**
 * Ask to unlock this extension from another device.
 *
 * @param {object} config The paired config.
 * @param {{publicKey: string, deviceLabel: string}} body The one-time public key (base64) and a label.
 * @return {Promise<{id: string, requestSecret: string, expiresAt: string}>}
 * @spec openspec/changes/crypto-new-device-approval/specs/new-device-approval/spec.md#requirement-a-new-device-requests-approval-with-a-one-time-key
 */
export function createDeviceApproval(config, body) {
	return request(config, 'POST', '/api/v1/device-approvals', {
		publicKey: body.publicKey,
		clientKind: 'extension',
		deviceLabel: body.deviceLabel,
	})
}

/**
 * Pick up a device approval request with its one-time secret.
 *
 * @param {object} config The paired config.
 * @param {string} id The request id.
 * @param {string} secret The request secret the create call returned.
 * @return {Promise<{status: string, sealedUnlockKey?: string}>}
 * @spec openspec/changes/crypto-new-device-approval/specs/new-device-approval/spec.md#requirement-pickup-is-one-time-and-unlocks-one-session
 */
export function pickupDeviceApproval(config, id, secret) {
	return request(
		config,
		'GET',
		'/api/v1/device-approvals/' + encodeURIComponent(id),
		undefined,
		{ [REQUEST_SECRET_HEADER]: secret },
	)
}

/**
 * End a device approval request this extension no longer waits for.
 *
 * @param {object} config The paired config.
 * @param {string} id The request id.
 * @return {Promise<object>}
 * @spec openspec/changes/crypto-new-device-approval/specs/new-device-approval/spec.md#requirement-deny-expiry-audit-and-administrator-switch
 */
export function endDeviceApproval(config, id) {
	return request(
		config,
		'POST',
		'/api/v1/device-approvals/' + encodeURIComponent(id) + '/deny',
	)
}
