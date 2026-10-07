// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.crypto

import kotlinx.cinterop.ExperimentalForeignApi
import kotlinx.cinterop.addressOf
import kotlinx.cinterop.convert
import kotlinx.cinterop.usePinned
import nl.conduction.keepiq.shared.argon2.argon2id_hash_raw

/**
 * Argon2id on iOS (task 1.3.1): neither the Security framework nor CryptoKit
 * has it, so this calls the reference C code (src/nativeInterop/argon2,
 * CC0 or Apache 2.0) through cinterop, as design D1 names it. Same
 * parameters and output as Bouncy Castle on Android; the known-answer and
 * password-Send vectors check both.
 */
@OptIn(ExperimentalForeignApi::class)
internal actual fun argon2id(
    password: ByteArray,
    salt: ByteArray,
    memoryKiB: Int,
    iterations: Int,
    parallelism: Int,
    lengthBytes: Int,
): ByteArray {
    if (salt.isEmpty() || lengthBytes <= 0) throw KeepiqCryptoException("Argon2id needs a salt and an output length")
    val out = ByteArray(lengthBytes)
    // An empty array has no address to pin; Argon2 accepts NULL with length 0.
    val pwd = if (password.isEmpty()) ByteArray(1) else password
    val rc = pwd.usePinned { p ->
        salt.usePinned { s ->
            out.usePinned { o ->
                argon2id_hash_raw(
                    iterations.convert(),
                    memoryKiB.convert(),
                    parallelism.convert(),
                    if (password.isEmpty()) null else p.addressOf(0),
                    password.size.convert(),
                    s.addressOf(0),
                    salt.size.convert(),
                    o.addressOf(0),
                    lengthBytes.convert(),
                )
            }
        }
    }
    if (rc != 0) throw KeepiqCryptoException("Argon2id failed (code $rc)")
    return out
}

internal actual val argon2idAvailable: Boolean = true
