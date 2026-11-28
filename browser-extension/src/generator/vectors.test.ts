import { describe, expect, it } from 'vitest'
import { generatorCases, seededRandom, webWordlist } from '@/src/testing/vectors'
import { generatePassphrase } from './passphrase'
import { generatePassword } from './password'
import { toPolicy } from './policy'

/** The web module's refusal for each code; a refusal it adds fails here until the port has it. */
const REFUSALS: Record<string, string> = {
	'Length must be at least 8 characters': 'length_too_short',
	'Length must not exceed 128 characters': 'length_too_long',
	'Choose at least one kind of character': 'no_kind',
	'The character set is empty after exclusions': 'charset_empty',
	'The character set must contain at least 2 distinct characters': 'charset_too_small',
	'A passphrase must have 4 to 12 words': 'words_out_of_range',
	'Your organisation has switched passphrases off': 'passphrase_off',
}

const cases = generatorCases()
// The extension has no regex option.
const ported = cases.filter((entry) => !('regex' in entry.options))
const words = webWordlist()

describe('the shared generator vectors', () => {
	it('draws with the same seeded source as the app repo', async () => {
		// A variable path keeps TypeScript from type-checking the app repo's JavaScript.
		const shared = new URL('../../../tests/vectors/seeded-random.mjs', import.meta.url).href
		const { seededRandom: theirs } = await import(/* @vite-ignore */ shared) as { seededRandom: typeof seededRandom }
		for (const seed of [1, 2, 7, 42, 0xdeadbeef]) {
			const [mine, other] = [seededRandom(seed), theirs(seed)]
			for (const [min, max] of [[0, 1], [0, 9], [3, 7775], [0, 127], [5, 5]] as const) {
				for (let i = 0; i < 50; i++) expect(mine(min, max)).toBe(other(min, max))
			}
		}
	})

	it('runs every case but the regex ones', () => {
		expect(cases.length - ported.length).toBe(cases.filter((entry) => entry.name.startsWith('regex')).length)
		expect(ported.length).toBeGreaterThan(150)
	})

	it.each(ported.map((entry) => [`${entry.name} (seed ${entry.seed})`, entry] as const))('%s', (_, entry) => {
		const rand = seededRandom(entry.seed)
		const policy = toPolicy(entry.policy)
		const result = entry.kind === 'passphrase'
			? generatePassphrase(entry.options, policy, words, rand)
			: generatePassword(entry.options, policy, rand)
		if (entry.error !== undefined) expect(result).toEqual({ ok: false, failure: REFUSALS[entry.error] ?? entry.error })
		else expect(result).toEqual({ ok: true, value: entry.expected })
	})
})
