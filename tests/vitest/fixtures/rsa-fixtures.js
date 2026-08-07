/**
 * SPDX-FileCopyrightText: 2026 Conduction / Doriath Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Deterministic RSA-2048 test material for the crypto unit tests.
 *
 * These are a real, self-signed X.509 certificate, its matching PKCS#8
 * private key, and the standalone SubjectPublicKeyInfo (SPKI) public key
 * extracted from the same certificate. Generated once with:
 *
 *   openssl req -x509 -newkey rsa:2048 -keyout k.pem -out cert.pem \
 *     -days 3650 -nodes -subj "/CN=doriath-test"
 *   openssl pkcs8 -topk8 -nocrypt -in k.pem -out key8.pem
 *   openssl x509 -in cert.pem -pubkey -noout > pub.pem
 *
 * The certificate exercises the X.509 -> SPKI extraction path (the Phase-0
 * vault-crypto fix). Byte-equality of the cert's embedded SPKI vs the
 * standalone SPKI key is asserted with Node's crypto as an oracle.
 *
 * NOTE: this material is RSA-2048. The src/crypto/rsa.js encrypt/decrypt
 * framing is hardcoded for RSA-4096 (512-byte blocks), so it is NOT used for
 * an encrypt/decrypt round-trip here. The round-trip (single + multi-chunk)
 * is covered with a freshly generated RSA-4096 key from the module's own
 * generateKeyPair(). These fixtures back the certificate/SPKI/PKCS#8 parsing
 * tests only.
 */

export const CERTIFICATE_PEM = `-----BEGIN CERTIFICATE-----
MIIDDzCCAfegAwIBAgIUEhoNogbI+8OLUPMTSWo4tbwxz2gwDQYJKoZIhvcNAQEL
BQAwFzEVMBMGA1UEAwwMZG9yaWF0aC10ZXN0MB4XDTI2MDYxMTA4MDUyNVoXDTM2
MDYwODA4MDUyNVowFzEVMBMGA1UEAwwMZG9yaWF0aC10ZXN0MIIBIjANBgkqhkiG
9w0BAQEFAAOCAQ8AMIIBCgKCAQEAypZ1aOlLzKUiIn7uQwzQ+OLQBeJDXBlPFVp8
trA2m2UT4qYUW3qbBk7lbXmCTO209PvNCQnFsbYP51a81h9Tqz6FM9LE/CVLpEtJ
m0zwMWG7THKlEQQoTuNbWeCKJqHh199eDSv4P0Av8zZq/ghQ/NRcnDVy9yBLzXI7
C2qdQU0+7zbM6ms/EtGLh7MV1BEjzGWa9L4fES05Z1aLp9+IQ6QCZgr7lm0rzN5f
9uyXvXKAVEsd03cNIBkDzV8jfwObuhFAS83VlrfxTJS3Uqt4egf7+MJPI6bZL98s
Lf0EzvxhcLL6UCgKn17d5awirUgMZjtwz7bUh0rDA29rH8Nq+wIDAQABo1MwUTAd
BgNVHQ4EFgQU3DgrP/p+0/4YDeoD7Bia1dvzyIswHwYDVR0jBBgwFoAU3DgrP/p+
0/4YDeoD7Bia1dvzyIswDwYDVR0TAQH/BAUwAwEB/zANBgkqhkiG9w0BAQsFAAOC
AQEAMJdc7vRPpvPTCK287EUEbymcJf1qb5Sbv5XXCraWWBjddrY0iLPYjtm6jqFF
8Kufb7BfLu1AEGg8hDf76HyEpZLjGtim5iMxGAXIlWKVXwmYrS2hVGNSLX33d4I2
02tm8D3SxJ1YIXB3W9JG2FP0ZcJR26Mof1qCSu15Wa2l7Uu1ynhewUa46cvh9viM
tnzNNiHJ8/NCsAIoK2e+YuTTrctrZNjkblnUEPlMqFD7p1jsD7jiiHJYCR9i8Qxv
NkhenEzF2d5c6titDcRvYxQc2md2xUpVPueQn9/dNPjzIwBMmBXphr69D5kNMdU6
MiB73WmnK1nfGDXinjg7mDo4jA==
-----END CERTIFICATE-----`

export const PRIVATE_KEY_PKCS8_PEM = `-----BEGIN PRIVATE KEY-----
MIIEvgIBADANBgkqhkiG9w0BAQEFAASCBKgwggSkAgEAAoIBAQDKlnVo6UvMpSIi
fu5DDND44tAF4kNcGU8VWny2sDabZRPiphRbepsGTuVteYJM7bT0+80JCcWxtg/n
VrzWH1OrPoUz0sT8JUukS0mbTPAxYbtMcqURBChO41tZ4IomoeHX314NK/g/QC/z
Nmr+CFD81FycNXL3IEvNcjsLap1BTT7vNszqaz8S0YuHsxXUESPMZZr0vh8RLTln
Voun34hDpAJmCvuWbSvM3l/27Je9coBUSx3Tdw0gGQPNXyN/A5u6EUBLzdWWt/FM
lLdSq3h6B/v4wk8jptkv3ywt/QTO/GFwsvpQKAqfXt3lrCKtSAxmO3DPttSHSsMD
b2sfw2r7AgMBAAECggEBAIvynk8IAtXvKYJ8/ukQvHeCb8PwxymjTi9pIAgv+Lkm
fTMwGZYMP3V/IRycOPgdqckm/UAGISyfoaLlF3QvleQRP4FKU8v/k55+Z+3Bm5fx
dKrd88uqfJHqm5ud8rG3WMWAx37/5fEDzVwNNqIgapoNtaAviCjRhav6AnHjh5io
onGdI+6V8dBzB4TLaC0SyF60rLcLfg1jZigvN4c1W5owPvOFX3wTTV8kfVtDCUuJ
Lc9hY+zy7GDNTY8C2vACGiPVFBQWti1labDtjiAmvl7PBWr9NTfO8yDFEniH66Uf
VNUJH62tcsn64eO1oBkpgGfNCKpdp08ZZ0wSTL5n6QkCgYEA5Gkh1qdgA3wZcM6g
3UyzBsZ8QcuN/cTUiqUj1UxWfMAfeDd16MXEPR4PNKnA4BFdSq3ieAxv68XLxv7+
68P57ZXGgWC+2hCD3vaZzQola9eUhamCArulcTJXm5DcWq9GtyplZ4LNn7e0TJZd
petRuQ1ZGv7SEGfa7kuaGGNuD2cCgYEA4w7Vrx+ETnSzuljhp1yQCnTR/hKhdW2E
8ZNUrlknyBL2zowVrmvJ+afn4exeIuFADtyxEGjhP5Vth2CFmylerdqPhHACoxX/
A6BZC/ayZqWTZ1Mqj6IvP4/IxDyaBD8TY9969XEdac43g7fuMk4Rb6QaxV9+qcHC
S47M3W7YT00CgYBZ5AYtNDHVLUHV43vrnAPY5sSAIFwBQzViWxt/FkvzTKkV5r3A
nhRc+TeCwkvl4u+UNFqsZDin0XAhILmyj64MkqVMxYZWy6kaVnKw/w07I9yPveYs
rSyvH+DamGggSFrMOyMtWY0TDnkmqwawBaxj55zpwt9pFXZT0e0TLA5kgQKBgEYT
9wmUvZ4FUM9LzWF9JQvFIGa9U03N3oE8yp8A71FF4RzAiZSKugyusNe+vxMe2El9
/bwl0pdwRBzLQpEwBIO9+BuVAotZJ5rz62fQ3SDnK4ZxWap5EQIaG4nNdm+nFBH4
EJgeMEjOl720j/TAuYruaEDQh2RXY+M0ELCrHGHlAoGBAKRFsFc2AKuU+52wCShX
U4yepyRuFVPvwYQy+6ZRpXOtsp+4BW8tfkAkQIQLooEH2Fa2bzMe47fDichfJn7s
fr9zdm/0awbYnApmtICZBtgajqEHeimy7mXZHcs3bcmAnfV1D26aqTDhGUQgjY9T
MM71k/F+nspvg5IxDx5ifhfr
-----END PRIVATE KEY-----`

export const PUBLIC_KEY_SPKI_PEM = `-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAypZ1aOlLzKUiIn7uQwzQ
+OLQBeJDXBlPFVp8trA2m2UT4qYUW3qbBk7lbXmCTO209PvNCQnFsbYP51a81h9T
qz6FM9LE/CVLpEtJm0zwMWG7THKlEQQoTuNbWeCKJqHh199eDSv4P0Av8zZq/ghQ
/NRcnDVy9yBLzXI7C2qdQU0+7zbM6ms/EtGLh7MV1BEjzGWa9L4fES05Z1aLp9+I
Q6QCZgr7lm0rzN5f9uyXvXKAVEsd03cNIBkDzV8jfwObuhFAS83VlrfxTJS3Uqt4
egf7+MJPI6bZL98sLf0EzvxhcLL6UCgKn17d5awirUgMZjtwz7bUh0rDA29rH8Nq
+wIDAQAB
-----END PUBLIC KEY-----`

/**
 * Deterministic RSA-4096 test material — pair A ("primary").
 *
 * Real 4096-bit RSA, generated once out-of-band and committed, so no test ever
 * pays a keygen:
 *
 *   openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:4096 -out k.pem
 *   openssl pkcs8 -topk8 -nocrypt -in k.pem -out key8.pem
 *   openssl rsa -in k.pem -pubout -out pub.pem
 *
 * The modulus is 4096 bits, so RSA-OAEP-SHA256 blocks are 512 bytes and the
 * chunking framing in `src/crypto/rsa.js` is exercised exactly as in
 * production. These keys are TEST material only and never touch a real vault.
 */
export const RSA4096_PRIVATE_KEY_PKCS8_PEM = `-----BEGIN PRIVATE KEY-----
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
TR8L8Sm+eFuU3gLBp1cI/jclLYSxNxPT
-----END PRIVATE KEY-----`

/** SPKI public half of {@link RSA4096_PRIVATE_KEY_PKCS8_PEM}. */
export const RSA4096_PUBLIC_KEY_SPKI_PEM = `-----BEGIN PUBLIC KEY-----
MIICIjANBgkqhkiG9w0BAQEFAAOCAg8AMIICCgKCAgEA5nI971jVJMdGEkzRKJwm
OJ8koLePTXiLF//ymL8SIAD+7+oohFsUejkNQttwCRfMdJjMIttmrK4brMoHKded
lFgr3athyG3Ad8dvVNOqw1qpPG3i0zRsO/VPZU2MrHFjvbYxGoEgSJUXGJEPG3CM
Cm8ta6GHwSQ8IksE9ocNu9GHyJYPkcG3/rPxmOGG0644tzBxSrIgrW9ElQJPwrLk
5GF+QJnDq08vWOW/14m1FmZhzEILDVdfz/GQYlGKWCapq9EiaS2Q4jZkyMChuJP8
y3O+v/Shgk9WZ0werHYz45E0j5pnQN5D1aGMG6G/ygbMJmKS3umzBJw8Wa44RxfF
+2dsP2uExrd9fcFN1t5w8xocRX7w7FAiR3dNjAfb/+ldFBQ6VITWwwakmFmh3CbJ
96Bbi6i8XtWwyTGtOBKNFb2lqw5wuPcQNvpwo/f/X0251IprMaTHsXZ5i8oPvCcK
Fe7W7rpB6anp4AvSVjp/fcSxnQpQuxWVSc8sUshv/ReXkBTHO3DNB6tQht2DmbX8
REUMNIgOK8GMh0+ucRX5sJGdtmuePmoMqViW+GXMh6kqQ6gZyOzFpjluVuy1Y9nt
ZcLKDvzasoYOaTfEE7GRuj+5d8gDh+agjLQqprF73HHwefXiKYO+MFhSj5mhmIig
D0x5Bm6KbHUJDW0OXOk3FSkCAwEAAQ==
-----END PUBLIC KEY-----`

/**
 * Deterministic RSA-4096 test material — pair B ("secondary").
 *
 * A second, definitely-different key pair for the wrong-key negative controls
 * (an attacker who is not the grantee must not be able to decrypt).
 */
export const RSA4096_SECONDARY_PRIVATE_KEY_PKCS8_PEM = `-----BEGIN PRIVATE KEY-----
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
dD+JeahENOtp1vLgAH+YJN8mUinsEB0=
-----END PRIVATE KEY-----`

/** SPKI public half of {@link RSA4096_SECONDARY_PRIVATE_KEY_PKCS8_PEM}. */
export const RSA4096_SECONDARY_PUBLIC_KEY_SPKI_PEM = `-----BEGIN PUBLIC KEY-----
MIICIjANBgkqhkiG9w0BAQEFAAOCAg8AMIICCgKCAgEAyH6i1Xvh+yg75+xsupko
Y6D8kx27ZcMFjLt2AWkd4Io45uIyDaamJD+IkmRI3eaijzEB5vu1TgnqZkpMCuhb
czVEJtMrYyrWzSUL21PDCZhOTNPlzW4LwHaW8rAYKG7nTuiFckpuBITPP7jG6LnW
TYRGSd8hL2MJFRtzHcq533yL11hhEAveqFJyBmKQQgBxwNUm9ICs+QakBwLftIDp
he9xeqNxG3RSQ/AX367sUFlPygS/KFNkbxkW/DkfFy92/c6DcI+2ILW4vAyOH0c9
wL8Mu+u33nUvtD+GS5o4onPbWo16HI46O1th34fTBOZIRNzYoZBqmIEx0Ipxgb89
mV+9qA7+PSMFQwLHGBO8DHz/oXU7TgJeNt1eB9ukchdwrbV8K0G3tdM1F82QPyx1
BIHOfouKmB5RNyV9HjHJoxnbd26mb4L20ndGeAdhfFNcWoOfktqd1C5iLhf7B6gY
WOYeWPNhz09Q/fLBqxv/y14Uy/xz+rbtg02CjJu+fnnfgM9cc/MVFpUrSEXNg53h
4B9hMk7JBAGOic1EHRTzfkgsrMkfMYP9UxNzHtc2sO/WamcYD/DBKZY55B5IdWzJ
wFaLu3ERfRfyg6WJfXGcdtsO7oBTNZsfJiz3BWHBSKf3u9lqu5ArS0JTJ0ORTIEh
BvrlPwlbjCjV2jlHyg+zI2kCAwEAAQ==
-----END PUBLIC KEY-----`

/**
 * Per-file cache of the imported RSA-4096 CryptoKey pairs.
 *
 * WHY THE KEYS ARE STATIC AND NOT GENERATED
 * -----------------------------------------
 * `generateKeyPair()` is a genuine WebCrypto RSA-4096 keygen — a random prime
 * search whose runtime is unbounded and highly variable (measured here at
 * 105-681ms across 10 samples on an idle machine; a contended two-core CI
 * runner executing 69 spec files across parallel workers lands several times
 * higher). Vitest's per-test default is 5000ms, so every spec that generated
 * a key on the timed path was a coin flip:
 *
 *   - `tests/store/import.spec.js` lost it in run 30884131373;
 *   - caching the generated pair per file (#148) removed the repeats but left
 *     the *first* keygen in each file on the timed path, and
 *     `tests/vitest/emergencyEnvelope.spec.js` then lost the same coin flip in
 *     run 31083918823 ("Test timed out in 5000ms").
 *
 * Importing committed key material is bounded (sub-millisecond) and removes
 * the variance outright. Nothing about the code under test changes: these are
 * real 4096-bit RSA keys, `crypto.subtle` performs the same RSA-OAEP
 * encrypt/decrypt, and every assertion still runs against genuine crypto. The
 * tests assert round-trip behaviour, never key freshness or uniqueness. Where
 * key *generation* itself is the subject, the spec calls `generateKeyPair()`
 * directly (see `rsa.spec.js`).
 *
 * @type {{primary: Promise<object>|null, secondary: Promise<object>|null}}
 */
const keyPairCache = { primary: null, secondary: null }

/**
 * Import a committed PKCS#8 / SPKI PEM pair as RSA-OAEP CryptoKeys.
 *
 * @param {string} privatePem PKCS#8 private key PEM
 * @param {string} publicPem SPKI public key PEM
 * @return {Promise<{publicKey: CryptoKey, privateKey: CryptoKey, publicKeyPem: string, privateKeyPem: string}>}
 */
async function importFixtureKeyPair(privatePem, publicPem) {
	const algorithm = { name: 'RSA-OAEP', hash: 'SHA-256' }
	const [privateKey, publicKey] = await Promise.all([
		// extractable = false mirrors the production import in src/crypto/rsa.js.
		crypto.subtle.importKey('pkcs8', pemToDer(privatePem), algorithm, false, ['decrypt']),
		crypto.subtle.importKey('spki', pemToDer(publicPem), algorithm, true, ['encrypt']),
	])

	return { publicKey, privateKey, publicKeyPem: publicPem, privateKeyPem: privatePem }
}

/**
 * The shared RSA-4096 key pair for this spec file.
 *
 * @return {Promise<object>} The cached key pair.
 */
export function sharedKeyPair() {
	if (keyPairCache.primary === null) {
		keyPairCache.primary = importFixtureKeyPair(RSA4096_PRIVATE_KEY_PKCS8_PEM, RSA4096_PUBLIC_KEY_SPKI_PEM)
	}

	return keyPairCache.primary
}

/**
 * A second RSA-4096 key pair, distinct from `sharedKeyPair()`, for negative
 * controls that must prove the wrong key cannot decrypt.
 *
 * @return {Promise<object>} The cached secondary key pair.
 */
export function secondaryKeyPair() {
	if (keyPairCache.secondary === null) {
		keyPairCache.secondary = importFixtureKeyPair(
			RSA4096_SECONDARY_PRIVATE_KEY_PKCS8_PEM,
			RSA4096_SECONDARY_PUBLIC_KEY_SPKI_PEM,
		)
	}

	return keyPairCache.secondary
}

/**
 * Decode a PEM body into raw DER bytes (drops armor + whitespace).
 *
 * @param {string} pem PEM-encoded blob
 * @return {Uint8Array} Raw DER bytes
 */
export function pemToDer(pem) {
	const body = pem
		.replace(/-----BEGIN [^-]+-----/, '')
		.replace(/-----END [^-]+-----/, '')
		.replace(/\s/g, '')
	return Uint8Array.from(atob(body), c => c.charCodeAt(0))
}
