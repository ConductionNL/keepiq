/** Partner certificates a failed save verified, so Save without them or Try again doesn't look every partner up again against the lookup limit (the web app's `verifiedPartners`). Public material, in background memory only. */
import type { VerifiedFederatedCertificate } from '@/src/crypto'

export const VERIFIED_PARTNER_TTL_MS = 5 * 60 * 1000

interface Kept { recipientCloudId: string; verified: VerifiedFederatedCertificate; at: number }
interface Share { shareId: string; recipientCloudId: string }

const kept = new Map<string, Kept>()
/** Bumped by every forget, so a save that ran across one keeps nothing. */
let epoch = 0

const keyOf = (accountId: string, shareId: string) => `${accountId}\n${shareId}`

/** On every lock, logout and account switch. */
export function forgetVerifiedPartners(): void {
	kept.clear()
	epoch++
}

/** One save's view: what it may reuse, and what it verified, kept only when it fails. */
export function verifiedPartners(accountId: string) {
	const started = epoch
	const used = new Map<string, Kept>()
	const fresh = (entry: Kept) => Date.now() - entry.at < VERIFIED_PARTNER_TTL_MS
	return {
		get(share: Share): VerifiedFederatedCertificate | undefined {
			const entry = kept.get(keyOf(accountId, share.shareId))
			if (!entry || !fresh(entry) || entry.recipientCloudId !== share.recipientCloudId) return undefined
			used.set(share.shareId, entry)
			return entry.verified
		},
		add(share: Share, verified: VerifiedFederatedCertificate): void {
			used.set(share.shareId, { recipientCloudId: share.recipientCloudId, verified, at: Date.now() })
		},
		/** The share got this save's value, so the next save looks it up again. */
		succeeded(): void {
			for (const shareId of used.keys()) kept.delete(keyOf(accountId, shareId))
		},
		failed(): void {
			if (epoch !== started) return
			for (const [key, entry] of kept) if (!fresh(entry)) kept.delete(key)
			for (const [shareId, entry] of used) kept.set(keyOf(accountId, shareId), entry)
		},
	}
}
