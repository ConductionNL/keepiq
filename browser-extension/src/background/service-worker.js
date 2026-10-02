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
	// A use-only copy is only ever offered on its own site (no "fill anyway").
	const ranked = filterForHost(matchSecrets(rows, payload.host), payload.host)
	// Return only index fields to the popup; the blobs stay in the worker cache.
	blobCache = new Map(ranked.map((r) => [r.id, r]))
	return ranked.map((r) => ({
		id: r.id,
		name: r.name,
		url: r.url,
		typeId: r.typeId,
		useOnly: isUseOnly(r),
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
	const [tab] = await chrome.tabs.query({ active: true, currentWindow: true })
	if (!tab) return { filled: false }
	const useOnly = isUseOnly(row)
	if (useOnly && !allowedOnHost(row, tabHost(tab))) {
		// Never fill a use-only copy on another site.
		return { filled: false }
	}
	const { login, secret } = await vault.decryptSecret(row)
	await touchActivity()
	const results = await chrome.tabs
		.sendMessage(tab.id, {
			type: 'fill-credential',
			payload: { login, secret, useOnly },
		})
		.catch(() => ({ filled: false }))
	// A fill counts as a use for the vault's Last used sort; a failed report
	// never fails the fill (vault-favourites-tags-and-last-used). A use-only
	// fill is also recorded for its owner (sharing-use-only-and-expiring-shares).
	await reportFill(results, payload.id, async (id) =>
		useOnly
			? api.reportUseOnlyFill(await api.loadConfig(), id)
			: api.markUsed(await api.loadConfig(), id),
	)
	// Auto-copy a matched TOTP code so it is one paste away on the 2FA prompt
	// (extension-totp-autofill §3). The popup performs the clipboard write +
	// scheduled clear (a service worker has no clipboard access).
	let host = ''
	try {
		host = tab.url ? new URL(tab.url).hostname : ''
	} catch {
		host = ''
	}
	const totpCode = host ? await totpCodeForHost(host) : null
	if (totpCode) {
		// Best-effort: fill a detected OTP field on the page; the popup also
		// copies the code as the fallback (extension-totp-autofill §4.1).
		chrome.tabs
			.sendMessage(tab.id, { type: 'fill-otp', payload: { code: totpCode } })
			.catch(() => {})
	}
	return { filled: !!results?.filled, totpCode }
}

/**
 * The hostname of a tab, or '' when it has none.
 *
 * @param {object} tab A chrome tab.
 * @return {string}
 */
function tabHost(tab) {
	try {
		return tab?.url ? new URL(tab.url).hostname : ''
	} catch {
		return ''
	}
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
 * Save or update a captured credential (encrypted client-side). A password
 * the org policy refuses is not saved; the reason comes back as the error.
 * @param payload
 */
async function doSaveCapture(payload) {
	if (!vault.isUnlocked()) throw new Error('vault is locked')
	const config = await api.loadConfig()
	const refusal = await policyRefusalFor(config, payload.secret)
	if (refusal !== null) {
		pendingCapture = null
		throw new Error(refusal)
	}
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
		// A login that belongs to a use-only copy is never offered for save
		// or update (sharing-use-only-and-expiring-shares D3).
		if (blocksSavePrompt(rows, capture.host)) {
			pendingCapture = null
			return { action: 'none' }
		}
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
