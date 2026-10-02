/**
 * Background service worker — the extension's trust core (browser-extension-
 * autofill §"Extension architecture"). It is the ONLY place the vault CryptoKey
 * lives (in memory, never persisted); the popup and content scripts are
 * untrusted UIs that message it.
 *
 * Responsibilities: pairing, in-worker unlock/lock, URL-matched candidate list
 * (metadata only until the user selects), decrypt-on-demand + fill, submit
 * capture → save/update, and auto-lock (idle timeout, browser lock, worker
 * termination clears memory for free).
 */

import * as api from '../lib/api.js'
import * as vault from '../lib/vault.js'
import { matchSecrets, hostOf } from '../lib/match.js'
import { classifyCapture } from '../lib/capture.js'
import { buildPasskeyOrchestrator } from '../passkey/orchestrator.js'
import { senderOrigin } from '../passkey/rp.js'
import { computeTotp } from '../lib/totp-service.js'
import { reportFill } from '../lib/usage.js'

// Passkey provider (extension-passkey-provider): bind the ceremony orchestrator
// to this worker's api + vault. Driven by the page-context shim relay in every
// browser; the origin always comes from the message sender (clients-passkey-origin).
const passkey = buildPasskeyOrchestrator({ api, vault, loadConfig: api.loadConfig })

const DEFAULT_IDLE_MINUTES = 15

// A pending submit-capture, surfaced to the popup for save/update confirmation.
let pendingCapture = null

async function idleMs() {
	const config = await api.loadConfig()
	const minutes = (config && config.idleMinutes) || DEFAULT_IDLE_MINUTES
	return minutes * 60 * 1000
}

async function touchActivity() {
	vault.armIdleLock(await idleMs())
}

/** Current state for the popup to render the right view. */
async function getState() {
	const config = await api.loadConfig()
	return {
		paired: !!config,
		unlocked: vault.isUnlocked(),
		user: config ? config.user : null,
		url: config ? config.url : null,
	}
}

async function doPair(payload) {
	const config = {
		url: payload.url,
		user: payload.user,
		appPassword: payload.appPassword,
	}
	// Verify the credential actually pairs before persisting it.
	await api.pair(config)
	await api.saveConfig(config)
	return { ok: true }
}

async function doUnpair() {
	const config = await api.loadConfig()
	if (config) {
		try {
			await api.unpair(config)
		} catch {
			// Best-effort; unpairing is local + NC-side revocation.
		}
	}
	vault.lock()
	await api.clearConfig()
	return { ok: true }
}

async function doUnlock(payload) {
	const config = await api.loadConfig()
	if (!config) throw new Error('not paired')
	await vault.unlock(config, payload.masterPassword)
	await touchActivity()
	return { ok: true }
}

/**
 * Candidate list for a host — metadata only (id/name/url). No decryption
 * happens here; a locked-but-paired extension can still list names/urls.
 * @param payload
 */
async function doMatch(payload) {
	const config = await api.loadConfig()
	if (!config) throw new Error('not paired')
	const rows = await api.match(config, payload.host)
	const ranked = matchSecrets(rows, payload.host)
	// Return only index fields to the popup; the blobs stay in the worker cache.
	blobCache = new Map(ranked.map((r) => [r.id, r]))
	return ranked.map((r) => ({
		id: r.id,
		name: r.name,
		url: r.url,
		typeId: r.typeId,
	}))
}

// Short-lived cache of the last match's blob rows (cleared on lock).
let blobCache = new Map()

/**
 * Decrypt the chosen secret and fill it into the active tab.
 * @param payload
 */
async function doFill(payload) {
	if (!vault.isUnlocked()) throw new Error('vault is locked')
	const row =
		blobCache.get(payload.id)
		|| (await api.getSecret(await api.loadConfig(), payload.id))
	const { login, secret } = await vault.decryptSecret(row)
	await touchActivity()
	const [tab] = await chrome.tabs.query({ active: true, currentWindow: true })
	if (!tab) return { filled: false }
	let host = ''
	try {
		host = tab.url ? new URL(tab.url).hostname : ''
	} catch {
		host = ''
	}
	// Every frame gets the message; only frames on this host fill (#740).
	if (!host) return { filled: false }
	const results = await chrome.tabs
		.sendMessage(tab.id, {
			type: 'fill-credential',
			payload: { login, secret, host },
		})
		.catch(() => ({ filled: false }))
	// A fill counts as a use for the vault's Last used sort; a failed report
	// never fails the fill (vault-favourites-tags-and-last-used).
	await reportFill(results, payload.id, async (id) =>
		api.markUsed(await api.loadConfig(), id),
	)
	// Auto-copy a matched TOTP code so it is one paste away on the 2FA prompt
	// (extension-totp-autofill §3). The popup performs the clipboard write +
	// scheduled clear (a service worker has no clipboard access).
	const totpCode = await totpCodeForHost(host)
	if (totpCode) {
		// Best-effort: fill a detected OTP field on the page; the popup also
		// copies the code as the fallback (extension-totp-autofill §4.1).
		chrome.tabs
			.sendMessage(tab.id, { type: 'fill-otp', payload: { code: totpCode, host } })
			.catch(() => {})
	}
	return { filled: !!results?.filled, totpCode }
}

/**
 * Find a `totp`-typed secret matching the host and compute its current code
 * (extension-totp-autofill §2.1). The seed is decrypted only transiently.
 *
 * @param {object} payload { host }
 * @return {Promise<{ valid: boolean, code?: string, secondsRemaining?: number }>}
 */
async function doTotpForHost(payload) {
	if (!vault.isUnlocked()) throw new Error('vault is locked')
	const config = await api.loadConfig()
	const totpTypeId = await api.typeIdByName(config, 'totp')
	if (!totpTypeId) return { valid: false, none: true }
	const rows = matchSecrets(await api.match(config, payload.host), payload.host)
	const totp = rows.find((r) => r.typeId === totpTypeId)
	if (!totp) return { valid: false, none: true }
	const seed = await vault.decryptField(totp.key)
	await touchActivity()
	return computeTotp(seed)
}

/**
 * Compute the TOTP code for a matched host, if any (auto-copy on fill).
 * @param {string} host
 * @return {Promise<string|null>}
 */
async function totpCodeForHost(host) {
	try {
		const result = await doTotpForHost({ host })
		return result.valid ? result.code : null
	} catch {
		return null
	}
}

/**
 * Save or update a captured credential (encrypted client-side).
 * @param payload
 */
async function doSaveCapture(payload) {
	if (!vault.isUnlocked()) throw new Error('vault is locked')
	const config = await api.loadConfig()
	const encryptedKey = await vault.encryptField(payload.secret)
	const encryptedLogin = await vault.encryptField(payload.login || '')
	const body = {
		name: payload.name || hostOf(payload.host),
		url: payload.url || payload.host,
		key: encryptedKey,
		login: encryptedLogin,
		encryptionSuiteId: vault.activeSuiteId(),
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
	await touchActivity()
	return { ok: true }
}

function takePendingCapture() {
	return pendingCapture
}

/**
 * Hold a submitted login and decide the offer (clients-save-prompt): update
 * when a saved login for the site has this username and another password,
 * nothing when it has this password, else save. A locked vault cannot tell,
 * so it offers nothing in the page and leaves the popup fallback.
 *
 * @param {object} capture The submitted login from the content script.
 * @return {Promise<{action: string, name?: string}>} The offer, without ids or secrets.
 */
async function doCapture(capture) {
	pendingCapture = { ...capture }
	if (!vault.isUnlocked()) return { action: 'locked' }
	const config = await api.loadConfig()
	if (!config) return { action: 'none' }
	let offer
	try {
		const rows = await api.match(config, capture.host)
		offer = await classifyCapture(capture, rows, (row) =>
			vault.decryptSecret(row),
		)
	} catch {
		return { action: 'none' }
	}
	if (offer.action === 'none') {
		pendingCapture = null
		return { action: 'none' }
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

// --- message router ---

const handlers = {
	'get-state': getState,
	pair: doPair,
	unpair: doUnpair,
	unlock: doUnlock,
	lock: async () => {
		vault.lock()
		blobCache = new Map()
		return { ok: true }
	},
	match: doMatch,
	fill: doFill,
	'save-capture': doSaveCapture,
	'totp-for-host': doTotpForHost,
	'pending-capture': async () => ({ capture: takePendingCapture() }),
	'capture-decision': doCaptureDecision,
	// WebAuthn ceremonies relayed from the page-context shim. The origin is the sender's, as the browser reports it; the page's own
	// claim in the payload is ignored (clients-passkey-origin).
	'webauthn-create': async (p, sender) => ({
		credential: await passkey.handleCreate(p.options, senderOrigin(sender)),
	}),
	'webauthn-get': async (p, sender) => ({
		assertion: await passkey.handleGet(p.options, senderOrigin(sender)),
	}),
}

chrome.runtime.onMessage.addListener((msg, sender, sendResponse) => {
	// Submit-capture arrives from a content script (has sender.tab): hold it and
	// answer with the offer the page shows at once.
	if (msg?.type === 'capture-credential') {
		doCapture(msg.payload || {})
			.then((offer) => sendResponse(offer))
			.catch(() => sendResponse({ action: 'none' }))
		return true
	}
	const handler = handlers[msg?.type]
	if (!handler) return false
	handler(msg.payload || {}, sender)
		.then((result) => sendResponse(result))
		.catch((e) => sendResponse({ error: e.message || String(e) }))
	return true // async response
})

// Auto-lock on OS/browser idle+locked.
if (chrome.idle && chrome.idle.onStateChanged) {
	chrome.idle.onStateChanged.addListener((state) => {
		if (state === 'locked') {
			vault.lock()
			blobCache = new Map()
		}
	})
}
