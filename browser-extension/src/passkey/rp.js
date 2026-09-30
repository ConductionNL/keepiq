/**
 * The relying-party check for passkey requests (clients-passkey-origin).
 *
 * In the page-context shim path the extension IS the authenticator, so the
 * WebAuthn origin rule the browser would apply is ours to apply: the rpId must
 * equal the requesting host or be a parent domain of it that is not a public
 * suffix, and the origin must be secure. The origin itself comes from the
 * browser (the message sender), never from the page.
 *
 * @spec openspec/specs/clients-passkey-origin/spec.md
 */

import { isPublicSuffix } from '../lib/match.js'

/**
 * Whether a page at `origin` may use a passkey for `rpId`.
 *
 * @param {string} rpId The relying party id the request names.
 * @param {string} origin The requesting frame's origin, as the browser reports it.
 * @return {boolean} True when the request may proceed.
 */
export function rpIdAllowed(rpId, origin) {
	let url
	try {
		url = new URL(origin)
	} catch {
		return false
	}
	const host = url.hostname.toLowerCase()
	const secure = url.protocol === 'https:' || host === 'localhost'
	if (!secure) {
		return false
	}
	const rp = String(rpId || '').toLowerCase()
	if (rp === '') {
		return false
	}
	if (rp === host) {
		return true
	}
	return host.endsWith('.' + rp) && !isPublicSuffix(rp)
}

/**
 * The origin of the frame that sent a runtime message, from the browser's own
 * sender record. Chromium sets `sender.origin`; Firefox sets `sender.url`.
 *
 * @param {object|undefined} sender The runtime.MessageSender.
 * @return {string} The origin, or '' when the sender has none.
 */
export function senderOrigin(sender) {
	if (sender && typeof sender.origin === 'string' && sender.origin !== 'null') {
		return sender.origin
	}
	try {
		return new URL(sender?.url || '').origin
	} catch {
		return ''
	}
}
