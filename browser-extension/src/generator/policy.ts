import { PASSWORD_LENGTH, type GeneratorPolicy } from './options'

/** The server's default floor (`PasswordPolicyService`), for a policy that names none. */
const DEFAULT_FLOOR = 12

/** `parseInt` as the web module does, so both read the same floor. */
function floor(value: unknown): number {
	const parsed = Number.parseInt(String(value ?? DEFAULT_FLOOR), 10)
	// The server accepts any floor from 8 up; above the generator's maximum no password could meet it.
	return Math.min(PASSWORD_LENGTH.max, Math.max(PASSWORD_LENGTH.min, Number.isNaN(parsed) ? DEFAULT_FLOOR : parsed))
}

/** The generator part of `GET /api/settings/policy`, as the web app's `generatorPolicy` maps it. */
export function toPolicy(raw: unknown): GeneratorPolicy | null {
	if (typeof raw !== 'object' || raw === null) return null
	const body = raw as Record<string, unknown>
	if (body.policy_enabled !== true) return null
	return {
		minLength: floor(body.generator_min_length),
		requireUpper: body.generator_require_upper === true,
		requireLower: body.generator_require_lower === true,
		requireDigit: body.generator_require_digit === true,
		requireSymbol: body.generator_require_symbol === true,
		allowPassphrase: body.generator_allow_passphrase !== false,
	}
}
