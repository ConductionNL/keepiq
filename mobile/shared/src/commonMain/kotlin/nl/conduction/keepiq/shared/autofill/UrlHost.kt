// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.autofill

/**
 * The host name the browser extension's `hostOf` reads from a URL
 * (browser-extension/src/lib/match.js), which is `new URL(…).hostname`
 * lower-cased. Common code has no WHATWG URL parser, so this is one for the
 * part `hostOf` uses: the authority. It follows the URL Standard's host
 * parser for special schemes (percent-decoding, forbidden code points,
 * IPv4 numbers, IPv6 brackets, IDNA as punycode) and the opaque host rules
 * for the others, such as `androidapp://`.
 *
 * IDNA here lower-cases and punycodes each label. The full UTS 46 mapping
 * table is not carried; a host that needs more than lower case to map reads
 * differently from the extension, which only narrows or widens the list the
 * user picks from (match.js says the same of its suffix list).
 */
internal object UrlHost {
    private val SPECIAL = setOf("http", "https", "ws", "wss", "ftp", "file")

    /** Code points a domain may not hold after percent-decoding (URL Standard, forbidden domain code point). */
    private const val FORBIDDEN_HOST = " #/:<>?@[\\]^|"

    /** `new URL(input).hostname`, or null where the URL parser throws. */
    fun hostname(url: String): String? {
        // The parser trims C0 controls and spaces, and drops tabs and newlines anywhere.
        val input = url.trim { it <= ' ' }.filter { it != '\t' && it != '\n' && it != '\r' }
        val colon = input.indexOf(':')
        if (colon <= 0) return null
        val scheme = input.substring(0, colon).lowercase()
        if (!scheme[0].isAsciiLetter() || !scheme.all { it.isAsciiLetter() || it.isDigit() || it == '+' || it == '-' || it == '.' }) return null
        val special = scheme in SPECIAL
        var rest = input.substring(colon + 1)
        if (scheme == "file") {
            // file://host/path names a host; file:/path and file:///path do not.
            if (!rest.startsWith("//")) return ""
            val host = rest.substring(2).substringBefore('/').substringBefore('\\').substringBefore('?').substringBefore('#')
            if (host.isEmpty()) return ""
            return parseSpecialHost(host)?.let { if (it == "localhost") "" else it }
        }
        if (special) {
            // Special schemes take any number of slashes, either way round.
            rest = rest.trimStart('/', '\\')
        } else {
            if (!rest.startsWith("//")) return ""
            rest = rest.substring(2)
        }
        val end = rest.indexOfFirst { it == '/' || it == '?' || it == '#' || (special && it == '\\') }
        var authority = if (end < 0) rest else rest.substring(0, end)
        val at = authority.lastIndexOf('@')
        if (at >= 0) authority = authority.substring(at + 1)
        val host = splitPort(authority) ?: return null
        if (!special) return parseOpaqueHost(host)
        if (host.isEmpty()) return null
        return parseSpecialHost(host)
    }

    /** The host without its port; null when the port is not a number up to 65535. */
    private fun splitPort(authority: String): String? {
        val bracketEnd = authority.lastIndexOf(']')
        val colon = authority.lastIndexOf(':')
        if (colon < 0 || colon < bracketEnd) return authority
        val port = authority.substring(colon + 1)
        if (port.isNotEmpty() && (!port.all { it in '0'..'9' } || port.trimStart('0').length > 5 || (port.trimStart('0').toIntOrNull() ?: 0) > 65535)) {
            return null
        }
        return authority.substring(0, colon)
    }

    private fun parseOpaqueHost(host: String): String? {
        if (host.startsWith("[")) return parseIpv6Literal(host)
        if (host.any { it in " #/:<>?@[\\]^|" }) return null
        return host
    }

    private fun parseSpecialHost(host: String): String? {
        if (host.startsWith("[")) return parseIpv6Literal(host)
        val decoded = percentDecode(host) ?: return null
        val ascii = toAscii(decoded) ?: return null
        if (ascii.isEmpty()) return null
        if (ascii.any { it in FORBIDDEN_HOST || it.code < 0x20 || it.code == 0x7f || it == '%' }) return null
        if (endsInANumber(ascii)) return ipv4(ascii)
        return ascii
    }

    private fun parseIpv6Literal(host: String): String? {
        if (!host.endsWith("]")) return null
        val inner = host.substring(1, host.length - 1)
        if (inner.isEmpty() || !inner.all { it.isDigit() || it.lowercaseChar() in 'a'..'f' || it == ':' || it == '.' }) return null
        // The serialisation compresses zeros; the extension compares the
        // result only with other hosts, so the lower-cased literal is kept.
        return "[" + inner.lowercase() + "]"
    }

    private fun percentDecode(text: String): String? {
        if (!text.contains('%')) return text
        val bytes = ArrayList<Byte>()
        var i = 0
        while (i < text.length) {
            val c = text[i]
            if (c == '%' && i + 2 < text.length && isHex(text[i + 1]) && isHex(text[i + 2])) {
                bytes.add(text.substring(i + 1, i + 3).toInt(16).toByte())
                i += 3
                continue
            }
            c.toString().encodeToByteArray().forEach { bytes.add(it) }
            i++
        }
        return bytes.toByteArray().decodeToString()
    }

    private fun isHex(c: Char) = c in '0'..'9' || c.lowercaseChar() in 'a'..'f'

    /** Lower case, then punycode for every label with a non-ASCII character. */
    private fun toAscii(domain: String): String? {
        val mapped = domain.lowercase().replace('。', '.').replace('．', '.').replace('｡', '.')
        val labels = ArrayList<String>()
        for (label in mapped.split('.')) {
            labels += if (label.all { it.code < 0x80 }) label else "xn--" + (Punycode.encode(label) ?: return null)
        }
        return labels.joinToString(".")
    }

    private fun endsInANumber(host: String): Boolean {
        val parts = host.split('.').toMutableList()
        if (parts.last().isEmpty()) {
            if (parts.size == 1) return false
            parts.removeAt(parts.size - 1)
        }
        val last = parts.last()
        if (last.isNotEmpty() && last.all { it in '0'..'9' }) return true
        return parseIpv4Number(last) != null
    }

    /** One IPv4 part: decimal, 0x hexadecimal or 0 octal. */
    private fun parseIpv4Number(part: String): Long? {
        if (part.isEmpty()) return null
        var text = part
        var radix = 10
        if (text.length >= 2 && (text.startsWith("0x") || text.startsWith("0X"))) {
            text = text.substring(2)
            radix = 16
        } else if (text.length >= 2 && text.startsWith("0")) {
            text = text.substring(1)
            radix = 8
        }
        if (text.isEmpty()) return 0
        val digits = when (radix) {
            16 -> text.all { isHex(it) }
            8 -> text.all { it in '0'..'7' }
            else -> text.all { it in '0'..'9' }
        }
        if (!digits) return null
        return text.toBigLongOrNull(radix)
    }

    private fun String.toBigLongOrNull(radix: Int): Long? {
        var value = 0L
        for (c in this) {
            value = value * radix + c.digitToInt(radix)
            if (value > 0xFFFFFFFFL * 256) return Long.MAX_VALUE
        }
        return value
    }

    private fun ipv4(host: String): String? {
        val parts = host.split('.').toMutableList()
        if (parts.last().isEmpty() && parts.size > 1) parts.removeAt(parts.size - 1)
        if (parts.size > 4) return null
        val numbers = parts.map { parseIpv4Number(it) ?: return null }
        if (numbers.dropLast(1).any { it > 255 }) return null
        val limit = 1L shl (8 * (5 - numbers.size))
        if (numbers.last() >= limit) return null
        var address = numbers.last()
        numbers.dropLast(1).forEachIndexed { i, n -> address += n shl (8 * (3 - i)) }
        return (3 downTo 0).joinToString(".") { ((address shr (8 * it)) and 0xFF).toString() }
    }

    private fun Char.isAsciiLetter() = this in 'a'..'z' || this in 'A'..'Z'
}

/** RFC 3492 punycode encoding of one label, as IDNA's ToASCII applies it. */
internal object Punycode {
    private const val BASE = 36
    private const val TMIN = 1
    private const val TMAX = 26
    private const val SKEW = 38
    private const val DAMP = 700
    private const val INITIAL_BIAS = 72
    private const val INITIAL_N = 128

    fun encode(label: String): String? {
        val codePoints = codePointsOf(label)
        val out = StringBuilder()
        codePoints.filter { it < 0x80 }.forEach { out.append(it.toChar()) }
        val basic = out.length
        var handled = basic
        if (basic > 0) out.append('-')
        var n = INITIAL_N
        var delta = 0L
        var bias = INITIAL_BIAS
        while (handled < codePoints.size) {
            val m = codePoints.filter { it >= n }.minOrNull() ?: return null
            delta += (m - n).toLong() * (handled + 1)
            n = m
            for (c in codePoints) {
                if (c < n) delta++
                if (c == n) {
                    var q = delta
                    var k = BASE
                    while (true) {
                        val t = if (k <= bias) TMIN else if (k >= bias + TMAX) TMAX else k - bias
                        if (q < t) break
                        out.append(digit((t + (q - t) % (BASE - t)).toInt()))
                        q = (q - t) / (BASE - t)
                        k += BASE
                    }
                    out.append(digit(q.toInt()))
                    bias = adapt(delta, handled + 1, handled == basic)
                    delta = 0
                    handled++
                }
            }
            delta++
            n++
        }
        return out.toString()
    }

    private fun adapt(deltaIn: Long, points: Int, first: Boolean): Int {
        var delta = if (first) deltaIn / DAMP else deltaIn / 2
        delta += delta / points
        var k = 0
        while (delta > ((BASE - TMIN) * TMAX) / 2) {
            delta /= BASE - TMIN
            k += BASE
        }
        return (k + (BASE - TMIN + 1) * delta / (delta + SKEW)).toInt()
    }

    private fun digit(d: Int): Char = if (d < 26) 'a' + d else '0' + (d - 26)

    private fun codePointsOf(text: String): List<Int> {
        val out = ArrayList<Int>()
        var i = 0
        while (i < text.length) {
            val c = text[i]
            if (c.isHighSurrogate() && i + 1 < text.length && text[i + 1].isLowSurrogate()) {
                out.add(((c.code - 0xD800) shl 10) + (text[i + 1].code - 0xDC00) + 0x10000)
                i += 2
            } else {
                out.add(c.code)
                i++
            }
        }
        return out
    }
}
