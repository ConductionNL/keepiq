// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.crypto

import kotlin.io.encoding.Base64

/**
 * Byte and text encodings exactly as the web app's runtime does them, so a
 * value encoded here decodes there unchanged and the reverse.
 */
object Encoding {
    private val base64 = Base64.Default
    private val base64UrlNoPad = Base64.UrlSafe.withPadding(Base64.PaddingOption.ABSENT)
    private val base64UrlAnyPad = Base64.UrlSafe.withPadding(Base64.PaddingOption.PRESENT_OPTIONAL)

    /** Standard base64 with padding (`btoa`). */
    fun toBase64(bytes: ByteArray): String = base64.encode(bytes)

    /** Standard base64 (`atob`). Whitespace is not accepted, as the server never sends it. */
    fun fromBase64(text: String): ByteArray = try {
        base64.decode(text)
    } catch (e: IllegalArgumentException) {
        throw KeepiqCryptoException("Not valid base64", e)
    }

    /** base64url without padding (sendCrypto.js toBase64Url, webauthn.js b64urlEncode). */
    fun toBase64Url(bytes: ByteArray): String = base64UrlNoPad.encode(bytes)

    /** base64url with or without padding (sendCrypto.js fromBase64Url). */
    fun fromBase64Url(text: String): ByteArray = try {
        base64UrlAnyPad.decode(text)
    } catch (e: IllegalArgumentException) {
        throw KeepiqCryptoException("Not valid base64url", e)
    }

    /**
     * UTF-8 as `TextEncoder.encode` writes it: a lone surrogate becomes
     * U+FFFD (EF BF BD), where the JVM would write `?`.
     */
    fun utf8(text: String): ByteArray {
        val out = ArrayList<Byte>(text.length + 8)
        var i = 0
        while (i < text.length) {
            var cp = text[i].code
            if (cp in 0xD800..0xDBFF && i + 1 < text.length && text[i + 1].code in 0xDC00..0xDFFF) {
                cp = 0x10000 + ((cp - 0xD800) shl 10) + (text[i + 1].code - 0xDC00)
                i++
            } else if (cp in 0xD800..0xDFFF) {
                cp = 0xFFFD
            }
            when {
                cp < 0x80 -> out.add(cp.toByte())
                cp < 0x800 -> {
                    out.add((0xC0 or (cp shr 6)).toByte())
                    out.add((0x80 or (cp and 0x3F)).toByte())
                }
                cp < 0x10000 -> {
                    out.add((0xE0 or (cp shr 12)).toByte())
                    out.add((0x80 or ((cp shr 6) and 0x3F)).toByte())
                    out.add((0x80 or (cp and 0x3F)).toByte())
                }
                else -> {
                    out.add((0xF0 or (cp shr 18)).toByte())
                    out.add((0x80 or ((cp shr 12) and 0x3F)).toByte())
                    out.add((0x80 or ((cp shr 6) and 0x3F)).toByte())
                    out.add((0x80 or (cp and 0x3F)).toByte())
                }
            }
            i++
        }
        return out.toByteArray()
    }

    /**
     * UTF-8 as `new TextDecoder().decode` reads it: invalid sequences become
     * U+FFFD, and one leading byte order mark is dropped (ignoreBOM is false
     * by default), which the web app's rsaDecrypt and decryptPrivateKey rely on.
     */
    fun fromUtf8(bytes: ByteArray): String {
        val start = if (bytes.size >= 3 && bytes[0] == 0xEF.toByte() && bytes[1] == 0xBB.toByte() &&
            bytes[2] == 0xBF.toByte()
        ) {
            3
        } else {
            0
        }
        return bytes.decodeToString(start, bytes.size, throwOnInvalidSequence = false)
    }

    internal fun uint32BigEndian(value: Long): ByteArray = byteArrayOf(
        (value ushr 24).toByte(),
        (value ushr 16).toByte(),
        (value ushr 8).toByte(),
        value.toByte(),
    )

    internal fun readUint32BigEndian(bytes: ByteArray, offset: Int): Long =
        ((bytes[offset].toLong() and 0xFF) shl 24) or
            ((bytes[offset + 1].toLong() and 0xFF) shl 16) or
            ((bytes[offset + 2].toLong() and 0xFF) shl 8) or
            (bytes[offset + 3].toLong() and 0xFF)

    internal fun hex(bytes: ByteArray): String = bytes.joinToString("") {
        (it.toInt() and 0xFF).toString(16).padStart(2, '0')
    }
}
