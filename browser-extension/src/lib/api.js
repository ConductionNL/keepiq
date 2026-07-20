/**
 * Doriath API client for the extension. Authenticates with the paired Nextcloud
 * app-password (HTTP Basic) — never a login password, never a new long-lived
 * Doriath secret (browser-extension-autofill §"Pairing"). Every response is an
 * encrypted blob or plaintext index field; the server never returns a decrypted
 * value.
 *
 * Config ({ url, user, appPassword }) lives in `storage.local`; the app-password
 * is a device-scoped, NC-revocable credential. No key material is stored here —
 * the master password and the derived CryptoKey never touch storage.
 */

const CONFIG_KEY = 'doriath.config'

/** Load the paired config from storage.local (or null if unpaired). */
export async function loadConfig() {
	const data = await chrome.storage.local.get(CONFIG_KEY)
	return data[CONFIG_KEY] || null
}

/**
 * Persist the paired config (non-sensitive: url, user, app-password).
 * @param config
 */
export async function saveConfig(config) {
	await chrome.storage.local.set({ [CONFIG_KEY]: config })
}

/** Clear the pairing. */
export async function clearConfig() {
	await chrome.storage.local.remove(CONFIG_KEY)
}

function authHeader(config) {
	return 'Basic ' + btoa(`${config.user}:${config.appPassword}`)
}

function base(config) {
	return String(config.url).replace(/\/+$/, '')
}

async function request(config, method, path, body) {
	const res = await fetch(base(config) + '/index.php/apps/doriath' + path, {
		method,
		headers: {
			Authorization: authHeader(config),
			'Content-Type': 'application/json',
			'OCS-APIRequest': 'true',
			Accept: 'application/json',
		},
		body: body ? JSON.stringify(body) : undefined,
	})
	if (!res.ok) {
		const text = await res.text().catch(() => '')
		const err = new Error(`Doriath ${method} ${path} failed (${res.status})`)
		err.status = res.status
		err.body = text
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
 * Acknowledge unpairing (revocation is the NC app-password).
 * @param config
 */
export function unpair(config) {
	return request(config, 'POST', '/api/v1/extension/unpair')
}

/**
 * Fetch the caller's active EncryptionSuite (private-key envelope + certificate).
 * @param config
 */
export async function fetchActiveSuite(config) {
	const suites = await request(config, 'GET', '/api/v1/suites')
	const list = Array.isArray(suites) ? suites : (suites.items || [])
	const active = list.find((s) => s.status === 'active')
	if (!active) throw new Error('no active encryption suite')
	return active
}

/**
 * URL-match secrets for a host — returns blob rows (ciphertext key/login).
 * @param config
 * @param host
 */
export async function match(config, host) {
	const data = await request(config, 'GET', '/api/v1/extension/match?host=' + encodeURIComponent(host))
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
 * Fetch the secret-type catalogue and return the id of a type by name/slug.
 * @param config
 * @param name
 */
export async function typeIdByName(config, name) {
	const types = await request(config, 'GET', '/api/v1/secret-types')
	const list = Array.isArray(types) ? types : (types.items || [])
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
