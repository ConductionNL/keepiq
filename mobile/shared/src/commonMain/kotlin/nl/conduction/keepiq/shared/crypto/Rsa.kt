// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.crypto

/**
 * An RSA public key a field is encrypted to: the suite or user key, given as
 * an SPKI PEM or as an X.509 certificate PEM (src/crypto/rsa.js importPublicKey).
 */
class RsaPublicKey private constructor(internal val spki: ByteArray) {
    companion object {
        /** Reads `BEGIN PUBLIC KEY` (SPKI) or `BEGIN CERTIFICATE` (X.509, SPKI taken out). */
        fun fromPem(pem: String): RsaPublicKey {
            val isCertificate = pem.contains("-----BEGIN CERTIFICATE-----")
            val body = pem
                .replaceFirst("-----BEGIN PUBLIC KEY-----", "")
                .replaceFirst("-----END PUBLIC KEY-----", "")
                .replaceFirst("-----BEGIN CERTIFICATE-----", "")
                .replaceFirst("-----END CERTIFICATE-----", "")
                .filterNot { it.isWhitespace() }
            val der = Encoding.fromBase64(body)
            return RsaPublicKey(if (isCertificate) Der.spkiFromCertificate(der) else der)
        }
    }
}

/** An RSA private key from a PKCS#8 PEM (src/crypto/rsa.js importPrivateKey). */
class RsaPrivateKey private constructor(internal val pkcs8: ByteArray) {
    companion object {
        fun fromPem(pem: String): RsaPrivateKey {
            val body = pem
                .replaceFirst("-----BEGIN PRIVATE KEY-----", "")
                .replaceFirst("-----END PRIVATE KEY-----", "")
                .replaceFirst("-----BEGIN RSA PRIVATE KEY-----", "")
                .replaceFirst("-----END RSA PRIVATE KEY-----", "")
                .filterNot { it.isWhitespace() }
            return RsaPrivateKey(Encoding.fromBase64(body))
        }
    }
}

/**
 * Field encryption: RSA-OAEP-4096 with SHA-256, the UTF-8 text split into
 * 446-byte chunks, each encrypted to a 512-byte block, stored as
 * base64(uint32 big-endian chunk count + blocks). Empty text is one chunk.
 * Decryption joins the chunk bytes and decodes UTF-8 once.
 *
 * Source of truth: src/crypto/rsa.js (RSA_BLOCK_SIZE, RSA_CHUNK_SIZE,
 * rsaEncrypt, rsaDecrypt).
 */
object RsaFields {
    const val BLOCK_SIZE = 512
    const val CHUNK_SIZE = 446

    fun encrypt(plaintext: String, publicKey: RsaPublicKey): String {
        val data = Encoding.utf8(plaintext)
        val chunks = if (data.isEmpty()) {
            listOf(ByteArray(0))
        } else {
            (data.indices step CHUNK_SIZE).map { data.copyOfRange(it, minOf(it + CHUNK_SIZE, data.size)) }
        }
        val out = ByteArray(4 + chunks.size * BLOCK_SIZE)
        Encoding.uint32BigEndian(chunks.size.toLong()).copyInto(out, 0)
        chunks.forEachIndexed { i, chunk ->
            val block = Primitives.rsaOaepSha256Encrypt(publicKey.spki, chunk)
            if (block.size != BLOCK_SIZE) {
                throw KeepiqCryptoException("RSA block is ${block.size} bytes, expected $BLOCK_SIZE (not a 4096-bit key)")
            }
            block.copyInto(out, 4 + i * BLOCK_SIZE)
        }
        return Encoding.toBase64(out)
    }

    fun decrypt(ciphertext: String, privateKey: RsaPrivateKey): String {
        val raw = Encoding.fromBase64(ciphertext)
        if (raw.size < 4) throw KeepiqCryptoException("Field ciphertext too short")
        val count = Encoding.readUint32BigEndian(raw, 0)
        // Like rsaDecrypt: read `count` blocks and ignore anything after them.
        if (raw.size.toLong() < 4 + count * BLOCK_SIZE) {
            throw KeepiqCryptoException("Field ciphertext holds ${raw.size} bytes for $count chunks")
        }
        val joined = ArrayList<ByteArray>(count.toInt())
        for (i in 0 until count.toInt()) {
            val block = raw.copyOfRange(4 + i * BLOCK_SIZE, 4 + (i + 1) * BLOCK_SIZE)
            joined.add(Primitives.rsaOaepSha256Decrypt(privateKey.pkcs8, block))
        }
        val total = joined.sumOf { it.size }
        val bytes = ByteArray(total)
        var offset = 0
        for (part in joined) {
            part.copyInto(bytes, offset)
            offset += part.size
        }
        return Encoding.fromUtf8(bytes)
    }
}

/** The minimal DER walking the web app does to take the SPKI out of a certificate. */
internal object Der {
    private const val SEQUENCE = 0x30
    private const val CONTEXT_0 = 0xA0

    private class Length(val length: Int, val headerEnd: Int)

    private fun readLength(der: ByteArray, offset: Int): Length {
        val first = der[offset].toInt() and 0xFF
        if (first and 0x80 == 0) return Length(first, offset + 1)
        val count = first and 0x7F
        if (count > 4) throw KeepiqCryptoException("DER length too long")
        var length = 0
        for (i in 0 until count) {
            length = (length shl 8) or (der[offset + 1 + i].toInt() and 0xFF)
        }
        return Length(length, offset + 1 + count)
    }

    /**
     * Mirrors extractSpkiFromCertificate in src/crypto/rsa.js: the
     * SubjectPublicKeyInfo is TBSCertificate child 6 after an explicit [0]
     * version, or child 5 without one.
     */
    fun spkiFromCertificate(cert: ByteArray): ByteArray {
        try {
            if (cert[0].toInt() and 0xFF != SEQUENCE) throw KeepiqCryptoException("Not a DER SEQUENCE (certificate expected)")
            val outer = readLength(cert, 1)
            if (cert[outer.headerEnd].toInt() and 0xFF != SEQUENCE) {
                throw KeepiqCryptoException("Malformed certificate: tbsCertificate not a SEQUENCE")
            }
            val tbs = readLength(cert, outer.headerEnd + 1)
            var pos = tbs.headerEnd
            val end = tbs.headerEnd + tbs.length
            val starts = ArrayList<IntArray>()
            while (pos < end) {
                val tag = cert[pos].toInt() and 0xFF
                val len = readLength(cert, pos + 1)
                val fieldEnd = len.headerEnd + len.length
                starts.add(intArrayOf(tag, pos, fieldEnd))
                pos = fieldEnd
            }
            val hasVersion = starts.isNotEmpty() && starts[0][0] == CONTEXT_0
            val spki = starts.getOrNull(if (hasVersion) 6 else 5)
            if (spki == null || cert[spki[1]].toInt() and 0xFF != SEQUENCE) {
                throw KeepiqCryptoException("Could not locate SubjectPublicKeyInfo in certificate")
            }
            return cert.copyOfRange(spki[1], spki[2])
        } catch (e: IndexOutOfBoundsException) {
            throw KeepiqCryptoException("Malformed certificate", e)
        }
    }
}
