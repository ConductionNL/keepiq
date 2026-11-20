// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.crypto

import nl.conduction.keepiq.shared.send.IsoTime

/** Why a partner recipient's certificate was refused, as the extension names it. */
enum class FederatedCertificateReason(val code: String) {
    MALFORMED("malformed"),
    UNTRUSTED_ROOT("untrusted_root"),
    BAD_CHAIN("bad_chain"),
    NAME_MISMATCH("name_mismatch"),
    NOT_VALID_NOW("not_valid_now"),
}

class FederatedCertificateException(val reason: FederatedCertificateReason) : KeepiqCryptoException(reason.code)

/**
 * A SHA-256 RSASSA-PSS signature with a salt other than 32 bytes, which the
 * web app checks but iOS can't: no verdict on the certificate, so it stops
 * the save instead of suspending the share.
 */
class FederatedCertificateUnsupportedException : KeepiqCryptoException("unsupported_signature")

/** A verified leaf: its SHA-256 as lowercase hex, the `certFingerprint` Keepiq takes, and the key that was checked. */
class VerifiedFederatedCertificate(val fingerprint: String, val spki: ByteArray)

/**
 * A port of browser-extension/src/crypto/federated-certificate.ts: catches a
 * certificate for the wrong user, an expired one, or a chain from another
 * partner before anything is encrypted to it. RSASSA-PSS is taken only with
 * a 32-byte salt, the one Keepiq signs with and the one iOS verifies; any
 * other throws [FederatedCertificateUnsupportedException]. Name constraints
 * are refused in any form, as the web app does: nothing here enforces them.
 */
object FederatedCertificate {
    private const val OID_COMMON_NAME = "2.5.4.3"
    private const val OID_BASIC_CONSTRAINTS = "2.5.29.19"
    private const val OID_KEY_USAGE = "2.5.29.15"
    private const val OID_NAME_CONSTRAINTS = "2.5.29.30"
    private const val OID_SHA256_RSA = "1.2.840.113549.1.1.11"
    private const val OID_RSA_PSS = "1.2.840.113549.1.1.10"
    private const val OID_MGF1 = "1.2.840.113549.1.1.8"
    private const val OID_SHA256 = "2.16.840.1.101.3.4.2.1"
    private const val UTF8_STRING = 0x0c
    private val ASCII_STRINGS = setOf(0x13, 0x14, 0x16)
    private const val PSS_SALT = 32

    /** How far `notBefore` may lie ahead of this clock; `notAfter` is strict. */
    private const val CLOCK_SKEW_MS = 5 * 60 * 1000L

    private val PEM = Regex("""^\s*-----BEGIN ([A-Z ]+)-----([A-Za-z0-9+/=\s]+)-----END \1-----\s*$""")
    private val TIME = Regex("""^(\d{2}|\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})Z$""")

    private class Element(val tag: Int, val offset: Int, val start: Int, val end: Int)

    private class Parsed(
        val der: ByteArray,
        val tbs: ByteArray,
        /** Null for a signature algorithm Keepiq does not use; true for PSS. */
        val pss: Boolean?,
        /** RSASSA-PSS with SHA-256 throughout but a salt other than 32 bytes. */
        val otherSalt: Boolean,
        val signature: ByteArray,
        val issuer: ByteArray,
        val subject: ByteArray,
        val notBefore: Long,
        val notAfter: Long,
        val spki: ByteArray,
        val ca: Boolean,
        val mayIssue: Boolean,
        val pathLen: Int?,
    )

    private fun malformed() = FederatedCertificateException(FederatedCertificateReason.MALFORMED)

    /**
     * Verifies [certificate] against [chain] (the partner's intermediate, then
     * its root), the owner server's [partnerRootFingerprint] pin, and the
     * share's own [cloudId], never the lookup's echo of it.
     */
    fun verify(certificate: String, chain: List<String>, partnerRootFingerprint: String, cloudId: String, now: Long): VerifiedFederatedCertificate {
        if (chain.isEmpty()) throw FederatedCertificateException(FederatedCertificateReason.UNTRUSTED_ROOT)
        // Keepiq's `FederationRootService::localChain` serves exactly these two.
        if (chain.size != 2) throw FederatedCertificateException(FederatedCertificateReason.BAD_CHAIN)
        val certs: List<Parsed>
        val commonName: String?
        try {
            certs = (listOf(certificate) + chain).map(::parse)
            commonName = commonNameOf(certs[0].subject)
        } catch (e: FederatedCertificateException) {
            throw e
        } catch (e: Exception) {
            throw malformed()
        }
        val pinned = partnerRootFingerprint.lowercase()
        if (pinned.isEmpty() || sha256Hex(certs.last().der) != pinned) throw FederatedCertificateException(FederatedCertificateReason.UNTRUSTED_ROOT)
        // The first link must be the partner's intermediate, not its root again; a CA-marked leaf is refused (keepiq#1257).
        if (certs[1].issuer.contentEquals(certs[1].subject) || certs[0].ca) throw FederatedCertificateException(FederatedCertificateReason.BAD_CHAIN)
        for (i in 1 until certs.size) {
            val issuer = certs[i]
            // pathLen counts the CAs below this one, not the leaf.
            val mayIssue = issuer.mayIssue && (issuer.pathLen == null || i - 1 <= issuer.pathLen)
            if (!mayIssue || !signedBy(certs[i - 1], issuer)) throw FederatedCertificateException(FederatedCertificateReason.BAD_CHAIN)
        }
        val expected = canonicalCloudId(cloudId)
        if (expected.isEmpty() || commonName == null || canonicalCloudId(commonName) != expected) {
            throw FederatedCertificateException(FederatedCertificateReason.NAME_MISMATCH)
        }
        if (certs.any { now + CLOCK_SKEW_MS < it.notBefore || now > it.notAfter }) {
            throw FederatedCertificateException(FederatedCertificateReason.NOT_VALID_NOW)
        }
        return VerifiedFederatedCertificate(sha256Hex(certs[0].der), certs[0].spki)
    }

    /** `user@host[:port][/path]`: an http instance names its users `bob@http://host`, while the share holds `bob@host`. Only the host's case is folded. */
    fun canonicalCloudId(cloudId: String): String {
        val at = cloudId.lastIndexOf('@')
        if (at <= 0) return ""
        val remote = cloudId.substring(at + 1).replace(Regex("^https?://", RegexOption.IGNORE_CASE), "").replace(Regex("/+$"), "")
        if (remote.isEmpty()) return ""
        val host = remote.substringBefore('/')
        return cloudId.substring(0, at) + "@" + host.lowercase() + remote.substring(host.length)
    }

    private fun signedBy(cert: Parsed, issuer: Parsed): Boolean {
        if (!cert.issuer.contentEquals(issuer.subject)) return false
        if (cert.otherSalt) throw FederatedCertificateUnsupportedException()
        val pss = cert.pss ?: return false
        return try {
            Primitives.rsaSha256Verify(issuer.spki, cert.tbs, cert.signature, pss)
        } catch (e: Exception) {
            false
        }
    }

    private fun sha256Hex(der: ByteArray): String = Encoding.hex(Primitives.sha256(der))

    private fun pemToDer(pem: String): ByteArray {
        val match = PEM.matchEntire(pem) ?: throw malformed()
        if (match.groupValues[1] != "CERTIFICATE") throw malformed()
        // Padding optional, as `atob` reads it: a refusal here suspends the share for good.
        return try {
            PEM_BASE64.decode(match.groupValues[2].filterNot { it.isWhitespace() })
        } catch (e: IllegalArgumentException) {
            throw malformed()
        }
    }

    private val PEM_BASE64 = kotlin.io.encoding.Base64.Default.withPadding(kotlin.io.encoding.Base64.PaddingOption.PRESENT_OPTIONAL)

    private fun byteAt(bytes: ByteArray, offset: Int): Int =
        if (offset in bytes.indices) bytes[offset].toInt() and 0xFF else throw malformed()

    /** One DER element whose length fits in [limit]. DER uses the long form only past 127, in as few bytes as it takes (X.690 10.1). */
    private fun tlv(bytes: ByteArray, offset: Int, limit: Int = bytes.size): Element {
        val tag = byteAt(bytes, offset)
        var cursor = offset + 1
        var length = byteAt(bytes, cursor++).toLong()
        if (length and 0x80L != 0L) {
            val count = (length and 0x7FL).toInt()
            if (count == 0 || count > 4) throw malformed()
            if (byteAt(bytes, cursor) == 0) throw malformed()
            length = 0L
            for (i in 0 until count) length = length * 256 + byteAt(bytes, cursor + i)
            if (length < 0x80) throw malformed()
            cursor += count
        }
        if (cursor + length > limit) throw malformed()
        return Element(tag, offset, cursor, cursor + length.toInt())
    }

    private fun children(bytes: ByteArray, parent: Element?): List<Element> {
        if (parent == null) throw malformed()
        val out = ArrayList<Element>()
        var cursor = parent.start
        while (cursor < parent.end) {
            val child = tlv(bytes, cursor, parent.end)
            out.add(child)
            cursor = child.end
        }
        return out
    }

    /** The one element that fills [parent], with [tag] when given. */
    private fun only(bytes: ByteArray, parent: Element?, tag: Int? = null): Element {
        val kids = children(bytes, parent)
        val child = kids.singleOrNull() ?: throw malformed()
        if (tag != null && child.tag != tag) throw malformed()
        return child
    }

    /** DER (X.690 8.3.2): no leading byte that only repeats the sign of the next. */
    private fun uint(bytes: ByteArray, element: Element): Int {
        val length = element.end - element.start
        if (element.tag != 0x02 || length < 1 || length > 4 || byteAt(bytes, element.start) and 0x80 != 0) throw malformed()
        if (length > 1 && byteAt(bytes, element.start) == 0 && byteAt(bytes, element.start + 1) and 0x80 == 0) throw malformed()
        var value = 0
        for (i in element.start until element.end) value = value * 256 + byteAt(bytes, i)
        return value
    }

    /** A BOOLEAN DEFAULT FALSE that is present: DER encodes TRUE as 0xFF only and leaves a default out. */
    private fun presentFlag(bytes: ByteArray, element: Element): Boolean {
        if (element.tag != 0x01 || element.end - element.start != 1 || byteAt(bytes, element.start) != 0xFF) throw malformed()
        return true
    }

    private fun oid(bytes: ByteArray, element: Element?): String {
        if (element?.tag != 0x06) throw malformed()
        val parts = ArrayList<Long>()
        var value = 0L
        for (i in element.start until element.end) {
            val byte = byteAt(bytes, i)
            value = value * 128 + (byte and 0x7F)
            if (byte and 0x80 == 0) {
                if (parts.isEmpty()) {
                    parts.add(value / 40)
                    parts.add(value % 40)
                } else {
                    parts.add(value)
                }
                value = 0
            }
        }
        return parts.joinToString(".")
    }

    private fun whole(bytes: ByteArray, element: Element): ByteArray = bytes.copyOfRange(element.offset, element.end)

    private fun time(bytes: ByteArray, element: Element?): Long {
        if (element == null) throw malformed()
        val text = buildString {
            for (i in element.start until element.end) {
                val byte = byteAt(bytes, i)
                if (byte !in 0x20..0x7E) throw malformed()
                append(byte.toChar())
            }
        }
        val g = TIME.matchEntire(text)?.groupValues ?: throw malformed()
        var year = g[1].toInt()
        if (g[1].length == 2) year += if (year < 50) 2000 else 1900
        val iso = "${year.toString().padStart(4, '0')}-${g[2]}-${g[3]}T${g[4]}:${g[5]}:${g[6]}Z"
        return IsoTime.parseMillis(iso) ?: throw malformed()
    }

    private class Issuing(var ca: Boolean = false, var keyCertSign: Boolean = true, var pathLen: Int? = null)

    /** RFC 5280 § 4.2: each extension at most once, none critical that this doesn't read; without basicConstraints a certificate is no CA. */
    private fun issuing(bytes: ByteArray, extensions: Element?): Issuing {
        val run = Issuing()
        val seen = HashSet<String>()
        val list = if (extensions != null) children(bytes, only(bytes, extensions, 0x30)) else emptyList()
        for (extension in list) {
            if (extension.tag != 0x30) throw malformed()
            val kids = children(bytes, extension)
            val value = kids.lastOrNull()
            if (kids.size < 2 || value?.tag != 0x04 || kids.size > 3) throw malformed()
            val critical = if (kids.size == 3) kids[1] else null
            val id = oid(bytes, kids[0])
            if (!seen.add(id)) throw malformed()
            val known = id == OID_BASIC_CONSTRAINTS || id == OID_KEY_USAGE
            if (id == OID_NAME_CONSTRAINTS || (critical != null && presentFlag(bytes, critical) && !known)) throw malformed()
            when (id) {
                OID_BASIC_CONSTRAINTS -> readBasicConstraints(bytes, value, run)
                OID_KEY_USAGE -> readKeyUsage(bytes, value, run)
            }
        }
        if (!run.ca) run.pathLen = null
        return run
    }

    private fun readBasicConstraints(bytes: ByteArray, value: Element, run: Issuing) {
        val fields = children(bytes, only(bytes, value, 0x30))
        var next = 0
        if (fields.getOrNull(next)?.tag == 0x01) run.ca = presentFlag(bytes, fields[next++])
        if (fields.getOrNull(next)?.tag == 0x02) run.pathLen = uint(bytes, fields[next++])
        if (next != fields.size) throw malformed()
    }

    private fun readKeyUsage(bytes: ByteArray, value: Element, run: Issuing) {
        val bits = only(bytes, value, 0x03)
        val length = bits.end - bits.start
        val unused = if (length > 0) byteAt(bytes, bits.start) else 0
        if (length == 0 || unused > 7 || (length == 1 && unused != 0)) throw malformed()
        // After the unused-bits count, keyCertSign is bit 5 of the first byte; when that byte is also the last, its unused bits are padding.
        val first = if (length > 1) byteAt(bytes, bits.start + 1) else 0
        val mask = if (length == 2) (0xFF shl unused) and 0xFF else 0xFF
        run.keyCertSign = first and mask and 0x04 != 0
    }

    /** Only SHA-256, and for PSS only with MGF1-SHA-256: the PSS default is SHA-1. Null for anything else; parameters that don't decode are malformed. */
    private fun algorithmOf(bytes: ByteArray, algorithm: Element): Boolean? {
        val kids = children(bytes, algorithm)
        if (kids.size > 2) throw malformed()
        val name = oid(bytes, kids.getOrNull(0))
        if (name == OID_SHA256_RSA) return false
        val params = kids.getOrNull(1)
        if (name != OID_RSA_PSS || params == null) return null
        return pssAlgorithm(bytes, params)
    }

    private fun pssAlgorithm(bytes: ByteArray, params: Element): Boolean? {
        if (params.tag != 0x30) throw malformed()
        var hash: String? = null
        var mask: String? = null
        var maskHash: String? = null
        var salt = 20
        var previous = 0
        // Each field at most once and in order (DER).
        for (field in children(bytes, params)) {
            if (field.tag <= previous) throw malformed()
            previous = field.tag
            val inner = only(bytes, field)
            when (field.tag) {
                0xA0 -> hash = algorithmIdOf(bytes, inner)
                0xA1 -> {
                    if (inner.tag != 0x30) throw malformed()
                    val parts = children(bytes, inner)
                    mask = oid(bytes, parts.getOrNull(0))
                    maskHash = parts.getOrNull(1)?.let { algorithmIdOf(bytes, it) }
                }
                0xA2 -> salt = uint(bytes, inner)
                0xA3 -> if (uint(bytes, inner) != 1) throw malformed()
                else -> throw malformed()
            }
        }
        val sha256Throughout = hash == OID_SHA256 && mask == OID_MGF1 && maskHash == OID_SHA256
        if (!sha256Throughout) return null
        return salt == PSS_SALT || throw FederatedCertificateUnsupportedException()
    }

    private fun algorithmIdOf(bytes: ByteArray, element: Element): String =
        oid(bytes, children(bytes, element.takeIf { it.tag == 0x30 }).getOrNull(0))

    /** Printable ASCII only in the other string types OpenSSL may write a cloud id in: any other byte reads differently per decoder. */
    private fun textOf(bytes: ByteArray, value: Element): String {
        val ascii = value.tag in ASCII_STRINGS && (value.start until value.end).all { byteAt(bytes, it) in 0x20..0x7E }
        if (value.tag != UTF8_STRING && !ascii) throw malformed()
        return try {
            bytes.decodeToString(value.start, value.end, throwOnInvalidSequence = true)
        } catch (e: CharacterCodingException) {
            throw malformed()
        }
    }

    /** The leaf's one common name; a second could name someone else to a reader that takes the other. */
    private fun commonNameOf(subject: ByteArray): String? {
        var commonName: String? = null
        for (rdn in children(subject, tlv(subject, 0))) {
            for (attribute in children(subject, rdn)) {
                val parts = children(subject, attribute)
                val type = parts.getOrNull(0)
                val value = parts.getOrNull(1)
                if (type == null || value == null || oid(subject, type) != OID_COMMON_NAME) continue
                if (commonName != null) throw malformed()
                commonName = textOf(subject, value)
            }
        }
        return commonName
    }

    private fun parse(pem: String): Parsed {
        val der = pemToDer(pem)
        val outer = tlv(der, 0)
        if (outer.tag != 0x30 || outer.end != der.size) throw malformed()
        val parts = children(der, outer)
        val tbs = parts.getOrNull(0)
        val algorithm = parts.getOrNull(1)
        val signature = parts.getOrNull(2)
        if (tbs?.tag != 0x30 || algorithm?.tag != 0x30) throw malformed()
        // A BIT STRING value starts with its unused-bits count, which a signature never has.
        if (signature?.tag != 0x03 || parts.size > 3 || signature.end == signature.start || byteAt(der, signature.start) != 0) throw malformed()

        val all = children(der, tbs)
        val fields = if (all.firstOrNull()?.tag == 0xA0) all.drop(1) else all
        val signed = fields.getOrNull(1)
        val issuer = fields.getOrNull(2)
        val validity = fields.getOrNull(3)
        val subject = fields.getOrNull(4)
        val spki = fields.getOrNull(5)
        if (signed == null || issuer == null || validity == null || subject == null) throw malformed()
        if (spki?.tag != 0x30) throw malformed()
        // RFC 5280 § 4.1.1.2: the algorithm the signature covers is the one it is checked with.
        if (!whole(der, signed).contentEquals(whole(der, algorithm))) throw malformed()
        // The unique IDs and the extensions, each at most once and in order, so no second extensions block goes unread.
        val optional = fields.drop(6)
        if (!optional.withIndex().all { (i, f) -> f.tag in setOf(0x81, 0x82, 0xA3) && f.tag > (optional.getOrNull(i - 1)?.tag ?: 0) }) throw malformed()

        val times = children(der, validity)
        val issuing = issuing(der, optional.firstOrNull { it.tag == 0xA3 })
        var otherSalt = false
        val pss = try {
            algorithmOf(der, algorithm)
        } catch (e: FederatedCertificateUnsupportedException) {
            otherSalt = true
            null
        }
        return Parsed(
            der = der,
            tbs = whole(der, tbs),
            pss = pss,
            otherSalt = otherSalt,
            signature = der.copyOfRange(signature.start + 1, signature.end),
            issuer = whole(der, issuer),
            subject = whole(der, subject),
            notBefore = time(der, times.getOrNull(0)),
            notAfter = time(der, times.getOrNull(1)),
            spki = whole(der, spki),
            ca = issuing.ca,
            mayIssue = issuing.ca && issuing.keyCertSign,
            pathLen = issuing.pathLen,
        )
    }
}
