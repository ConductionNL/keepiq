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
 * Lazily-generated, per-file cache of real RSA-4096 key pairs.
 *
 * `generateKeyPair()` is a genuine WebCrypto RSA-4096 keygen — a random prime
 * search whose runtime is unbounded and highly variable (measured locally at
 * 154-850ms over 12 samples; a contended two-core CI runner executing 69 spec
 * files across parallel workers lands several times higher). Specs that called
 * it once per test were paying that variance repeatedly against vitest's
 * 5000ms per-test default, which is what made
 * `tests/store/import.spec.js` time out in ConductionNL/doriath run
 * 30884131373 while passing locally.
 *
 * Vitest isolates module state per spec file, so this cache is per-file: the
 * first test in a file pays for one keygen and the rest reuse it. The keys are
 * real and the encrypt/decrypt paths under test are unchanged — only the
 * redundant regeneration is removed. Use `freshKeyPair()` where the test's
 * subject really is key *generation*, and `secondaryKeyPair()` where a test
 * needs a second, definitely-different pair (wrong-key negative controls).
 *
 * @type {{primary: Promise<object>|null, secondary: Promise<object>|null}}
 */
const keyPairCache = { primary: null, secondary: null }

/**
 * The shared RSA-4096 key pair for this spec file.
 *
 * @param {Function} generateKeyPair The module-under-test's key generator.
 * @return {Promise<object>} The cached key pair.
 */
export function sharedKeyPair(generateKeyPair) {
	if (keyPairCache.primary === null) {
		keyPairCache.primary = generateKeyPair()
	}

	return keyPairCache.primary
}

/**
 * A second RSA-4096 key pair, distinct from `sharedKeyPair()`, for negative
 * controls that must prove the wrong key cannot decrypt.
 *
 * @param {Function} generateKeyPair The module-under-test's key generator.
 * @return {Promise<object>} The cached secondary key pair.
 */
export function secondaryKeyPair(generateKeyPair) {
	if (keyPairCache.secondary === null) {
		keyPairCache.secondary = generateKeyPair()
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
