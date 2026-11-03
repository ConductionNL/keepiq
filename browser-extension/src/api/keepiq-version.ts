/** Which Keepiq versions this extension works with; the supported list is `docs/browser-extension/supported-keepiq-versions.md`. */

/** The oldest Keepiq this extension adds or unlocks an account on; a release stays supported for five years. */
export const MIN_KEEPIQ_VERSION = '0.3.12'

/** Features that need a newer Keepiq than `MIN_KEEPIQ_VERSION`, by the first version that has them; see KEEPIQ-VERSIONS.md. */
export const KEEPIQ_FEATURES = {} as const satisfies Record<string, string>

export type KeepiqFeature = keyof typeof KEEPIQ_FEATURES

type Core = [number, number, number]

/** `major.minor.patch` and whether it is an `-unstable` build; `null` for anything else. */
export function parseVersion(version: string): { core: Core; unstable: boolean } | null {
	const match = /^(\d+)\.(\d+)\.(\d+)(?:-([0-9A-Za-z.-]+))?$/.exec(version.trim())
	if (!match) return null
	return { core: [Number(match[1]), Number(match[2]), Number(match[3])], unstable: match[4]?.startsWith('unstable') ?? false }
}

function atLeast(a: Core, b: Core): boolean {
	for (let i = 0; i < 3; i++) if (a[i] !== b[i]) return a[i]! > b[i]!
	return true
}

/**
 * Prereleases compare as their release, so a 0.3.12 beta counts as 0.3.12.
 * `-unstable` builds always pass: a development checkout reports an old version, so their routes are trusted to tell instead.
 */
export function meetsVersion(version: string | null | undefined, minimum: string): boolean {
	const parsed = version ? parseVersion(version) : null
	if (!parsed) return false
	return parsed.unstable || atLeast(parsed.core, parseVersion(minimum)!.core)
}

export const supportedKeepiq = (version: string | null | undefined) => meetsVersion(version, MIN_KEEPIQ_VERSION)

/** Pass the account's stored `keepiqVersion`; the background decides, the popup gets the answer. */
export function keepiqSupports(version: string | null | undefined, feature: KeepiqFeature): boolean {
	return meetsVersion(version, KEEPIQ_FEATURES[feature])
}
