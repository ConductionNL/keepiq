## ADDED Requirements

### Requirement: DecryptService — Stateless PHP Decryption
The system MUST provide a `DecryptService` class (`OCA\Doriath\Service\DecryptService`) that performs stateless RSA and AES decryption using PHP OpenSSL. The service MUST NOT access any database tables or entities. It MUST accept ciphertext and key material as parameters and return plaintext.

This service is intended for internal Nextcloud app consumption (e.g., OpenConnector retrieving application secrets). It is NOT used for browser-user decryption (which is always client-side via WebCrypto).

Methods:
- `rsaDecrypt(string $ciphertext, string $privateKeyPem): string` — Decrypt RSA-OAEP-SHA256 ciphertext with chunking support
- `aesDecrypt(string $envelope, string $key): string` — Decrypt AES-256-GCM envelope (version + salt + IV + ciphertext + tag)
- `decryptPrivateKey(string $encryptedBlob, string $aesKey): string` — Convenience: AES-decrypt a private key blob, return PEM

RSA decryption MUST handle the chunked ciphertext format: `[4 bytes: chunk count][512 bytes: chunk 1][512 bytes: chunk 2]...`. Each 512-byte chunk is decrypted independently with RSA-OAEP-SHA256, and the plaintext chunks are concatenated.

#### Scenario: Decrypt single-chunk RSA ciphertext
- **WHEN** `rsaDecrypt` is called with a ciphertext containing chunk count = 1
- **THEN** it MUST decrypt the single 512-byte chunk using `openssl_private_decrypt` with `OPENSSL_PKCS1_OAEP_PADDING` and SHA-256 digest
- **AND** return the plaintext

#### Scenario: Decrypt multi-chunk RSA ciphertext
- **WHEN** `rsaDecrypt` is called with a ciphertext containing chunk count > 1
- **THEN** it MUST decrypt each 512-byte chunk independently
- **AND** concatenate the plaintext chunks in order
- **AND** return the full plaintext

#### Scenario: Decrypt AES-256-GCM envelope
- **WHEN** `aesDecrypt` is called with a base64-encoded envelope
- **THEN** it MUST decode the base64, parse the version byte, extract salt + IV + ciphertext + GCM tag
- **AND** decrypt using `openssl_decrypt` with `aes-256-gcm`
- **AND** return the plaintext

#### Scenario: Invalid GCM tag
- **WHEN** `aesDecrypt` is called with a tampered envelope (wrong GCM tag)
- **THEN** it MUST throw a `DecryptionException` indicating authentication failure

### Requirement: EncryptService — Stateless PHP Encryption
The system MUST provide an `EncryptService` class (`OCA\Doriath\Service\EncryptService`) that performs stateless RSA and AES encryption using PHP OpenSSL. The service MUST NOT access any database tables or entities.

Methods:
- `rsaEncrypt(string $plaintext, string $publicKeyPem): string` — Encrypt with RSA-OAEP-SHA256, auto-chunking for data > 446 bytes
- `aesEncrypt(string $plaintext, string $key): string` — Encrypt with AES-256-GCM, return base64-encoded envelope
- `encryptPrivateKey(string $privateKeyPem, string $aesKey): string` — Convenience: AES-encrypt a PEM private key

RSA encryption MUST split plaintext into chunks of at most 446 bytes (RSA-4096 OAEP-SHA256 limit: 512 - 2*32 - 2 = 446). Each chunk is encrypted independently to produce a 512-byte ciphertext block. The output format is: `[4 bytes: chunk count][512 bytes: chunk 1]...`.

AES encryption MUST generate a random 16-byte salt and 12-byte IV per operation. The envelope format is: `[1 byte: version 0x01][16 bytes: salt][12 bytes: IV][N bytes: ciphertext][16 bytes: GCM tag]`, base64-encoded.

#### Scenario: Encrypt small plaintext with RSA
- **WHEN** `rsaEncrypt` is called with plaintext <= 446 bytes
- **THEN** it MUST produce a single-chunk ciphertext: `[chunk count = 1][512-byte encrypted chunk]`

#### Scenario: Encrypt large plaintext with RSA chunking
- **WHEN** `rsaEncrypt` is called with plaintext of 1000 bytes
- **THEN** it MUST split into 3 chunks (446 + 446 + 108 bytes)
- **AND** produce ciphertext: `[chunk count = 3][512 bytes][512 bytes][512 bytes]`

#### Scenario: Encrypt with AES-256-GCM
- **WHEN** `aesEncrypt` is called with plaintext and a 256-bit key
- **THEN** it MUST generate a random salt and IV
- **AND** produce a base64-encoded envelope with version 0x01
- **AND** the envelope MUST be decryptable by `aesDecrypt` with the same key

### Requirement: Cross-Implementation Ciphertext Compatibility
Ciphertext produced by PHP/OpenSSL MUST be decryptable by JS/WebCrypto, and vice versa. Both implementations MUST use identical:
- RSA padding: OAEP-SHA256
- RSA chunk format: 4-byte count + 512-byte blocks
- AES mode: AES-256-GCM
- AES envelope format: version + salt + IV + ciphertext + tag (base64)
- PBKDF2 parameters: SHA-256, 600,000 iterations, 16-byte salt

#### Scenario: PHP encrypts, JS decrypts
- **WHEN** `EncryptService::rsaEncrypt` produces ciphertext using a public key
- **THEN** the JS WebCrypto implementation MUST successfully decrypt it using the corresponding private key
- **AND** produce identical plaintext

#### Scenario: JS encrypts, PHP decrypts
- **WHEN** the JS WebCrypto implementation encrypts plaintext using a public key
- **THEN** `DecryptService::rsaDecrypt` MUST successfully decrypt it using the corresponding private key
- **AND** produce identical plaintext

#### Scenario: AES cross-implementation round-trip
- **WHEN** either implementation encrypts a private key with AES-256-GCM
- **THEN** the other implementation MUST successfully decrypt it
- **AND** produce the identical PEM private key

### Requirement: WebCrypto Utility Module
The system MUST provide a JavaScript module (`src/crypto/`) implementing the browser-side equivalents of DecryptService and EncryptService using the WebCrypto API. Functions:

- `deriveAesKey(masterPassword, salt)` — PBKDF2-SHA256, 600K iterations, returns CryptoKey
- `encryptPrivateKey(privateKeyPem, aesKey)` — AES-256-GCM envelope, returns base64 string
- `decryptPrivateKey(envelope, aesKey)` — Decrypt AES envelope, return PEM string
- `importPrivateKey(privateKeyPem)` — Import as CryptoKey with `extractable: false`, `decrypt` usage
- `rsaEncrypt(plaintext, publicKey)` — RSA-OAEP-SHA256 with auto-chunking
- `rsaDecrypt(ciphertext, privateKey)` — RSA-OAEP-SHA256 with chunk parsing
- `generateKeyPair()` — Generate RSA-4096 key pair, return { publicKey, privateKey } as PEM strings

All functions MUST use `crypto.subtle` (WebCrypto API). No third-party crypto libraries.

#### Scenario: Generate RSA key pair in browser
- **WHEN** `generateKeyPair()` is called
- **THEN** it MUST generate a 4096-bit RSA key pair using `crypto.subtle.generateKey`
- **AND** export both keys as PEM-encoded strings (SPKI for public, PKCS8 for private)

#### Scenario: Import private key as non-extractable
- **WHEN** `importPrivateKey(pem)` is called
- **THEN** it MUST import the key via `crypto.subtle.importKey` with `extractable: false` and usage `['decrypt']`
- **AND** return a CryptoKey that cannot be exported

#### Scenario: Derive AES key from master password
- **WHEN** `deriveAesKey(password, salt)` is called
- **THEN** it MUST use `crypto.subtle.deriveKey` with PBKDF2, SHA-256, 600,000 iterations
- **AND** return an AES-GCM CryptoKey with 256-bit length

### Requirement: RSA Key Pair Generation for Suite Creation
During EncryptionSuite creation, the RSA-4096 key pair MUST be generated in the browser via WebCrypto. The public key is sent to the server for X.509 certificate signing. The private key is AES-encrypted client-side and the encrypted blob is sent to the server for storage. The plaintext private key MUST NOT leave the browser.

#### Scenario: Suite creation key generation flow
- **WHEN** a new EncryptionSuite is being created
- **THEN** the browser MUST call `generateKeyPair()` to create an RSA-4096 key pair
- **AND** export the public key as PEM (SPKI format) and send it to the server
- **AND** derive an AES key from the master password
- **AND** encrypt the private key PEM with AES-256-GCM
- **AND** send the encrypted private key blob to the server
- **AND** the server MUST sign the public key with the active CA intermediate to produce an X.509 certificate
- **AND** store the certificate and encrypted private key in the EncryptionSuite record
