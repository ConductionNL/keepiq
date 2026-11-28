/**
 * The passkeys a conditional request may offer in the inline list. A page can start requests and focus fields at will,
 * so every login's passkeys are decrypted once per account, key and vault version, never per request (design.md § Risks).
 */
import type { PasskeyRow } from '@/src/messages'
import { listPasskeys, offeredFrom, type PasskeyListing } from './match'

let version = 0
let cached: { accountId: string; key: CryptoKey; version: number; listing: Promise<PasskeyListing[]> } | null = null

/**
 * Every passkey for `rpId`, with no allow list to filter by. The caller holds the account's unlocked key and checked
 * the request may still be offered passkeys.
 */
export async function offeredPasskeys(accountId: string, key: CryptoKey, rpId: string): Promise<PasskeyRow[]> {
	let entry = cached
	if (entry?.accountId !== accountId || entry.key !== key || entry.version !== version) {
		const fresh = { accountId, key, version, listing: listPasskeys(accountId, key) }
		fresh.listing.catch(() => {
			if (cached === fresh) cached = null
		})
		cached = entry = fresh
	}
	return offeredFrom(await entry.listing, { rpId, allowCredentials: [] })
}

/** On a vault change, lock, logout, account switch and turning passkeys off. */
export function forgetOfferedPasskeys(): void {
	version++
	cached = null
}
