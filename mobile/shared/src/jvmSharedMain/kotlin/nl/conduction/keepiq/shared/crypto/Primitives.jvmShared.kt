// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.crypto

import org.bouncycastle.crypto.generators.Argon2BytesGenerator
import org.bouncycastle.crypto.params.Argon2Parameters
import java.security.GeneralSecurityException
import java.security.KeyFactory
import java.security.KeyPairGenerator
import java.security.MessageDigest
import java.security.SecureRandom
import java.security.Signature
import java.security.interfaces.ECPrivateKey
import java.security.interfaces.ECPublicKey
import java.security.spec.ECGenParameterSpec
import java.security.spec.MGF1ParameterSpec
import java.security.spec.PKCS8EncodedKeySpec
import java.security.spec.X509EncodedKeySpec
import javax.crypto.Cipher
import javax.crypto.Mac
import javax.crypto.spec.GCMParameterSpec
import javax.crypto.spec.OAEPParameterSpec
import javax.crypto.spec.PSource
import javax.crypto.spec.SecretKeySpec

/** javax.crypto and java.security, shared by the jvm() target and Android (API 26+). */
internal actual object Primitives {
    private val random = SecureRandom()

    // Explicit: "OAEPWithSHA-256AndMGF1Padding" alone gives MGF1-SHA1 on the JDK.
    private val oaep = OAEPParameterSpec("SHA-256", "MGF1", MGF1ParameterSpec.SHA256, PSource.PSpecified.DEFAULT)

    actual fun randomBytes(size: Int): ByteArray = ByteArray(size).also { random.nextBytes(it) }

    actual fun sha256(data: ByteArray): ByteArray = MessageDigest.getInstance("SHA-256").digest(data)

    actual fun hmac(algorithm: HmacAlgorithm, key: ByteArray, data: ByteArray): ByteArray {
        val name = when (algorithm) {
            HmacAlgorithm.SHA1 -> "HmacSHA1"
            HmacAlgorithm.SHA256 -> "HmacSHA256"
            HmacAlgorithm.SHA512 -> "HmacSHA512"
        }
        val mac = Mac.getInstance(name)
        mac.init(SecretKeySpec(key, name))
        return mac.doFinal(data)
    }

    /**
     * RFC 8018 PBKDF2 written over HmacSHA256 so the password stays raw UTF-8
     * bytes. PBEKeySpec takes a char[] and providers differ in how they turn
     * it into bytes; WebCrypto uses the bytes as given.
     */
    actual fun pbkdf2Sha256(password: ByteArray, salt: ByteArray, iterations: Int, lengthBytes: Int): ByteArray {
        val mac = Mac.getInstance("HmacSHA256")
        // An empty password is a valid HMAC key for PBKDF2 but SecretKeySpec refuses it.
        mac.init(if (password.isEmpty()) EmptyKey else SecretKeySpec(password, "HmacSHA256"))
        val hLen = mac.macLength
        val blocks = (lengthBytes + hLen - 1) / hLen
        val out = ByteArray(blocks * hLen)
        for (block in 1..blocks) {
            mac.update(salt)
            mac.update(Encoding.uint32BigEndian(block.toLong()))
            var u = mac.doFinal()
            val t = u.copyOf()
            for (i in 2..iterations) {
                u = mac.doFinal(u)
                for (j in t.indices) t[j] = (t[j].toInt() xor u[j].toInt()).toByte()
            }
            t.copyInto(out, (block - 1) * hLen)
        }
        return out.copyOf(lengthBytes)
    }

    actual fun aesGcmEncrypt(key: ByteArray, iv: ByteArray, plaintext: ByteArray): ByteArray {
        val cipher = Cipher.getInstance("AES/GCM/NoPadding")
        cipher.init(Cipher.ENCRYPT_MODE, SecretKeySpec(key, "AES"), GCMParameterSpec(128, iv))
        return cipher.doFinal(plaintext)
    }

    actual fun aesGcmDecrypt(key: ByteArray, iv: ByteArray, ciphertextAndTag: ByteArray): ByteArray = wrap {
        val cipher = Cipher.getInstance("AES/GCM/NoPadding")
        cipher.init(Cipher.DECRYPT_MODE, SecretKeySpec(key, "AES"), GCMParameterSpec(128, iv))
        cipher.doFinal(ciphertextAndTag)
    }

    actual fun rsaOaepSha256Encrypt(spki: ByteArray, plaintext: ByteArray): ByteArray = wrap {
        val key = KeyFactory.getInstance("RSA").generatePublic(X509EncodedKeySpec(spki))
        val cipher = Cipher.getInstance("RSA/ECB/OAEPPadding")
        cipher.init(Cipher.ENCRYPT_MODE, key, oaep)
        cipher.doFinal(plaintext)
    }

    actual fun rsaOaepSha256Decrypt(pkcs8: ByteArray, block: ByteArray): ByteArray = wrap {
        val key = KeyFactory.getInstance("RSA").generatePrivate(PKCS8EncodedKeySpec(pkcs8))
        val cipher = Cipher.getInstance("RSA/ECB/OAEPPadding")
        cipher.init(Cipher.DECRYPT_MODE, key, oaep)
        cipher.doFinal(block)
    }

    actual fun ecdsaP256Sign(pkcs8: ByteArray, data: ByteArray): ByteArray = wrap {
        val key = KeyFactory.getInstance("EC").generatePrivate(PKCS8EncodedKeySpec(pkcs8))
        Signature.getInstance("SHA256withECDSA").run {
            initSign(key)
            update(data)
            sign()
        }
    }

    actual fun ecdsaP256Verify(spki: ByteArray, data: ByteArray, signatureDer: ByteArray): Boolean = try {
        val key = KeyFactory.getInstance("EC").generatePublic(X509EncodedKeySpec(spki))
        Signature.getInstance("SHA256withECDSA").run {
            initVerify(key)
            update(data)
            verify(signatureDer)
        }
    } catch (e: GeneralSecurityException) {
        false
    }

    actual fun ecdsaP256Generate(): RawEcKeyPair = wrap {
        val generator = KeyPairGenerator.getInstance("EC")
        generator.initialize(ECGenParameterSpec("secp256r1"), random)
        val pair = generator.generateKeyPair()
        val point = (pair.public as ECPublicKey).w
        RawEcKeyPair(
            d = fixed32((pair.private as ECPrivateKey).s),
            point = byteArrayOf(0x04) + fixed32(point.affineX) + fixed32(point.affineY),
        )
    }

    /** A non-negative integer as exactly 32 big-endian bytes (BigInteger adds a sign byte or drops leading zeros). */
    private fun fixed32(value: java.math.BigInteger): ByteArray {
        val bytes = value.toByteArray()
        return when {
            bytes.size == 32 -> bytes
            bytes.size > 32 -> bytes.copyOfRange(bytes.size - 32, bytes.size)
            else -> ByteArray(32 - bytes.size) + bytes
        }
    }

    private inline fun <T> wrap(block: () -> T): T = try {
        block()
    } catch (e: GeneralSecurityException) {
        throw KeepiqCryptoException(e.message ?: e::class.simpleName ?: "crypto failure", e)
    }

    private object EmptyKey : javax.crypto.SecretKey {
        private fun readResolve(): Any = EmptyKey
        override fun getAlgorithm() = "HmacSHA256"
        override fun getFormat() = "RAW"
        override fun getEncoded() = ByteArray(0)
    }
}

internal actual fun argon2id(
    password: ByteArray,
    salt: ByteArray,
    memoryKiB: Int,
    iterations: Int,
    parallelism: Int,
    lengthBytes: Int,
): ByteArray {
    val params = Argon2Parameters.Builder(Argon2Parameters.ARGON2_id)
        .withVersion(Argon2Parameters.ARGON2_VERSION_13)
        .withMemoryAsKB(memoryKiB)
        .withIterations(iterations)
        .withParallelism(parallelism)
        .withSalt(salt)
        .build()
    val out = ByteArray(lengthBytes)
    Argon2BytesGenerator().apply { init(params) }.generateBytes(password, out)
    return out
}

internal actual val argon2idAvailable: Boolean = true
