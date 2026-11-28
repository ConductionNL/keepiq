/**
 * A login's one-time code (spec `totp-autofill`), from its own encrypted `totp` field (keepiq#1325). Seeds and codes
 * live only for the caller's handler.
 */
import type { OpenSecretRow } from '@/src/api/types'
import { readSettings } from '@/src/accounts/settings'
import { rsaDecrypt } from '@/src/crypto'
import { readLastUsed, type LastUsed } from '@/src/last-used'
import type { InlineCode } from '@/src/messages'
import { generateCode, parseTotpSeed } from '@/src/totp/totp'
import { readSnapshot } from '@/src/vault/store'
import { candidatesFor } from './matcher'

/** Null and `''` are no value. */
export const hasCode = (row: Pick<OpenSecretRow, 'totp'>) => typeof row.totp === 'string' && row.totp !== ''

/** The logins matching `pageUrl` that have a code, last used first; use-only copies included, as their code may be shown. */
export async function matchingCodes(accountId: string, pageUrl: string | undefined): Promise<{ rows: OpenSecretRow[]; lastUsed: LastUsed }> {
	const vault = await readSnapshot(accountId)
	const lastUsed = await readLastUsed(accountId)
	if (!vault) return { rows: [], lastUsed }
	const rows = candidatesFor(pageUrl, vault, (await readSettings(accountId)).uriMatch, lastUsed)
	return { rows: rows.filter((row): row is OpenSecretRow => !row.blocked && hasCode(row)), lastUsed }
}

/** The code of `seed` at `now`, or `null` for anything that is not a valid TOTP seed. */
export async function currentCode(seed: string, now = Date.now()): Promise<{ digits: string; period: number; validUntil: number } | null> {
	try {
		const params = parseTotpSeed(seed)
		const validUntil = (Math.floor(now / 1000 / params.period) + 1) * params.period * 1000
		return { digits: await generateCode(params, now), period: params.period, validUntil }
	} catch {
		return null
	}
}

/** `row`'s seed, or `null` without one or when it does not decrypt. */
export function seedOf(row: Pick<OpenSecretRow, 'totp'>, key: CryptoKey): Promise<string | null> {
	return hasCode(row) ? rsaDecrypt(row.totp!, key).catch(() => null) : Promise.resolve(null)
}

/** `row`'s current code as the inline list shows it; a seed that does not decrypt or parse has no digits. */
export async function inlineCode(row: Pick<OpenSecretRow, 'totp'>, key: CryptoKey, now = Date.now()): Promise<InlineCode> {
	const seed = await seedOf(row, key)
	return (seed === null ? null : await currentCode(seed, now)) ?? { digits: null, period: 30, validUntil: (Math.floor(now / 30_000) + 1) * 30_000 }
}
