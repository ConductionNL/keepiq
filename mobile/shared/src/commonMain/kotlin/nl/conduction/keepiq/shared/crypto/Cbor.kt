// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.crypto

/**
 * The CBOR subset WebAuthn needs, byte for byte as
 * browser-extension/src/passkey/cbor.js cborEncode writes it: integers,
 * byte strings, text strings, arrays and maps in insertion order, always in
 * the shortest head. Used for the attestation object and the COSE key.
 */
internal object Cbor {
    /** A map with its keys in the order given, as the extension's Map keeps them. */
    class OrderedMap(val entries: List<Pair<Any, Any>>)

    fun encode(value: Any): ByteArray = when (value) {
        is Int -> integer(value.toLong())
        is Long -> integer(value)
        is ByteArray -> head(2, value.size.toLong()) + value
        is String -> Encoding.utf8(value).let { head(3, it.size.toLong()) + it }
        is List<*> -> value.fold(head(4, value.size.toLong())) { acc, v -> acc + encode(v ?: error("cbor: null")) }
        is OrderedMap -> value.entries.fold(head(5, value.entries.size.toLong())) { acc, (k, v) -> acc + encode(k) + encode(v) }
        else -> throw IllegalArgumentException("cbor: unsupported value ${value::class.simpleName}")
    }

    private fun integer(value: Long): ByteArray = if (value >= 0) head(0, value) else head(1, -value - 1)

    private fun head(major: Int, value: Long): ByteArray {
        val m = major shl 5
        return when {
            value < 24 -> byteArrayOf((m or value.toInt()).toByte())
            value < 0x100 -> byteArrayOf((m or 24).toByte(), value.toByte())
            value < 0x10000 -> byteArrayOf((m or 25).toByte(), (value shr 8).toByte(), value.toByte())
            else -> byteArrayOf((m or 26).toByte()) + Encoding.uint32BigEndian(value)
        }
    }
}
