// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.vault

import kotlinx.coroutines.CancellationException
import nl.conduction.keepiq.shared.api.KeepiqApi
import nl.conduction.keepiq.shared.api.KeepiqApiException

/** The login was not there when it was read fresh. */
class LoginGoneException : IllegalStateException("The login is gone")

/**
 * The fresh read of a login never reached the server: a transport failure,
 * or a [KeepiqApiException] with status 0, before any answer. Status 0, as
 * WriteProblem reads it, so a write still shows as offline.
 */
class ServerUnreachableException(cause: Throwable) : KeepiqApiException(0, "Cannot reach the server") {
    init {
        initCause(cause)
    }
}

/**
 * The read-modify-write of a login's `passkeys` (mobile-login-totp-passkeys
 * D7, D11, D12): the login is read fresh, the change is made on the
 * decrypted value, and the PUT carries the row's `updatedAt` as
 * `baseUpdatedAt`, so a change saved elsewhere in between is refused with
 * 409 (lib/Service/OfflineEditGuard.php) instead of overwritten. After that
 * refusal the login is read and changed once more; a second refusal fails.
 * A server that does not store `passkeys` refuses before the PUT
 * (KeepiqApi.requireLoginFields).
 */
object PasskeysWrite {
    /** The server's answer to a write based on an older version (StaleWriteException). */
    const val STALE_WRITE = 409

    /** What a change decided: the new value, or null to write nothing, and what [rewrite] returns. */
    class Change<T>(val passkeys: String?, val result: T)

    /**
     * Reads login [id] fresh and writes what [change] makes of its
     * decrypted `passkeys` (`""` for none). Throws [LoginGoneException] when
     * the login is not there, and [ServerUnreachableException] only when the
     * fresh read got no answer at all.
     */
    suspend fun <T> rewrite(api: KeepiqApi, keys: VaultKeys, id: String, change: (fresh: VaultRow, stored: String) -> Change<T>): T {
        var attempt = 0
        while (true) {
            val row = try {
                api.getSecret(id)
            } catch (e: CancellationException) {
                throw e
            } catch (e: KeepiqApiException) {
                if (e.status == 0) throw ServerUnreachableException(e)
                throw e
            } catch (e: Exception) {
                // KeepiqApi turns every answer into JSON or a KeepiqApiException: anything else is the transport.
                throw ServerUnreachableException(e)
            }
            val fresh = row?.let { VaultRow.from(it) } ?: throw LoginGoneException()
            val stored = if (fresh.passkeys.isNullOrEmpty()) "" else keys.decryptField(fresh.passkeys)
            val next = change(fresh, stored)
            val passkeys = next.passkeys ?: return next.result
            api.requireLoginFields()
            try {
                api.updateSecret(id, ItemCodec.passkeysBody(passkeys, keys, fresh.updatedAt))
                return next.result
            } catch (e: KeepiqApiException) {
                attempt++
                if (e.status != STALE_WRITE || attempt > 1) throw e
            }
        }
    }
}
