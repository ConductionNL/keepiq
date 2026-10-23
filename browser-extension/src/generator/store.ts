import { PASSWORD_LENGTH, sanitize, type GeneratorOptions, type GeneratorPolicy } from './options'
import { createQueue } from './queue'

/** Apart from `settings.<id>`, which `writeSettings` replaces whole. Kept on logout. */
export const generatorOptionsKey = (accountId: string) => `generatorOptions.${accountId}`
/** The last policy a sync read; cleared on logout so a re-login starts from the server's. */
export const generatorPolicyKey = (accountId: string) => `generatorPolicy.${accountId}`

async function local(key: string): Promise<unknown> {
	return (await browser.storage.local.get(key))[key]
}

export async function readOptions(accountId: string): Promise<GeneratorOptions> {
	return sanitize(await local(generatorOptionsKey(accountId)))
}

/** A slider sends a save per step; one at a time, so an older one never lands last. */
const queued = createQueue()

/** Stores the options as `sanitize` leaves them and returns that. */
export function saveOptions(accountId: string, options: unknown): Promise<GeneratorOptions> {
	return queued(async () => {
		const saved = sanitize(options, await readOptions(accountId))
		await browser.storage.local.set({ [generatorOptionsKey(accountId)]: saved })
		return saved
	})
}

const POLICY_FLAGS = ['requireUpper', 'requireLower', 'requireDigit', 'requireSymbol', 'allowPassphrase'] as const

/** A damaged record reads as no policy, as `generate` would otherwise refuse every password. */
export async function readPolicy(accountId: string): Promise<GeneratorPolicy | null> {
	const stored = await local(generatorPolicyKey(accountId)) as Partial<Record<keyof GeneratorPolicy, unknown>> | null | undefined
	if (typeof stored !== 'object' || stored === null) return null
	const { minLength } = stored
	const valid = Number.isInteger(minLength) && (minLength as number) >= PASSWORD_LENGTH.min && (minLength as number) <= PASSWORD_LENGTH.max
		&& POLICY_FLAGS.every((flag) => typeof stored[flag] === 'boolean')
	return valid ? stored as GeneratorPolicy : null
}

/** `null` means the server answered that no policy applies. */
export async function writePolicy(accountId: string, policy: GeneratorPolicy | null): Promise<void> {
	if (policy) await browser.storage.local.set({ [generatorPolicyKey(accountId)]: policy })
	else await clearPolicy(accountId)
}

export async function clearPolicy(accountId: string): Promise<void> {
	await browser.storage.local.remove(generatorPolicyKey(accountId))
}
