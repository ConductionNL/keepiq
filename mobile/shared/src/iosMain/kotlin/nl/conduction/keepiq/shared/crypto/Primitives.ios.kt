// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.crypto

import dev.whyoleg.cryptography.BinarySize.Companion.bits
import dev.whyoleg.cryptography.CryptographyAlgorithmId
import dev.whyoleg.cryptography.CryptographyAlgorithm
import dev.whyoleg.cryptography.CryptographyProvider
import dev.whyoleg.cryptography.DelicateCryptographyApi
import dev.whyoleg.cryptography.algorithms.AES
import dev.whyoleg.cryptography.algorithms.EC
import dev.whyoleg.cryptography.algorithms.ECDSA
import dev.whyoleg.cryptography.algorithms.HMAC
import dev.whyoleg.cryptography.algorithms.PBKDF2
import dev.whyoleg.cryptography.algorithms.RSA
import dev.whyoleg.cryptography.algorithms.SHA1
import dev.whyoleg.cryptography.algorithms.SHA256
import dev.whyoleg.cryptography.algorithms.SHA512
import dev.whyoleg.cryptography.providers.apple.Apple
import dev.whyoleg.cryptography.providers.cryptokit.CryptoKit
import dev.whyoleg.cryptography.random.CryptographyRandom

/**
 * iOS: Security framework (SecKey) and CommonCrypto through
 * cryptography-kotlin's Apple provider, CryptoKit for what the Apple provider
 * lacks (AES-GCM). Design D2 names the same frameworks; the library only
 * bridges them to Kotlin/Native.
 */
@OptIn(DelicateCryptographyApi::class)
internal actual object Primitives {
    private fun <A : CryptographyAlgorithm> algorithm(id: CryptographyAlgorithmId<A>): A =
        CryptographyProvider.Apple.getOrNull(id) ?: CryptographyProvider.CryptoKit.get(id)

    actual fun randomBytes(size: Int): ByteArray = CryptographyRandom.nextBytes(size)

    actual fun sha256(data: ByteArray): ByteArray = algorithm(SHA256).hasher().hashBlocking(data)

    actual fun hmac(algorithm: HmacAlgorithm, key: ByteArray, data: ByteArray): ByteArray {
        val digest = when (algorithm) {
            HmacAlgorithm.SHA1 -> SHA1
            HmacAlgorithm.SHA256 -> SHA256
            HmacAlgorithm.SHA512 -> SHA512
        }
        val hmacKey = algorithm(HMAC).keyDecoder(digest).decodeFromByteArrayBlocking(HMAC.Key.Format.RAW, key)
        return hmacKey.signatureGenerator().generateSignatureBlocking(data)
    }

    actual fun pbkdf2Sha256(password: ByteArray, salt: ByteArray, iterations: Int, lengthBytes: Int): ByteArray =
        algorithm(PBKDF2)
            .secretDerivation(SHA256, iterations, (lengthBytes * 8).bits, salt)
            .deriveSecretToByteArrayBlocking(password)

    actual fun aesGcmEncrypt(key: ByteArray, iv: ByteArray, plaintext: ByteArray): ByteArray =
        aesKey(key).cipher().encryptWithIvBlocking(iv, plaintext)

    actual fun aesGcmDecrypt(key: ByteArray, iv: ByteArray, ciphertextAndTag: ByteArray): ByteArray = wrap {
        aesKey(key).cipher().decryptWithIvBlocking(iv, ciphertextAndTag)
    }

    actual fun rsaOaepSha256Encrypt(spki: ByteArray, plaintext: ByteArray): ByteArray = wrap {
        algorithm(RSA.OAEP).publicKeyDecoder(SHA256)
            .decodeFromByteArrayBlocking(RSA.PublicKey.Format.DER, spki)
            .encryptor().encryptBlocking(plaintext)
    }

    actual fun rsaOaepSha256Decrypt(pkcs8: ByteArray, block: ByteArray): ByteArray = wrap {
        algorithm(RSA.OAEP).privateKeyDecoder(SHA256)
            .decodeFromByteArrayBlocking(RSA.PrivateKey.Format.DER, pkcs8)
            .decryptor().decryptBlocking(block)
    }

    actual fun ecdsaP256Sign(pkcs8: ByteArray, data: ByteArray): ByteArray = wrap {
        algorithm(ECDSA).privateKeyDecoder(EC.Curve.P256)
            .decodeFromByteArrayBlocking(EC.PrivateKey.Format.DER, pkcs8)
            .signatureGenerator(SHA256, ECDSA.SignatureFormat.DER)
            .generateSignatureBlocking(data)
    }

    actual fun ecdsaP256Verify(spki: ByteArray, data: ByteArray, signatureDer: ByteArray): Boolean = try {
        algorithm(ECDSA).publicKeyDecoder(EC.Curve.P256)
            .decodeFromByteArrayBlocking(EC.PublicKey.Format.DER, spki)
            .signatureVerifier(SHA256, ECDSA.SignatureFormat.DER)
            .tryVerifySignatureBlocking(data, signatureDer)
    } catch (e: Exception) {
        false
    }

    private fun aesKey(key: ByteArray) =
        algorithm(AES.GCM).keyDecoder().decodeFromByteArrayBlocking(AES.Key.Format.RAW, key)

    private inline fun <T> wrap(block: () -> T): T = try {
        block()
    } catch (e: KeepiqCryptoException) {
        throw e
    } catch (e: Exception) {
        throw KeepiqCryptoException(e.message ?: "crypto failure", e)
    }
}

/**
 * Argon2id is not available on iOS yet: neither the Security framework nor
 * CryptoKit has it, and the reference C code through cinterop is open as
 * task 1.3.1 in openspec/changes/clients-mobile-apps/tasks.md. Until then a
 * password Send cannot be created or opened on iOS; everything else works.
 */
internal actual fun argon2id(
    password: ByteArray,
    salt: ByteArray,
    memoryKiB: Int,
    iterations: Int,
    parallelism: Int,
    lengthBytes: Int,
): ByteArray = throw UnsupportedOperationException("Argon2id is not available on this target (iOS, task 1.3.1)")

internal actual val argon2idAvailable: Boolean = false
