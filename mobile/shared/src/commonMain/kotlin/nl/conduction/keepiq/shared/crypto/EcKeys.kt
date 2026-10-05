// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.crypto

/**
 * P-256 keys in the shapes WebCrypto exports them, so a passkey made on the
 * phone stores the same bytes the browser extension would
 * (browser-extension/src/passkey/webauthn.js createCredential exports
 * `pkcs8` and `raw`):
 *
 * - PKCS#8 with the curve named and the public key included, as Chrome,
 *   Firefox and Node export it. Firefox imports an EC key only with its
 *   public key, so the phone always writes it.
 * - SubjectPublicKeyInfo and the COSE EC2 key from the uncompressed point.
 */
internal object EcKeys {
    private val EC_ALGORITHM = byteArrayOf(
        0x30, 0x13,
        0x06, 0x07, 0x2A, 0x86.toByte(), 0x48, 0xCE.toByte(), 0x3D, 0x02, 0x01,
        0x06, 0x08, 0x2A, 0x86.toByte(), 0x48, 0xCE.toByte(), 0x3D, 0x03, 0x01, 0x07,
    )

    /** DER PrivateKeyInfo: version 0, id-ecPublicKey on prime256v1, ECPrivateKey with the public key. */
    fun pkcs8(d: ByteArray, point: ByteArray): ByteArray {
        require(d.size == 32 && point.size == 65 && point[0] == 0x04.toByte()) { "not a P-256 key" }
        val publicKey = byteArrayOf(0xA1.toByte(), 0x44, 0x03, 0x42, 0x00) + point
        val ecPrivateKey = byteArrayOf(0x30, 0x6B, 0x02, 0x01, 0x01, 0x04, 0x20) + d + publicKey
        val body = byteArrayOf(0x02, 0x01, 0x00) + EC_ALGORITHM + byteArrayOf(0x04, 0x6D) + ecPrivateKey
        return byteArrayOf(0x30, 0x81.toByte(), 0x87.toByte()) + body
    }

    /** DER SubjectPublicKeyInfo of an uncompressed P-256 point. */
    fun spki(point: ByteArray): ByteArray {
        require(point.size == 65 && point[0] == 0x04.toByte()) { "not an uncompressed P-256 point" }
        return byteArrayOf(0x30, 0x59) + EC_ALGORITHM + byteArrayOf(0x03, 0x42, 0x00) + point
    }

    /** The uncompressed point of a P-256 SubjectPublicKeyInfo: its last 65 bytes. */
    fun pointOfSpki(spki: ByteArray): ByteArray {
        require(spki.size == 91 && spki[spki.size - 65] == 0x04.toByte()) { "not a P-256 public key" }
        return spki.copyOfRange(spki.size - 65, spki.size)
    }

    /**
     * The scalar and, when the key carries it, the public point of a P-256
     * PKCS#8 key (the shape WebCrypto and this file write). Null for any
     * other shape.
     */
    fun parsePkcs8(der: ByteArray): Pair<ByteArray, ByteArray?>? = runCatching {
        val outer = Der(der).sequence()
        outer.integer()
        outer.sequence()
        val inner = Der(outer.octets()).sequence()
        inner.integer()
        val d = inner.octets()
        var point: ByteArray? = null
        while (inner.hasMore()) {
            val (tag, value) = inner.any()
            if (tag == 0xA1) point = Der(value).bitString()
        }
        if (d.size != 32) null else d to point?.takeIf { it.size == 65 && it[0] == 0x04.toByte() }
    }.getOrNull()

    /** The COSE EC2 key (kty 2, alg -7, crv 1, x, y) of an uncompressed point, as coseKeyFromRawPoint writes it. */
    fun cose(point: ByteArray): ByteArray = Cbor.encode(
        Cbor.OrderedMap(
            listOf(
                1 to 2,
                3 to -7,
                -1 to 1,
                -2 to point.copyOfRange(1, 33),
                -3 to point.copyOfRange(33, 65),
            ),
        ),
    )

    /** PEM with 64-character lines and a final newline, as webauthn.js pemFrom writes it. */
    fun pem(der: ByteArray, label: String): String =
        "-----BEGIN $label-----\n" + Encoding.toBase64(der).chunked(64).joinToString("\n") + "\n-----END $label-----\n"

    /** A minimal DER reader: definite lengths only, enough for the keys above. */
    private class Der(private val bytes: ByteArray) {
        private var pos = 0

        fun hasMore(): Boolean = pos < bytes.size

        fun any(): Pair<Int, ByteArray> {
            val tag = bytes[pos++].toInt() and 0xFF
            var length = bytes[pos++].toInt() and 0xFF
            if (length and 0x80 != 0) {
                val count = length and 0x7F
                require(count in 1..2) { "der: length" }
                length = 0
                repeat(count) { length = (length shl 8) or (bytes[pos++].toInt() and 0xFF) }
            }
            require(pos + length <= bytes.size) { "der: truncated" }
            val value = bytes.copyOfRange(pos, pos + length)
            pos += length
            return tag to value
        }

        private fun expect(tag: Int): ByteArray = any().let { (t, v) ->
            require(t == tag) { "der: tag $t, expected $tag" }
            v
        }

        fun sequence(): Der = Der(expect(0x30))
        fun integer(): ByteArray = expect(0x02)
        fun octets(): ByteArray = expect(0x04)
        fun bitString(): ByteArray = expect(0x03).let { it.copyOfRange(1, it.size) }
    }
}
