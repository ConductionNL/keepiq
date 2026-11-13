/**
 * Whether this browser can run a passkey ceremony from an extension page with the paired server as relying party.
 * `browser_too_old`: Chrome and Edge before 122 and Firefox before 150 refuse that relying party.
 */
export type PasskeySupport = 'supported' | 'browser_too_old' | 'unsupported'

const MINIMUM: ReadonlyArray<readonly [RegExp, number]> = [[/Firefox\/(\d+)/, 150], [/Chrom(?:e|ium)\/(\d+)/, 122]]

interface Environment {
	target: string
	userAgent: string
	credential: typeof PublicKeyCredential | undefined
}

function current(): Environment {
	return {
		target: import.meta.env.BROWSER,
		userAgent: globalThis.navigator?.userAgent ?? '',
		credential: (globalThis as { PublicKeyCredential?: typeof PublicKeyCredential }).PublicKeyCredential,
	}
}

/** Runs in an extension page, not the worker, which has no WebAuthn. */
export async function passkeySupport(env: Environment = current()): Promise<PasskeySupport> {
	// Safari's extension origin and relying party rules are unchecked, so it keeps the master password only.
	if (env.target === 'safari') return 'unsupported'
	const engine = MINIMUM.map(([pattern, minimum]) => [env.userAgent.match(pattern), minimum] as const).find(([match]) => match)
	if (!engine) return 'unsupported'
	if (Number(engine[0]![1]) < engine[1]) return 'browser_too_old'
	if (!env.credential) return 'unsupported'
	if (!(await env.credential.isUserVerifyingPlatformAuthenticatorAvailable().catch(() => false))) return 'unsupported'
	// A browser that reports its capabilities must report PRF; one that does not is checked at enrolment.
	const capabilities = await (env.credential as { getClientCapabilities?: () => Promise<Record<string, boolean>> }).getClientCapabilities?.().catch(() => undefined)
	if (capabilities && capabilities['extension:prf'] === false) return 'unsupported'
	return 'supported'
}
