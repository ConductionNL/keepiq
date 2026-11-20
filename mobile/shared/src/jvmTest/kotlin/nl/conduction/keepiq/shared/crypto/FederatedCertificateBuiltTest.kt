// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.crypto

import nl.conduction.keepiq.shared.send.IsoTime
import java.security.KeyPair
import java.security.KeyPairGenerator
import java.security.Signature
import java.security.spec.MGF1ParameterSpec
import java.security.spec.PSSParameterSpec
import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertFailsWith

/**
 * The partner certificate check on chains signed here with one throwaway
 * key, so each certificate differs from a good one only where a test says:
 * browser-extension/src/crypto/federated-certificate.test.ts "on chains
 * built here", plus the rules only the web app has (name constraints, PSS
 * field order). On the JVM, which signs; the parser under test is common code.
 */
class FederatedCertificateBuiltTest {
    private companion object {
        const val MALLORY = "mallory@cloud.partner.example"
        val NOW = IsoTime.parseMillis("2030-01-01T00:00:00Z")!!
        val KEYS: KeyPair = KeyPairGenerator.getInstance("RSA").apply { initialize(2048) }.generateKeyPair()

        fun b(vararg bytes: Int) = ByteArray(bytes.size) { bytes[it].toByte() }

        /** A DER element of up to 64 KiB. */
        fun der(tag: Int, vararg parts: ByteArray): ByteArray {
            val body = parts.fold(ByteArray(0)) { all, part -> all + part }
            val n = body.size
            val length = when {
                n < 0x80 -> b(n)
                n < 0x100 -> b(0x81, n)
                else -> b(0x82, n shr 8, n and 0xFF)
            }
            return b(tag) + length + body
        }

        val OID_SHA256_RSA = b(0x2a, 0x86, 0x48, 0x86, 0xf7, 0x0d, 0x01, 0x01, 0x0b)
        val OID_PSS = b(0x2a, 0x86, 0x48, 0x86, 0xf7, 0x0d, 0x01, 0x01, 0x0a)
        val OID_MGF1 = b(0x2a, 0x86, 0x48, 0x86, 0xf7, 0x0d, 0x01, 0x01, 0x08)
        val OID_SHA256 = b(0x60, 0x86, 0x48, 0x01, 0x65, 0x03, 0x04, 0x02, 0x01)
        val OID_SHA384 = b(0x60, 0x86, 0x48, 0x01, 0x65, 0x03, 0x04, 0x02, 0x02)
        val OID_SHA1 = b(0x2b, 0x0e, 0x03, 0x02, 0x1a)
        val OID_COMMON_NAME = b(0x55, 0x04, 0x03)
        val OID_BASIC_CONSTRAINTS = b(0x55, 0x1d, 0x13)
        val OID_KEY_USAGE = b(0x55, 0x1d, 0x0f)
        val OID_SUBJECT_KEY_ID = b(0x55, 0x1d, 0x0e)
        val OID_AUTHORITY_KEY_ID = b(0x55, 0x1d, 0x23)
        val OID_NAME_CONSTRAINTS = b(0x55, 0x1d, 0x1e)

        fun utf8(text: String) = der(0x0c, text.encodeToByteArray())
        fun ascii(text: String) = text.encodeToByteArray()
        fun name(vararg commonNames: ByteArray) =
            der(0x30, *commonNames.map { der(0x31, der(0x30, der(0x06, OID_COMMON_NAME), it)) }.toTypedArray())
        fun extension(id: ByteArray, value: ByteArray, critical: Boolean = false) =
            der(0x30, der(0x06, id), if (critical) der(0x01, b(0xff)) else ByteArray(0), der(0x04, value))
        fun basicConstraints(vararg fields: ByteArray) = extension(OID_BASIC_CONSTRAINTS, der(0x30, *fields), critical = true)

        val CA = basicConstraints(der(0x01, b(0xff)))
        val PKCS1 = der(0x30, der(0x06, OID_SHA256_RSA), der(0x05))
        fun pssParams(vararg fields: ByteArray) = der(0x30, der(0x06, OID_PSS), der(0x30, *fields))
        fun pssHash(hash: ByteArray = OID_SHA256) = der(0xa0, der(0x30, der(0x06, hash)))
        fun pssMask(mask: ByteArray = OID_MGF1, maskHash: ByteArray = OID_SHA256) = der(0xa1, der(0x30, der(0x06, mask), der(0x30, der(0x06, maskHash))))
        fun pssSalt(salt: Int = 32) = der(0xa2, der(0x02, b(salt)))
        fun pss(hash: ByteArray = OID_SHA256, mask: ByteArray = OID_MGF1, maskHash: ByteArray = OID_SHA256) =
            pssParams(pssHash(hash), pssMask(mask, maskHash), pssSalt())

        val ROOT = name(utf8("Built Root CA"))
        val INTERMEDIATE = name(utf8("Built Intermediate CA"))
        val SPKI: ByteArray = KEYS.public.encoded

        fun ByteArray.contains(pattern: ByteArray) = (0..size - pattern.size).any { i -> pattern.indices.all { this[i + it] == pattern[it] } }

        fun pemOf(der: ByteArray) = "-----BEGIN CERTIFICATE-----\n${Encoding.toBase64(der)}\n-----END CERTIFICATE-----"
        fun sha256Hex(pem: String) = Encoding.hex(Primitives.sha256(Encoding.fromBase64(pem.replace(Regex("-----[A-Z ]+-----|\\s"), ""))))
    }

    private class Spec(
        val issuer: ByteArray,
        val subject: ByteArray,
        val extensions: List<ByteArray>? = null,
        /** The algorithm in the TBS, and outside it unless [outer] says otherwise. */
        val algorithm: ByteArray = PKCS1,
        val outer: ByteArray? = null,
        val spki: ByteArray = SPKI,
    )

    /** Signed with SHA-256: PSS with MGF1-SHA-256 and a 32-byte salt under any PSS algorithm, whatever its parameters claim. */
    private fun certificate(spec: Spec): String {
        val validity = der(0x30, der(0x17, ascii("200101000000Z")), der(0x17, ascii("491231235959Z")))
        val extensions = spec.extensions?.let { der(0xa3, der(0x30, *it.toTypedArray())) } ?: ByteArray(0)
        val tbs = der(0x30, der(0xa0, der(0x02, b(2))), der(0x02, b(1)), spec.algorithm, spec.issuer, validity, spec.subject, spec.spki, extensions)
        val signer = if (spec.algorithm.contains(OID_PSS)) {
            Signature.getInstance("RSASSA-PSS").apply { setParameter(PSSParameterSpec("SHA-256", "MGF1", MGF1ParameterSpec.SHA256, 32, 1)) }
        } else {
            Signature.getInstance("SHA256withRSA")
        }
        signer.initSign(KEYS.private)
        signer.update(tbs)
        return pemOf(der(0x30, tbs, spec.outer ?: spec.algorithm, der(0x03, b(0x00), signer.sign())))
    }

    private class Chain(val root: String, val intermediate: String, val leaf: String)

    private fun rootSpec(extensions: List<ByteArray> = listOf(CA), issuer: ByteArray = ROOT, subject: ByteArray = ROOT) = Spec(issuer, subject, extensions)
    private fun intermediateSpec(extensions: List<ByteArray> = listOf(CA), issuer: ByteArray = ROOT, subject: ByteArray = INTERMEDIATE) = Spec(issuer, subject, extensions)
    private fun leafSpec(
        extensions: List<ByteArray>? = null,
        issuer: ByteArray = INTERMEDIATE,
        subject: ByteArray = name(utf8(MALLORY)),
        algorithm: ByteArray = PKCS1,
        outer: ByteArray? = null,
        spki: ByteArray = SPKI,
    ) = Spec(issuer, subject, extensions, algorithm, outer, spki)

    private fun build(root: Spec = rootSpec(), intermediate: Spec = intermediateSpec(), leaf: Spec = leafSpec()) =
        Chain(certificate(root), certificate(intermediate), certificate(leaf))

    private fun verify(chain: Chain, cloudId: String = MALLORY): VerifiedFederatedCertificate =
        FederatedCertificate.verify(chain.leaf, listOf(chain.intermediate, chain.root), sha256Hex(chain.root), cloudId, NOW)

    private fun verifyBuilt(root: Spec = rootSpec(), intermediate: Spec = intermediateSpec(), leaf: Spec = leafSpec(), cloudId: String = MALLORY) =
        verify(build(root, intermediate, leaf), cloudId)

    /** "accepted", or the reason the chain was refused. */
    private fun outcome(block: () -> Unit): String = try {
        block()
        "accepted"
    } catch (e: FederatedCertificateException) {
        e.reason.code
    }

    private fun reasonOf(block: () -> Unit): String = outcome(block).also { if (it == "accepted") throw AssertionError("the certificate was accepted") }

    @Test
    fun acceptsTheProfileKeepiqIssuesToday() {
        // keyCertSign and cRLSign, with one unused bit; the key identifiers are not critical and not read.
        val keyUsage = extension(OID_KEY_USAGE, der(0x03, b(0x01, 0x06)), critical = true)
        val keyIds = listOf(
            extension(OID_SUBJECT_KEY_ID, der(0x04, ByteArray(20) { 7 })),
            extension(OID_AUTHORITY_KEY_ID, der(0x30, der(0x80, ByteArray(20) { 7 }))),
        )
        val chain = build(
            root = rootSpec(listOf(CA, keyUsage) + keyIds),
            intermediate = intermediateSpec(listOf(basicConstraints(der(0x01, b(0xff)), der(0x02, b(0x00))), keyUsage) + keyIds),
            // openssl writes CA:FALSE as an empty SEQUENCE.
            leaf = leafSpec(listOf(basicConstraints()) + keyIds),
        )
        assertEquals(sha256Hex(chain.leaf), verify(chain).fingerprint)
    }

    @Test
    fun acceptsALeafSignedWithPssSha256AndMgf1Sha256() {
        verifyBuilt(leaf = leafSpec(algorithm = pss()))
    }

    @Test
    fun refusesPssParametersThatNameAnotherHashThoughTheSignatureVerifies() {
        assertEquals("bad_chain", reasonOf { verifyBuilt(leaf = leafSpec(algorithm = pss(hash = OID_SHA384))) })
        assertEquals("bad_chain", reasonOf { verifyBuilt(leaf = leafSpec(algorithm = pss(maskHash = OID_SHA1))) })
        assertEquals("bad_chain", reasonOf { verifyBuilt(leaf = leafSpec(algorithm = pss(mask = OID_MGF1.copyOf().also { it[it.size - 1] = 0x09 }))) })
    }

    @Test
    fun refusesPssParametersThatDontDecode() {
        val cases = mapOf(
            "a hash that is no AlgorithmIdentifier" to der(0x30, der(0x06, OID_PSS), der(0x30, der(0xa0, der(0x02, b(5))))),
            "no SEQUENCE" to der(0x30, der(0x06, OID_PSS), der(0x05)),
            "a field it does not know" to der(0x30, der(0x06, OID_PSS), der(0x30, der(0xa4, der(0x02, b(1))))),
            // The web app's order rule: each field at most once, in order.
            "a salt before the hash" to pssParams(pssSalt(), pssHash(), pssMask()),
            "the hash twice" to pssParams(pssHash(), pssHash(), pssMask(), pssSalt()),
        )
        for ((label, algorithm) in cases) assertEquals("malformed", reasonOf { verifyBuilt(leaf = leafSpec(algorithm = algorithm)) }, label)
    }

    @Test
    fun aPssSaltOtherThan32StopsTheSaveWithoutAVerdict() {
        for (algorithm in listOf(pssParams(pssHash(), pssMask(), pssSalt(20)), pssParams(pssHash(), pssMask()))) {
            assertFailsWith<FederatedCertificateUnsupportedException> { verifyBuilt(leaf = leafSpec(algorithm = algorithm)) }
        }
    }

    @Test
    fun refusesAnOuterAlgorithmOtherThanTheOneInsideTheTbs() {
        assertEquals("malformed", reasonOf { verifyBuilt(leaf = leafSpec(outer = der(0x30, der(0x06, OID_SHA256_RSA)))) })
    }

    @Test
    fun refusesALeafWhoseIssuerNameIsNotTheIntermediates() {
        assertEquals("bad_chain", reasonOf { verifyBuilt(leaf = leafSpec(issuer = name(utf8("Another CA")))) })
    }

    @Test
    fun refusesAnExplicitCaFalseAndALeafMarkedCa() {
        assertEquals("malformed", reasonOf { verifyBuilt(intermediate = intermediateSpec(listOf(basicConstraints(der(0x01, b(0x00)))))) })
        // digitalSignature only.
        val keyUsage = extension(OID_KEY_USAGE, der(0x03, b(0x07, 0x80)), critical = true)
        assertEquals("bad_chain", reasonOf { verifyBuilt(leaf = leafSpec(listOf(CA, keyUsage))) })
    }

    @Test
    fun refusesAKeyUsageThatClaimsMoreThan7UnusedBits() {
        val keyUsage = extension(OID_KEY_USAGE, der(0x03, b(0x08, 0x04, 0x00)), critical = true)
        assertEquals("malformed", reasonOf { verifyBuilt(intermediate = intermediateSpec(listOf(CA, keyUsage))) })
    }

    @Test
    fun refusesNameConstraintsCriticalOrNot() {
        val permitted = der(0x30, der(0xa0, der(0x30, der(0x82, ascii("partner.example")))))
        for (critical in listOf(false, true)) {
            val constraints = extension(OID_NAME_CONSTRAINTS, permitted, critical)
            assertEquals("malformed", reasonOf { verifyBuilt(intermediate = intermediateSpec(listOf(CA, constraints))) }, "critical $critical")
        }
    }

    @Test
    fun refusesALeafWithACommonNameThatReadsDifferentlyPerDecoder() {
        fun bmp(text: String) = der(0x1e, text.flatMap { listOf((it.code shr 8).toByte(), (it.code and 0xFF).toByte()) }.toByteArray())
        val cases = mutableMapOf(
            "a second common name" to name(utf8("alice@cloud.partner.example"), utf8(MALLORY)),
            "a BMPString" to name(bmp(MALLORY)),
            "no UTF-8" to name(der(0x0c, ascii(MALLORY) + b(0xff))),
            "an IA5String with a control character" to name(der(0x16, ascii(MALLORY) + b(0x0a))),
            "a PrintableString with UTF-8 é" to name(der(0x13, ascii(MALLORY) + b(0xc3, 0xa9))),
        )
        for (byte in listOf(0x1f, 0x7f, 0xe9)) cases["a T61String with byte $byte"] = name(der(0x14, ascii(MALLORY) + b(byte)))
        for ((label, subject) in cases) assertEquals("malformed", reasonOf { verifyBuilt(leaf = leafSpec(subject = subject)) }, label)
        for (tag in listOf(0x13, 0x14, 0x16)) verifyBuilt(leaf = leafSpec(subject = name(der(tag, ascii(MALLORY)))))
    }

    @Test
    fun readsNoCommonNameOfACa() {
        val root = name(utf8("Built Root CA"), utf8("Built Root CA again"))
        val intermediate = name(utf8("Built Intermediate CA"), der(0x1e, "Built Intermediate CA".flatMap { listOf(0.toByte(), it.code.toByte()) }.toByteArray()))
        verifyBuilt(rootSpec(issuer = root, subject = root), intermediateSpec(issuer = root, subject = intermediate), leafSpec(issuer = intermediate))
    }

    @Test
    fun matchesNoOneOnGarbageOrAByteOrderMark() {
        assertEquals("name_mismatch", reasonOf { verifyBuilt(leaf = leafSpec(subject = name(utf8("no cloud id"))), cloudId = "garbage") })
        assertEquals("name_mismatch", reasonOf { verifyBuilt(leaf = leafSpec(subject = name(der(0x0c, b(0xef, 0xbb, 0xbf) + ascii(MALLORY))))) })
    }

    @Test
    fun refusesALeafWhoseKeyIsNoSequence() {
        assertEquals("malformed", reasonOf { verifyBuilt(leaf = leafSpec(spki = der(0x04, SPKI))) })
    }

    // DER (X.690 11.1, 11.5, 8.3.2): a BOOLEAN is 0x00 or 0xFF, a field at its DEFAULT is left out, and an INTEGER has no redundant leading byte.
    @Test
    fun refusesWhatStrictDerRefuses() {
        fun flagged(id: ByteArray, flag: Int, value: ByteArray) = der(0x30, der(0x06, id), der(0x01, b(flag)), der(0x04, value))
        val cases: Map<String, () -> Unit> = mapOf(
            "a critical flag that is an INTEGER" to { verifyBuilt(intermediate = intermediateSpec(listOf(CA, der(0x30, der(0x06, OID_KEY_USAGE), der(0x02, b(0xff)), der(0x04, der(0x03, b(0x01, 0x06))))))) },
            "a critical flag that is an OCTET STRING" to { verifyBuilt(intermediate = intermediateSpec(listOf(der(0x30, der(0x06, OID_BASIC_CONSTRAINTS), der(0x04, b(0xff)), der(0x04, der(0x30, der(0x01, b(0xff)))))))) },
            "a cA flag with a long-form length" to { verifyBuilt(intermediate = intermediateSpec(listOf(basicConstraints(b(0x01, 0x81, 0x01, 0xff))))) },
            "a pathLen with a long-form length" to { verifyBuilt(root = rootSpec(listOf(basicConstraints(der(0x01, b(0xff)), b(0x02, 0x81, 0x01, 0x05))))) },
            "a length of 127 in long form" to { verifyBuilt(leaf = leafSpec(listOf(der(0x30, der(0x06, OID_SUBJECT_KEY_ID), b(0x04, 0x81, 0x7f) + ByteArray(127))))) },
            "a length with a leading zero byte" to { verifyBuilt(leaf = leafSpec(listOf(der(0x30, der(0x06, OID_SUBJECT_KEY_ID), b(0x04, 0x82, 0x00, 0x80) + ByteArray(128))))) },
            "a cA BOOLEAN of 0x01" to { verifyBuilt(intermediate = intermediateSpec(listOf(basicConstraints(der(0x01, b(0x01)))))) },
            "a cA BOOLEAN of two bytes" to { verifyBuilt(intermediate = intermediateSpec(listOf(basicConstraints(der(0x01, b(0xff, 0xff)))))) },
            "a critical flag of 0x01" to { verifyBuilt(intermediate = intermediateSpec(listOf(CA, flagged(OID_KEY_USAGE, 0x01, der(0x03, b(0x01, 0x06)))))) },
            "an explicit critical FALSE" to { verifyBuilt(intermediate = intermediateSpec(listOf(CA, flagged(OID_SUBJECT_KEY_ID, 0x00, der(0x04, b(7)))))) },
            "an explicit critical FALSE on basicConstraints" to { verifyBuilt(intermediate = intermediateSpec(listOf(flagged(OID_BASIC_CONSTRAINTS, 0x00, der(0x30, der(0x01, b(0xff))))))) },
            "an explicit cA FALSE on the leaf" to { verifyBuilt(leaf = leafSpec(listOf(basicConstraints(der(0x01, b(0x00)))))) },
            "a pathLen of 00 00" to { verifyBuilt(intermediate = intermediateSpec(listOf(basicConstraints(der(0x01, b(0xff)), der(0x02, b(0x00, 0x00)))))) },
            "a pathLen of 00 05" to { verifyBuilt(root = rootSpec(listOf(basicConstraints(der(0x01, b(0xff)), der(0x02, b(0x00, 0x05)))))) },
        )
        for ((label, case) in cases) assertEquals("malformed", reasonOf(case), label)
        verifyBuilt(leaf = leafSpec(listOf(der(0x30, der(0x06, OID_SUBJECT_KEY_ID), der(0x04, ByteArray(128))))))
        verifyBuilt(root = rootSpec(listOf(basicConstraints(der(0x01, b(0xff)), der(0x02, b(0x00, 0x80))))))
        verifyBuilt(leaf = leafSpec(listOf(basicConstraints())))
    }

    // Every encoding of each rule's input class, so a loosened rule fails here and not only on the examples above.

    private val base by lazy { build() }

    /** The built chain with the intermediate or the leaf replaced; all share a key, so each case signs only that one. */
    private fun outcomeWith(intermediate: Spec? = null, leaf: Spec? = null): String {
        val chain = Chain(base.root, intermediate?.let(::certificate) ?: base.intermediate, leaf?.let(::certificate) ?: base.leaf)
        return outcome { verify(chain) }
    }

    private fun <T> mismatches(inputs: Iterable<T>, run: (T) -> String, expected: (T) -> String): List<String> =
        inputs.mapNotNull { input -> run(input).takeIf { it != expected(input) }?.let { "$input: $it, expected ${expected(input)}" } }

    /** A length's header in [form] (-1 for the short form, else a long-form count byte), or null where that form can't hold it; 0x80 is BER's indefinite length. */
    private fun header(form: Int, n: Int): ByteArray? {
        if (form == -1) return if (n < 0x80) b(n) else null
        if (form == 0x80) return b(0x80)
        val count = form and 0x7f
        if (count < 4 && n >= 1 shl (8 * count)) return null
        return b(form) + ByteArray(count) { i -> ((n.toLong() shr (8 * (count - 1 - i))) and 0xFF).toByte() }
    }

    private fun minimal(form: Int, n: Int) = (form == -1 && n < 0x80) || (form == 0x81 && n in 0x80 until 0x100) || (form == 0x82 && n >= 0x100)

    /** A non-critical subjectKeyId, whose value is not read, as an OCTET STRING of [n] bytes behind [lengthBytes]. */
    private fun keyIdWith(lengthBytes: ByteArray, n: Int) = der(0x30, der(0x06, OID_SUBJECT_KEY_ID), b(0x04) + lengthBytes + ByteArray(n))

    @Test
    fun acceptsALengthOf0To300OnlyInItsShortestEncoding() {
        for ((form, count) in listOf(-1 to 128, 0x80 to 301, 0x81 to 256, 0x82 to 301, 0x83 to 301, 0x84 to 301, 0x85 to 301)) {
            val encodable = (0..300).filter { header(form, it) != null }
            assertEquals(count, encodable.size)
            val wrong = mismatches(encodable, { n -> outcomeWith(leaf = leafSpec(listOf(keyIdWith(header(form, n)!!, n)))) }) { n -> if (minimal(form, n)) "accepted" else "malformed" }
            assertEquals(emptyList(), wrong, "form $form")
        }
    }

    @Test
    fun refusesEveryLengthOf0To300ThatRunsOneBytePastItsParent() {
        val wrong = mismatches(0..300, { n ->
            val form = if (n + 1 < 0x80) -1 else if (n + 1 < 0x100) 0x81 else 0x82
            outcomeWith(leaf = leafSpec(listOf(keyIdWith(header(form, n + 1)!!, n))))
        }) { "malformed" }
        assertEquals(emptyList(), wrong)
    }

    @Test
    fun acceptsACriticalFlagAndACaFlagOf0xffOnly() {
        fun keyUsageFlagged(flag: Int) = der(0x30, der(0x06, OID_KEY_USAGE), der(0x01, b(flag)), der(0x04, der(0x03, b(0x01, 0x06))))
        val expected = { flag: Int -> if (flag == 0xff) "accepted" else "malformed" }
        assertEquals(emptyList(), mismatches(0..255, { outcomeWith(intermediate = intermediateSpec(listOf(CA, keyUsageFlagged(it)))) }, expected))
        assertEquals(emptyList(), mismatches(0..255, { outcomeWith(intermediate = intermediateSpec(listOf(basicConstraints(der(0x01, b(it)))))) }, expected))
    }

    private fun pathLen(value: ByteArray) = outcomeWith(intermediate = intermediateSpec(listOf(basicConstraints(der(0x01, b(0xff)), der(0x02, value)))))

    @Test
    fun readsAPathLenConstraintAsANonNegativeIntegerWithoutARedundantLeadingByte() {
        val cases = listOf(
            "zero length" to ("malformed" to b()),
            "0" to ("accepted" to b(0x00)),
            "127" to ("accepted" to b(0x7f)),
            "128 with its needed leading zero" to ("accepted" to b(0x00, 0x80)),
            "256" to ("accepted" to b(0x01, 0x00)),
            "2^31 - 1" to ("accepted" to b(0x7f, 0xff, 0xff, 0xff)),
            "0 with a redundant leading zero" to ("malformed" to b(0x00, 0x00)),
            "5 with a redundant leading zero" to ("malformed" to b(0x00, 0x05)),
            "128 with two leading zeros" to ("malformed" to b(0x00, 0x00, 0x80)),
            "-1" to ("malformed" to b(0xff)),
            "-128" to ("malformed" to b(0x80)),
            "-1 with a redundant leading 0xFF" to ("malformed" to b(0xff, 0xff)),
            "-128 with a redundant leading 0xFF" to ("malformed" to b(0xff, 0x80)),
            "-129" to ("malformed" to b(0xff, 0x7f)),
            "2^31" to ("malformed" to b(0x00, 0x80, 0x00, 0x00, 0x00)),
        )
        for ((label, case) in cases) assertEquals(case.first, pathLen(case.second), label)
        val classes = listOf<Triple<String, (Int) -> ByteArray, (Int) -> Boolean>>(
            Triple("one byte", { byte -> b(byte) }, { byte -> byte < 0x80 }),
            Triple("two bytes after 0x00", { byte -> b(0x00, byte) }, { byte -> byte >= 0x80 }),
            Triple("two bytes after 0xFF", { byte -> b(0xff, byte) }, { _ -> false }),
        )
        for ((label, value, accepted) in classes) {
            assertEquals(emptyList(), mismatches(0..255, { pathLen(value(it)) }) { if (accepted(it)) "accepted" else "malformed" }, label)
        }
    }

    @Test
    fun canonicalCloudId() {
        val cases = mapOf(
            "bob@http://Cloud.Partner.example/" to "bob@cloud.partner.example",
            "Bob@https://cloud.partner.example:8443/nc" to "Bob@cloud.partner.example:8443/nc",
            "bob@Cloud.Partner.example/NC/" to "bob@cloud.partner.example/NC",
            "bob" to "",
            "bob@" to "",
        )
        for ((input, expected) in cases) assertEquals(expected, FederatedCertificate.canonicalCloudId(input), input)
    }
}
