/**
 * The Generator tab's options and history: defaults, sanitising against the
 * supported ranges and the org policy, and the history ring. Pure: no DOM,
 * no storage.
 *
 * Ranges follow Keepiq's own generator, which is stricter than Bitwarden's
 * where they differ: a password has at least 8 characters, a passphrase 4 to
 * 12 words.
 *
 * @spec openspec/specs/extension-generator/spec.md#requirement-options-remembered-per-account
 */

import {
	generatorPolicy,
	MAX_LENGTH,
	MAX_WORDS,
	MIN_LENGTH,
	MIN_WORDS,
} from '../../../src/generator/generator.js'

/** How many generated values the history keeps. */
export const MAX_HISTORY = 50

/** The sub-tabs of the Generator tab. */
export const GENERATOR_TABS = Object.freeze(['password', 'passphrase', 'username'])

/** Options for a first use, close to Bitwarden's defaults. */
export const DEFAULT_OPTIONS = Object.freeze({
	tab: 'password',
	password: Object.freeze({
		length: 14,
		includeUppercase: true,
		includeLowercase: true,
		includeDigits: true,
		includeSpecialCharacters: false,
		minDigits: 1,
		minSpecial: 1,
		avoidAmbiguous: true,
	}),
	passphrase: Object.freeze({
		words: 5,
		separator: '-',
		capitalise: false,
		includeNumber: false,
	}),
	username: Object.freeze({
		type: 'word',
		capitalize: true,
		includeNumber: true,
		email: '',
		emailMode: 'random',
		domain: '',
		domainMode: 'random',
	}),
})

/**
 * A whole number within a range, or the fallback.
 *
 * @param {*} value The value.
 * @param {number} min Lowest allowed.
 * @param {number} max Highest allowed.
 * @param {number} fallback Used when value is not a number.
 * @return {number}
 */
function clampInt(value, min, max, fallback) {
	const n = Math.round(Number(value))
	if (!Number.isFinite(n)) {
		return fallback
	}
	return Math.min(max, Math.max(min, n))
}

/**
 * A boolean, or the fallback.
 *
 * @param {*} value The value.
 * @param {boolean} fallback Used when value is not a boolean.
 * @return {boolean}
 */
function bool(value, fallback) {
	return typeof value === 'boolean' ? value : fallback
}

/**
 * Stored options made safe: unknown keys dropped, numbers clamped to the
 * supported ranges and to the org policy, classes the policy requires on.
 *
 * @param {object|null|undefined} raw The stored options.
 * @param {object|null} [rawPolicy] The org policy as the server returns it.
 * @return {object}
 */
export function sanitizeOptions(raw, rawPolicy = null) {
	const d = DEFAULT_OPTIONS
	const p = raw?.password || {}
	const w = raw?.passphrase || {}
	const u = raw?.username || {}
	const policy = generatorPolicy(rawPolicy)
	const password = {
		length: clampInt(p.length, MIN_LENGTH, MAX_LENGTH, d.password.length),
		includeUppercase: bool(p.includeUppercase, d.password.includeUppercase),
		includeLowercase: bool(p.includeLowercase, d.password.includeLowercase),
		includeDigits: bool(p.includeDigits, d.password.includeDigits),
		includeSpecialCharacters: bool(
			p.includeSpecialCharacters,
			d.password.includeSpecialCharacters,
		),
		minDigits: clampInt(p.minDigits, 0, 9, d.password.minDigits),
		minSpecial: clampInt(p.minSpecial, 0, 9, d.password.minSpecial),
		avoidAmbiguous: bool(p.avoidAmbiguous, d.password.avoidAmbiguous),
	}
	if (policy !== null) {
		password.length = Math.max(password.length, policy.minLength)
		password.includeUppercase ||= policy.requireUpper
		password.includeLowercase ||= policy.requireLower
		password.includeDigits ||= policy.requireDigit
		password.includeSpecialCharacters ||= policy.requireSymbol
		// A required class needs at least one character of it.
		if (policy.requireDigit) password.minDigits = Math.max(password.minDigits, 1)
		if (policy.requireSymbol) {
			password.minSpecial = Math.max(password.minSpecial, 1)
		}
	}
	if (
		!password.includeUppercase
		&& !password.includeLowercase
		&& !password.includeDigits
		&& !password.includeSpecialCharacters
	) {
		password.includeLowercase = true
	}
	const tab = GENERATOR_TABS.includes(raw?.tab) ? raw.tab : d.tab
	return {
		tab:
			tab === 'passphrase' && policy !== null && !policy.allowPassphrase
				? 'password'
				: tab,
		password,
		passphrase: {
			words: clampInt(w.words, MIN_WORDS, MAX_WORDS, d.passphrase.words),
			separator:
				typeof w.separator === 'string'
					? w.separator.slice(0, 3)
					: d.passphrase.separator,
			capitalise: bool(w.capitalise, d.passphrase.capitalise),
			includeNumber: bool(w.includeNumber, d.passphrase.includeNumber),
		},
		username: {
			type: ['word', 'plus', 'catchall'].includes(u.type)
				? u.type
				: d.username.type,
			capitalize: bool(u.capitalize, d.username.capitalize),
			includeNumber: bool(u.includeNumber, d.username.includeNumber),
			email: typeof u.email === 'string' ? u.email.slice(0, 254) : '',
			emailMode: u.emailMode === 'website' ? 'website' : 'random',
			domain: typeof u.domain === 'string' ? u.domain.slice(0, 253) : '',
			domainMode: u.domainMode === 'website' ? 'website' : 'random',
		},
	}
}

/**
 * The history with a new value first, at most MAX_HISTORY long.
 *
 * @param {Array<object>} history The current history, newest first.
 * @param {{value: string, kind: string}} entry The new value and its kind.
 * @param {number} [now] The time, in ms.
 * @return {Array<object>}
 */
export function addToHistory(history, entry, now = Date.now()) {
	const list = Array.isArray(history) ? history : []
	return [
		{
			value: String(entry.value),
			kind: String(entry.kind || 'password'),
			at: now,
		},
		...list,
	].slice(0, MAX_HISTORY)
}

/**
 * How long ago, in words.
 *
 * @param {number} at The time of the entry, in ms.
 * @param {number} [now] The current time, in ms.
 * @return {string}
 */
export function relativeTime(at, now = Date.now()) {
	const seconds = Math.max(0, Math.round((now - at) / 1000))
	if (seconds < 60) return 'just now'
	const minutes = Math.round(seconds / 60)
	if (minutes < 60)
		return minutes === 1 ? '1 minute ago' : `${minutes} minutes ago`
	const hours = Math.round(minutes / 60)
	return hours === 1 ? '1 hour ago' : `${hours} hours ago`
}
