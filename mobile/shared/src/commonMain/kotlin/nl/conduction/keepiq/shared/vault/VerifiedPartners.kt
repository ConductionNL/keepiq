// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.vault

import nl.conduction.keepiq.shared.crypto.VerifiedFederatedCertificate

/** A verified partner certificate, and the value fields beyond `key`, `login` and `additionalFields` that partner stores. */
class VerifiedPartner(val certificate: VerifiedFederatedCertificate, val valueFields: List<String>)

/** A partner share a save writes: its id and the recipient's cloud id, as the server names them. */
data class PartnerShare(val shareId: String, val recipientCloudId: String)

/**
 * Partner certificates a failed save verified, so Save without them or a
 * retry doesn't look every partner up again against the lookup limit
 * (browser-extension/src/vault/verified-partners.ts). Public material, in memory only.
 */
object VerifiedPartners {
    const val TTL_MILLIS = 5 * 60 * 1000L

    private class Kept(val recipientCloudId: String, val verified: VerifiedPartner, val at: Long)

    private val kept = HashMap<String, Kept>()

    /** Bumped by every forget, so a save that ran across one keeps nothing. */
    private var epoch = 0

    private fun keyOf(accountId: String, shareId: String) = "$accountId\n$shareId"

    /** On every lock, logout and account switch. */
    fun forget() {
        kept.clear()
        epoch++
    }

    /** One save's view: what it may reuse, and what it verified, kept only when it fails. */
    class Session internal constructor(private val accountId: String, private val now: () -> Long) {
        private val started = epoch
        private val used = LinkedHashMap<String, Kept>()

        private fun fresh(entry: Kept) = now() - entry.at < TTL_MILLIS

        fun get(share: PartnerShare): VerifiedPartner? {
            val entry = kept[keyOf(accountId, share.shareId)] ?: return null
            if (!fresh(entry) || entry.recipientCloudId != share.recipientCloudId) return null
            used[share.shareId] = entry
            return entry.verified
        }

        fun add(share: PartnerShare, verified: VerifiedPartner) {
            used[share.shareId] = Kept(share.recipientCloudId, verified, now())
        }

        /** The shares got this save's value, so the next save looks them up again. */
        fun succeeded() {
            for (shareId in used.keys) kept.remove(keyOf(accountId, shareId))
        }

        fun failed() {
            if (epoch != started) return
            kept.entries.removeAll { !fresh(it.value) }
            for ((shareId, entry) in used) kept[keyOf(accountId, shareId)] = entry
        }
    }

    fun session(accountId: String, now: () -> Long): Session = Session(accountId, now)
}
