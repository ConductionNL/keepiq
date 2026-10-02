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
import { matchSecrets, hostOf, registrableDomain } from '../lib/match.js'
import { classifyCapture } from '../lib/capture.js'
import { policyRefusal } from '../lib/policy.js'
import { buildPasskeyOrchestrator } from '../passkey/orchestrator.js'
import { senderOrigin } from '../passkey/rp.js'
import { computeTotp } from '../lib/totp-service.js'
import { reportFill } from '../lib/usage.js'
import { isServerSupported } from '../lib/version.js'
import { buildVaultHandlers } from './vault-handlers.js'
import { areaOrMemory, buildGeneratorHandlers } from './generator-handlers.js'

/**
 * The messages a content script (a tab) may send. Everything else needs an
 * extension page as sender.
 */
export const PAGE_MESSAGES = Object.freeze(
	new Set([
		'capture-credential',
		'capture-decision',
		'webauthn-create',
		'webauthn-get',
		'otp-field-detected',
		// A random password for a sign-up field; it carries no vault data.
		'generate-for-field',
	]),
)

/** How long after a login fill the code may fill on the next step. */
export const OTP_INTENT_MS = 5 * 60 * 1000

// chrome.storage.session key of the pending code intents, by tab id. Session
// storage is held in memory by the browser and is not readable by content
// scripts. An intent holds no seed and no code (extension-totp-autofill).
const OTP_INTENTS_KEY = 'keepiq.otpIntents'

function sessionStore() {
	return chrome.storage && chrome.storage.session ? chrome.storage.session : null
}

// The Generator tab's state (clients-extension-complete), built on first use
// so it binds to the storage areas the browser provides at that time.
let generatorState = null

function generatorModule() {
	if (!generatorState) {
		generatorState = buildGeneratorHandlers({
			api,
			activeAccount,
			activeHost: async () => {
				const [tab] = await chrome.tabs.query({
					active: true,
					currentWindow: true,
				})
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

// A pending submit-capture, surfaced for save/update confirmation. It is bound
// to the account that was active when the login was submitted.
let pendingCapture = null

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
 * unlock window), not from a content script in a tab.
 *
 * @param {object|undefined} sender The runtime.MessageSender.
 * @return {boolean}
 */
export function fromExtensionPage(sender) {
	if (!sender || sender.tab) return false
	if (sender.id !== chrome.runtime.id) return false
	const base = chrome.runtime.getURL('')
	return typeof sender.url === 'string' && sender.url.startsWith(base)
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
	if (pendingCapture && pendingCapture.accountId === accountId) {
		pendingCapture = null
	}
}

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

/** Current state for the popup to render the right view. */
async function getState() {
	const accounts = await api.loadAccounts()
	const activeId = await api.activeAccountId()
	const active = accounts.find((a) => a.id === activeId) || null
	// An account paired before the handshake has no version yet: ask once.
	if (active && active.serverVersion === undefined) {
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
		})),
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

async function doPair(payload) {
	if ((await api.loadAccounts()).length >= api.MAX_ACCOUNTS) {
		throw new Error(
			'You can connect up to '
				+ api.MAX_ACCOUNTS
				+ ' accounts. Disconnect one first.',
		)
	}
	const config = {
		url: payload.url,
		user: payload.user,
		appPassword: payload.appPassword,
	}
	// Verify the credential actually pairs before persisting it.
	const res = await api.pair(config)
	const account = await api.addAccount({
		...config,
		serverVersion: res?.serverVersion ?? null,
	})
	return { ok: true, accountId: account.id }
}

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
		// pairing (#748). The local state is cleared either way.
		revoked = await api.revokeAppPassword(account)
	} catch {
		revoked = false
	}
	lockAccount(id)
	maxIdleByAccount.delete(id)
	await generatorModule()
		.forget(id)
		.catch(() => {})
	await api.removeAccount(id)
	return { ok: true, revoked }
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

async function doUnlock(payload) {
	const account = await activeAccount()
	await refreshServerVersion(account)
	await vault.unlock(account.id, account, payload.masterPassword)
	await refreshPolicy(account)
	await touchActivity(account.id)
	return { ok: true }
}

/**
 * Unlock one account with a raw unlock key the unlock window unwrapped from a
 * passkey (extension-biometric-unlock). The key is used once and not kept.
 *
 * @param {{accountId: string, rawKey: number[]}} payload The account and key bytes.
 * @return {Promise<{ok: boolean}>}
 */
async function doUnlockRaw(payload) {
	const account = await api.loadAccount(payload.accountId)
	if (!account) throw new Error('unknown account')
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
	return { ok: true }
}

/**
 * Candidate list for a host — metadata only (id/name/url). No decryption
 * happens here; a locked-but-paired extension can still list names/urls.
 * @param payload
 */
async function doMatch(payload) {
	const account = await activeAccount()
	if (!isServerSupported(account.serverVersion)) {
		throw new Error(
			'Update Keepiq on your server to use this extension version.',
		)
	}
	const host = hostOf(payload.host)
	const rows = await api.match(account, payload.host)
	const ranked = matchSecrets(rows, payload.host)
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
		accountId: account.id,
	}))
}

/**
 * Decrypt the chosen secret and fill it into the active tab. Refused unless
 * the id came from the ACTIVE account's own last match, the message names
 * that account, and the tab is still on the matched site.
 * @param payload
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
	const [tab] = await chrome.tabs.query({ active: true, currentWindow: true })
	if (!tab) return { filled: false }
	if (hostOf(tab.url) !== cache.host) {
		throw new Error('The page changed. Open Keepiq again to fill.')
	}
	const { login, secret } = await vault.decryptSecret(account.id, row)
	await touchActivity(account.id)
	const results = await chrome.tabs
		.sendMessage(tab.id, {
			type: 'fill-credential',
			// Every frame gets the message; only frames on this host fill (#740).
			payload: { login, secret, host: cache.host },
		})
		.catch(() => ({ filled: false }))
	// A fill counts as a use for the vault's Last used sort; a failed report
	// never fails the fill (vault-favourites-tags-and-last-used).
	await reportFill(results, payload.id, async (id) => api.markUsed(account, id))
	// Auto-copy a matched TOTP code so it is one paste away on the 2FA prompt
	// (extension-totp-autofill §3). The popup performs the clipboard write +
	// scheduled clear (a service worker has no clipboard access).
	const totp = cache.host ? await totpForHost(cache.host) : null
	const totpCode = totp ? totp.code : null
	if (totp) {
		// Best-effort: fill a detected OTP field on the page; the popup also
		// copies the code as the fallback (extension-totp-autofill §4.1).
		const otp = await chrome.tabs
			.sendMessage(tab.id, {
				type: 'fill-otp',
				// Every frame gets the message; only frames on this host fill (#740).
				payload: { code: totp.code, host: cache.host },
			})
			.catch(() => ({ filled: false }))
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
	const site = registrableDomain(hostOf(senderOrigin(sender)))
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
			{ type: 'fill-otp', payload: { code: result.code } },
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
 * Save or update a captured credential (encrypted client-side) to the account
 * the capture belongs to. A password the org policy refuses is not saved; the
 * reason comes back as the error.
 * @param payload
 */
async function doSaveCapture(payload) {
	const accountId =
		pendingCapture?.accountId
		|| payload.accountId
		|| (await api.activeAccountId())
	const config = await api.loadAccount(accountId)
	if (!config) throw new Error('not paired')
	if (!vault.isUnlocked(accountId)) throw new Error('vault is locked')
	const refusal = await policyRefusalFor(config, payload.secret)
	if (refusal !== null) {
		pendingCapture = null
		throw new Error(refusal)
	}
	const encryptedKey = await vault.encryptField(accountId, payload.secret)
	const encryptedLogin = await vault.encryptField(accountId, payload.login || '')
	const body = {
		name: payload.name || hostOf(payload.host),
		url: payload.url || payload.host,
		key: encryptedKey,
		login: encryptedLogin,
		encryptionSuiteId: vault.activeSuiteId(accountId),
	}
	if (payload.id) {
		// An update changes the credential only; the saved name and address stay.
		await api.updateSecret(config, payload.id, {
			key: encryptedKey,
			login: encryptedLogin,
			encryptionSuiteId: body.encryptionSuiteId,
		})
	} else {
		await api.createSecret(config, body)
	}
	pendingCapture = null
	await touchActivity(accountId)
	return { ok: true }
}

/**
 * The pending capture for the popup's save prompt, with the account it will
 * be saved to.
 *
 * @return {Promise<{capture: object|null}>}
 */
async function takePendingCapture() {
	if (!pendingCapture) return { capture: null }
	const account = await api.loadAccount(pendingCapture.accountId)
	return {
		capture: {
			...pendingCapture,
			account: account ? account.user + '@' + hostLabel(account) : '',
		},
	}
}

/**
 * Hold a submitted login and decide the offer (clients-save-prompt): update
 * when a saved login for the site has this username and another password,
 * nothing when it has this password, else save. A locked vault cannot tell,
 * so it offers nothing in the page and leaves the popup fallback. The capture
 * belongs to the account active at submit time.
 *
 * @param {object} capture The submitted login from the content script.
 * @return {Promise<{action: string, name?: string}>} The offer, without ids or secrets.
 */
export async function doCapture(capture) {
	const config = await api.loadConfig()
	if (!config) return { action: 'none' }
	pendingCapture = {
		host: capture.host,
		url: capture.url,
		name: capture.name,
		login: capture.login,
		secret: capture.secret,
		accountId: config.id,
	}
	if (!vault.isUnlocked(config.id)) return { action: 'locked' }
	let offer
	try {
		const rows = await api.match(config, capture.host)
		offer = await classifyCapture(capture, rows, (row) =>
			vault.decryptSecret(config.id, row),
		)
	} catch {
		return { action: 'none' }
	}
	if (offer.action === 'none') {
		pendingCapture = null
		return { action: 'none' }
	}
	// Say so in the page instead of offering a save the policy would refuse.
	const refusal = await policyRefusalFor(config, capture.secret)
	if (refusal !== null) {
		pendingCapture = null
		return { action: 'refused', reason: refusal }
	}
	pendingCapture.id = offer.id
	return { action: offer.action, name: offer.name }
}

/**
 * Act on the choice made in the in-page offer.
 *
 * @param {{choice: string}} payload save, update or dismiss.
 * @return {Promise<object>} The save result, or ok.
 */
async function doCaptureDecision(payload) {
	if (!pendingCapture) return { ok: false }
	if (payload.choice === 'save' || payload.choice === 'update') {
		return doSaveCapture(pendingCapture)
	}
	pendingCapture = null
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
	unpair: doUnpair,
	'switch-account': doSwitchAccount,
	'set-idle': doSetIdle,
	unlock: doUnlock,
	'unlock-raw': doUnlockRaw,
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
		return doCapture(msg.payload || {}).catch(() => ({ action: 'none' }))
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
