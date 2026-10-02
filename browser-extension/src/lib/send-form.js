/**
 * Send form rules for the popup: expiry presets, view bounds, the credential
 * body and the row label. Pure: no DOM, no network.
 *
 * @spec openspec/changes/clients-extension-generator-vault-send/specs/extension-send/spec.md#requirement-create-a-send-from-the-popup
 */

/** Most views a send may allow (EphemeralSendService::MAX_VIEWS_CAP). */
export const MAX_VIEWS_CAP = 100

/** Longest custom expiry, in hours (30 days). */
export const MAX_CUSTOM_HOURS = 720

/** The expiry presets, in the order the form shows them. */
export const EXPIRY_PRESETS = Object.freeze([
	{ id: '1h', label: '1 hour', seconds: 3600 },
	{ id: '1d', label: '1 day', seconds: 86400 },
	{ id: '2d', label: '2 days', seconds: 2 * 86400 },
	{ id: '3d', label: '3 days', seconds: 3 * 86400 },
	{ id: '7d', label: '7 days', seconds: 7 * 86400 },
	{ id: '30d', label: '30 days', seconds: 30 * 86400 },
	{ id: 'custom', label: 'Custom', seconds: null },
])

/**
 * The expiry in seconds, or an error to show under the field.
 *
 * @param {string} presetId One of EXPIRY_PRESETS' ids.
 * @param {string|number} [customHours] Hours for the Custom preset.
 * @return {{ttlSeconds: number}|{error: string}}
 */
export function expirySeconds(presetId, customHours) {
	const preset = EXPIRY_PRESETS.find((p) => p.id === presetId)
	if (!preset) {
		return { error: 'Choose when the send expires' }
	}
	if (preset.seconds !== null) {
		return { ttlSeconds: preset.seconds }
	}
	const hours = Number(customHours)
	if (!Number.isInteger(hours) || hours < 1) {
		return { error: 'Enter a whole number of hours, at least 1' }
	}
	if (hours > MAX_CUSTOM_HOURS) {
		return { error: 'At most 720 hours (30 days)' }
	}
	return { ttlSeconds: hours * 3600 }
}

/**
 * The view limit, or an error to show under the field.
 *
 * @param {string|number} value The entered limit.
 * @return {{maxViews: number}|{error: string}}
 */
export function maxViewsFrom(value) {
	const views = Number(value)
	if (!Number.isInteger(views) || views < 1 || views > MAX_VIEWS_CAP) {
		return { error: `Between 1 and ${MAX_VIEWS_CAP} views` }
	}
	return { maxViews: views }
}

/**
 * The plaintext of a credential send: exactly two lines, as the recipient
 * page shows the payload as plain text.
 *
 * @param {string} username The username.
 * @param {string} password The password.
 * @return {string}
 */
export function credentialPayload(username, password) {
	return `Username: ${username}\nPassword: ${password}`
}

/**
 * The label of a send in the list: its kind and when it was made, since
 * sends have no name.
 *
 * @param {{payloadType: string, createdAt: string}} send The send row.
 * @param {string} [locale] The display locale (default: the browser's).
 * @return {string}
 */
export function sendRowLabel(send, locale) {
	const kind = send.payloadType === 'credential' ? 'Credential send' : 'Text send'
	const created = new Date(send.createdAt)
	if (Number.isNaN(created.getTime())) {
		return kind
	}
	const when = created.toLocaleString(locale, {
		dateStyle: 'medium',
		timeStyle: 'short',
	})
	return `${kind}, ${when}`
}
