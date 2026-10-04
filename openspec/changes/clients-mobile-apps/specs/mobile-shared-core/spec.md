## ADDED Requirements

### Requirement: One shared core for both apps
The iOS and Android apps MUST share one Kotlin Multiplatform module in `mobile/shared/` for the vault crypto, the API client, pairing, sync and the offline store. Screens, system autofill and the passkey providers stay native per platform. The core MUST NOT depend on Google Play Services, Firebase or any closed-source library.

#### Scenario: Both apps link the same core
- **GIVEN** the `mobile/` project
- **WHEN** the Android app and the iOS app are built
- **THEN** both link the `shared` module, and neither carries its own copy of the crypto or API code

#### Scenario: No proprietary dependency
- **GIVEN** the resolved dependency tree of the `shared` module and the Android `fdroid` flavour
- **WHEN** CI lists it
- **THEN** no artifact from `com.google.android.gms`, `com.google.firebase` or another non-free source is present

### Requirement: Crypto byte-compatible with the web app
The core MUST read and write the same formats as `src/crypto/` in the web app: RSA-OAEP 4096 with SHA-256 and 446-byte chunks, the version 1 private-key envelope with PBKDF2-SHA256 at 600,000 iterations and AES-256-GCM, the Send payload and its Argon2id password wrap (65,536 KiB, 3 passes, parallelism 1), RFC 6238 TOTP, and the passkey item JSON with ES256 signatures. An item written by a phone MUST open in the web app and the browser extension, and the reverse.

#### Scenario: The phone reads what the web app wrote
- **GIVEN** the shared test vectors in `tests/vectors/crypto/`, written by the web app's own modules
- **WHEN** the core's tests run on Android and on iOS
- **THEN** every envelope opens with its password, every field ciphertext decrypts to its plaintext, every Send opens, and every TOTP code matches

#### Scenario: The web app reads what the phone wrote
- **GIVEN** ciphertext produced by the core for a field with multibyte text longer than one chunk
- **WHEN** a vitest test decrypts it with `src/crypto/rsa.js`
- **THEN** the plaintext matches byte for byte

#### Scenario: An unknown envelope version is refused
- **GIVEN** a private-key envelope whose version is not 1
- **WHEN** the core tries to open it
- **THEN** it refuses with an explicit error and does not try to decrypt

### Requirement: API contract shared with the browser extension
The core MUST call the keepiq API the way the browser extension does: `{server}/index.php/apps/keepiq{path}`, HTTP Basic with the app password, `OCS-APIRequest: true`, and no cookies. It MUST treat an OCS envelope with `meta.statuscode` of 400 or more as an error, even when the HTTP status is 200. It MUST refuse a plain `http` server address.

#### Scenario: A refusal inside HTTP 200 is an error
- **GIVEN** a server answer with HTTP 200 and `meta.statuscode` 403
- **WHEN** the core reads it
- **THEN** the call fails with that refusal, and nothing is treated as saved

#### Scenario: No cookies
- **GIVEN** a paired account
- **WHEN** the core makes any request
- **THEN** no cookie is sent or stored

### Requirement: Encrypted offline store
The core MUST keep its local copy in an SQLCipher database whose key is random and held under a device-bound Keychain or Keystore key. Item ciphertext MUST stay as the server sent it, and names, URLs and folder names MUST be encrypted again under the unlock key. The store MUST be emptied on unpair, on a suite change, and when the organisation turns offline caching off.

#### Scenario: The database holds no plaintext secret
- **GIVEN** a synced vault and the database file copied off the device
- **WHEN** someone opens it without the device key and the master password
- **THEN** no password, note, name or URL can be read from it

#### Scenario: Offline caching turned off
- **GIVEN** an organisation where offline caching is disabled, so the manifest answers 428
- **WHEN** the app syncs
- **THEN** it deletes its local copy and keeps working online only

### Requirement: Sync without a change feed
The core MUST fetch the vault from `GET /api/v1/offline/manifest` on start, on return to the foreground, after every write and every 15 minutes while open. Between those it MAY use the newest `updatedAt` and `total` from the secrets list as a cheap freshness check. It MUST compare the suite's `unlockKeyEpoch` on every sync.

#### Scenario: A change on the web reaches the phone
- **GIVEN** an item edited in the web app
- **WHEN** the phone app returns to the foreground
- **THEN** the item shows its new value after the sync

#### Scenario: A master-password change elsewhere locks the phone
- **GIVEN** an unlocked phone and a master-password change in the web app
- **WHEN** the next sync sees a higher `unlockKeyEpoch`
- **THEN** the app locks, deletes its biometric and PIN wraps and asks for the new master password
