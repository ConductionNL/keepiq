/**
 * Generator options, policy and history shapes. Field names follow the web app's
 * `src/generator/*.js`, whose port and shared vectors take these objects as they are.
 */

export const GENERATOR_KINDS = ['password', 'passphrase', 'username'] as const
export type GeneratorKind = typeof GENERATOR_KINDS[number]

export interface PasswordOptions {
	length: number
	includeUppercase: boolean
	includeLowercase: boolean
	includeDigits: boolean
	includeSpecialCharacters: boolean
	minDigits: number
	minSpecial: number
	avoidAmbiguous: boolean
}

export interface PassphraseOptions {
	words: number
	separator: string
	capitalise: boolean
	includeNumber: boolean
}

export const USERNAME_TYPES = ['word', 'plus', 'catchall'] as const
export type UsernameType = typeof USERNAME_TYPES[number]

/** Where an email's label comes from: random, or the current tab's host. */
export type EmailLabelMode = 'random' | 'website'

export interface UsernameOptions {
	type: UsernameType
	capitalize: boolean
	includeNumber: boolean
	/** Empty means the account's email. */
	email: string
	domain: string
	mode: EmailLabelMode
}

export interface GeneratorOptions {
	tab: GeneratorKind
	password: PasswordOptions
	passphrase: PassphraseOptions
	username: UsernameOptions
}

/** The generator part of `GET /api/settings/policy`, as the web app's `generatorPolicy` maps it; `null` when no policy applies. */
export interface GeneratorPolicy {
	minLength: number
	requireUpper: boolean
	requireLower: boolean
	requireDigit: boolean
	requireSymbol: boolean
	allowPassphrase: boolean
}

export interface GeneratorHistoryEntry {
	value: string
	kind: GeneratorKind
	createdAt: string
}

// Codes, never text: the popup words them (ext-i18n).
export type GeneratorFailure = 'wordlist_unavailable' | 'needs_email' | 'needs_domain' | 'no_website' | 'passphrase_off'

export const PASSWORD_LENGTH = { min: 8, max: 128 } as const
export const MIN_COUNT = { min: 0, max: 9 } as const
export const PASSPHRASE_WORDS = { min: 4, max: 12 } as const
export const HISTORY_LIMIT = 50

export const DEFAULT_PASSWORD: Readonly<PasswordOptions> = {
	length: 14,
	includeUppercase: true,
	includeLowercase: true,
	includeDigits: true,
	includeSpecialCharacters: false,
	minDigits: 1,
	minSpecial: 1,
	avoidAmbiguous: true,
}

export const DEFAULT_PASSPHRASE: Readonly<PassphraseOptions> = {
	words: 5,
	separator: '-',
	capitalise: false,
	includeNumber: false,
}

export const DEFAULT_USERNAME: Readonly<UsernameOptions> = {
	type: 'word',
	capitalize: true,
	includeNumber: true,
	email: '',
	domain: '',
	mode: 'random',
}

export function defaultOptions(): GeneratorOptions {
	return {
		tab: 'password',
		password: { ...DEFAULT_PASSWORD },
		passphrase: { ...DEFAULT_PASSPHRASE },
		username: { ...DEFAULT_USERNAME },
	}
}

/** Long enough for a full email address or domain; anything longer is not one. */
const MAX_TEXT = 254

type Loose<T> = { [K in keyof T]?: unknown }

const isRecord = (value: unknown): value is Record<string, unknown> => typeof value === 'object' && value !== null && !Array.isArray(value)

function count(value: unknown, range: { min: number; max: number }, fallback: number): number {
	if (typeof value !== 'number' || !Number.isFinite(value)) return fallback
	return Math.min(range.max, Math.max(range.min, Math.round(value)))
}

const flag = (value: unknown, fallback: boolean): boolean => typeof value === 'boolean' ? value : fallback
const oneOf = <T extends string>(value: unknown, allowed: readonly T[], fallback: T): T => allowed.includes(value as T) ? value as T : fallback
const text = (value: unknown): string => typeof value === 'string' ? value.trim().slice(0, MAX_TEXT) : ''

const CLASSES = ['includeUppercase', 'includeLowercase', 'includeDigits', 'includeSpecialCharacters'] as const

/** One character per enabled class, more where a minimum asks for it: shorter can't satisfy them. Applied at generation, never stored. */
export function minimumLength(options: PasswordOptions): number {
	return (options.includeUppercase ? 1 : 0) + (options.includeLowercase ? 1 : 0)
		+ (options.includeDigits ? Math.max(options.minDigits, 1) : 0)
		+ (options.includeSpecialCharacters ? Math.max(options.minSpecial, 1) : 0)
}

/** The policy's length floor, within the range; the range's minimum without a policy. */
export const lengthFloor = (policy: GeneratorPolicy | null): number =>
	policy ? Math.min(PASSWORD_LENGTH.max, Math.max(PASSWORD_LENGTH.min, policy.minLength)) : PASSWORD_LENGTH.min

/**
 * The password options as shown and generated: required classes on, their minimums at least 1, and the
 * length raised to the floor and to `minimumLength`. Idempotent, and never written back.
 */
export function effective(stored: PasswordOptions, policy: GeneratorPolicy | null): PasswordOptions {
	const options = {
		...stored,
		includeUppercase: stored.includeUppercase || !!policy?.requireUpper,
		includeLowercase: stored.includeLowercase || !!policy?.requireLower,
		includeDigits: stored.includeDigits || !!policy?.requireDigit,
		includeSpecialCharacters: stored.includeSpecialCharacters || !!policy?.requireSymbol,
		minDigits: policy?.requireDigit ? Math.max(1, stored.minDigits) : stored.minDigits,
		minSpecial: policy?.requireSymbol ? Math.max(1, stored.minSpecial) : stored.minSpecial,
	}
	if (!CLASSES.some((name) => options[name])) options.includeLowercase = true
	options.length = Math.min(PASSWORD_LENGTH.max, Math.max(lengthFloor(policy), minimumLength(options), stored.length))
	return options
}

function sanitizePassword(raw: unknown, previous: PasswordOptions): PasswordOptions {
	const input: Loose<PasswordOptions> = isRecord(raw) ? raw : {}
	const options: PasswordOptions = {
		length: count(input.length, PASSWORD_LENGTH, previous.length),
		includeUppercase: flag(input.includeUppercase, previous.includeUppercase),
		includeLowercase: flag(input.includeLowercase, previous.includeLowercase),
		includeDigits: flag(input.includeDigits, previous.includeDigits),
		includeSpecialCharacters: flag(input.includeSpecialCharacters, previous.includeSpecialCharacters),
		minDigits: count(input.minDigits, MIN_COUNT, previous.minDigits),
		minSpecial: count(input.minSpecial, MIN_COUNT, previous.minSpecial),
		avoidAmbiguous: flag(input.avoidAmbiguous, previous.avoidAmbiguous),
	}
	// Turning off the last class keeps the classes the user had.
	if (!CLASSES.some((name) => options[name])) for (const name of CLASSES) options[name] = previous[name]
	return options
}

function sanitizePassphrase(raw: unknown, previous: PassphraseOptions): PassphraseOptions {
	const input: Loose<PassphraseOptions> = isRecord(raw) ? raw : {}
	return {
		words: count(input.words, PASSPHRASE_WORDS, previous.words),
		// One code point at most, so a character outside the BMP is not cut in half.
		separator: typeof input.separator === 'string' ? Array.from(input.separator)[0] ?? '' : previous.separator,
		capitalise: flag(input.capitalise, previous.capitalise),
		includeNumber: flag(input.includeNumber, previous.includeNumber),
	}
}

function sanitizeUsername(raw: unknown, previous: UsernameOptions): UsernameOptions {
	const input: Loose<UsernameOptions> = isRecord(raw) ? raw : {}
	return {
		type: oneOf(input.type, USERNAME_TYPES, previous.type),
		capitalize: flag(input.capitalize, previous.capitalize),
		includeNumber: flag(input.includeNumber, previous.includeNumber),
		email: input.email === undefined ? previous.email : text(input.email),
		domain: input.domain === undefined ? previous.domain : text(input.domain),
		mode: oneOf(input.mode, ['random', 'website'] as const, previous.mode),
	}
}

/**
 * Valid options from anything stored or sent: each field in its range, a missing or invalid one
 * from `previous` (the defaults when absent). The user's own values: the policy and `minimumLength`
 * apply at generation, so they never overwrite them.
 */
export function sanitize(raw: unknown, previous: GeneratorOptions = defaultOptions()): GeneratorOptions {
	const input: Loose<GeneratorOptions> = isRecord(raw) ? raw : {}
	return {
		tab: oneOf(input.tab, GENERATOR_KINDS, previous.tab),
		password: sanitizePassword(input.password, previous.password),
		passphrase: sanitizePassphrase(input.passphrase, previous.passphrase),
		username: sanitizeUsername(input.username, previous.username),
	}
}
