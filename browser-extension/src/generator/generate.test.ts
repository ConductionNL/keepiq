import { describe, expect, it, vi } from 'vitest'
import { generatorCases, seededRandom, webWordlist } from '@/src/testing/vectors'
import { applyPolicy, generate, passphraseAllowed } from './generate'
import { DEFAULT_PASSWORD, defaultOptions, type GeneratorOptions, type GeneratorPolicy } from './options'

const policy = (overrides: Partial<GeneratorPolicy> = {}): GeneratorPolicy => ({
	minLength: 8, requireUpper: false, requireLower: false, requireDigit: false, requireSymbol: false, allowPassphrase: true, ...overrides,
})

describe('applyPolicy', () => {
	it('leaves the defaults alone without a policy', () => {
		expect(applyPolicy(DEFAULT_PASSWORD, null)).toEqual({
			options: DEFAULT_PASSWORD,
			minLength: 8,
			locked: { length: false, uppercase: false, lowercase: false, digits: false, special: false },
		})
	})

	it('raises the length to the sum of the minimums, counting one per enabled class', () => {
		const { options, minLength } = applyPolicy({ ...DEFAULT_PASSWORD, length: 8, includeSpecialCharacters: true, minDigits: 6, minSpecial: 6 }, null)
		expect(minLength).toBe(14)
		expect(options.length).toBe(14)
	})

	it('counts an enabled class with minimum 0 once', () => {
		expect(applyPolicy({ ...DEFAULT_PASSWORD, length: 8, minDigits: 0 }, null).minLength).toBe(8)
		expect(applyPolicy({ ...DEFAULT_PASSWORD, includeSpecialCharacters: true, minDigits: 0, minSpecial: 0 }, null).minLength).toBe(8)
	})

	it('applies the floor and required classes on read, keeping the stored values', () => {
		const stored = { ...DEFAULT_PASSWORD, length: 12, minSpecial: 0 }
		const { options, minLength, locked } = applyPolicy(stored, policy({ minLength: 20, requireSymbol: true }))
		expect(options).toMatchObject({ length: 20, includeSpecialCharacters: true, minSpecial: 1 })
		expect(minLength).toBe(20)
		expect(locked).toEqual({ length: true, uppercase: false, lowercase: false, digits: false, special: true })
		expect(stored).toMatchObject({ length: 12, includeSpecialCharacters: false, minSpecial: 0 })
	})

	it('raises a required number minimum from 0 to 1', () => {
		const { options, locked } = applyPolicy({ ...DEFAULT_PASSWORD, includeDigits: false, minDigits: 0 }, policy({ requireDigit: true }))
		expect(options).toMatchObject({ includeDigits: true, minDigits: 1 })
		expect(locked.digits).toBe(true)
	})

	it.each([
		['requireUpper', 'includeUppercase', 'uppercase'],
		['requireLower', 'includeLowercase', 'lowercase'],
	] as const)('%s turns its class on and locks it', (rule, key, lock) => {
		const { options, locked } = applyPolicy({ ...DEFAULT_PASSWORD, [key]: false }, policy({ [rule]: true }))
		expect(options[key]).toBe(true)
		expect(locked[lock]).toBe(true)
	})

	it('raises a stored length below the minimums', () => {
		expect(applyPolicy({ ...DEFAULT_PASSWORD, length: 8, minDigits: 9 }, null).options.length).toBe(11)
	})

	it('caps a floor above the maximum at 128 and keeps a floor below 8 at 8', () => {
		expect(applyPolicy(DEFAULT_PASSWORD, policy({ minLength: 200 })).options.length).toBe(128)
		const low = applyPolicy(DEFAULT_PASSWORD, policy({ minLength: 4 }))
		expect(low.minLength).toBe(8)
		expect(low.locked.length).toBe(false)
	})

	it('caps a stored length above the maximum', () => {
		expect(applyPolicy({ ...DEFAULT_PASSWORD, length: 300 }, null).options.length).toBe(128)
	})

	it('turns lowercase on when stored options have no class at all', () => {
		const none = { ...DEFAULT_PASSWORD, includeUppercase: false, includeLowercase: false, includeDigits: false }
		expect(applyPolicy(none, null).options.includeLowercase).toBe(true)
	})
})

describe('generate', () => {
	const words = webWordlist()
	const loaded = async (): Promise<readonly string[] | null> => words
	const unavailable = async (): Promise<readonly string[] | null> => null
	const context = { website: 'github.com', accountEmail: 'alice@example.org' }
	const options = (change: (options: GeneratorOptions) => void = () => {}) => {
		const value = defaultOptions()
		change(value)
		return value
	}
	const run = (kind: 'password' | 'passphrase' | 'username', value: GeneratorOptions, rule: GeneratorPolicy | null = null, wordlist = loaded) =>
		generate(kind, value, rule, context, undefined, wordlist)

	it('generates a password of the stored length without the word list', async () => {
		const wordlist = vi.fn(unavailable)
		const result = await run('password', options(), null, wordlist)
		expect(result).toMatchObject({ ok: true, value: expect.stringMatching(/^[A-Za-z2-9]{14}$/) })
		expect(wordlist).not.toHaveBeenCalled()
	})

	it('applies the policy on read, and gives the same once the options already carry it', async () => {
		const rule = policy({ minLength: 20, requireSymbol: true })
		const stored = options()
		const once = await run('password', stored, rule)
		const twice = await run('password', { ...stored, password: applyPolicy(stored.password, rule).options }, rule)
		for (const result of [once, twice]) {
			expect(result.ok && result.value).toHaveLength(20)
			expect(result.ok && /[^A-Za-z0-9]/.test(result.value)).toBe(true)
		}
		expect(stored.password).toEqual(DEFAULT_PASSWORD)
	})

	it('gives the shared vector for the extension defaults, draw for draw', async () => {
		const vector = generatorCases().find((entry) => entry.name === 'the extension defaults' && entry.seed === 1)!
		expect(await generate('password', options(), null, context, seededRandom(1), loaded)).toEqual({ ok: true, value: vector.expected })
	})

	it('generates a passphrase, or says the word list or the organisation stops it', async () => {
		expect(await run('passphrase', options())).toMatchObject({ ok: true, value: expect.stringMatching(/^[a-z]+(-[a-z]+){4}$/) })
		expect(await run('passphrase', options(), null, unavailable)).toEqual({ ok: false, failure: 'wordlist_unavailable' })
		expect(await run('passphrase', options(), policy({ allowPassphrase: false }))).toEqual({ ok: false, failure: 'passphrase_off' })
	})

	it('falls back to the account email and the popup tab\'s website for usernames', async () => {
		const plus = options((value) => Object.assign(value.username, { type: 'plus', mode: 'website' }))
		expect(await run('username', plus, null, unavailable)).toEqual({ ok: true, value: 'alice+github.com@example.org' })
		const typed = options((value) => Object.assign(value.username, { type: 'plus', email: 'bob@example.net' }))
		expect(await run('username', typed)).toMatchObject({ ok: true, value: expect.stringMatching(/^bob\+[a-z0-9]{8}@example\.net$/) })
		const noEmail = options((value) => Object.assign(value.username, { type: 'plus' }))
		expect(await generate('username', noEmail, null, { website: null, accountEmail: null }, undefined, loaded)).toEqual({ ok: false, failure: 'needs_email' })
		const noWebsite = options((value) => Object.assign(value.username, { type: 'catchall', domain: 'example.org', mode: 'website' }))
		expect(await generate('username', noWebsite, null, { website: null, accountEmail: null }, undefined, loaded)).toEqual({ ok: false, failure: 'no_website' })
	})

	it('needs the word list only for a random-word username', async () => {
		expect(await run('username', options(), null, unavailable)).toEqual({ ok: false, failure: 'wordlist_unavailable' })
		expect(await run('username', options())).toMatchObject({ ok: true, value: expect.stringMatching(/^[A-Z][a-z-]+\d{4}$/) })
	})
})

describe('passphraseAllowed', () => {
	it('allows passphrases unless the policy turns them off', () => {
		expect(passphraseAllowed(null)).toBe(true)
		expect(passphraseAllowed(policy())).toBe(true)
		expect(passphraseAllowed(policy({ allowPassphrase: false }))).toBe(false)
	})
})
