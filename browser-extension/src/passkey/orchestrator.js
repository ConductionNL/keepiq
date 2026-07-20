/**
 * Passkey ceremony orchestration in the service worker (extension-passkey-
 * provider §2–§5). Ties the pure ceremony (`webauthn.js`) to the vault (decrypt
 * the passkey private key, save a new credential, write back the counter) and to
 * per-site consent. Every sign is gated on: (a) the vault being unlocked — the
 * key must be decryptable, and the unlocked state satisfies user verification;
 * and (b) an explicit per-RP consent the user grants in an extension window.
 *
 * On decline, no-match, or unsupported algorithm, the caller falls through to
 * the platform authenticator rather than aborting the page's ceremony.
 */

import { createCredential, getAssertion } from './webauthn.js'
import { serializePasskey, parsePasskey } from './vault-passkey.js'

/**
 * Open the consent window and resolve true/false. The window messages back
 * `{ type: 'passkey-consent-result', id, allow }`. Closing the window without a
 * decision resolves false (decline).
 *
 * @param {string} rpId The relying-party id shown to the user
 * @param {string} op 'create' | 'get'
 * @return {Promise<boolean>}
 */
function requestConsent(rpId, op) {
	return new Promise((resolve) => {
		const id = Math.floor(performance.now()) + ':' + rpId
		const url = chrome.runtime.getURL('consent.html')
			+ '?rp=' + encodeURIComponent(rpId) + '&op=' + encodeURIComponent(op) + '&id=' + encodeURIComponent(id)
		let settled = false
		const listener = (msg) => {
			if (msg && msg.type === 'passkey-consent-result' && msg.id === id) {
				settled = true
				chrome.runtime.onMessage.removeListener(listener)
				resolve(!!msg.allow)
			}
		}
		chrome.runtime.onMessage.addListener(listener)
		chrome.windows.create({ url, type: 'popup', width: 380, height: 260 }, (win) => {
			// If the window is closed without a decision, treat as decline.
			if (chrome.windows && chrome.windows.onRemoved) {
				const onClosed = (closedId) => {
					if (win && closedId === win.id && !settled) {
						chrome.windows.onRemoved.removeListener(onClosed)
						chrome.runtime.onMessage.removeListener(listener)
						resolve(false)
					}
				}
				chrome.windows.onRemoved.addListener(onClosed)
			}
		})
	})
}

/**
 * Build the orchestrator bound to the worker's api + vault modules.
 *
 * @param {object} deps { api, vault }
 * @param deps.api
 * @param deps.vault
 * @param deps.loadConfig
 * @return {{ handleCreate: Function, handleGet: Function }}
 */
export function buildPasskeyOrchestrator({ api, vault, loadConfig }) {
	async function handleCreate(options, origin) {
		if (!vault.isUnlocked()) throw new Error('locked')
		const rpId = (options.rp && options.rp.id) || new URL(origin).hostname
		if (!(await requestConsent(rpId, 'create'))) throw new Error('declined')

		const { record, credential } = await createCredential(options, origin) // throws unsupported-algorithm → fall-through
		const config = await loadConfig()
		const typeId = await api.passkeyTypeId(config)
		const encryptedKey = await vault.encryptField(serializePasskey(record))
		await api.createSecret(config, {
			name: record.rpName || rpId,
			url: rpId,
			typeId,
			key: encryptedKey,
			encryptionSuiteId: vault.activeSuiteId(),
		})
		return credential
	}

	async function handleGet(options, origin) {
		if (!vault.isUnlocked()) throw new Error('locked')
		const rpId = options.rpId || new URL(origin).hostname
		const config = await loadConfig()

		// Candidate passkeys for this RP (matched on the plaintext url index).
		const rows = await api.match(config, rpId)
		const allow = (options.allowCredentials || []).map((c) => encodeAllowId(c.id))

		let chosen = null
		for (const row of rows) {
			let record
			try {
				record = parsePasskey(await vault.decryptField(row.key))
			} catch {
				continue
			}
			if (!record || record.rpId !== rpId) continue
			if (allow.length && !allow.includes(record.credentialId)) continue
			chosen = { row, record }
			break
		}
		if (!chosen) throw new Error('no-credential') // fall-through to platform

		if (!(await requestConsent(rpId, 'get'))) throw new Error('declined')

		const { assertion, counter } = await getAssertion(options, origin, chosen.record)

		// Write back a non-zero (hardware-style) counter via the existing update path.
		if (counter > 0 && counter !== chosen.record.counter) {
			const updated = { ...chosen.record, counter }
			const encryptedKey = await vault.encryptField(serializePasskey(updated))
			await api.updateSecret(config, chosen.row.id, {
				name: chosen.row.name,
				url: chosen.row.url,
				typeId: chosen.row.typeId,
				key: encryptedKey,
				encryptionSuiteId: vault.activeSuiteId(),
			})
		}
		return assertion
	}

	return { handleCreate, handleGet }
}

// allowCredentials ids arrive as base64url strings or byte arrays; normalise to
// the base64url form the stored record uses.
function encodeAllowId(id) {
	if (typeof id === 'string') return id
	const bytes = Array.isArray(id) ? Uint8Array.from(id) : new Uint8Array(id)
	let s = ''
	for (const b of bytes) s += String.fromCharCode(b)
	return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')
}
