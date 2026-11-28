// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.account

/**
 * The lock state the app and its autofill extension share (design D4,
 * "Locking"; task 2.5): the extension honours the app's idle time, the
 * organisation's cap and every lock of the app or the device.
 *
 * The app and the extension run in separate processes and share only the
 * device-bound [SecureStorage] (on iOS the Keychain access group of the app
 * group). The app writes two values per account there:
 *
 * - the time of its last lock: an idle lock, a lock the user asked for, a
 *   lock by sync, or the device locking;
 * - the organisation's `maxIdleMinutes` it read at its last unlock, or none
 *   when the policy could not be read, so the default cap applies and an
 *   unreachable policy never lengthens the delay.
 *
 * The extension keeps the vault keys it opened only while [isOpen] says so.
 *
 * @spec openspec/changes/clients-mobile-apps/specs/mobile-pairing-and-unlock/spec.md
 */
class SharedLockState(private val storage: SecureStorage, private val clock: () -> Long) {
    /**
     * Records that the app locked the account's vault now. The extension
     * then forgets keys it opened before this moment.
     *
     * @spec openspec/changes/clients-mobile-apps/specs/mobile-pairing-and-unlock/spec.md
     */
    fun recordLock(accountId: String) {
        storage.write(lockedAtKey(accountId), clock().toString())
    }

    /**
     * Records the organisation's idle maximum the app read, or that it could
     * not be read (null).
     *
     * @spec openspec/changes/clients-mobile-apps/specs/mobile-pairing-and-unlock/spec.md
     */
    fun recordOrganisationMax(accountId: String, maxMinutes: Int?) {
        if (maxMinutes == null || maxMinutes <= 0) {
            storage.delete(maxKey(accountId))
        } else {
            storage.write(maxKey(accountId), maxMinutes.toString())
        }
    }

    /**
     * The organisation's idle maximum the app last read, or null.
     *
     * @spec openspec/changes/clients-mobile-apps/specs/mobile-pairing-and-unlock/spec.md
     */
    fun organisationMax(accountId: String): Int? = storage.read(maxKey(accountId))?.toIntOrNull()?.takeIf { it > 0 }

    /**
     * When the app last locked the account's vault, in epoch milliseconds,
     * or null when it never did.
     *
     * @spec openspec/changes/clients-mobile-apps/specs/mobile-pairing-and-unlock/spec.md
     */
    fun lastLockMillis(accountId: String): Long? = storage.read(lockedAtKey(accountId))?.toLongOrNull()

    /**
     * The idle time that applies to the account: the user's [choice] capped
     * by the organisation's maximum the app recorded.
     *
     * @spec openspec/changes/clients-mobile-apps/specs/mobile-pairing-and-unlock/spec.md
     */
    fun effectiveMinutes(accountId: String, choice: Int): Int = IdlePolicy.effectiveMinutes(choice, organisationMax(accountId))

    /**
     * Whether vault keys the extension opened at [keptAtMillis] may still be
     * used: the idle time has not run out, and the app has not locked since.
     * A lock in the same millisecond counts as after, so a tie locks.
     *
     * @spec openspec/changes/clients-mobile-apps/specs/mobile-pairing-and-unlock/spec.md
     */
    fun isOpen(accountId: String, keptAtMillis: Long, choice: Int): Boolean {
        val now = clock()
        if (now < keptAtMillis) return false
        if (now - keptAtMillis >= effectiveMinutes(accountId, choice) * 60_000L) return false
        val lockedAt = lastLockMillis(accountId) ?: return true
        return lockedAt < keptAtMillis
    }

    /**
     * Deletes what this class keeps for the account, on unpair.
     *
     * @spec openspec/changes/clients-mobile-apps/specs/mobile-pairing-and-unlock/spec.md
     */
    fun forget(accountId: String) {
        storage.delete(lockedAtKey(accountId))
        storage.delete(maxKey(accountId))
    }

    private fun lockedAtKey(accountId: String) = "lock.at:$accountId"

    private fun maxKey(accountId: String) = "lock.max:$accountId"
}
