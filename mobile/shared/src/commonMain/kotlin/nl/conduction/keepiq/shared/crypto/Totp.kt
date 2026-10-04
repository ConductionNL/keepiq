// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.crypto

/**
 * RFC 6238 TOTP, parsed and computed as src/totp/totp.js does: an
 * `otpauth://totp/` URI or a bare base32 secret; SHA1, SHA256 or SHA512;
 * 6 or 8 digits (anything else falls back to 6); a positive period, default
 * 30 seconds. HOTP and other otpauth types are refused.
 */
object Totp {
    private const val BASE32_ALPHABET = "ABCDEFGHIJKLMNOPQRSTUVWXYZ234567"

    /** Parsed parameters; [issuer] and [account] are for display only. */
    data class Params(
        val secret: String,
        val algorithm: String,
        val digits: Int,
        val period: Int,
        val issuer: String?,
        val account: String?,
    )

    /** base32Decode: upper-cases, drops trailing `=` and all whitespace. */
    fun base32Decode(input: String): ByteArray {
        val clean = input.uppercase().trimEnd('=').filterNot { it.isWhitespace() }
        if (clean.isEmpty()) throw KeepiqCryptoException("Empty base32 secret")
        var bits = 0
        var value = 0
        val out = ArrayList<Byte>()
        for (char in clean) {
            val idx = BASE32_ALPHABET.indexOf(char)
            if (idx == -1) throw KeepiqCryptoException("Invalid base32 character")
            value = (value shl 5) or idx
            bits += 5
            if (bits >= 8) {
                bits -= 8
                out.add(((value ushr bits) and 0xFF).toByte())
            }
        }
        if (out.isEmpty()) throw KeepiqCryptoException("Base32 secret too short")
        return out.toByteArray()
    }

    /** parseOtpauth. */
    fun parse(raw: String): Params {
        val value = raw.trim()
        if (value.isEmpty()) throw KeepiqCryptoException("Empty TOTP seed")
        if (!value.startsWith("otpauth://", ignoreCase = true)) {
            base32Decode(value)
            return Params(value.filterNot { it.isWhitespace() }.uppercase(), "SHA1", 6, 30, null, null)
        }
        val rest = value.substring("otpauth://".length)
        val hostEnd = rest.indexOfFirst { it == '/' || it == '?' || it == '#' }.let { if (it < 0) rest.length else it }
        if (rest.substring(0, hostEnd).lowercase() != "totp") throw KeepiqCryptoException("Not an otpauth://totp URI")
        val afterHost = rest.substring(hostEnd).substringBefore('#')
        val path = afterHost.substringBefore('?')
        val query = if (afterHost.contains('?')) afterHost.substringAfter('?') else ""
        val params = parseQuery(query)

        val secretParam = params["secret"]
        if (secretParam.isNullOrEmpty()) throw KeepiqCryptoException("otpauth URI has no secret")
        base32Decode(secretParam)

        val algorithm = params["algorithm"].let { a ->
            if (a.isNullOrEmpty()) {
                "SHA1"
            } else {
                a.uppercase().also {
                    if (it !in setOf("SHA1", "SHA256", "SHA512")) throw KeepiqCryptoException("Unsupported TOTP algorithm: $it")
                }
            }
        }
        val digits = if (parseIntLikeJs(params["digits"]) == 8) 8 else 6
        val period = parseIntLikeJs(params["period"])?.takeIf { it > 0 } ?: 30

        val label = percentDecode(path.removePrefix("/"))
        var account: String? = label.ifEmpty { null }
        var issuer: String? = params["issuer"]
        if (label.contains(':')) {
            val parts = label.split(':')
            if (issuer.isNullOrEmpty()) issuer = parts[0].trim().ifEmpty { null }
            account = parts.drop(1).joinToString(":").trim().ifEmpty { null }
        }
        return Params(
            secret = secretParam.filterNot { it.isWhitespace() }.uppercase(),
            algorithm = algorithm,
            digits = digits,
            period = period,
            issuer = issuer?.ifEmpty { null },
            account = account,
        )
    }

    /** generateTotp at [epochMillis]. */
    fun generate(params: Params, epochMillis: Long): String {
        val key = base32Decode(params.secret)
        val algorithm = when (params.algorithm) {
            "SHA256" -> HmacAlgorithm.SHA256
            "SHA512" -> HmacAlgorithm.SHA512
            else -> HmacAlgorithm.SHA1
        }
        val counter = floorDiv(epochMillis, 1000L * params.period)
        val counterBytes = ByteArray(8)
        var temp = counter
        for (i in 7 downTo 0) {
            counterBytes[i] = (temp and 0xFF).toByte()
            temp = temp ushr 8
        }
        val hmac = Primitives.hmac(algorithm, key, counterBytes)
        val offset = hmac[hmac.size - 1].toInt() and 0x0F
        val binary = ((hmac[offset].toInt() and 0x7F) shl 24) or
            ((hmac[offset + 1].toInt() and 0xFF) shl 16) or
            ((hmac[offset + 2].toInt() and 0xFF) shl 8) or
            (hmac[offset + 3].toInt() and 0xFF)
        var mod = 1
        repeat(params.digits) { mod *= 10 }
        return (binary % mod).toString().padStart(params.digits, '0')
    }

    /** Seconds left in the current window. */
    fun secondsRemaining(period: Int, epochMillis: Long): Int =
        period - (floorDiv(epochMillis, 1000L) % period).toInt()

    private fun floorDiv(a: Long, b: Long): Long {
        val q = a / b
        return if ((a % b != 0L) && ((a < 0) != (b < 0))) q - 1 else q
    }

    /** parseInt(raw, 10): leading whitespace and sign, then digits; null when none. */
    private fun parseIntLikeJs(raw: String?): Int? {
        if (raw == null) return null
        val s = raw.trimStart()
        var i = 0
        var negative = false
        if (i < s.length && (s[i] == '+' || s[i] == '-')) {
            negative = s[i] == '-'
            i++
        }
        val start = i
        while (i < s.length && s[i] in '0'..'9') i++
        if (i == start) return null
        val n = s.substring(start, i).take(10).toLongOrNull() ?: return null
        val v = if (negative) -n else n
        return v.coerceIn(Int.MIN_VALUE.toLong(), Int.MAX_VALUE.toLong()).toInt()
    }

    /** URLSearchParams: `+` is a space, percent-decoding, first value wins. */
    private fun parseQuery(query: String): Map<String, String> {
        val out = LinkedHashMap<String, String>()
        if (query.isEmpty()) return out
        for (pair in query.split('&')) {
            if (pair.isEmpty()) continue
            val name = percentDecode(pair.substringBefore('=').replace('+', ' '))
            val value = if (pair.contains('=')) percentDecode(pair.substringAfter('=').replace('+', ' ')) else ""
            if (name !in out) out[name] = value
        }
        return out
    }

    private fun percentDecode(text: String): String {
        if (!text.contains('%')) return text
        val bytes = ArrayList<Byte>()
        var i = 0
        while (i < text.length) {
            val c = text[i]
            if (c == '%' && i + 2 < text.length && text.substring(i + 1, i + 3).toIntOrNull(16) != null) {
                bytes.add(text.substring(i + 1, i + 3).toInt(16).toByte())
                i += 3
            } else {
                Encoding.utf8(c.toString()).forEach { bytes.add(it) }
                i++
            }
        }
        return Encoding.fromUtf8(bytes.toByteArray())
    }
}
