// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.vault

import nl.conduction.keepiq.shared.crypto.VerifiedFederatedCertificate
import kotlin.concurrent.atomics.AtomicReference
import kotlin.concurrent.atomics.ExperimentalAtomicApi

/** A verified partner certificate, and the value fields beyond `key`, `login` and `additionalFields` that partner stores. */
class VerifiedPartner(val certificate: VerifiedFederatedCertificate, val valueFields: List<String>)

/** A partner share a save writes: its id and the recipient's cloud id, as the server names them. */
data class PartnerShare(val shareId: String, val recipientCloudId: String)

/**
 * Partner certificates a failed save verified, so Save without them or a
 * retry doesn't look every partner up again against the lookup limit
 * (browser-extension/src/vault/verified-partners.ts). Public material, in memory only.
 */
@OptIn(ExperimentalAtomicApi::class)
object VerifiedPartners {
    const val TTL_MILLIS = 5 * 60 * 1000L

    private class Kept(val recipientCloudId: String, val verified: VerifiedPartner, val at: Long)

    /** [epoch] is bumped by every forget, so a save that ran across one keeps nothing. */
    private class State(val kept: Map<String, Kept>, val epoch: Int)

    // Saves run at once from the app, the autofill service and the credential provider.
    private val state = AtomicReference(State(emptyMap(), 0))

    private fun update(change: (State) -> State) {
        while (true) {
            val current = state.load()
            if (state.compareAndSet(current, change(current))) return
        }
    }

    private fun keyOf(accountId: String, shareId: String) = "$accountId\n$shareId"

    /** On every lock, logout and account switch. */
    fun forget() = update { State(emptyMap(), it.epoch + 1) }

    /** One save's view: what it may reuse, and what it verified, kept only when it fails. */
    class Session internal constructor(private val accountId: String, private val now: () -> Long) {
        private val started = state.load().epoch
        private val used = LinkedHashMap<String, Kept>()

        private fun fresh(entry: Kept) = now() - entry.at < TTL_MILLIS

        fun get(share: PartnerShare): VerifiedPartner? {
            val entry = state.load().kept[keyOf(accountId, share.shareId)] ?: return null
            if (!fresh(entry) || entry.recipientCloudId != share.recipientCloudId) return null
            used[share.shareId] = entry
            return entry.verified
        }

        fun add(share: PartnerShare, verified: VerifiedPartner) {
            used[share.shareId] = Kept(share.recipientCloudId, verified, now())
        }

        /** The shares got this save's value, so the next save looks them up again. */
        fun succeeded() = update { current ->
            State(current.kept - used.keys.map { keyOf(accountId, it) }.toSet(), current.epoch)
        }

        fun failed() = update { current ->
            if (current.epoch != started) return@update current
            State(current.kept.filterValues { fresh(it) } + used.map { (shareId, entry) -> keyOf(accountId, shareId) to entry }, current.epoch)
        }
    }

    fun session(accountId: String, now: () -> Long): Session = Session(accountId, now)
}
