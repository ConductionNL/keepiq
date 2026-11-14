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
 * browser extension has its own TypeScript port, held in step by
 * `tests/vectors/generator/cases.json`.
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
/**
 * The list without its four hyphenated words (drop-down, felt-tip, t-shirt,
 * yo-yo): with the `-` separator they let two word sequences give the same
 * passphrase.
 */
const PASSPHRASE_WORDS = EFF_LARGE_WORDLIST.filter((word) => !word.includes('-'))

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
		// The server stores any floor of 8 or more; above 128 nothing could be generated.
		minLength: Math.min(
			MAX_LENGTH,
			Math.max(MIN_LENGTH, Number.isNaN(floor) ? 12 : floor),
		),
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
 * Fisher–Yates shuffle in place: every arrangement is equally likely, so a
 * character forced in for a minimum may land on any position.
 *
 * @param {string[]} chars The characters.
 * @param {(min: number, max: number) => number} rand The random-integer source.
 * @return {string[]}
 */
function shuffle(chars, rand) {
	for (let i = chars.length - 1; i > 0; i--) {
		const j = rand(0, i)
		const swap = chars[i]
		chars[i] = chars[j]
		chars[j] = swap
	}
	return chars
}

/**
 * Generate from the built-in character classes: a uniform draw from the set,
 * topped up to the minimum of every chosen class, then shuffled.
 *
 * @param {object} options The request.
 * @param {object|null} policy A normalised policy.
 * @param {(min: number, max: number) => number} rand The random-integer source.
 * @return {string}
 * @spec openspec/specs/extension-generator-policy/spec.md#requirement-every-chosen-kind-of-character-appears
 * @spec openspec/specs/extension-generator/spec.md#requirement-password-options
 * @spec openspec/specs/org-password-policies/spec.md#requirement-generator-locked-to-policy
 */
function generateFromCharset(options, policy, rand) {
	const spec = readSpec(options, policy)
	if (
		!spec.includeUppercase
		&& !spec.includeLowercase
		&& !spec.includeDigits
		&& !spec.includeSpecialCharacters
	) {
		throw new GeneratorError('Choose at least one kind of character')
	}
	const charset = buildCharset(spec, policy)
	const minimums = classMinimums(spec, charset)
	// The length grows to hold every minimum, as Bitwarden's atLeastSum does.
	const length = Math.max(
		spec.length,
		minimums.reduce((sum, [, count]) => sum + count, 0),
	)
	assertLengthInRange(length)
	assertCharsetViable(charset)

	const value = ensureMinimums(
		buildString(charset, length, rand),
		charset,
		minimums,
		rand,
	)
	return shuffle([...value], rand).join('')
}

/**
 * A non-negative whole count; anything else is 0. Whole numbers only: a
 * fraction would make the length a fraction too.
 *
 * @param {number|string|undefined} value The requested count.
 * @return {number}
 */
function wholeCount(value) {
	return Math.max(0, Math.floor(Number(value ?? 0)) || 0)
}

/**
 * The request with its defaults. Under an org policy the length is raised to
 * the floor and the required kinds are switched on.
 *
 * @param {object} options The request.
 * @param {object|null} policy A normalised policy.
 * @return {object} length, the four kinds, the two minimums and the excluded set.
 */
function readSpec(options, policy) {
	const spec = {
		length: Number(options.length ?? 16),
		includeUppercase: options.includeUppercase !== false,
		includeLowercase: options.includeLowercase !== false,
		includeDigits: options.includeDigits !== false,
		includeSpecialCharacters: options.includeSpecialCharacters !== false,
		minDigits: wholeCount(options.minDigits),
		minSpecial: wholeCount(options.minSpecial),
		excludedCharacters: new Set(options.excludedCharacters ?? ''),
	}
	if (options.avoidAmbiguous === true) {
		for (const char of AMBIGUOUS) {
			spec.excludedCharacters.add(char)
		}
	}
	if (policy !== null) {
		spec.length = Math.max(spec.length, policy.minLength)
		spec.includeUppercase = spec.includeUppercase || policy.requireUpper
		spec.includeLowercase = spec.includeLowercase || policy.requireLower
		spec.includeDigits = spec.includeDigits || policy.requireDigit
		spec.includeSpecialCharacters =
			spec.includeSpecialCharacters || policy.requireSymbol
	}
	return spec
}

/**
 * The chosen kinds minus the exclusions. An exclusion list may not hollow out
 * a class the policy requires, so such a class is added back whole.
 *
 * @param {object} spec The request as readSpec returns it.
 * @param {object|null} policy A normalised policy.
 * @return {string}
 */
function buildCharset(spec, policy) {
	let charset =
		(spec.includeUppercase ? UPPERCASE : '')
		+ (spec.includeLowercase ? LOWERCASE : '')
		+ (spec.includeDigits ? DIGITS : '')
		+ (spec.includeSpecialCharacters ? SPECIAL : '')
	charset = dedupe([...charset].filter((c) => !spec.excludedCharacters.has(c)))
	if (policy !== null) {
		for (const [, classSet] of requiredClasses(policy)) {
			if (!intersects(charset, classSet)) {
				charset += classSet
			}
		}
	}
	return charset
}

/**
 * Every chosen kind appears at least once, on top of the minimums. A required
 * class is chosen and in the set, so this also meets the policy.
 *
 * @param {object} spec The request as readSpec returns it.
 * @param {string} charset The resolved set.
 * @return {Array<[string, number]>} Class set and minimum count.
 */
function classMinimums(spec, charset) {
	return [
		[UPPERCASE, spec.includeUppercase ? 1 : 0],
		[LOWERCASE, spec.includeLowercase ? 1 : 0],
		[DIGITS, spec.includeDigits ? Math.max(spec.minDigits, 1) : 0],
		[SPECIAL, spec.includeSpecialCharacters ? Math.max(spec.minSpecial, 1) : 0],
	].filter(([classSet, count]) => count > 0 && intersects(charset, classSet))
}

/**
 * Make sure the value holds at least `count` characters of each class, by
 * replacing characters of other classes at random positions. The length
 * must hold the sum of the counts.
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
		const have = chars
			.map((c, i) => (classSet.includes(c) ? i : -1))
			.filter((i) => i >= 0)
		for (const i of have.slice(0, count)) {
			reserved.add(i)
		}
		for (let missing = count - have.length; missing > 0; missing--) {
			const free = chars.map((c, i) => i).filter((i) => !reserved.has(i))
			const position = free[rand(0, free.length - 1)]
			chars[position] = allowed[rand(0, allowed.length - 1)]
			reserved.add(position)
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
 * The characters of the escaped classes `\d` and `\w`, and of `\s`:
 * whitespace is not a useful generation set, so a space stands for it.
 */
const ESCAPE_CLASSES = new Map([
	['d', [...DIGITS]],
	['w', [...(UPPERCASE + LOWERCASE + DIGITS + '_')]],
	['s', [' ']],
])

/**
 * Expand one escape inside a character class.
 *
 * @param {string} escape The character after the backslash.
 * @return {string[]}
 */
function expandEscape(escape) {
	return ESCAPE_CLASSES.get(escape) ?? [escape]
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
 * Refuse a pattern that cannot meet the org policy; it is never weakened.
 *
 * @param {object} policy A normalised policy.
 * @param {object} pattern The pattern as generateFromRegex reads it.
 * @return {void}
 */
function assertPolicyReachable(policy, pattern) {
	if (pattern.maxLength < policy.minLength) {
		throw new GeneratorError(
			`The regex cannot reach the org policy minimum length of ${policy.minLength} characters`,
		)
	}
	for (const [label, classSet] of requiredClasses(policy)) {
		if (!intersects(pattern.charset, classSet)) {
			throw new GeneratorError(
				`The regex excludes the ${label} characters the org policy requires`,
			)
		}
	}
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
	const pattern = regexPattern(regex)
	if (policy !== null) {
		assertPolicyReachable(policy, pattern)
		pattern.minLength = Math.max(
			pattern.minLength,
			Math.min(policy.minLength, pattern.maxLength),
		)
	}
	assertCharsetViable(pattern.charset)
	return matchingCandidate(pattern, rand)
}

/**
 * A pattern's compiled form, its quantifier's length range and its first
 * class's characters; a range shorter than the minimum length is refused.
 *
 * @param {string} regex The pattern.
 * @return {{compiled: RegExp, minLength: number, maxLength: number, charset: string}}
 */
function regexPattern(regex) {
	const compiled = compilePattern(regex)
	const [minLength, maxLength] = extractLength(regex)
	const pattern = {
		compiled,
		minLength,
		maxLength,
		charset: extractCharset(regex),
	}
	if (pattern.minLength < MIN_LENGTH) {
		throw new GeneratorError(
			`The regex length must be at least ${MIN_LENGTH} characters`,
		)
	}
	return pattern
}

/**
 * A candidate within the pattern's length range that the pattern itself
 * accepts, after at most MAX_REGEX_ATTEMPTS tries.
 *
 * @param {object} pattern The pattern.
 * @param {(min: number, max: number) => number} rand The random-integer source.
 * @return {string}
 */
function matchingCandidate(pattern, rand) {
	for (let attempt = 0; attempt < MAX_REGEX_ATTEMPTS; attempt++) {
		const candidate = buildString(
			pattern.charset,
			rand(pattern.minLength, pattern.maxLength),
			rand,
		)
		pattern.compiled.lastIndex = 0
		if (pattern.compiled.test(candidate)) {
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
 * large word list without its hyphenated words, joined by a separator.
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
	if (policy?.allowPassphrase === false) {
		throw new GeneratorError('Your organisation has switched passphrases off')
	}

	const spec = passphraseSpec(options, policy)
	if (policy?.requireSymbol && !intersects(spec.separator, SPECIAL)) {
		spec.separator = SPECIAL[rand(0, SPECIAL.length - 1)]
	}

	const words = passphraseWords(spec, rand)
	while (words.join(spec.separator).length < policy?.minLength) {
		words.push(drawWord(spec, rand))
	}
	return words.join(spec.separator)
}

/**
 * The passphrase request with its defaults, its word count checked and the
 * policy's required kinds switched on.
 *
 * @param {object} options The request.
 * @param {object|null} policy A normalised policy.
 * @return {{count: number, separator: string, capitalise: boolean, includeNumber: boolean}}
 * @throws {GeneratorError} When the word count is out of range.
 */
function passphraseSpec(options, policy) {
	const count = Number(options.words ?? DEFAULT_WORDS)
	if (!Number.isInteger(count) || count < MIN_WORDS || count > MAX_WORDS) {
		throw new GeneratorError(
			`A passphrase must have ${MIN_WORDS} to ${MAX_WORDS} words`,
		)
	}
	const spec = {
		count,
		separator: options.separator ?? '-',
		capitalise: options.capitalise === true,
		includeNumber: options.includeNumber === true,
	}
	if (policy !== null) {
		spec.capitalise = spec.capitalise || policy.requireUpper
		spec.includeNumber = spec.includeNumber || policy.requireDigit
	}
	return spec
}

/**
 * One word from the list, capitalised when the request asks for it.
 *
 * @param {object} spec The request as passphraseSpec returns it.
 * @param {(min: number, max: number) => number} rand The random-integer source.
 * @return {string}
 */
function drawWord(spec, rand) {
	const word = PASSPHRASE_WORDS[rand(0, PASSPHRASE_WORDS.length - 1)]
	return spec.capitalise ? word[0].toUpperCase() + word.slice(1) : word
}

/**
 * The requested number of words, one of them with a digit appended when
 * the request asks for a number.
 *
 * @param {object} spec The request as passphraseSpec returns it.
 * @param {(min: number, max: number) => number} rand The random-integer source.
 * @return {string[]}
 */
function passphraseWords(spec, rand) {
	const words = Array.from({ length: spec.count }, () => drawWord(spec, rand))
	if (spec.includeNumber) {
		const index = rand(0, words.length - 1)
		words[index] += String(rand(0, 9))
	}
	return words
}
