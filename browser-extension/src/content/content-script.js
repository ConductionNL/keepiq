/**
 * Content script — runs in every frame (`all_frames: true`), including the
 * login-form iframes the incumbent's extension fails to fill (design:
 * "Content-script fill covers iframes explicitly").
 *
 * Responsibilities:
 *  - detect username/password fields in this document,
 *  - fill a credential the background worker sends (only after explicit user
 *    selection in the popup — the content script never decides to fill),
 *  - capture a submitted credential and offer it to the worker for save/update,
 *  - detect a one-time-code field and fill a TOTP code (extension-totp-autofill),
 *  - relay page-context WebAuthn ceremonies (extension-passkey-provider).
 *
 * No secret is ever stored here; the worker owns all key material.
 */

import { frameMayFill } from '../lib/fillScope.js'
import { watchForOtpField } from './otp-watch.js'
import { attachPasswordSuggestions } from './password-suggest.js'
import { showSavePrompt } from './save-prompt.js'

const USERNAME_SELECTORS = [
	'input[autocomplete="username"]',
	'input[autocomplete="email"]',
	'input[type="email"]',
	'input[name*="user" i]',
	'input[name*="email" i]',
	'input[id*="user" i]',
	'input[id*="email" i]',
]
const PASSWORD_SELECTORS = [
	'input[type="password"]',
	'input[autocomplete="current-password"]',
]
const OTP_SELECTORS = [
	'input[autocomplete="one-time-code"]',
	'input[name*="otp" i]',
	'input[name*="totp" i]',
	'input[id*="otp" i]',
	'input[inputmode="numeric"][maxlength="6"]',
]

function visible(el) {
	if (!el || el.disabled || el.readOnly) return false
	const rect = el.getBoundingClientRect()
	if (rect.width === 0 && rect.height === 0) return false
	const style = getComputedStyle(el)
	return style.visibility !== 'hidden' && style.display !== 'none'
}

function firstVisible(selectors) {
	for (const sel of selectors) {
		for (const el of document.querySelectorAll(sel)) {
			if (visible(el)) return el
		}
	}
	return null
}

/** Detect the login field pair in this frame. */
function detectLoginFields() {
	const password = firstVisible(PASSWORD_SELECTORS)
	let username = firstVisible(USERNAME_SELECTORS)
	// If no explicit username field, take a preceding visible text input.
	if (!username && password) {
		const inputs = Array.from(document.querySelectorAll('input'))
		const pwIndex = inputs.indexOf(password)
		for (let i = pwIndex - 1; i >= 0; i--) {
			const t = (inputs[i].type || 'text').toLowerCase()
			if (
				(t === 'text' || t === 'email' || t === 'tel')
				&& visible(inputs[i])
			) {
				username = inputs[i]
				break
			}
		}
	}
	return { username, password }
}

/**
 * Fire the input/change events frameworks (React/Vue) listen for.
 * @param el
 * @param value
 */
function setValue(el, value) {
	if (!el) return
	const proto = Object.getPrototypeOf(el)
	const setter = Object.getOwnPropertyDescriptor(proto, 'value')?.set
	if (setter) {
		setter.call(el, value)
	} else {
		el.value = value
	}
	el.dispatchEvent(new Event('input', { bubbles: true }))
	el.dispatchEvent(new Event('change', { bubbles: true }))
}

function fillCredential({ login, secret }) {
	const { username, password } = detectLoginFields()
	let filled = false
	if (username && login) {
		username.focus()
		setValue(username, login)
		filled = true
	}
	if (password && secret) {
		password.focus()
		setValue(password, secret)
		filled = true
	}
	return filled
}

/**
 * Fill a one-time code into a detected OTP field, if any (best-effort).
 * @param code
 */
function fillOtp(code) {
	const field = firstVisible(OTP_SELECTORS)
	if (!field) return false
	field.focus()
	setValue(field, code)
	return true
}

function reportHasLoginForm() {
	const { username, password } = detectLoginFields()
	return {
		hasPassword: !!password,
		hasUsername: !!username,
		hasOtp: !!firstVisible(OTP_SELECTORS),
	}
}

// --- submit capture (save/update prompt) ---

function attachSubmitCapture() {
	document.addEventListener('submit', onSubmit, true)
	// SPA logins often don't fire submit; also capture on password-field blur+enter.
	document.addEventListener(
		'keydown',
		(e) => {
			if (e.key === 'Enter') captureCurrent()
		},
		true,
	)
}

function onSubmit() {
	captureCurrent()
}

// One capture per submit: Enter and submit both fire for the same form.
let lastCaptured = ''

/**
 * Send the submitted login to the worker and show its offer in the page at
 * once (clients-save-prompt). The worker decides save, update or nothing; the
 * popup keeps the same offer as a fallback.
 *
 * @return {Promise<void>}
 */
async function captureCurrent() {
	const { username, password } = detectLoginFields()
	if (!password || !password.value) return
	const login = username ? username.value : ''
	const stamp = login + '\u0000' + password.value
	if (stamp === lastCaptured) return
	lastCaptured = stamp
	let offer
	try {
		offer = await chrome.runtime.sendMessage({
			type: 'capture-credential',
			payload: {
				host: location.hostname,
				url: location.origin,
				login,
				secret: password.value,
			},
		})
	} catch {
		// The worker may be asleep; the capture is best-effort.
		return
	}
	if (window.top !== window) return // one bar, in the top frame's view only
	if (!offer) return
	if (offer.action === 'refused') {
		// Nothing to decide: the policy refused the password, so only explain.
		await showSavePrompt(offer, location.hostname)
		return
	}
	if (offer.action !== 'save' && offer.action !== 'update') return
	const choice = await showSavePrompt(offer, location.hostname)
	try {
		await chrome.runtime.sendMessage({
			type: 'capture-decision',
			payload: { choice },
		})
	} catch {
		// The popup still offers the capture.
	}
}

// --- message handling from the popup / background worker ---

chrome.runtime.onMessage.addListener((msg, sender, sendResponse) => {
	switch (msg?.type) {
		case 'detect-login':
			sendResponse(reportHasLoginForm())
			return true
		case 'fill-credential':
			// A frame of another site (an embedded widget, an advert) stays
			// silent, so the answer comes from a frame that may fill (#740).
			if (!frameMayFill(location.hostname, msg.payload?.host)) {
				return false
			}
			sendResponse({ filled: fillCredential(msg.payload) })
			return true
		case 'fill-otp':
			if (!frameMayFill(location.hostname, msg.payload?.host)) {
				return false
			}
			sendResponse({ filled: fillOtp(msg.payload?.code) })
			return true
		default:
			return false
	}
})

attachSubmitCapture()

// A strong password for a sign-up or change-password field, generated by the
// worker under the org policy (clients-extension-generator-vault-send).
attachPasswordSuggestions(document, () =>
	chrome.runtime.sendMessage({ type: 'generate-for-field', payload: {} }),
)

// A code field on the step after the login: tell the worker, which fills it
// only when a login fill on this site in this tab asked for it.
watchForOtpField({
	doc: document,
	find: () => firstVisible(OTP_SELECTORS),
	report: () => {
		chrome.runtime
			.sendMessage({ type: 'otp-field-detected', payload: {} })
			.catch(() => {})
	},
})

// --- WebAuthn relay (extension-passkey-provider, page-context shim path) ---

// Inject the page-context shim so it can override navigator.credentials in the
// page's own JS world (a content script's overrides are not visible to the page).
function injectShim() {
	try {
		const s = document.createElement('script')
		s.src = chrome.runtime.getURL('inpage-shim.js')
		s.onload = () => s.remove()
		;(document.head || document.documentElement).appendChild(s)
	} catch {
		// CSP may block injection; the page then keeps the browser's own authenticator.
	}
}

// Relay page shim → service worker → page.
window.addEventListener('message', async (event) => {
	if (event.source !== window) return
	const data = event.data
	if (!data || data.__keepiq !== 'request') return
	const type = data.op === 'create' ? 'webauthn-create' : 'webauthn-get'
	try {
		const res = await chrome.runtime.sendMessage({
			type,
			// The worker takes the origin from the browser's sender record; this
			// is only informative and never the page's own claim.
			payload: { options: data.options, origin: location.origin },
		})
		window.postMessage(
			{
				__keepiq: 'response',
				id: data.id,
				...(res || { error: 'no-response' }),
			},
			event.origin,
		)
	} catch (e) {
		window.postMessage(
			{ __keepiq: 'response', id: data.id, error: e.message || String(e) },
			event.origin,
		)
	}
})

// Only the top frame injects the shim (avoids duplicate overrides in iframes).
if (window.top === window) {
	injectShim()
}
