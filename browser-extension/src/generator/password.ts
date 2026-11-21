import { PASSWORD_LENGTH, type GeneratorPolicy, type PasswordOptions } from './options'
import { randomInt, shuffle, type Generated, type RandomInt } from './random'

const UPPERCASE = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ'
const LOWERCASE = 'abcdefghijklmnopqrstuvwxyz'
const DIGITS = '0123456789'
export const SPECIAL = '!@#$%^&*()-_=+[]{}|;:,.<>?/'
/** Characters that are easy to mistake for one another. */
export const AMBIGUOUS = 'IOl01'
const MIN_CHARSET_SIZE = 2

/** The web module's own defaults fill a missing field; `excludedCharacters` only the vectors use. */
export type PasswordRequest = Partial<PasswordOptions> & { excludedCharacters?: string }

/** Refusals the popup can't cause: `sanitize` and `effective` keep its options valid. */
export type InvalidPassword =
	| 'length_too_short'
	| 'length_too_long'
	| 'no_kind'
	| 'charset_empty'
	| 'charset_too_small'

export function intersects(a: string, b: string): boolean {
	for (const char of b) if (a.includes(char)) return true
	return false
}

function requiredClasses(policy: GeneratorPolicy): string[] {
	return [
		policy.requireUpper && UPPERCASE,
		policy.requireLower && LOWERCASE,
		policy.requireDigit && DIGITS,
		policy.requireSymbol && SPECIAL,
	].filter((set): set is string => !!set)
}

function buildString(charset: string, length: number, rand: RandomInt): string {
	let result = ''
	for (let i = 0; i < length; i++) result += charset[rand(0, charset.length - 1)]
	return result
}

/** At least `count` characters of each class, put in at random free positions; the length holds the sum. */
function ensureMinimums(value: string, charset: string, minimums: [string, number][], rand: RandomInt): string[] {
	const chars = [...value]
	const reserved = new Set<number>()
	for (const [classSet, count] of minimums) {
		const allowed = [...classSet].filter((c) => charset.includes(c))
		const have = chars.map((c, i) => (classSet.includes(c) ? i : -1)).filter((i) => i >= 0)
		for (const i of have.slice(0, count)) reserved.add(i)
		for (let missing = count - have.length; missing > 0; missing--) {
			const free = chars.map((_, i) => i).filter((i) => !reserved.has(i))
			const position = free[rand(0, free.length - 1)]!
			chars[position] = allowed[rand(0, allowed.length - 1)]!
			reserved.add(position)
		}
	}
	return chars
}

/** The request's options with `avoidAmbiguous` folded into the excluded characters. */
type PasswordSpec = Omit<PasswordOptions, 'avoidAmbiguous'> & { excludedCharacters: Set<string> }

/** A non-negative whole count; anything else is 0. */
function wholeCount(value: unknown): number {
	return Math.max(0, Math.floor(Number(value ?? 0)) || 0)
}

/** The request with the web module's defaults, raised to the policy's floor. */
function readSpec(request: PasswordRequest, policy: GeneratorPolicy | null): PasswordSpec {
	const spec: PasswordSpec = {
		length: Number(request.length ?? 16),
		includeUppercase: request.includeUppercase !== false,
		includeLowercase: request.includeLowercase !== false,
		includeDigits: request.includeDigits !== false,
		includeSpecialCharacters: request.includeSpecialCharacters !== false,
		minDigits: wholeCount(request.minDigits),
		minSpecial: wholeCount(request.minSpecial),
		excludedCharacters: new Set(request.excludedCharacters ?? ''),
	}
	if (request.avoidAmbiguous === true) for (const char of AMBIGUOUS) spec.excludedCharacters.add(char)
	if (policy !== null) {
		spec.length = Math.max(spec.length, policy.minLength)
		spec.includeUppercase ||= policy.requireUpper
		spec.includeLowercase ||= policy.requireLower
		spec.includeDigits ||= policy.requireDigit
		spec.includeSpecialCharacters ||= policy.requireSymbol
	}
	return spec
}

function buildCharset(spec: PasswordSpec, policy: GeneratorPolicy | null): string {
	let charset = (spec.includeUppercase ? UPPERCASE : '') + (spec.includeLowercase ? LOWERCASE : '') + (spec.includeDigits ? DIGITS : '') + (spec.includeSpecialCharacters ? SPECIAL : '')
	charset = [...new Set([...charset].filter((c) => !spec.excludedCharacters.has(c)))].join('')
	// An exclusion may not hollow out a class the policy requires.
	if (policy !== null) for (const classSet of requiredClasses(policy)) if (!intersects(charset, classSet)) charset += classSet
	return charset
}

/** The minimum count of every chosen class the charset still holds. */
function classMinimums(spec: PasswordSpec, charset: string): [string, number][] {
	return ([
		[UPPERCASE, spec.includeUppercase ? 1 : 0],
		[LOWERCASE, spec.includeLowercase ? 1 : 0],
		[DIGITS, spec.includeDigits ? Math.max(spec.minDigits, 1) : 0],
		[SPECIAL, spec.includeSpecialCharacters ? Math.max(spec.minSpecial, 1) : 0],
	] as [string, number][]).filter(([classSet, min]) => min > 0 && intersects(charset, classSet))
}

function invalidLength(length: number): InvalidPassword | null {
	if (!Number.isInteger(length) || length < PASSWORD_LENGTH.min) return 'length_too_short'
	return length > PASSWORD_LENGTH.max ? 'length_too_long' : null
}

function invalidCharset(charset: string): InvalidPassword | null {
	if (charset.length === 0) return 'charset_empty'
	return charset.length < MIN_CHARSET_SIZE ? 'charset_too_small' : null
}

/** A uniform draw from the set, topped up to the minimum of every chosen class, then shuffled. */
export function generatePassword(request: PasswordRequest, policy: GeneratorPolicy | null, rand: RandomInt = randomInt): Generated<InvalidPassword> {
	const spec = readSpec(request, policy)
	if (!spec.includeUppercase && !spec.includeLowercase && !spec.includeDigits && !spec.includeSpecialCharacters) return { ok: false, failure: 'no_kind' }
	const charset = buildCharset(spec, policy)
	const minimums = classMinimums(spec, charset)
	const length = Math.max(spec.length, minimums.reduce((sum, [, min]) => sum + min, 0))
	const failure = invalidLength(length) ?? invalidCharset(charset)
	if (failure !== null) return { ok: false, failure }

	const chars = ensureMinimums(buildString(charset, length, rand), charset, minimums, rand)
	return { ok: true, value: shuffle(chars, rand).join('') }
}
