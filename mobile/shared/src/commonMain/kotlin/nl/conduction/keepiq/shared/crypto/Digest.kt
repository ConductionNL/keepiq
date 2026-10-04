// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.crypto

/** The platform digest and random source, for code outside the crypto package. */
object Digest {
    fun sha256(data: ByteArray): ByteArray = Primitives.sha256(data)
}

/** Cryptographically secure random bytes from the platform (design D2). */
object SecureRandomBytes {
    fun next(size: Int): ByteArray = Primitives.randomBytes(size)
}
