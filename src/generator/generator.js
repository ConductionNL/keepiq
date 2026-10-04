/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Client-side key generator.
 *
 * Generates passwords, API keys and tokens in the browser, so the value is
 * never seen by the server: only its ciphertext is ever sent. This is a port
 * of the server's KeyGeneratorService and KeyGeneratorRegexParser with the
 * same options, the same org policy clamp and the same error messages. The
 * browser extension imports it verbatim, as it does `src/totp`.
 *
 * Pure module: the org policy is passed in, and the random source can be
 * swapped for tests.
 *
 * @spec openspec/specs/key-generator/spec.md#requirement-default-generation
 * @spec openspec/specs/org-password-policies/spec.md#requirement-generator-locked-to-policy
 */

import { EFF_LARGE_WORDLIST } from './eff-large-wordlist.js'

export const MIN_LENGTH = 8
export const MAX_LENGTH = 128
export const MIN_CHARSET_SIZE = 2
const MAX_REGEX_ATTEMPTS = 3
export const MIN_WORDS = 4
export const MAX_WORDS = 12
export const DEFAULT_WORDS = 5

const UPPERCASE = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ'
const LOWERCASE = 'abcdefghijklmnopqrstuvwxyz'
const DIGITS = '0123456789'
const SPECIAL = '!@#$%^&*()-_=+[]{}|;:,.<>?/'
/** Characters that are easy to mistake for one another. */
const AMBIGUOUS = 'IOl01'

/** Thrown for a request the generator refuses; `message` is user-facing. */
export class GeneratorError extends Error {
	/**
	 * @param {string} message Why the request was refused.
	 * @param {object} [options] Error options, such as the `cause`.
	 */
	constructor(message, options) {
		super(message, options)
		this.name = 'GeneratorError'
	}
}

/**
 * A uniform random integer in [min, max], by rejection sampling over
 * `crypto.getRandomValues`, so no value is favoured (no modulo bias).
 *
 * @param {number} min Lowest value, inclusive.
 * @param {number} max Highest value, inclusive.
 * @return {number}
 * @spec exclude Random primitive; behaviour covered through generateKey.
 */
export function randomInt(min, max) {
	const range = max - min + 1
	if (range <= 1) {
		return min
	}
	const limit = Math.floor(0x100000000 / range) * range
	const buffer = new Uint32Array(1)
	let value
	do {
		globalThis.crypto.getRandomValues(buffer)
		value = buffer[0]
	} while (value >= limit)
	return min + (value % range)
}

/**
 * The generator-relevant part of the org policy, or null when no policy
 * applies. Accepts the shape of `GET /api/settings/policy`.
 *
 * @param {object|null|undefined} raw The policy as the server returns it.
 * @return {{minLength: number, requireUpper: boolean, requireLower: boolean,
 *   requireDigit: boolean, requireSymbol: boolean}|null}
 * @spec openspec/specs/org-password-policies/spec.md#requirement-generator-locked-to-policy
 */
export function generatorPolicy(raw) {
	if (!raw || raw.policy_enabled !== true) {
		return null
	}
	const floor = Number.parseInt(raw.generator_min_length ?? 12, 10)
	return {
		minLength: Math.max(MIN_LENGTH, Number.isNaN(floor) ? 12 : floor),
		requireUpper: raw.generator_require_upper === true,
		requireLower: raw.generator_require_lower === true,
		requireDigit: raw.generator_require_digit === true,
		requireSymbol: raw.generator_require_symbol === true,
		allowPassphrase: raw.generator_allow_passphrase !== false,
	}
}

/**
 * Whether the organisation lets its members generate passphrases.
 *
 * @param {object|null|undefined} raw The policy as the server returns it.
 * @return {boolean}
 * @spec openspec/changes/client-side-key-generator/specs/passphrase-generator/spec.md#requirement-passphrases-follow-the-organisation-password-policy
 */
export function passphraseAllowed(raw) {
	return generatorPolicy(raw)?.allowPassphrase ?? true
}

/**
 * The character classes the policy requires, by label.
 *
 * @param {object} policy A normalised policy.
 * @return {Array<[string, string]>}
 */
function requiredClasses(policy) {
	const classes = []
	if (policy.requireUpper) {
		classes.push(['uppercase', UPPERCASE])
	}
	if (policy.requireLower) {
		classes.push(['lowercase', LOWERCASE])
	}
	if (policy.requireDigit) {
		classes.push(['digit', DIGITS])
	}
	if (policy.requireSymbol) {
		classes.push(['symbol', SPECIAL])
	}
	return classes
}

/**
 * Whether the two strings share a character.
 *
 * @param {string} a First set.
 * @param {string} b Second set.
 * @return {boolean}
 */
function intersects(a, b) {
	for (const char of b) {
		if (a.includes(char)) {
			return true
		}
	}
	return false
}

/**
 * Remove duplicates, keeping first occurrences in order.
 *
 * @param {Iterable<string>} chars The characters.
 * @return {string}
 */
function dedupe(chars) {
	return [...new Set(chars)].join('')
}

/**
 * Refuse a length outside the supported range.
 *
 * @param {number} length The requested length.
 */
function assertLengthInRange(length) {
	if (!Number.isInteger(length) || length < MIN_LENGTH) {
		throw new GeneratorError(`Length must be at least ${MIN_LENGTH} characters`)
	}
	if (length > MAX_LENGTH) {
		throw new GeneratorError(`Length must not exceed ${MAX_LENGTH} characters`)
	}
}

/**
 * Refuse a character set too small to generate from.
 *
 * @param {string} charset The resolved set.
 */
function assertCharsetViable(charset) {
	if (charset.length === 0) {
		throw new GeneratorError('The character set is empty after exclusions')
	}
	if (charset.length < MIN_CHARSET_SIZE) {
		throw new GeneratorError(
			`The character set must contain at least ${MIN_CHARSET_SIZE} distinct characters`,
		)
	}
}

/**
 * A string of `length` characters drawn uniformly from `charset`.
 *
 * @param {string} charset The characters to draw from.
 * @param {number} length The length.
 * @param {(min: number, max: number) => number} rand The random-integer source.
 * @return {string}
 */
function buildString(charset, length, rand) {
	let result = ''
	for (let i = 0; i < length; i++) {
		result += charset[rand(0, charset.length - 1)]
	}
	return result
}

/**
 * Put one character of every required class the result lacks into it, each
 * at a different position.
 *
 * @param {string} result The generated value.
 * @param {object} policy A normalised policy.
 * @param {string} charset The resolved set.
 * @param {(min: number, max: number) => number} rand The random-integer source.
 * @return {string}
 */
function forceRequiredClasses(result, policy, charset, rand) {
	const chars = [...result]
	const used = new Set()
	for (const [, classSet] of requiredClasses(policy)) {
		if (intersects(result, classSet)) {
			continue
		}
		const allowed = [...classSet].filter((c) => charset.includes(c))
		if (allowed.length === 0) {
			continue
		}
		let position
		do {
			position = rand(0, chars.length - 1)
		} while (used.has(position))
		used.add(position)
		chars[position] = allowed[rand(0, allowed.length - 1)]
	}
	return chars.join('')
}

/**
 * Generate from the built-in character classes.
 *
 * @param {object} options The request.
 * @param {object|null} policy A normalised policy.
 * @param {(min: number, max: number) => number} rand The random-integer source.
 * @return {string}
 * @spec openspec/changes/clients-extension-finish/specs/extension-generator-policy/spec.md#requirement-every-chosen-kind-of-character-appears
 */
function generateFromCharset(options, policy, rand) {
	let length = Number(options.length ?? 16)
	let includeUpper = options.includeUppercase !== false
	let includeLower = options.includeLowercase !== false
	let includeDigits = options.includeDigits !== false
	let includeSpecial = options.includeSpecialCharacters !== false
	let minDigits = Math.max(0, Number(options.minDigits ?? 0) || 0)
	let minSpecial = Math.max(0, Number(options.minSpecial ?? 0) || 0)
	const excluded = new Set(options.excludedCharacters ?? '')
	if (options.avoidAmbiguous === true) {
		for (const char of AMBIGUOUS) {
			excluded.add(char)
		}
	}

	// Org policy clamp: the length is raised to the floor, and required
	// classes are switched on, forced into the set and into the output.
	if (policy !== null) {
		length = Math.max(length, policy.minLength)
		includeUpper = includeUpper || policy.requireUpper
		includeLower = includeLower || policy.requireLower
		includeDigits = includeDigits || policy.requireDigit
		includeSpecial = includeSpecial || policy.requireSymbol
	}
	if (!includeUpper && !includeLower && !includeDigits && !includeSpecial) {
		throw new GeneratorError('Choose at least one kind of character')
	}
	minDigits = includeDigits ? minDigits : 0
	minSpecial = includeSpecial ? minSpecial : 0
	// The minimums must fit: the length grows to hold them.
	length = Math.max(length, minDigits + minSpecial)
	assertLengthInRange(length)

	let charset =
		(includeUpper ? UPPERCASE : '')
		+ (includeLower ? LOWERCASE : '')
		+ (includeDigits ? DIGITS : '')
		+ (includeSpecial ? SPECIAL : '')
	charset = dedupe([...charset].filter((c) => !excluded.has(c)))
	if (policy !== null) {
		// An exclusion list may not hollow out a required class.
		for (const [, classSet] of requiredClasses(policy)) {
			if (!intersects(charset, classSet)) {
				charset += classSet
			}
		}
	}
	assertCharsetViable(charset)

	let result = buildString(charset, length, rand)
	// Every chosen kind appears at least once, on top of the minimums.
	result = ensureMinimums(
		result,
		charset,
		[
			[UPPERCASE, includeUpper ? 1 : 0],
			[LOWERCASE, includeLower ? 1 : 0],
			[DIGITS, includeDigits ? Math.max(minDigits, 1) : 0],
			[SPECIAL, includeSpecial ? Math.max(minSpecial, 1) : 0],
		],
		rand,
	)
	return policy !== null
		? forceRequiredClasses(result, policy, charset, rand)
		: result
}

/**
 * Make sure the value holds at least `count` characters of each class, by
 * replacing characters of other classes at random positions.
 *
 * @param {string} value The generated value.
 * @param {string} charset The resolved set.
 * @param {Array<[string, number]>} minimums Class set and minimum count.
 * @param {(min: number, max: number) => number} rand The random-integer source.
 * @return {string}
 */
function ensureMinimums(value, charset, minimums, rand) {
	const chars = [...value]
	const reserved = new Set()
	for (const [classSet, count] of minimums) {
		const allowed = [...classSet].filter((c) => charset.includes(c))
		if (count === 0 || allowed.length === 0) {
			continue
		}
		const have = chars
			.map((c, i) => (classSet.includes(c) ? i : -1))
			.filter((i) => i >= 0)
		for (const i of have.slice(0, count)) {
			reserved.add(i)
		}
		let missing = count - Math.min(have.length, count)
		while (missing > 0) {
			const free = chars.map((c, i) => i).filter((i) => !reserved.has(i))
			if (free.length === 0) {
				break
			}
			const position = free[rand(0, free.length - 1)]
			chars[position] = allowed[rand(0, allowed.length - 1)]
			reserved.add(position)
			missing--
		}
	}
	return chars.join('')
}

/**
 * Turn a pattern into a RegExp, accepting PHP-style delimiters (`/…/i`,
 * `#…#`, `~…~`) as the server does.
 *
 * @param {string} pattern The pattern as the user typed it.
 * @return {RegExp}
 */
export function compilePattern(pattern) {
	let body = pattern
	let flags = ''
	const first = pattern[0]
	if (['/', '#', '~'].includes(first) && pattern.length >= 2) {
		const end = pattern.lastIndexOf(first)
		if (end > 0 && /^[a-zA-Z]*$/.test(pattern.slice(end + 1))) {
			body = pattern.slice(1, end)
			flags = pattern.slice(end + 1).replace(/[^imsu]/g, '')
		}
	}
	try {
		return new RegExp(body, flags)
	} catch (e) {
		throw new GeneratorError('The regex pattern is syntactically invalid', {
			cause: e,
		})
	}
}

/**
 * The length window from the first `{n}` or `{n,m}` quantifier.
 *
 * @param {string} regex The pattern.
 * @return {[number, number]}
 */
export function extractLength(regex) {
	const match = regex.match(/\{(\d+)(?:,(\d+))?\}/)
	if (!match) {
		throw new GeneratorError(
			'The regex must contain a length quantifier (e.g. {16} or {8,16})',
		)
	}
	const min = Number.parseInt(match[1], 10)
	const max = match[2] !== undefined ? Number.parseInt(match[2], 10) : min
	if (max < min) {
		throw new GeneratorError('The regex length range is invalid (max < min)')
	}
	return [min, max]
}

/**
 * Expand one escape inside a character class.
 *
 * @param {string} escape The character after the backslash.
 * @return {string[]}
 */
function expandEscape(escape) {
	switch (escape) {
		case 'd':
			return [...DIGITS]
		case 'w':
			return [...(UPPERCASE + LOWERCASE + DIGITS + '_')]
		case 's':
			// Whitespace is not a useful generation set; a space stands for it.
			return [' ']
		default:
			return [escape]
	}
}

/**
 * Expand a character-class body (`a-zA-Z0-9_\-`) into its characters.
 *
 * @param {string} body The text between the brackets.
 * @return {string[]}
 */
function expandCharacterClass(body) {
	const chars = []
	let i = 0
	while (i < body.length) {
		const char = body[i]
		if (char === '\\' && i + 1 < body.length) {
			chars.push(...expandEscape(body[i + 1]))
			i += 2
			continue
		}
		if (i + 2 < body.length && body[i + 1] === '-' && body[i + 2] !== ']') {
			const start = body.charCodeAt(i)
			const end = body.charCodeAt(i + 2)
			if (end >= start) {
				for (let code = start; code <= end; code++) {
					chars.push(String.fromCharCode(code))
				}
				i += 3
				continue
			}
		}
		chars.push(char)
		i += 1
	}
	return chars
}

/**
 * The printable ASCII characters not in `disallowed`.
 *
 * @param {string[]} disallowed The negated class's characters.
 * @return {string[]}
 */
function complementAscii(disallowed) {
	const blocked = new Set(disallowed)
	const allowed = []
	for (let code = 0x21; code <= 0x7e; code++) {
		const char = String.fromCharCode(code)
		if (!blocked.has(char)) {
			allowed.push(char)
		}
	}
	return allowed
}

/**
 * The character set of the first character class in the pattern.
 *
 * @param {string} regex The pattern.
 * @return {string}
 */
export function extractCharset(regex) {
	const match = regex.match(/\[(\^?)((?:\\.|[^\]\\])*)\]/)
	if (!match) {
		throw new GeneratorError(
			'The regex must contain a character class (e.g. [a-zA-Z0-9])',
		)
	}
	let allowed = expandCharacterClass(match[2])
	if (match[1] === '^') {
		allowed = complementAscii(allowed)
	}
	return dedupe(allowed)
}

/**
 * Generate from a regex: a length within its quantifier, characters from its
 * first class, checked against the pattern itself.
 *
 * @param {string} regex The pattern.
 * @param {object|null} policy A normalised policy.
 * @param {(min: number, max: number) => number} rand The random-integer source.
 * @return {string}
 */
function generateFromRegex(regex, policy, rand) {
	const compiled = compilePattern(regex)
	const [quantifierMin, maxLength] = extractLength(regex)
	let minLength = quantifierMin
	const charset = extractCharset(regex)

	if (minLength < MIN_LENGTH) {
		throw new GeneratorError(
			`The regex length must be at least ${MIN_LENGTH} characters`,
		)
	}
	// A pattern that cannot meet the policy is refused, never weakened.
	if (policy !== null) {
		if (maxLength < policy.minLength) {
			throw new GeneratorError(
				`The regex cannot reach the org policy minimum length of ${policy.minLength} characters`,
			)
		}
		for (const [label, classSet] of requiredClasses(policy)) {
			if (!intersects(charset, classSet)) {
				throw new GeneratorError(
					`The regex excludes the ${label} characters the org policy requires`,
				)
			}
		}
		minLength = Math.max(minLength, Math.min(policy.minLength, maxLength))
	}
	assertCharsetViable(charset)

	for (let attempt = 0; attempt < MAX_REGEX_ATTEMPTS; attempt++) {
		const candidate = buildString(charset, rand(minLength, maxLength), rand)
		compiled.lastIndex = 0
		if (compiled.test(candidate)) {
			return candidate
		}
	}
	throw new GeneratorError(
		'Unable to generate a value matching the supplied regex',
	)
}

/**
 * Generate a key in the browser.
 *
 * @param {object} [options] The request.
 * @param {number} [options.length] Length, 8 to 128 (default 16).
 * @param {boolean} [options.includeSpecialCharacters] Add symbols (default true).
 * @param {string} [options.excludedCharacters] Characters to leave out.
 * @param {string} [options.regex] A pattern; when set the other options are ignored.
 * @param {object|null} [rawPolicy] The org policy as `GET /api/settings/policy` returns it.
 * @param {(min: number, max: number) => number} [rand] Random-integer source (tests only).
 * @return {string}
 * @throws {GeneratorError} When the request cannot be met.
 * @spec openspec/specs/key-generator/spec.md#requirement-default-generation
 * @spec openspec/specs/org-password-policies/spec.md#requirement-generator-locked-to-policy
 */
export function generateKey(options = {}, rawPolicy = null, rand = randomInt) {
	const policy = generatorPolicy(rawPolicy)
	if (options.regex) {
		return generateFromRegex(options.regex, policy, rand)
	}
	return generateFromCharset(options, policy, rand)
}

/**
 * Generate a passphrase in the browser: words drawn uniformly from the EFF
 * large word list, joined by a separator.
 *
 * With the org policy on, the passphrase is made to meet it rather than
 * refused: words are added until the length floor is met, words are
 * capitalised when an upper-case letter is required, a digit is added when
 * one is required, and a random symbol becomes the separator when a symbol
 * is required and the chosen separator is not one.
 *
 * @param {object} [options] The request.
 * @param {number} [options.words] Number of words, 4 to 12 (default 5).
 * @param {string} [options.separator] Between the words (default `-`).
 * @param {boolean} [options.capitalise] Capitalise each word (default false).
 * @param {boolean} [options.includeNumber] Add a digit to one word (default false).
 * @param {object|null} [rawPolicy] The org policy as `GET /api/settings/policy` returns it.
 * @param {(min: number, max: number) => number} [rand] Random-integer source (tests only).
 * @return {string}
 * @throws {GeneratorError} When the word count is out of range or the organisation switched passphrases off.
 * @spec openspec/changes/client-side-key-generator/specs/passphrase-generator/spec.md#requirement-generate-a-passphrase
 * @spec openspec/changes/client-side-key-generator/specs/passphrase-generator/spec.md#requirement-passphrases-follow-the-organisation-password-policy
 */
export function generatePassphrase(
	options = {},
	rawPolicy = null,
	rand = randomInt,
) {
	const policy = generatorPolicy(rawPolicy)
	if (policy !== null && !policy.allowPassphrase) {
		throw new GeneratorError('Your organisation has switched passphrases off')
	}

	const count = Number(options.words ?? DEFAULT_WORDS)
	if (!Number.isInteger(count) || count < MIN_WORDS || count > MAX_WORDS) {
		throw new GeneratorError(
			`A passphrase must have ${MIN_WORDS} to ${MAX_WORDS} words`,
		)
	}
	let separator = options.separator ?? '-'
	let capitalise = options.capitalise === true
	let includeNumber = options.includeNumber === true

	if (policy !== null) {
		capitalise = capitalise || policy.requireUpper
		includeNumber = includeNumber || policy.requireDigit
		if (policy.requireSymbol && !intersects(separator, SPECIAL)) {
			separator = SPECIAL[rand(0, SPECIAL.length - 1)]
		}
	}

	const draw = () => {
		const word = EFF_LARGE_WORDLIST[rand(0, EFF_LARGE_WORDLIST.length - 1)]
		return capitalise ? word[0].toUpperCase() + word.slice(1) : word
	}
	const words = Array.from({ length: count }, draw)
	if (includeNumber) {
		const index = rand(0, words.length - 1)
		words[index] += String(rand(0, 9))
	}
	if (policy !== null) {
		while (words.join(separator).length < policy.minLength) {
			words.push(draw())
		}
	}
	return words.join(separator)
}
