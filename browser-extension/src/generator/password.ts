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
export type InvalidPassword = 'length_too_short' | 'length_too_long' | 'no_kind' | 'charset_empty' | 'charset_too_small'

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

/** A uniform draw from the set, topped up to the minimum of every chosen class, then shuffled. */
export function generatePassword(request: PasswordRequest, policy: GeneratorPolicy | null, rand: RandomInt = randomInt): Generated<InvalidPassword> {
	let length = Number(request.length ?? 16)
	let includeUpper = request.includeUppercase !== false
	let includeLower = request.includeLowercase !== false
	let includeDigits = request.includeDigits !== false
	let includeSpecial = request.includeSpecialCharacters !== false
	const minDigits = Math.max(0, Math.floor(Number(request.minDigits ?? 0)) || 0)
	const minSpecial = Math.max(0, Math.floor(Number(request.minSpecial ?? 0)) || 0)
	const excluded = new Set(request.excludedCharacters ?? '')
	if (request.avoidAmbiguous === true) for (const char of AMBIGUOUS) excluded.add(char)

	if (policy !== null) {
		length = Math.max(length, policy.minLength)
		includeUpper ||= policy.requireUpper
		includeLower ||= policy.requireLower
		includeDigits ||= policy.requireDigit
		includeSpecial ||= policy.requireSymbol
	}
	if (!includeUpper && !includeLower && !includeDigits && !includeSpecial) return { ok: false, failure: 'no_kind' }
	let charset = (includeUpper ? UPPERCASE : '') + (includeLower ? LOWERCASE : '') + (includeDigits ? DIGITS : '') + (includeSpecial ? SPECIAL : '')
	charset = [...new Set([...charset].filter((c) => !excluded.has(c)))].join('')
	// An exclusion may not hollow out a class the policy requires.
	if (policy !== null) for (const classSet of requiredClasses(policy)) if (!intersects(charset, classSet)) charset += classSet

	const minimums = ([
		[UPPERCASE, includeUpper ? 1 : 0],
		[LOWERCASE, includeLower ? 1 : 0],
		[DIGITS, includeDigits ? Math.max(minDigits, 1) : 0],
		[SPECIAL, includeSpecial ? Math.max(minSpecial, 1) : 0],
	] as [string, number][]).filter(([classSet, count]) => count > 0 && intersects(charset, classSet))
	length = Math.max(length, minimums.reduce((sum, [, count]) => sum + count, 0))

	if (!Number.isInteger(length) || length < PASSWORD_LENGTH.min) return { ok: false, failure: 'length_too_short' }
	if (length > PASSWORD_LENGTH.max) return { ok: false, failure: 'length_too_long' }
	if (charset.length === 0) return { ok: false, failure: 'charset_empty' }
	if (charset.length < MIN_CHARSET_SIZE) return { ok: false, failure: 'charset_too_small' }

	const chars = ensureMinimums(buildString(charset, length, rand), charset, minimums, rand)
	return { ok: true, value: shuffle(chars, rand).join('') }
}
