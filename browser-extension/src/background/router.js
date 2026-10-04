/**
 * The worker's message handlers (browser-extension-autofill §"Extension
 * architecture"). The service worker is the ONLY place vault keys live; the
 * popup, the unlock window and the content scripts are UIs that message it.
 *
 * Trust: a content script runs inside a web page, so a compromised page can
 * speak through it. Only the four messages a content script needs are accepted
 * from a tab (`PAGE_MESSAGES`); everything that unlocks, lists, fills, saves or
 * changes settings is accepted from the extension's own pages only.
 *
 * Accounts (extension-account-switching): up to five paired accounts, each with
 * its own key, idle timer and match cache. Matching, filling, the one-time
 * code and saving use the ACTIVE account, and a fill is refused unless the
 * secret id came from the active account's own last match for this site.
 */

import * as api from '../lib/api.js'
import * as vault from '../lib/vault.js'
import * as deviceApproval from '../lib/deviceApproval.js'
import { matchSecrets, hostOf, registrableDomain } from '../lib/match.js'
import { classifyCapture } from '../lib/capture.js'
import { policyRefusal } from '../lib/policy.js'
import { buildPasskeyOrchestrator } from '../passkey/orchestrator.js'
import { senderOrigin } from '../passkey/rp.js'
import { computeTotp } from '../lib/totp-service.js'
import { reportFill } from '../lib/usage.js'
import {
	allowedOnHost,
	blocksSavePrompt,
	filterForHost,
	isUseOnly,
} from '../lib/useOnly.js'
import { isServerSupported } from '../lib/version.js'
import { isSecureServerUrl, normalizeServerUrl } from '../lib/server-url.js'
import { buildPinUnlock } from '../lib/pin-unlock.js'
import { readSettings, writeSettings } from '../lib/extension-settings.js'
import {
	decodeEnvelope,
	decryptPrivateKeyWithRawKey,
	deriveUnlockKeyRaw,
} from '../crypto/index.js'
import { buildVaultHandlers } from './vault-handlers.js'
import { areaOrMemory, buildGeneratorHandlers } from './generator-handlers.js'
import { buildVaultSync, isOffline, SYNC_INTERVAL_MINUTES } from './vault-sync.js'
import {
	buildClipboardClear,
	CLEAR_CHOICES,
	clearClipboardNow,
} from './clipboard-clear.js'

/**
 * The messages a content script (a tab) may send. Everything else needs an
 * extension page as sender.
 */
export const PAGE_MESSAGES = Object.freeze(
	new Set([
		'capture-credential',
		'capture-decision',
		// A frame says it loaded; the worker records its host from the sender.
		'frame-ready',
		// A new page asks whether a save offer is still waiting for its tab.
		'capture-offer',
		'webauthn-create',
		'webauthn-get',
		'otp-field-detected',
		// A random password for a sign-up field; it carries no vault data.
		'generate-for-field',
		// Whether to offer those suggestions at all; no vault data either.
		'page-settings',
	]),
)

// chrome.storage.session key of a tab's frames, { frameId: host }, recorded
// from the browser's sender record when each frame's content script loads.
const FRAMES_KEY = (tabId) => 'frames:' + tabId

/**
 * Record a frame of a tab with the host the browser says it is on.
 *
 * @param {object} payload Unused: the page's claims are not read.
 * @param {object} sender The runtime.MessageSender of the content script.
 * @return {Promise<{ok: boolean}>}
 * @spec openspec/changes/clients-extension-gaps/specs/extension-fill-and-capture/spec.md#requirement-a-fill-reaches-only-frames-on-the-matched-site
 */
async function doFrameReady(payload, sender) {
	const tabId = sender?.tab?.id
	const frameId = sender?.frameId
	const host = hostOf(sender?.url || '')
	const store = sessionStore()
	if (!store || !Number.isInteger(tabId) || !Number.isInteger(frameId) || !host) {
		return { ok: false }
	}
	const key = FRAMES_KEY(tabId)
	const frames = (await store.get(key))[key] || {}
	// Frames report in any order; a new page clears the record when it
	// starts loading (below), never when its top frame reports.
	await store.set({ [key]: { ...frames, [frameId]: host } })
	return { ok: true }
}

// A tab starts loading a new page: its frames are gone.
chrome.tabs?.onUpdated?.addListener((tabId, info) => {
	if (info?.status !== 'loading') return
	sessionStore()
		?.remove(FRAMES_KEY(tabId))
		.catch(() => {})
})

/**
 * The frames of a tab that are on a host. Without a record (the worker
 * restarted), only the top frame, whose page the caller already checked.
 *
 * @param {number} tabId The tab.
 * @param {string} host The matched host.
 * @return {Promise<Array<number>>}
 * @spec openspec/changes/clients-extension-gaps/specs/extension-fill-and-capture/spec.md#requirement-a-fill-reaches-only-frames-on-the-matched-site
 */
async function framesOn(tabId, host) {
	const store = sessionStore()
	const key = FRAMES_KEY(tabId)
	const frames = store ? (await store.get(key))[key] : null
	if (!frames || Object.keys(frames).length === 0) return [0]
	return Object.entries(frames)
		.filter(([, h]) => h === host)
		.map(([id]) => Number(id))
}

/**
 * Send a message to the frames of a tab on a host, one by one, and report
 * whether any of them handled it.
 *
 * @param {number} tabId The tab.
 * @param {string} host The matched host.
 * @param {object} message The message.
 * @return {Promise<{filled: boolean}>}
 * @spec openspec/changes/clients-extension-gaps/specs/extension-fill-and-capture/spec.md#requirement-a-fill-reaches-only-frames-on-the-matched-site
 */
async function sendToFramesOn(tabId, host, message) {
	let filled = false
	for (const frameId of await framesOn(tabId, host)) {
		const res = await chrome.tabs
			.sendMessage(tabId, message, { frameId })
			.catch(() => null)
		if (res?.filled) filled = true
	}
	return { filled }
}

/** How long after a login fill the code may fill on the next step. */
export const OTP_INTENT_MS = 5 * 60 * 1000

// chrome.storage.session key of the pending code intents, by tab id. Session
// storage is held in memory by the browser and is not readable by content
// scripts. An intent holds no seed and no code (extension-totp-autofill).
const OTP_INTENTS_KEY = 'keepiq.otpIntents'

function sessionStore() {
	return chrome.storage && chrome.storage.session ? chrome.storage.session : null
}

/**
 * The page tab the popup acts on: the one a popped-out popup was opened over
 * (by id), else the active tab of the current window.
 *
 * @param {number|undefined} tabId The pinned tab id, if any.
 * @return {Promise<object|null>}
 */
async function targetTab(tabId) {
	if (Number.isInteger(tabId)) {
		return chrome.tabs.get(tabId).catch(() => null)
	}
	const [tab] = await chrome.tabs.query({ active: true, currentWindow: true })
	return tab || null
}

// The Generator tab's state (clients-extension-complete), built on first use
// so it binds to the storage areas the browser provides at that time.
let generatorState = null

// Clearing the clipboard after a copy (clients-extension-gaps), built on first
// use like the generator state.
let clipboardState = null

/**
 * The clipboard clearer.
 *
 * @return {object}
 * @spec openspec/changes/clients-extension-gaps/specs/extension-clipboard/spec.md#requirement-every-copy-is-cleared-after-a-delay-the-user-sets
 */
function clipboardModule() {
	if (!clipboardState) {
		clipboardState = buildClipboardClear({
			local: areaOrMemory(chrome.storage?.local),
			clear: clearClipboardNow,
		})
	}
	return clipboardState
}

function generatorModule() {
	if (!generatorState) {
		generatorState = buildGeneratorHandlers({
			api,
			activeAccount,
			activeHost: async (payload) => {
				const tab = await targetTab(payload?.tabId)
				try {
					const url = new URL(tab?.url || '')
					return url.protocol === 'http:' || url.protocol === 'https:'
						? url.hostname
						: ''
				} catch {
					return ''
				}
			},
			local: chrome.storage.local,
			session: areaOrMemory(sessionStore()),
		})
	}
	return generatorState
}

// The vault snapshot and its sync (clients-extension-complete), built on
// first use, like the generator state.
let syncState = null

function syncModule() {
	if (!syncState) {
		syncState = buildVaultSync({
			api,
			local: chrome.storage.local,
			activeSuiteId: (id) => vault.activeSuiteId(id),
			activeSuiteEpoch: (id) => vault.activeSuiteEpoch(id),
			lock: (id) => lockAccount(id),
		})
	}
	return syncState
}

const SYNC_ALARM = (id) => 'keepiq-sync:' + id

/**
 * After an unlock: sync now, then every SYNC_INTERVAL_MINUTES while unlocked.
 *
 * @param {object} account The account.
 * @return {void}
 */
function startSync(account) {
	syncModule()
		.sync(account, { force: true })
		.catch(() => {})
	chrome.alarms?.create(SYNC_ALARM(account.id), {
		periodInMinutes: SYNC_INTERVAL_MINUTES,
	})
}

/**
 * A scheduled sync fired: sync that account if it is still unlocked.
 *
 * @param {{name: string}} alarm The alarm.
 * @return {Promise<void>}
 */
export async function onAlarm(alarm) {
	if (await clipboardModule().onAlarm(alarm)) return
	if (!alarm?.name?.startsWith('keepiq-sync:')) return
	const id = alarm.name.slice('keepiq-sync:'.length)
	const account = await api.loadAccount(id)
	if (account && vault.isUnlocked(id)) {
		await syncModule()
			.sync(account)
			.catch(() => {})
	}
}

// No sync runs while locked: the alarm goes with the key. The popup's last
// tab is forgotten too, so a locked popup reopens on its first tab.
vault.onLock((accountId) => {
	chrome.alarms?.clear(SYNC_ALARM(accountId))
	// Tell an open popup, so it drops what it shows of the vault at once.
	try {
		chrome.runtime
			.sendMessage({ type: 'keepiq-locked', accountId })
			?.catch?.(() => {})
	} catch {
		// No page is listening.
	}
	sessionStore()
		?.remove('popup:lastTab')
		.catch(() => {})
})

// Generator history goes whenever an account locks, for any reason.
vault.onLock((accountId) => {
	generatorModule()
		.clearHistory(accountId)
		.catch(() => {})
})

async function readIntents() {
	const store = sessionStore()
	if (!store) return {}
	const data = await store.get(OTP_INTENTS_KEY)
	return data[OTP_INTENTS_KEY] || {}
}

async function writeIntents(intents) {
	const store = sessionStore()
	if (!store) return
	if (Object.keys(intents).length === 0) {
		await store.remove(OTP_INTENTS_KEY)
	} else {
		await store.set({ [OTP_INTENTS_KEY]: intents })
	}
}

/**
 * Drop every pending code intent (lock, OS lock, unpair).
 *
 * @return {Promise<void>}
 */
export async function clearOtpIntents() {
	await writeIntents({})
}

/**
 * The idle cap used when the organisation's maximum could not be read at
 * unlock: the default delay, so an unreachable policy never lengthens it.
 */
const FALLBACK_MAX_IDLE_MINUTES = api.DEFAULT_IDLE_MINUTES

// accountId → the administrator maximum read at that account's last unlock.
const maxIdleByAccount = new Map()

// accountId → { host, rows: Map(id → blob row) } from that account's last match.
// Cleared on lock.
const matchCache = new Map()

// Submitted logins waiting for a save or update decision, one per tab. Each is
// bound to the account active at submit and expires after CAPTURE_TTL_MS
// (clients-extension-gaps).
const captures = new Map()

// Sites the user never wants a save offer on (clients-extension-gaps).
const NEVER_KEY = 'capture-never'

/**
 * The sites with no save offer.
 *
 * @return {Promise<Array<string>>}
 * @spec openspec/changes/clients-extension-gaps/specs/extension-autofill-extras/spec.md#requirement-never-offer-to-save-on-a-site
 */
async function neverSites() {
	const area = chrome.storage?.local
	if (!area) return []
	const list = (await area.get(NEVER_KEY))[NEVER_KEY]
	return Array.isArray(list) ? list : []
}

/**
 * Add or remove a site from the no-save list.
 *
 * @param {string} host The site.
 * @param {boolean} on Add (true) or remove (false).
 * @return {Promise<Array<string>>} The list.
 * @spec openspec/changes/clients-extension-gaps/specs/extension-autofill-extras/spec.md#requirement-never-offer-to-save-on-a-site
 */
async function setNever(host, on) {
	const list = (await neverSites()).filter((h) => h !== host)
	if (on && host) list.push(host)
	list.sort()
	await chrome.storage.local.set({ [NEVER_KEY]: list })
	return list
}

/** How long a submitted login waits for a decision. */
export const CAPTURE_TTL_MS = 5 * 60 * 1000

/**
 * The live capture of a tab, or null. An expired one is dropped.
 *
 * @param {number|undefined} tabId The tab.
 * @return {object|null}
 * @spec openspec/changes/clients-extension-gaps/specs/extension-fill-and-capture/spec.md#requirement-the-save-prompt-trusts-the-browser-not-the-page
 */
function captureOf(tabId) {
	const capture = captures.get(tabId)
	if (!capture) return null
	if (capture.expiresAt <= Date.now()) {
		captures.delete(tabId)
		return null
	}
	return capture
}

chrome.tabs?.onRemoved?.addListener((tabId) => {
	captures.delete(tabId)
	sessionStore()
		?.remove(FRAMES_KEY(tabId))
		.catch(() => {})
})

// Passkey provider: the orchestrator works on the active account.
const passkey = buildPasskeyOrchestrator({
	api,
	vault: vault.boundTo(api.activeAccountId),
	loadConfig: api.loadConfig,
})

/**
 * The relying party id of the extension's own passkey: the host part of the
 * extension origin (the extension id on Chromium, the install uuid on Firefox).
 *
 * @return {string}
 */
export function extensionRpId() {
	return new URL(chrome.runtime.getURL('')).hostname
}

/**
 * Whether a message comes from one of the extension's own pages (popup,
 * unlock window, popped-out popup), not from a content script in a tab.
 *
 * A page in its own window is a tab too. It counts only as that tab's top
 * frame: the extension's pages are not web-accessible, so a web page can
 * neither frame them nor navigate to them.
 *
 * @param {object|undefined} sender The runtime.MessageSender.
 * @return {boolean}
 */
export function fromExtensionPage(sender) {
	if (!sender || sender.id !== chrome.runtime.id) return false
	const base = chrome.runtime.getURL('')
	if (typeof sender.url !== 'string' || !sender.url.startsWith(base)) {
		return false
	}
	return !sender.tab || sender.frameId === 0
}

/**
 * The idle delay in force for an account: its own choice, capped by the
 * organisation's maximum read at its last unlock.
 *
 * @param {object} account The stored account.
 * @return {number} Minutes.
 */
export function effectiveIdleMinutes(account) {
	const chosen = api.IDLE_CHOICES.includes(account?.idleMinutes)
		? account.idleMinutes
		: api.DEFAULT_IDLE_MINUTES
	const max = maxIdleByAccount.get(account?.id) ?? FALLBACK_MAX_IDLE_MINUTES
	return Math.min(chosen, max)
}

async function touchActivity(accountId) {
	const account = await api.loadAccount(accountId)
	if (!account) return
	vault.armIdleLock(accountId, effectiveIdleMinutes(account) * 60 * 1000)
}

function lockAccount(accountId) {
	vault.lock(accountId)
	matchCache.delete(accountId)
	clearOtpIntents().catch(() => {})
	for (const [tabId, capture] of captures) {
		if (capture.accountId === accountId) captures.delete(tabId)
	}
}

// PIN unlock (clients-extension-gaps), on session storage, built on first use.
let pinState = null

/**
 * The PIN store.
 *
 * @return {object}
 * @spec openspec/changes/clients-extension-gaps/specs/extension-pin-unlock/spec.md#requirement-unlock-with-a-pin-until-the-browser-closes
 */
function pinModule() {
	if (!pinState) pinState = buildPinUnlock(areaOrMemory(sessionStore()))
	return pinState
}

/**
 * The account's active suite: from the server, or from the vault snapshot
 * when the server cannot be reached.
 *
 * @param {object} account The account.
 * @return {Promise<object>}
 * @spec openspec/changes/clients-extension-gaps/specs/extension-pin-unlock/spec.md#requirement-unlock-with-a-pin-until-the-browser-closes
 */
async function suiteFor(account) {
	try {
		return await api.fetchActiveSuite(account)
	} catch (e) {
		const suite = isOffline(e)
			? (await syncModule().snapshotOf(account.id))?.suite
			: null
		if (!suite) throw e
		return suite
	}
}

/**
 * Set a PIN for the unlocked account on screen. The master password proves
 * it is the user and yields the unlock key the PIN then wraps.
 *
 * @param {{masterPassword: string, pin: string}} payload The master password and the new PIN.
 * @return {Promise<{ok: boolean}>}
 * @spec openspec/changes/clients-extension-gaps/specs/extension-pin-unlock/spec.md#requirement-unlock-with-a-pin-until-the-browser-closes
 */
async function doPinSet(payload) {
	const account = await activeAccount()
	if (!vault.isUnlocked(account.id)) throw new Error('vault is locked')
	const suite = await suiteFor(account)
	const { salt } = decodeEnvelope(suite.privateKey)
	const rawKey = await deriveUnlockKeyRaw(
		String(payload.masterPassword ?? ''),
		salt,
	)
	try {
		await decryptPrivateKeyWithRawKey(suite.privateKey, rawKey)
	} catch {
		rawKey.fill(0)
		throw new Error('Invalid master password')
	}
	try {
		await pinModule().set(account.id, rawKey, payload.pin)
	} finally {
		rawKey.fill(0)
	}
	return { ok: true }
}

/**
 * Unlock the account on screen with its PIN.
 *
 * @param {{pin: string}} payload The PIN.
 * @return {Promise<{ok: boolean}>}
 * @spec openspec/changes/clients-extension-gaps/specs/extension-pin-unlock/spec.md#requirement-unlock-with-a-pin-until-the-browser-closes
 */
async function doPinUnlock(payload) {
	const account = await activeAccount()
	if (account.loggedOut) {
		throw new Error(
			'This account is signed out. Sign in again with a new app password.',
		)
	}
	const rawKey = await pinModule().open(account.id, payload.pin)
	try {
		const suite = await suiteFor(account)
		await vault.unlockWithRawKey(account.id, account, rawKey, { suite })
	} catch (e) {
		if (e?.name === 'OperationError') {
			// The master password changed: the wrapped key opens nothing now.
			await pinModule().remove(account.id)
			throw new Error(
				'Your master password changed. Unlock with it, then set the PIN again.',
			)
		}
		throw e
	} finally {
		rawKey.fill(0)
	}
	await refreshPolicy(account)
	await touchActivity(account.id)
	startSync(account)
	return { ok: true }
}

// Accounts being signed out right now, so parallel 401s do it once.
const signingOut = new Set()

/**
 * Sign an account out after the server refused its app password (401): the
 * password was revoked or changed in Nextcloud. Lock it, forget the password,
 * the vault snapshot and the generator state, and keep the account so the
 * user can sign in again with a new app password.
 *
 * @param {string} accountId The account.
 * @param {string} [reason] revoked (the server refused it) or logout (the user's choice).
 * @return {Promise<void>}
 * @spec openspec/changes/clients-extension-gaps/specs/extension-pairing/spec.md#requirement-a-revoked-app-password-signs-the-account-out
 * @spec openspec/changes/clients-extension-gaps/specs/extension-pin-unlock/spec.md#requirement-unlock-with-a-pin-until-the-browser-closes
 */
export async function signOutAccount(accountId, reason = 'revoked') {
	if (!accountId || signingOut.has(accountId)) return
	signingOut.add(accountId)
	try {
		lockAccount(accountId)
		await api
			.updateAccount(accountId, {
				appPassword: '',
				loggedOut: true,
				loggedOutReason: reason,
			})
			.catch(() => {})
		await syncModule()
			.forget(accountId)
			.catch(() => {})
		await generatorModule()
			.forget(accountId)
			.catch(() => {})
		await pinModule()
			.remove(accountId)
			.catch(() => {})
	} finally {
		signingOut.delete(accountId)
	}
}

api.onUnauthorized((config) => {
	signOutAccount(config.id)
})

/** Lock every account and drop every cache (OS lock, Lock button). */
export function lockEverything() {
	vault.lockAll()
	matchCache.clear()
	clearOtpIntents().catch(() => {})
}

async function activeAccount() {
	const config = await api.loadConfig()
	if (!config) throw new Error('not paired')
	return config
}

function hostLabel(account) {
	try {
		return new URL(account.url).host
	} catch {
		return String(account.url || '')
	}
}

/**
 * Read and store the server version of an account (the pair route reports
 * it). A server that cannot be reached keeps the last known version.
 *
 * @param {object} account The account.
 * @return {Promise<string|null>} The version now stored.
 */
async function refreshServerVersion(account) {
	try {
		const res = await api.pair(account)
		const version = res?.serverVersion ?? null
		await api.updateAccount(account.id, { serverVersion: version })
		account.serverVersion = version
	} catch {
		// Offline or unreachable: keep what we had.
	}
	return account.serverVersion ?? null
}

/**
 * Current state for the popup to render the right view.
 *
 * @return {Promise<object>}
 * @spec openspec/changes/clients-extension-gaps/specs/extension-pairing/spec.md#requirement-a-revoked-app-password-signs-the-account-out
 * @spec openspec/changes/clients-extension-gaps/specs/extension-pairing/spec.md#requirement-a-server-address-is-https-and-stored-clean
 * @spec openspec/changes/clients-extension-gaps/specs/extension-unlock-and-accounts/spec.md#requirement-lock-and-log-out-per-account-or-all
 * @spec openspec/changes/clients-extension-gaps/specs/extension-pin-unlock/spec.md#requirement-unlock-with-a-pin-until-the-browser-closes
 */
async function getState() {
	const accounts = await api.loadAccounts()
	const activeId = await api.activeAccountId()
	const active = accounts.find((a) => a.id === activeId) || null
	const loggedOut = active?.loggedOut === true
	const insecure = active ? !isSecureServerUrl(active.url) : false
	// An account paired before the handshake has no version yet: ask once.
	if (active && active.serverVersion === undefined && !loggedOut && !insecure) {
		await refreshServerVersion(active)
	}
	return {
		paired: accounts.length > 0,
		maxAccounts: api.MAX_ACCOUNTS,
		activeAccountId: activeId,
		accounts: accounts.map((a) => ({
			id: a.id,
			user: a.user,
			host: hostLabel(a),
			label: a.label || '',
			unlocked: vault.isUnlocked(a.id),
			idleMinutes: a.idleMinutes,
			loggedOut: a.loggedOut === true,
		})),
		loggedOut,
		loggedOutReason: loggedOut ? active.loggedOutReason || 'revoked' : null,
		pinSet: active ? await pinModule().has(active.id) : false,
		insecure,
		unlocked: active ? vault.isUnlocked(active.id) : false,
		user: active ? active.user : null,
		url: active ? active.url : null,
		idleMinutes: active ? active.idleMinutes : null,
		maxIdleMinutes: active ? (maxIdleByAccount.get(active.id) ?? null) : null,
		idleChoices: api.IDLE_CHOICES,
		serverVersion: active ? (active.serverVersion ?? null) : null,
		serverOutdated: active ? !isServerSupported(active.serverVersion) : false,
	}
}

/**
 * Pair an account: clean the address, verify the app password, store it.
 *
 * @param {{url: string, user: string, appPassword: string}} payload The pairing form.
 * @return {Promise<{ok: boolean, accountId: string}>}
 * @spec openspec/changes/clients-extension-gaps/specs/extension-pairing/spec.md#requirement-a-server-address-is-https-and-stored-clean
 * @spec openspec/changes/clients-extension-gaps/specs/extension-unlock-and-accounts/spec.md#requirement-unlock-offline-and-say-what-went-wrong
 */
async function doPair(payload) {
	if ((await api.loadAccounts()).length >= api.MAX_ACCOUNTS) {
		throw new Error(
			'You can connect up to '
				+ api.MAX_ACCOUNTS
				+ ' accounts. Disconnect one first.',
		)
	}
	const config = {
		url: normalizeServerUrl(payload.url),
		user: payload.user,
		appPassword: payload.appPassword,
	}
	// Verify the credential actually pairs before persisting it.
	const res = await api.pair(config).catch((e) => {
		throw new Error(pairingProblem(e))
	})
	const account = await api.addAccount({
		...config,
		serverVersion: res?.serverVersion ?? null,
	})
	return { ok: true, accountId: account.id }
}

/**
 * Disconnect an account and delete its app password in Nextcloud.
 *
 * @param {{accountId?: string}} payload The account, or the active one.
 * @return {Promise<{ok: boolean, revoked: boolean}>}
 * @spec openspec/changes/clients-extension-gaps/specs/extension-pairing/spec.md#requirement-a-revoked-app-password-signs-the-account-out
 * @spec openspec/changes/clients-extension-gaps/specs/extension-pin-unlock/spec.md#requirement-unlock-with-a-pin-until-the-browser-closes
 */
async function doUnpair(payload) {
	const id = payload.accountId || (await api.activeAccountId())
	const account = id ? await api.loadAccount(id) : null
	if (!account) return { ok: true }
	try {
		await api.unpair(account)
	} catch {
		// Best-effort acknowledgement.
	}
	let revoked = false
	try {
		// Delete the app password itself, so Disconnect really ends the
		// pairing (#748). The local state is cleared either way. A signed-out
		// account has no password left to delete.
		revoked = account.loggedOut ? false : await api.revokeAppPassword(account)
	} catch {
		revoked = false
	}
	lockAccount(id)
	deviceApproval.forget(id)
	maxIdleByAccount.delete(id)
	await generatorModule()
		.forget(id)
		.catch(() => {})
	await syncModule()
		.forget(id)
		.catch(() => {})
	await pinModule()
		.remove(id)
		.catch(() => {})
	await api.removeAccount(id)
	return { ok: true, revoked }
}

/**
 * What went wrong when pairing, in words the user can act on.
 *
 * @param {{status?: number, insecure?: boolean, message?: string}} error The failure.
 * @return {string}
 * @spec openspec/changes/clients-extension-gaps/specs/extension-unlock-and-accounts/spec.md#requirement-unlock-offline-and-say-what-went-wrong
 */
export function pairingProblem(error) {
	if (error?.insecure) return error.message
	const status = error?.status
	if (!status) return 'Cannot reach this server. Check the address.'
	if (status === 401) {
		return 'Nextcloud did not accept this user name and app password.'
	}
	if (status === 403) return 'This Nextcloud account may not use Keepiq.'
	if (status === 404) {
		return 'Keepiq is not installed on this server, or the address is wrong.'
	}
	if (status >= 500) return 'The server could not answer. Try again later.'
	return 'Connecting failed (' + status + ').'
}

/**
 * Log out of accounts on purpose: delete each app password in Nextcloud and
 * sign the account out here, keeping its address and user.
 *
 * @param {{accountId?: string, all?: boolean}} payload One account, or all.
 * @return {Promise<{ok: boolean}>}
 * @spec openspec/changes/clients-extension-gaps/specs/extension-unlock-and-accounts/spec.md#requirement-lock-and-log-out-per-account-or-all
 */
async function doLogout(payload) {
	const ids = payload.all
		? (await api.loadAccounts()).map((a) => a.id)
		: [payload.accountId || (await api.activeAccountId())]
	for (const id of ids) {
		const account = id ? await api.loadAccount(id) : null
		if (!account || account.loggedOut) continue
		try {
			await api.revokeAppPassword(account)
		} catch {
			// Offline: the password is still forgotten here.
		}
		await signOutAccount(id, 'logout')
	}
	return { ok: true }
}

/**
 * Sign a signed-out account in again with a new app password. The address
 * and user stay; the password is verified before it is stored.
 *
 * @param {{accountId: string, appPassword: string}} payload The account and its new app password.
 * @return {Promise<{ok: boolean}>}
 * @spec openspec/changes/clients-extension-gaps/specs/extension-pairing/spec.md#requirement-a-revoked-app-password-signs-the-account-out
 */
async function doRelogin(payload) {
	const account = await api.loadAccount(payload.accountId)
	if (!account) throw new Error('This account is no longer connected')
	const appPassword = String(payload.appPassword ?? '').trim()
	if (appPassword === '') throw new Error('Enter a new app password')
	const config = { url: account.url, user: account.user, appPassword }
	const res = await api.pair(config)
	await api.updateAccount(account.id, {
		appPassword,
		loggedOut: false,
		serverVersion: res?.serverVersion ?? account.serverVersion ?? null,
	})
	return { ok: true }
}

async function doSwitchAccount(payload) {
	await api.setActiveAccount(payload.accountId)
	return { ok: true }
}

async function doSetIdle(payload) {
	const id = payload.accountId || (await api.activeAccountId())
	const account = await api.updateAccount(id, {
		idleMinutes: Number(payload.idleMinutes),
	})
	if (vault.isUnlocked(id)) await touchActivity(id)
	return { ok: true, effectiveIdleMinutes: effectiveIdleMinutes(account) }
}

/**
 * Read the organisation's idle maximum for an account at unlock. A policy that
 * cannot be read caps the delay at the default instead of lifting it.
 *
 * @param {object} account The account.
 * @return {Promise<void>}
 */
async function refreshPolicy(account) {
	let max = FALLBACK_MAX_IDLE_MINUTES
	try {
		const policy = await api.extensionPolicy(account)
		const value = Number(policy?.maxIdleMinutes)
		if (api.IDLE_CHOICES.includes(value)) max = value
	} catch {
		// Keep the fallback.
	}
	maxIdleByAccount.set(account.id, max)
}

/**
 * Unlock the active account with its master password. A signed-out account
 * cannot unlock.
 *
 * @param {{masterPassword: string}} payload The master password.
 * @return {Promise<{ok: boolean}>}
 * @spec openspec/changes/clients-extension-gaps/specs/extension-pairing/spec.md#requirement-a-revoked-app-password-signs-the-account-out
 * @spec openspec/changes/clients-extension-gaps/specs/extension-unlock-and-accounts/spec.md#requirement-unlock-offline-and-say-what-went-wrong
 */
async function doUnlock(payload) {
	const account = await activeAccount()
	if (account.loggedOut) {
		throw new Error(
			'This account is signed out. Sign in again with a new app password.',
		)
	}
	await refreshServerVersion(account)
	let offline = false
	try {
		await vault.unlock(account.id, account, payload.masterPassword)
	} catch (e) {
		// No server: unlock with the suite in the vault snapshot, if any.
		const suite = isOffline(e)
			? (await syncModule().snapshotOf(account.id))?.suite
			: null
		if (!suite) throw e
		await vault.unlock(account.id, account, payload.masterPassword, { suite })
		offline = true
	}
	await refreshPolicy(account)
	await touchActivity(account.id)
	startSync(account)
	return { ok: true, offline }
}

/**
 * Unlock one account with a raw unlock key the unlock window unwrapped from a
 * passkey (extension-biometric-unlock). The key is used once and not kept.
 *
 * @param {{accountId: string, rawKey: number[]}} payload The account and key bytes.
 * @return {Promise<{ok: boolean}>}
 * @spec openspec/changes/clients-extension-gaps/specs/extension-pairing/spec.md#requirement-a-revoked-app-password-signs-the-account-out
 */
async function doUnlockRaw(payload) {
	const account = await api.loadAccount(payload.accountId)
	if (!account) throw new Error('unknown account')
	if (account.loggedOut) {
		throw new Error(
			'This account is signed out. Sign in again with a new app password.',
		)
	}
	const bytes = Array.isArray(payload.rawKey) ? payload.rawKey : []
	if (bytes.length !== 32) throw new Error('invalid unlock key')
	const rawKey = Uint8Array.from(bytes)
	try {
		await vault.unlockWithRawKey(account.id, account, rawKey)
	} finally {
		rawKey.fill(0)
	}
	await refreshPolicy(account)
	await touchActivity(account.id)
	startSync(account)
	return { ok: true }
}

/**
 * Whether device approval is on, and the active account's open request.
 *
 * @return {Promise<{enabled: boolean, request: object|null}>}
 * @spec openspec/specs/new-device-approval/spec.md#requirement-a-new-device-requests-approval-with-a-one-time-key
 */
async function doDeviceApprovalState() {
	const account = await activeAccount()
	return {
		enabled: await api.deviceApprovalEnabled(account),
		request: deviceApproval.current(account.id),
	}
}

/**
 * Start "Approve from another device" for the active account.
 *
 * @return {Promise<object>} The request: id, phrase, expiry and status.
 * @spec openspec/specs/new-device-approval/spec.md#requirement-a-new-device-requests-approval-with-a-one-time-key
 */
async function doDeviceApprovalStart() {
	const account = await activeAccount()
	const agent = globalThis.navigator?.userAgent ?? ''
	return { request: await deviceApproval.start(account, agent) }
}

/**
 * Poll the active account's request once; an approval unlocks the account
 * through the same raw-key unlock as a passkey.
 *
 * @return {Promise<{status: string}>}
 * @spec openspec/specs/new-device-approval/spec.md#requirement-pickup-is-one-time-and-unlocks-one-session
 */
async function doDeviceApprovalPoll() {
	const account = await activeAccount()
	const status = await deviceApproval.poll(account, async (rawKey) => {
		await vault.unlockWithRawKey(account.id, account, rawKey)
		await refreshPolicy(account)
		await touchActivity(account.id)
	})
	return { status }
}

/**
 * Stop waiting for an approval.
 *
 * @return {Promise<{ok: boolean}>}
 * @spec openspec/specs/new-device-approval/spec.md#requirement-deny-expiry-audit-and-administrator-switch
 */
async function doDeviceApprovalCancel() {
	await deviceApproval.cancel(await activeAccount())
	return { ok: true }
}

/**
 * Candidate list for a host — metadata only (id/name/url). No decryption
 * happens here; a locked-but-paired extension can still list names/urls.
 * A blocked row is never offered.
 *
 * @param {{host: string}} payload The site.
 * @return {Promise<Array<object>>}
 * @spec openspec/changes/clients-extension-gaps/specs/extension-fill-and-capture/spec.md#requirement-blocked-items-are-never-offered
 */
async function doMatch(payload) {
	const account = await activeAccount()
	if (!isServerSupported(account.serverVersion)) {
		throw new Error(
			'Update Keepiq on your server to use this extension version.',
		)
	}
	const host = hostOf(payload.host)
	let rows
	try {
		rows = await api.match(account, payload.host)
	} catch (e) {
		// Offline: offer logins from the vault snapshot instead.
		const snapshot = isOffline(e)
			? await syncModule().snapshotOf(account.id)
			: null
		if (!snapshot) throw e
		rows = snapshot.secrets.filter(
			(r) => !r.blocked && !r.trashedAt && !r.archivedAt,
		)
	}
	// A blocked row (its key cannot be used) is never offered, online or not.
	rows = rows.filter((r) => !r.blocked)
	// A use-only copy is only ever offered on its own site (no "fill anyway").
	const ranked = filterForHost(matchSecrets(rows, payload.host), payload.host)
	// Return only index fields; the blobs stay in this account's cache.
	matchCache.set(account.id, {
		host,
		rows: new Map(ranked.map((r) => [r.id, r])),
	})
	return ranked.map((r) => ({
		id: r.id,
		name: r.name,
		url: r.url,
		typeId: r.typeId,
		useOnly: isUseOnly(r),
		accountId: account.id,
	}))
}

/** The context menu item and the keyboard command that fill a login. */
export const FILL_MENU_ID = 'keepiq-fill'
export const FILL_COMMAND = 'fill-login'

/**
 * Open the popup, where the user can unlock or choose. Browsers allow it only
 * right after a user action, which a menu click or a shortcut is.
 *
 * @return {Promise<void>}
 */
async function openPopupForChoice() {
	try {
		await chrome.action?.openPopup?.()
	} catch {
		// Not allowed here: the toolbar button still opens it.
	}
}

/**
 * Fill the login for a tab from the context menu or the shortcut: the only
 * login for the site fills at once; several, a locked vault or an https
 * login on an http page open the popup instead.
 *
 * @param {object} tab The tab.
 * @return {Promise<{filled: boolean, opened?: boolean}>}
 * @spec openspec/changes/clients-extension-gaps/specs/extension-autofill-extras/spec.md#requirement-fill-from-the-context-menu-and-a-shortcut
 */
export async function fillFromShortcut(tab) {
	if (!tab || !/^https?:/i.test(String(tab.url || ''))) return { filled: false }
	const account = await api.loadConfig()
	if (!account) return { filled: false }
	if (account.loggedOut || !vault.isUnlocked(account.id)) {
		await openPopupForChoice()
		return { filled: false, opened: true }
	}
	const offered = await doMatch({ host: hostOf(tab.url) })
	if (offered.length !== 1) {
		if (offered.length > 1) await openPopupForChoice()
		return { filled: false, opened: offered.length > 1 }
	}
	const res = await doFill({
		id: offered[0].id,
		accountId: account.id,
		tabId: tab.id,
	})
	if (res.confirm) {
		await openPopupForChoice()
		return { filled: false, opened: true }
	}
	return { filled: !!res.filled }
}

chrome.runtime?.onInstalled?.addListener(() => {
	chrome.contextMenus?.removeAll?.(() => {
		chrome.contextMenus.create({
			id: FILL_MENU_ID,
			title: 'Fill a login with Keepiq',
			contexts: ['editable'],
		})
	})
})

chrome.contextMenus?.onClicked?.addListener((info, tab) => {
	if (info?.menuItemId === FILL_MENU_ID) fillFromShortcut(tab).catch(() => {})
})

chrome.commands?.onCommand?.addListener(async (command, tab) => {
	if (command !== FILL_COMMAND) return
	const target =
		tab || (await chrome.tabs.query({ active: true, currentWindow: true }))[0]
	fillFromShortcut(target).catch(() => {})
})

/**
 * Whether filling a row into a page would send an https login to a plain
 * http page.
 *
 * @param {object} row The matched row.
 * @param {string} pageUrl The page address.
 * @return {boolean}
 * @spec openspec/changes/clients-extension-gaps/specs/extension-fill-and-capture/spec.md#requirement-ask-before-filling-an-https-login-into-an-http-page
 */
export function downgradesToHttp(row, pageUrl) {
	try {
		return (
			new URL(pageUrl).protocol === 'http:'
			&& /^https:\/\//i.test(String(row.url || ''))
		)
	} catch {
		return false
	}
}

/**
 * Decrypt the chosen secret and fill it into the active tab. Refused unless
 * the id came from the ACTIVE account's own last match, the message names
 * that account, and the tab is still on the matched site. Only the tab's
 * frames on the matched host receive the values, and an https login waits
 * for the user's yes before it fills a plain http page.
 *
 * @param {{id: string, accountId: string, tabId?: number, allowHttp?: boolean}} payload The choice.
 * @return {Promise<object>}
 * @spec openspec/changes/clients-extension-gaps/specs/extension-fill-and-capture/spec.md#requirement-a-fill-reaches-only-frames-on-the-matched-site
 * @spec openspec/changes/clients-extension-gaps/specs/extension-fill-and-capture/spec.md#requirement-ask-before-filling-an-https-login-into-an-http-page
 */
async function doFill(payload) {
	const account = await activeAccount()
	if (payload.accountId !== account.id) {
		throw new Error('This login belongs to another account')
	}
	if (!vault.isUnlocked(account.id)) throw new Error('vault is locked')
	const cache = matchCache.get(account.id)
	const row = cache ? cache.rows.get(payload.id) : undefined
	if (!row) throw new Error('This login was not offered for this site')
	// A popped-out popup names the tab it was opened over; its own window
	// has no page to fill. The host check below applies either way.
	const tab = await targetTab(payload.tabId)
	if (!tab) return { filled: false }
	if (hostOf(tab.url) !== cache.host) {
		throw new Error('The page changed. Open Keepiq again to fill.')
	}
	const useOnly = isUseOnly(row)
	if (useOnly && !allowedOnHost(row, cache.host)) {
		// Never fill a use-only copy on another site.
		return { filled: false }
	}
	// An https login into a plain http page: the user decides, in the popup.
	if (downgradesToHttp(row, tab.url) && payload.allowHttp !== true) {
		return { filled: false, confirm: 'http-page' }
	}
	const { login, secret } = await vault.decryptSecret(account.id, row)
	await touchActivity(account.id)
	// Only frames on this host get the values; each frame checks again (#740).
	const results = await sendToFramesOn(tab.id, cache.host, {
		type: 'fill-credential',
		payload: { login, secret, host: cache.host, useOnly },
	})
	// A fill counts as a use for the vault's Last used sort; a failed report
	// never fails the fill (vault-favourites-tags-and-last-used). A use-only
	// fill is also recorded for its owner (sharing-use-only-and-expiring-shares).
	await reportFill(results, payload.id, async (id) =>
		useOnly ? api.reportUseOnlyFill(account, id) : api.markUsed(account, id),
	)
	// Auto-copy a matched TOTP code so it is one paste away on the 2FA prompt
	// (extension-totp-autofill §3). The popup performs the clipboard write +
	// scheduled clear (a service worker has no clipboard access).
	const totp = cache.host ? await totpForHost(cache.host) : null
	const totpCode = totp ? totp.code : null
	if (totp) {
		// Best-effort: fill a detected OTP field on the page; the popup also
		// copies the code as the fallback (extension-totp-autofill §4.1).
		const otp = await sendToFramesOn(tab.id, cache.host, {
			type: 'fill-otp',
			payload: { code: totp.code, host: cache.host },
		})
		if (!otp?.filled) {
			// The code field is on the next step: remember, for this tab, this
			// site and five minutes, which secret to compute it from. No seed,
			// no code.
			const intents = await readIntents()
			intents[tab.id] = {
				tabId: tab.id,
				site: registrableDomain(cache.host),
				totpSecretId: totp.secretId,
				expiresAt: Date.now() + OTP_INTENT_MS,
			}
			await writeIntents(intents)
		}
	}
	return { filled: !!results?.filled, totpCode }
}

/**
 * Find a `totp`-typed secret matching the host and compute its current code
 * (extension-totp-autofill §2.1), from the active account. The seed is
 * decrypted only transiently.
 *
 * @param {object} payload { host }
 * @return {Promise<{ valid: boolean, code?: string, secondsRemaining?: number }>}
 */
async function doTotpForHost(payload) {
	const found = await findTotp(payload.host)
	return found ? found.result : { valid: false, none: true }
}

/**
 * The active account's `totp` secret for a host and its current code. The
 * seed is decrypted only transiently.
 *
 * @param {string} host The site.
 * @return {Promise<{row: object, result: object}|null>}
 */
async function findTotp(host) {
	const account = await activeAccount()
	if (!vault.isUnlocked(account.id)) throw new Error('vault is locked')
	const totpTypeId = await api.typeIdByName(account, 'totp')
	if (!totpTypeId) return null
	const rows = matchSecrets(await api.match(account, host), host)
	const totp = rows.find((r) => r.typeId === totpTypeId)
	if (!totp) return null
	const seed = await vault.decryptField(account.id, totp.key)
	await touchActivity(account.id)
	return { row: totp, result: await computeTotp(seed) }
}

async function totpForHost(host) {
	try {
		const found = await findTotp(host)
		return found && found.result.valid
			? { code: found.result.code, secretId: found.row.id }
			: null
	} catch {
		return null
	}
}

/**
 * A code field showed up in a tab (extension-totp-autofill, next step). Fill
 * it only for the tab, the site and the time a login fill named, while the
 * vault is unlocked, and only once: the intent is deleted before the code is
 * computed.
 *
 * @param {object} payload Ignored: the site comes from the browser's sender record.
 * @param {object} sender The content script's sender.
 * @return {Promise<{filled: boolean}>}
 */
async function doOtpFieldDetected(payload, sender) {
	const tabId = sender?.tab?.id
	if (tabId === undefined) return { filled: false }
	const intents = await readIntents()
	const intent = intents[tabId]
	if (!intent) return { filled: false }
	const host = hostOf(senderOrigin(sender))
	const site = registrableDomain(host)
	if (site === '' || site !== intent.site) return { filled: false }
	delete intents[tabId]
	await writeIntents(intents)
	if (Date.now() >= intent.expiresAt) return { filled: false }
	const account = await api.loadConfig()
	if (!account || !vault.isUnlocked(account.id)) return { filled: false }
	const row = await api.getSecret(account, intent.totpSecretId).catch(() => null)
	if (!row || !row.key) return { filled: false }
	const result = await computeTotp(await vault.decryptField(account.id, row.key))
	if (!result.valid) return { filled: false }
	const res = await chrome.tabs
		.sendMessage(
			tabId,
			// The frame fills only for its own host (fillScope, #740), so
			// the message names the host the field was reported from.
			{ type: 'fill-otp', payload: { code: result.code, host } },
			{ frameId: sender.frameId ?? 0 },
		)
		.catch(() => ({ filled: false }))
	return { filled: !!res?.filled }
}

/**
 * Why the org password policy refuses a captured password, or null
 * (keepiq#746). Runs before anything is encrypted, as the web app does.
 *
 * @param {object} config The paired config.
 * @param {string} value The captured password.
 * @return {Promise<string|null>} The refusal reason, or null.
 */
async function policyRefusalFor(config, value) {
	const policy = await api.fetchPolicy(config)
	return policyRefusal(policy, value, (prefix) => api.breachRange(config, prefix))
}

/**
 * Save or update a held capture (encrypted here) to the account it belongs
 * to. A password the org policy refuses is not saved; the reason comes back
 * as the error.
 *
 * @param {object} capture The held capture.
 * @param {number} tabId The tab it was held for.
 * @param {string|null} [folderId] The folder a new login goes into.
 * @return {Promise<{ok: boolean, saved: string}>}
 * @spec openspec/changes/clients-extension-gaps/specs/extension-autofill-extras/spec.md#requirement-save-a-new-login-into-a-folder
 * @spec openspec/changes/clients-extension-finish/specs/extension-save-prompt-details/spec.md#requirement-update-the-one-login-that-is-meant
 * @spec openspec/changes/clients-extension-finish/specs/extension-save-prompt-details/spec.md#requirement-a-save-that-confirms
 */
async function saveHeldCapture(capture, tabId, folderId = null) {
	const accountId = capture.accountId
	const config = await api.loadAccount(accountId)
	if (!config) throw new Error('not paired')
	if (!vault.isUnlocked(accountId)) throw new Error('vault is locked')
	const refusal = await policyRefusalFor(config, capture.secret)
	if (refusal !== null) {
		captures.delete(tabId)
		throw new Error(refusal)
	}
	const encryptedKey = await vault.encryptField(accountId, capture.secret)
	const encryptedLogin = await vault.encryptField(accountId, capture.login || '')
	const encryptionSuiteId = vault.activeSuiteId(accountId)
	// An update re-reads the login first: it may be gone since the offer.
	let update = !!capture.id
	if (update) {
		try {
			await api.getSecret(config, capture.id)
		} catch (e) {
			if (e?.status !== 404) throw e
			update = false
		}
	}
	if (update) {
		// The password only: name, address and username stay as they are.
		await api.updateSecret(config, capture.id, {
			key: encryptedKey,
			encryptionSuiteId,
		})
	} else {
		const typeId = await api.typeIdByName(config, 'login').catch(() => null)
		await api.createSecret(config, {
			name: capture.name || capture.host,
			url: capture.url || capture.host,
			key: encryptedKey,
			login: encryptedLogin,
			encryptionSuiteId,
			...(typeId ? { typeId } : {}),
			...(folderId ? { folderId } : {}),
		})
	}
	captures.delete(tabId)
	await touchActivity(accountId)
	// The vault list and the snapshot show the change at once; a failed
	// sync never fails the save.
	try {
		await syncModule().sync(config, { force: true })
	} catch {
		// The next sync catches up.
	}
	return { ok: true, saved: update ? 'updated' : 'saved' }
}

/**
 * Save the capture held for the popup's tab. The popup names the tab, never
 * the credential: what is saved is what the browser saw submitted there.
 *
 * @param {{tabId?: number}} payload The popup's pinned tab, if popped out.
 * @return {Promise<{ok: boolean}>}
 * @spec openspec/changes/clients-extension-gaps/specs/extension-fill-and-capture/spec.md#requirement-the-save-prompt-trusts-the-browser-not-the-page
 */
async function doSaveCapture(payload) {
	const tab = await targetTab(payload.tabId)
	const capture = tab ? captureOf(tab.id) : null
	if (!capture) throw new Error('There is no login waiting to be saved here')
	return saveHeldCapture(capture, tab.id, payload.folderId || null)
}

/**
 * Never offer to save on the site of the popup's held capture.
 *
 * @param {{tabId?: number}} payload The popup's pinned tab, if popped out.
 * @return {Promise<{ok: boolean}>}
 * @spec openspec/changes/clients-extension-gaps/specs/extension-autofill-extras/spec.md#requirement-never-offer-to-save-on-a-site
 */
async function doCaptureNever(payload) {
	const tab = await targetTab(payload.tabId)
	const capture = tab ? captureOf(tab.id) : null
	if (!capture) return { ok: false }
	await setNever(capture.host, true)
	captures.delete(tab.id)
	return { ok: true }
}

/**
 * The capture held for the popup's tab, for its save prompt, with the account
 * it will be saved to. No secret leaves the worker.
 *
 * @param {{tabId?: number}} payload The popup's pinned tab, if popped out.
 * @return {Promise<{capture: object|null}>}
 * @spec openspec/changes/clients-extension-gaps/specs/extension-fill-and-capture/spec.md#requirement-the-save-prompt-trusts-the-browser-not-the-page
 */
async function takePendingCapture(payload = {}) {
	const tab = await targetTab(payload.tabId)
	const capture = tab ? captureOf(tab.id) : null
	if (!capture) return { capture: null }
	const account = await api.loadAccount(capture.accountId)
	return {
		capture: {
			host: capture.host,
			login: capture.login,
			update: !!capture.id,
			account: account ? account.user + '@' + hostLabel(account) : '',
		},
	}
}

/**
 * Hold a submitted login and decide the offer (clients-save-prompt): update
 * when a saved login for the site has this username and another password,
 * nothing when it has this password, else save. A locked vault cannot tell,
 * so it offers nothing in the page and leaves the popup fallback. The capture
 * belongs to the account active at submit time and to the tab it came from.
 * Its site is the one the browser says sent it, never the page's claim.
 *
 * @param {object} capture The submitted login from the content script.
 * @param {object} sender The runtime.MessageSender of the content script.
 * @return {Promise<{action: string, name?: string}>} The offer, without ids or secrets.
 * @spec openspec/changes/clients-extension-gaps/specs/extension-fill-and-capture/spec.md#requirement-the-save-prompt-trusts-the-browser-not-the-page
 */
export async function doCapture(capture, sender) {
	const tabId = sender?.tab?.id
	let origin
	try {
		origin = new URL(sender?.url || '')
	} catch {
		return { action: 'none' }
	}
	if (!Number.isInteger(tabId) || !/^https?:$/.test(origin.protocol)) {
		return { action: 'none' }
	}
	const host = origin.hostname
	// The user said never for this site.
	if ((await neverSites()).includes(host)) return { action: 'none' }
	const config = await api.loadConfig()
	if (!config) return { action: 'none' }
	captures.set(tabId, {
		host,
		url: origin.origin,
		name: host,
		login: String(capture?.login ?? ''),
		secret: String(capture?.secret ?? ''),
		accountId: config.id,
		expiresAt: Date.now() + CAPTURE_TTL_MS,
	})
	if (!vault.isUnlocked(config.id)) return { action: 'locked' }
	let offer
	try {
		const rows = await api.match(config, host)
		// A login that belongs to a use-only copy is never offered for save
		// or update (sharing-use-only-and-expiring-shares D3).
		if (blocksSavePrompt(rows, host)) {
			captures.delete(tabId)
			return { action: 'none' }
		}
		offer = await classifyCapture({ ...captures.get(tabId) }, rows, (row) =>
			vault.decryptSecret(config.id, row),
		)
	} catch {
		return { action: 'none' }
	}
	// The user may have switched the save or the update offer off.
	const settings = await readSettings(chrome.storage?.local)
	if (
		offer.action === 'none'
		|| (offer.action === 'save' && !settings.offerSave)
		|| (offer.action === 'update' && !settings.offerUpdate)
	) {
		captures.delete(tabId)
		return { action: 'none' }
	}
	// Say so in the page instead of offering a save the policy would refuse.
	const refusal = await policyRefusalFor(config, captures.get(tabId).secret)
	if (refusal !== null) {
		captures.delete(tabId)
		return { action: 'refused', reason: refusal }
	}
	captures.get(tabId).id = offer.id
	// Kept, so the next page on the site can show the offer again.
	captures.get(tabId).offer = { action: offer.action, name: offer.name }
	return { action: offer.action, name: offer.name }
}

/**
 * The offer for a page that just loaded in a tab with a held capture: the
 * login form often redirects, so the bar comes back on the next page of the
 * same site. A page on another site drops the capture.
 *
 * @param {object} payload Unused.
 * @param {object} sender The runtime.MessageSender of the content script.
 * @return {Promise<{action: string, name?: string}>}
 * @spec openspec/changes/clients-extension-finish/specs/extension-save-prompt-details/spec.md#requirement-the-offer-follows-the-site-not-the-page
 */
async function doCaptureOffer(payload, sender) {
	const tabId = sender?.tab?.id
	const capture = captureOf(tabId)
	if (!capture?.offer || sender?.frameId) return { action: 'none' }
	const host = hostOf(sender?.url || '')
	if (registrableDomain(host) !== registrableDomain(capture.host)) {
		captures.delete(tabId)
		return { action: 'none' }
	}
	return capture.offer
}

/**
 * Act on the choice made in the in-page offer, for the tab that made it.
 *
 * @param {{choice: string}} payload save, update or dismiss.
 * @param {object} sender The runtime.MessageSender of the content script.
 * @return {Promise<object>} The save result, or ok.
 * @spec openspec/changes/clients-extension-gaps/specs/extension-fill-and-capture/spec.md#requirement-the-save-prompt-trusts-the-browser-not-the-page
 */
async function doCaptureDecision(payload, sender) {
	const tabId = sender?.tab?.id
	const capture = captureOf(tabId)
	if (!capture) return { ok: false }
	if (payload.choice === 'save' || payload.choice === 'update') {
		return saveHeldCapture(capture, tabId)
	}
	if (payload.choice === 'never') await setNever(capture.host, true)
	captures.delete(tabId)
	return { ok: true }
}

// --- extension passkey unlock (extension-biometric-unlock) ---

async function unlockedAccount(accountId) {
	const account = await api.loadAccount(accountId)
	if (!account) throw new Error('unknown account')
	if (!vault.isUnlocked(account.id)) throw new Error('vault is locked')
	return account
}

/**
 * What the unlock window needs to enrol a passkey for an unlocked account:
 * a challenge, the private-key envelope (already ciphertext; its salt feeds
 * the unlock-key derivation) and the relying party id.
 *
 * @param {{accountId: string}} payload The account.
 * @return {Promise<object>}
 */
async function doBiometricEnrolContext(payload) {
	const account = await unlockedAccount(payload.accountId)
	const [{ challenge }, suite] = await Promise.all([
		api.passkeyChallenge(account),
		api.fetchActiveSuite(account),
	])
	return {
		challenge,
		envelope: suite.privateKey,
		rpId: extensionRpId(),
		user: account.user,
	}
}

/**
 * Store the enrolled extension passkey. Only the listed fields are sent: the
 * credential id, the PRF salt, the wrapped unlock key, a label and the
 * transports. Anything else the window sent is dropped.
 *
 * @param {{accountId: string, body: object}} payload The account and credential.
 * @return {Promise<object>}
 */
async function doBiometricEnrol(payload) {
	const account = await unlockedAccount(payload.accountId)
	const b = payload.body || {}
	return api.enrolPasskey(account, {
		credentialId: String(b.credentialId || ''),
		wrappedUnlockKey: String(b.wrappedUnlockKey || ''),
		prfSalt: String(b.prfSalt || ''),
		label: String(b.label || 'Browser extension'),
		transports: String(b.transports || ''),
		rpId: extensionRpId(),
	})
}

/**
 * The extension's passkey login options for an account (its own relying
 * party only).
 *
 * @param {{accountId: string}} payload The account.
 * @return {Promise<object>}
 */
async function doBiometricOptions(payload) {
	const account = await api.loadAccount(payload.accountId)
	if (!account) throw new Error('unknown account')
	const rpId = extensionRpId()
	const options = await api.passkeyLoginOptions(account, rpId)
	return { ...options, rpId }
}

async function doBiometricUsed(payload) {
	const account = await api.loadAccount(payload.accountId)
	if (account && payload.id) {
		await api.markPasskeyUsed(account, payload.id).catch(() => {})
	}
	return { ok: true }
}

// --- message router ---

const handlers = {
	'get-state': getState,
	pair: doPair,
	relogin: doRelogin,
	logout: doLogout,
	'pin-set': doPinSet,
	'pin-unlock': doPinUnlock,
	'pin-remove': async () => {
		await pinModule().remove((await activeAccount()).id)
		return { ok: true }
	},
	unpair: doUnpair,
	'switch-account': doSwitchAccount,
	'set-idle': doSetIdle,
	unlock: doUnlock,
	'unlock-raw': doUnlockRaw,
	'device-approval-state': doDeviceApprovalState,
	'device-approval-start': doDeviceApprovalStart,
	'device-approval-poll': doDeviceApprovalPoll,
	'device-approval-cancel': doDeviceApprovalCancel,
	lock: async (payload) => {
		if (payload.accountId) lockAccount(payload.accountId)
		else lockEverything()
		return { ok: true }
	},
	match: doMatch,
	fill: doFill,
	'save-capture': doSaveCapture,
	'totp-for-host': doTotpForHost,
	'pending-capture': takePendingCapture,
	// The extension's settings for this browser (clients-extension-gaps).
	'extension-settings': () => readSettings(chrome.storage?.local),
	'set-extension-settings': (payload) =>
		writeSettings(chrome.storage?.local, payload || {}),
	'page-settings': async () => ({
		suggestPasswords: (await readSettings(chrome.storage?.local))
			.suggestPasswords,
	}),
	'capture-never': doCaptureNever,
	'capture-offer': doCaptureOffer,
	'never-sites': async () => ({ sites: await neverSites() }),
	'never-remove': async (payload) => ({
		sites: await setNever(String(payload.host || ''), false),
	}),
	// A copy in the popup; the worker clears the clipboard later.
	'clipboard-copied': () => clipboardModule().copied(),
	'clipboard-settings': async () => ({
		seconds: await clipboardModule().seconds(),
		choices: CLEAR_CHOICES,
	}),
	'set-clipboard-clear': async (payload) => ({
		seconds: await clipboardModule().setSeconds(payload.seconds),
	}),
	'frame-ready': doFrameReady,
	'capture-decision': doCaptureDecision,
	'biometric-enrol-context': doBiometricEnrolContext,
	'biometric-enrol': doBiometricEnrol,
	'biometric-options': doBiometricOptions,
	'biometric-used': doBiometricUsed,
	'otp-field-detected': doOtpFieldDetected,
	// Generator tab context, options and history (clients-extension-complete).
	'generator-context': (p) => generatorModule().handlers['generator-context'](p),
	'generator-options-save': (p) =>
		generatorModule().handlers['generator-options-save'](p),
	'generator-history-add': (p) =>
		generatorModule().handlers['generator-history-add'](p),
	'generator-history-clear': (p) =>
		generatorModule().handlers['generator-history-clear'](p),
	// Vault, Generator and Send tabs (clients-extension-generator-vault-send).
	...buildVaultHandlers({
		api,
		vault,
		activeAccount,
		policyRefusalFor: (config, value, typeName) =>
			api
				.fetchPolicy(config)
				.then((policy) =>
					policyRefusal(
						policy,
						value,
						(prefix) => api.breachRange(config, prefix),
						typeName,
					),
				),
		touchActivity,
		sync: syncModule,
		suggestPasswords: async () =>
			(await readSettings(chrome.storage?.local)).suggestPasswords,
	}),
	// WebAuthn ceremonies relayed from the page-context shim. The origin is the
	// sender's, as the browser reports it; the page's own claim in the payload
	// is ignored (clients-passkey-origin).
	'webauthn-create': async (p, sender) => ({
		credential: await passkey.handleCreate(p.options, senderOrigin(sender)),
	}),
	'webauthn-get': async (p, sender) => ({
		assertion: await passkey.handleGet(p.options, senderOrigin(sender)),
	}),
}

/**
 * Whether this router answers a message type.
 *
 * @param {string} type The message type.
 * @return {boolean}
 */
export function handles(type) {
	return (
		type === 'capture-credential'
		|| Object.prototype.hasOwnProperty.call(handlers, type)
	)
}

/**
 * Handle one runtime message. Resolves to the response, or to null for a
 * message this router does not own (another listener may take it, such as the
 * passkey consent result).
 *
 * @param {object} msg The message ({ type, payload }).
 * @param {object} sender The runtime.MessageSender.
 * @return {Promise<object>|null}
 */
export function handleMessage(msg, sender) {
	const type = msg?.type
	if (type === 'capture-credential') {
		// Submit-capture arrives from a content script: hold it and answer
		// with the offer the page shows at once.
		return doCapture(msg.payload || {}, sender).catch(() => ({
			action: 'none',
		}))
	}
	const handler = Object.prototype.hasOwnProperty.call(handlers, type)
		? handlers[type]
		: null
	if (!handler) return null
	if (!PAGE_MESSAGES.has(type) && !fromExtensionPage(sender)) {
		return Promise.resolve({ error: 'not allowed from a web page' })
	}
	return handler(msg.payload || {}, sender).catch((e) => ({
		error: e.message || String(e),
	}))
}

/**
 * The OS or browser reported an idle state; a locked screen locks every
 * account.
 *
 * @param {string} state active, idle or locked.
 * @return {void}
 */
export function onIdleState(state) {
	if (state === 'locked') lockEverything()
}
