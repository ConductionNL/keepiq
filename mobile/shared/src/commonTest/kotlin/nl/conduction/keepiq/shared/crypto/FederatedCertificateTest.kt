// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.crypto

import kotlinx.serialization.json.JsonObject
import nl.conduction.keepiq.shared.send.IsoTime
import nl.conduction.keepiq.shared.vectors.Vectors
import nl.conduction.keepiq.shared.vectors.str
import kotlin.test.Test
import kotlin.test.assertContentEquals
import kotlin.test.assertEquals
import kotlin.test.assertFailsWith

/**
 * The partner certificate check on the chains the web app and the extension
 * verify (browser-extension/src/crypto/federated-certificate.test.ts), so
 * every client refuses the same certificates for the same reason.
 */
class FederatedCertificateTest {
    private val f = Vectors.federationChain
    private val c = Vectors.caConstraintsChain
    private val p = Vectors.phpseclibChain

    private companion object {
        const val BOB = "bob@cloud.partner.example"
        const val CAROL = "carol@cloud.partner.example"
        const val MALLORY = "mallory@cloud.partner.example"
        const val HOUR = 3_600_000L
        const val MINUTE = 60_000L
    }

    private fun JsonObject.pem(name: String) = str(name)

    private fun derOf(pem: String) = Encoding.fromBase64(pem.replace(Regex("-----[A-Z ]+-----|\\s"), ""))

    private fun pemOf(der: ByteArray) = "-----BEGIN CERTIFICATE-----\n${Encoding.toBase64(der)}\n-----END CERTIFICATE-----"

    private fun sha256Hex(pem: String) = Encoding.hex(Primitives.sha256(derOf(pem)))

    /** The notBefore of a certificate, read with a minimal walk: certificate, TBS, then validity after the version, serial, algorithm and issuer. */
    private fun notBefore(pem: String): Long {
        val der = derOf(pem)
        fun header(at: Int): Pair<Int, Int> {
            val first = der[at + 1].toInt() and 0xFF
            if (first < 0x80) return (at + 2) to first
            val count = first and 0x7F
            var length = 0
            for (i in 0 until count) length = length * 256 + (der[at + 2 + i].toInt() and 0xFF)
            return (at + 2 + count) to length
        }
        var at = header(header(0).first).first
        if (der[at].toInt() and 0xFF == 0xA0) at = header(at).let { it.first + it.second }
        repeat(3) { at = header(at).let { it.first + it.second } }
        val (start, length) = header(header(at).first)
        val text = der.copyOfRange(start, start + length).decodeToString()
        val year = if (text.length == 13) text.take(2).toInt().let { if (it < 50) 2000 + it else 1900 + it } else text.take(4).toInt()
        val rest = text.substring(if (text.length == 13) 2 else 4)
        return IsoTime.parseMillis("$year-${rest.substring(0, 2)}-${rest.substring(2, 4)}T${rest.substring(4, 6)}:${rest.substring(6, 8)}:${rest.substring(8, 10)}Z")!!
    }

    /** An hour after the newest certificate of a chain starts: its leaves may be valid for a year only. */
    private fun minted(vararg pems: String) = pems.maxOf { notBefore(it) } + HOUR

    private val fNow by lazy { minted(f.pem("bob"), f.pem("intermediateA"), f.pem("rootA")) }

    private fun verify(
        certificate: String = f.pem("bob"),
        chain: List<String> = listOf(f.pem("intermediateA"), f.pem("rootA")),
        pin: String = f.pem("rootAFingerprint"),
        cloudId: String = BOB,
        now: Long = fNow,
    ) = FederatedCertificate.verify(certificate, chain, pin, cloudId, now)

    private val cNow by lazy { minted(c.pem("mallory"), c.pem("intermediate"), c.pem("root")) }

    private fun verifyMallory(
        certificate: String = c.pem("mallory"),
        chain: List<String> = listOf(c.pem("intermediate"), c.pem("root")),
        pin: String = c.pem("rootFingerprint"),
        cloudId: String = MALLORY,
    ) = FederatedCertificate.verify(certificate, chain, pin, cloudId, cNow)

    private fun reasonOf(block: () -> Unit): FederatedCertificateReason = assertFailsWith<FederatedCertificateException> { block() }.reason

    @Test
    fun acceptsThePinnedChainAndReturnsTheLeafsFingerprintAndKey() {
        val verified = verify()
        assertEquals(sha256Hex(f.pem("bob")), verified.fingerprint)
        assertContentEquals(Der.spkiFromCertificate(derOf(f.pem("bob"))), verified.spki)
        assertEquals(sha256Hex(f.pem("bobPss")), verify(certificate = f.pem("bobPss")).fingerprint, "a renewal signed with RSASSA-PSS")
    }

    @Test
    fun theReturnedKeyIsTheOneTheRecipientOpens() {
        val sealed = RsaFields.encrypt("hunter2", RsaPublicKey.fromSpki(verifyMallory().spki))
        assertEquals("hunter2", RsaFields.decrypt(sealed, RsaPrivateKey.fromPem(c.pem("malloryKey"))))
    }

    @Test
    fun refusesAnotherCertificateSmuggledAroundTheCheckedOne() {
        val bob = f.pem("bob")
        val forged = Encoding.toBase64(derOf(c.pem("forgedAlice")))
        assertEquals(FederatedCertificateReason.MALFORMED, reasonOf { verify(certificate = "$forged\n$bob") })
        assertEquals(FederatedCertificateReason.MALFORMED, reasonOf { verify(certificate = "$bob\n$forged") })
        assertEquals(FederatedCertificateReason.MALFORMED, reasonOf { verify(certificate = bob + c.pem("forgedAlice")) })
        assertEquals(FederatedCertificateReason.MALFORMED, reasonOf { verify(certificate = "$bob\n${f.pem("mallory")}") })
        assertEquals(FederatedCertificateReason.MALFORMED, reasonOf { verify(chain = listOf("${f.pem("intermediateA")}\n$bob", f.pem("rootA"))) })
        assertEquals(FederatedCertificateReason.MALFORMED, reasonOf { verify(certificate = pemOf(derOf(bob) + derOf(c.pem("forgedAlice")))) })
        assertEquals(FederatedCertificateReason.MALFORMED, reasonOf { verify(certificate = bob.replace("-----END CERTIFICATE-----", "-----END PUBLIC KEY-----")) })
        assertEquals(FederatedCertificateReason.MALFORMED, reasonOf { verify(certificate = bob.replace("CERTIFICATE", "PUBLIC KEY")) })
    }

    @Test
    fun refusesASignatureWhoseBitStringClaimsUnusedBits() {
        val bytes = derOf(f.pem("bob"))
        // A 2048-bit signature: 03 82 01 01, then the unused-bits count and 256 bytes.
        assertContentEquals(byteArrayOf(0x03, 0x82.toByte(), 0x01, 0x01, 0x00), bytes.copyOfRange(bytes.size - 261, bytes.size - 256))
        bytes[bytes.size - 257] = 0x01
        assertEquals(FederatedCertificateReason.MALFORMED, reasonOf { verify(certificate = pemOf(bytes)) })
    }

    @Test
    fun refusesALengthPastTheBufferWithoutReadingOutOfBounds() {
        assertEquals(FederatedCertificateReason.MALFORMED, reasonOf { verify(certificate = pemOf(derOf(f.pem("bob")).copyOf(200))) })
        val huge = byteArrayOf(0x30, 0x84.toByte(), 0xFF.toByte(), 0xFF.toByte(), 0xFF.toByte(), 0xFF.toByte(), 0x30)
        assertEquals(FederatedCertificateReason.MALFORMED, reasonOf { verify(certificate = pemOf(huge)) })
    }

    @Test
    fun refusesAnotherRootAnUnsignedLeafAndAnotherName() {
        assertEquals(FederatedCertificateReason.UNTRUSTED_ROOT, reasonOf { verify(certificate = f.pem("bobOtherRoot"), chain = listOf(f.pem("intermediateB"), f.pem("rootB"))) })
        assertEquals(FederatedCertificateReason.BAD_CHAIN, reasonOf { verify(certificate = f.pem("bobOtherRoot")) })
        assertEquals(FederatedCertificateReason.NAME_MISMATCH, reasonOf { verify(certificate = f.pem("mallory")) })
        assertEquals(FederatedCertificateReason.NAME_MISMATCH, reasonOf { verify(cloudId = "bob@cloud.elsewhere.example") })
        assertEquals(FederatedCertificateReason.NAME_MISMATCH, reasonOf { verify(cloudId = "not a cloud id") })
    }

    @Test
    fun matchesTheCommonNameWithoutTheSchemeAnHttpInstanceWrites() {
        assertEquals(sha256Hex(f.pem("bob")), verify(cloudId = "bob@http://cloud.partner.example").fingerprint)
        assertEquals(FederatedCertificateReason.NAME_MISMATCH, reasonOf { verify(cloudId = "BOB@cloud.partner.example") })
        assertEquals(FederatedCertificateReason.NAME_MISMATCH, reasonOf { verify(cloudId = "bob@cloud.partner.example.evil") })
        assertEquals(FederatedCertificateReason.NAME_MISMATCH, reasonOf { verify(cloudId = "bob@http://cloud.other.example") })
    }

    @Test
    fun refusesOutsideItsValidityWithAFewMinutesOfSkewOnNotBefore() {
        assertEquals(FederatedCertificateReason.NOT_VALID_NOW, reasonOf { verify(now = IsoTime.parseMillis("2300-01-01T00:00:00Z")!!) })
        assertEquals(FederatedCertificateReason.NOT_VALID_NOW, reasonOf { verify(now = IsoTime.parseMillis("2000-01-01T00:00:00Z")!!) })
        val issued = fNow - HOUR
        assertEquals(sha256Hex(f.pem("bob")), verify(now = issued - 2 * MINUTE).fingerprint)
        assertEquals(FederatedCertificateReason.NOT_VALID_NOW, reasonOf { verify(now = issued - 10 * MINUTE) })
    }

    @Test
    fun refusesWithoutAPinOrAChainAndComparesThePinWithoutCase() {
        assertEquals(FederatedCertificateReason.UNTRUSTED_ROOT, reasonOf { verify(pin = "") })
        assertEquals(FederatedCertificateReason.UNTRUSTED_ROOT, reasonOf { verify(chain = emptyList()) })
        assertEquals(FederatedCertificateReason.MALFORMED, reasonOf { verify(certificate = "not a certificate") })
        assertEquals(sha256Hex(f.pem("bob")), verify(pin = f.pem("rootAFingerprint").uppercase()).fingerprint)
    }

    @Test
    fun theWebAppsChainConstraints() {
        assertEquals(sha256Hex(f.pem("bob")), verify(chain = listOf(f.pem("intermediateABasicConstraintsOnly"), f.pem("rootA"))).fingerprint)
        assertEquals(sha256Hex(f.pem("bob")), verify(chain = listOf(f.pem("intermediateAPathLen0"), f.pem("rootA"))).fingerprint)
        assertEquals(sha256Hex(f.pem("bob")), verify(chain = listOf(f.pem("intermediateAUnknownNonCritical"), f.pem("rootA"))).fingerprint)
        val badChain = listOf(
            { verify(certificate = f.pem("leafMarkedCa"), cloudId = MALLORY) },
            { verify(certificate = f.pem("leafSignedByLeaf"), chain = listOf(f.pem("mallory"), f.pem("intermediateA"), f.pem("rootA")), cloudId = CAROL) },
            { verify(certificate = f.pem("leafSignedByLeaf"), chain = listOf(f.pem("mallory"), f.pem("intermediateA")), pin = sha256Hex(f.pem("intermediateA")), cloudId = CAROL) },
            { verify(certificate = f.pem("leafSignedByRoot"), chain = listOf(f.pem("rootA")), cloudId = MALLORY) },
            { verify(certificate = f.pem("leafSignedByRoot"), chain = listOf(f.pem("rootA"), f.pem("rootA")), cloudId = MALLORY) },
            { verify(chain = listOf(f.pem("intermediateANoBasicConstraints"), f.pem("rootA"))) },
            { verify(chain = listOf(f.pem("intermediateANotCa"), f.pem("rootA"))) },
            { verify(chain = listOf(f.pem("intermediateANoCertSign"), f.pem("rootA"))) },
            { verify(chain = listOf(f.pem("intermediateAKeyCertSignInPadding"), f.pem("rootA"))) },
            { verify(chain = listOf(f.pem("intermediateA"), f.pem("rootAPathLen0")), pin = f.pem("rootAPathLen0Fingerprint")) },
        )
        badChain.forEachIndexed { i, case -> assertEquals(FederatedCertificateReason.BAD_CHAIN, reasonOf { case() }, "case $i") }
        for (name in listOf("intermediateAUnknownCritical", "intermediateADuplicateBasicConstraints", "intermediateAEmptyKeyUsageWithUnusedBits")) {
            assertEquals(FederatedCertificateReason.MALFORMED, reasonOf { verify(chain = listOf(f.pem(name), f.pem("rootA"))) }, name)
        }
        val expired = listOf(
            { verify(chain = listOf(f.pem("intermediateAExpired"), f.pem("rootA"))) },
            { verify(chain = listOf(f.pem("intermediateANotYetValid"), f.pem("rootA"))) },
            { verify(chain = listOf(f.pem("intermediateA"), f.pem("rootAExpired")), pin = f.pem("rootAExpiredFingerprint")) },
        )
        expired.forEachIndexed { i, case -> assertEquals(FederatedCertificateReason.NOT_VALID_NOW, reasonOf { case() }, "case $i") }
    }

    @Test
    fun theExtensionsChainConstraints() {
        assertEquals(sha256Hex(c.pem("mallory")), verifyMallory().fingerprint)
        verifyMallory(chain = listOf(c.pem("intermediatePathLen0"), c.pem("root")))
        verifyMallory(certificate = c.pem("malloryUnknownNonCritical"))
        val alice = c.pem("forgedAlice")
        val badChain = listOf(
            { verifyMallory(certificate = alice, cloudId = "alice@cloud.partner.example", chain = listOf(c.pem("mallory"), c.pem("intermediate"), c.pem("root"))) },
            { verifyMallory(certificate = alice, cloudId = "alice@cloud.partner.example", chain = listOf(c.pem("mallory"), c.pem("intermediate")), pin = sha256Hex(c.pem("intermediate"))) },
            { verifyMallory(certificate = c.pem("malloryByRoot"), chain = listOf(c.pem("root"))) },
            { verifyMallory(certificate = c.pem("malloryByRoot"), chain = listOf(c.pem("root"), c.pem("root"))) },
            { verifyMallory(certificate = c.pem("malloryBySubIntermediate"), chain = listOf(c.pem("subIntermediate"), c.pem("intermediate"), c.pem("root"))) },
            { verifyMallory(chain = listOf(c.pem("intermediateNoBasicConstraints"), c.pem("root"))) },
            { verifyMallory(chain = listOf(c.pem("intermediateNotCa"), c.pem("root"))) },
            { verifyMallory(chain = listOf(c.pem("intermediateNoCertSign"), c.pem("root"))) },
            { verifyMallory(chain = listOf(c.pem("intermediate"), c.pem("rootPathLen0")), pin = c.pem("rootPathLen0Fingerprint")) },
        )
        badChain.forEachIndexed { i, case -> assertEquals(FederatedCertificateReason.BAD_CHAIN, reasonOf { case() }, "case $i") }
        val malformed = listOf(
            { verifyMallory(certificate = c.pem("malloryUnknownCritical")) },
            { verifyMallory(chain = listOf(c.pem("intermediateUnknownCritical"), c.pem("root"))) },
            { verifyMallory(certificate = c.pem("malloryDuplicateExtension")) },
            { verifyMallory(chain = listOf(c.pem("intermediateDuplicateBasicConstraints"), c.pem("root"))) },
        )
        malformed.forEachIndexed { i, case -> assertEquals(FederatedCertificateReason.MALFORMED, reasonOf { case() }, "case $i") }
        val expired = listOf(
            { verifyMallory(chain = listOf(c.pem("intermediateExpired"), c.pem("root"))) },
            { verifyMallory(chain = listOf(c.pem("intermediateNotYetValid"), c.pem("root"))) },
            { verifyMallory(chain = listOf(c.pem("intermediate"), c.pem("rootExpired")), pin = c.pem("rootExpiredFingerprint")) },
        )
        expired.forEachIndexed { i, case -> assertEquals(FederatedCertificateReason.NOT_VALID_NOW, reasonOf { case() }, "case $i") }
    }

    @Test
    fun aChainKeepiqMintedThroughPhpseclib() {
        for (name in listOf("leaf", "leafResigned", "opensslLeafResigned")) {
            val leaf = p.pem(name)
            val verified = FederatedCertificate.verify(
                leaf, listOf(p.pem("intermediate"), p.pem("root")), p.pem("rootFingerprint"), BOB, minted(leaf, p.pem("intermediate"), p.pem("root")),
            )
            assertEquals(sha256Hex(leaf), verified.fingerprint, name)
            assertContentEquals(Der.spkiFromCertificate(derOf(leaf)), verified.spki, name)
        }
    }
}
