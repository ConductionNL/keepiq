import { PASSPHRASE_WORDS, type GeneratorPolicy, type PassphraseOptions } from './options'
import { intersects, SPECIAL } from './password'
import { randomInt, type Generated, type RandomInt } from './random'

const DEFAULT_WORDS = 5

/** `words_out_of_range` the popup can't cause: `sanitize` keeps the count in range. */
export type PassphraseFailure = 'passphrase_off' | 'words_out_of_range'

// With the `-` separator the four hyphenated words (drop-down, felt-tip, t-shirt, yo-yo) let two word sequences give the same passphrase.
const withoutHyphens = new WeakMap<readonly string[], readonly string[]>()
function passphraseWords(list: readonly string[]): readonly string[] {
	let words = withoutHyphens.get(list)
	if (!words) withoutHyphens.set(list, words = list.filter((word) => !word.includes('-')))
	return words
}

/**
 * Words drawn uniformly from the list, joined by the separator. Under a policy the passphrase is made to
 * meet it: capitalised for upper case, a digit for a digit, a symbol separator for a symbol, and words
 * added until the floor is met.
 */
export function generatePassphrase(
	options: Partial<PassphraseOptions>,
	policy: GeneratorPolicy | null,
	list: readonly string[],
	rand: RandomInt = randomInt,
): Generated<PassphraseFailure> {
	if (policy !== null && !policy.allowPassphrase) return { ok: false, failure: 'passphrase_off' }
	const count = Number(options.words ?? DEFAULT_WORDS)
	if (!Number.isInteger(count) || count < PASSPHRASE_WORDS.min || count > PASSPHRASE_WORDS.max) return { ok: false, failure: 'words_out_of_range' }
	let separator = options.separator ?? '-'
	let capitalise = options.capitalise === true
	let includeNumber = options.includeNumber === true
	if (policy !== null) {
		capitalise ||= policy.requireUpper
		includeNumber ||= policy.requireDigit
		if (policy.requireSymbol && !intersects(separator, SPECIAL)) separator = SPECIAL[rand(0, SPECIAL.length - 1)]!
	}

	const words = passphraseWords(list)
	const draw = () => {
		const word = words[rand(0, words.length - 1)]!
		return capitalise ? word[0]!.toUpperCase() + word.slice(1) : word
	}
	const drawn = Array.from({ length: count }, draw)
	if (includeNumber) drawn[rand(0, drawn.length - 1)] += String(rand(0, 9))
	if (policy !== null) while (drawn.join(separator).length < policy.minLength) drawn.push(draw())
	return { ok: true, value: drawn.join(separator) }
}
