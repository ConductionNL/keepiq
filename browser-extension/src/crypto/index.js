/**
 * Crypto for the extension — the SAME recipe as the web app, re-exported
 * verbatim so PHP↔JS↔extension round-trips stay valid (ADR-003 dual-
 * implementation invariant, browser-extension-autofill §2.2). No re-
 * implementation: every primitive is imported from the web app's `src/crypto`.
 *
 * The extension unlock mirrors the web client exactly (ADR-003 browser-user
 * client-side WebCrypto path): master password + suite salt → AES unlock key →
 * decrypt the private-key envelope → import a NON-EXTRACTABLE RSA CryptoKey held
 * only in the service worker's memory. Field values are RSA-OAEP decrypted on
 * demand with that key.
 */
export {
	deriveAesKey,
	encryptPrivateKey,
	decryptPrivateKey,
} from '../../../src/crypto/aes.js'

export {
	generateKeyPair,
	importPrivateKey,
	importPublicKey,
	rsaEncrypt,
	rsaDecrypt,
} from '../../../src/crypto/rsa.js'

export {
	encodeEnvelope,
	decodeEnvelope,
} from '../../../src/crypto/envelope.js'
