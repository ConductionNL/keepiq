import { effective, lengthFloor, minimumLength, PASSWORD_LENGTH, type GeneratorFailure, type GeneratorKind, type GeneratorOptions, type GeneratorPolicy, type PasswordOptions } from './options'
import { generatePassphrase } from './passphrase'
import { generatePassword } from './password'
import { randomInt, type RandomInt } from './random'
import { generateUsername } from './username'
import { wordlist as bundledWordlist } from './wordlist'

export interface GenerateContext {
	/** The popup tab's hostname without port, for the Website name sub-mode; never stored. */
	website: string | null
	accountEmail: string | null
}

export type GenerateResult = { ok: true; value: string } | { ok: false; failure: GeneratorFailure }

/** A refusal sanitised and `effective` options can't reach; a bug, not something to word. */
function unreachable(failure: string): never {
	throw new Error(`Generator refused valid options: ${failure}`)
}

/** One value of the kind, with the policy applied on read; the popup words a failure code. */
export async function generate(
	kind: GeneratorKind,
	options: GeneratorOptions,
	policy: GeneratorPolicy | null,
	context: GenerateContext,
	rand: RandomInt = randomInt,
	wordlist: () => Promise<readonly string[] | null> = bundledWordlist,
): Promise<GenerateResult> {
	if (kind === 'password') {
		const result = generatePassword(effective(options.password, policy), policy, rand)
		return result.ok ? result : unreachable(result.failure)
	}
	if (kind === 'passphrase' && policy?.allowPassphrase === false) return { ok: false, failure: 'passphrase_off' }
	const { username } = options
	// Emails don't need the list, so they keep working when it fails to load.
	const words = kind === 'passphrase' || username.type === 'word' ? await wordlist() : []
	if (!words) return { ok: false, failure: 'wordlist_unavailable' }
	if (kind === 'passphrase') {
		const result = generatePassphrase(options.passphrase, policy, words, rand)
		return result.ok ? result : unreachable(result.failure)
	}
	return generateUsername({ ...username, email: username.email || context.accountEmail || '', website: context.website }, words, rand)
}

export interface EffectivePassword {
	options: PasswordOptions
	/** The lowest length the controls may show: the policy floor or the sum of the class minimums. */
	minLength: number
	/** Controls the organisation's policy sets. */
	locked: { length: boolean; uppercase: boolean; lowercase: boolean; digits: boolean; special: boolean }
}

/** The password options as generated: the policy applied on read, the length raised to fit every enabled class. */
export function applyPolicy(stored: PasswordOptions, policy: GeneratorPolicy | null): EffectivePassword {
	const floor = lengthFloor(policy)
	const options = effective(stored, policy)
	const minLength = Math.min(PASSWORD_LENGTH.max, Math.max(floor, minimumLength(options)))
	return {
		options,
		minLength,
		locked: {
			length: floor > PASSWORD_LENGTH.min,
			uppercase: !!policy?.requireUpper,
			lowercase: !!policy?.requireLower,
			digits: !!policy?.requireDigit,
			special: !!policy?.requireSymbol,
		},
	}
}

export const passphraseAllowed = (policy: GeneratorPolicy | null): boolean => policy?.allowPassphrase !== false
