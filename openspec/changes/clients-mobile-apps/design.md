# Design: native iOS and Android apps

## Context

Keepiq is zero-knowledge. The server stores ciphertext; the client derives the keys and decrypts. Two clients exist today, the web app (`src/`) and the browser extension (`browser-extension/`). The extension does not reimplement the crypto. It re-exports the web app's modules (`browser-extension/src/crypto/index.js:1-56`), so the two can never disagree.

A native app cannot import JavaScript. It reimplements the crypto in Kotlin, and that is the largest risk in this change: an item written by the phone that the web app cannot read is data loss. The design therefore starts from the wire formats, and treats test vectors as the contract.

## Goals and non-goals

**Goals (v1):**

- Fill passwords, one-time codes and passkeys in other apps and in mobile browsers, through the operating system.
- View, search, copy, create and edit items and folders, also offline.
- Create and open a Send. Generate passwords and passphrases.
- One codebase for the logic, native screens per platform.
- Ship on the App Store, Google Play and F-Droid.

**Non-goals (v1):**

- Admin screens, organisation policy editing, audit views, health reports, import and export. The web app keeps those.
- Push notifications. There is no FCM on F-Droid, and the same app must behave the same on both Android stores.
- Wear OS, watchOS, tablets beyond a scaled phone layout, and desktop builds of the KMP core.
- New server endpoints. Gaps found while building become their own backend change.

## Decisions

### D1. Kotlin Multiplatform core, native screens

Chosen by the product owner on 2026-10-04.

```
mobile/
  shared/            KMP module: crypto, API, pairing, sync, store, policy
    src/commonMain/
    src/androidMain/ platform crypto, Keystore, SQLCipher driver
    src/iosMain/     platform crypto, Keychain, SQLCipher driver
    src/commonTest/  test vectors, shared with the web app
  android/
    app/             Compose app, AutofillService, CredentialProviderService
  ios/
    Keepiq/          SwiftUI app
    KeepiqAutoFill/  AutoFill credential provider extension
  fastlane/          store metadata, also read by F-Droid
```

The iOS app and its AutoFill extension both link the `shared` framework. The extension runs in its own process with a tight memory limit (about 120 MB), so the core must open the store and decrypt one item without loading the whole vault.

**Libraries, all free software so F-Droid accepts them:** Ktor (HTTP), kotlinx.serialization, SQLDelight with SQLCipher (store), kotlinx.coroutines, Bouncy Castle on Android for Argon2id, and the reference Argon2 C code (CC0) through cinterop on iOS. No Firebase, no Google Play Services, no analytics or crash-reporting SDK.

**Why not a cross-platform UI** (Compose Multiplatform, Flutter): system autofill, the iOS extension and the passkey providers are native APIs anyway. Native screens keep the extension small and follow each platform's accessibility behaviour without a bridge.

### D2. Crypto is byte-compatible with the web app

The core implements exactly these formats. Each line cites the web app's source of truth.

| What | Format | Source |
|---|---|---|
| User key pair | RSA-OAEP 4096, e=65537, SHA-256 for hash and MGF1. Public key SPKI PEM, or an X.509 certificate from which the SPKI is taken. Private key PKCS#8 PEM. | `src/crypto/rsa.js:8,18-24,51-67,169-190` |
| Field ciphertext | UTF-8 bytes split into 446-byte chunks, each OAEP-encrypted to 512 bytes. Stored as base64(`uint32 BE count` + blocks). Empty text is one chunk. Decrypt joins the bytes, then decodes UTF-8 once. | `src/crypto/rsa.js:9-10,200-268` |
| Private-key envelope | base64(`uint32 BE version=1` + 16-byte salt + 12-byte IV + AES-256-GCM ciphertext and 16-byte tag), no AAD. Other versions are refused. | `src/crypto/envelope.js:4-12,23-37,55` |
| Unlock key | PBKDF2-HMAC-SHA256, 600,000 iterations, UTF-8 master password, the envelope's salt, 32 bytes. | `src/crypto/aes.js:15,24-46,104-119` |
| Send payload | Fresh AES-256-GCM key, base64(12-byte IV + ciphertext and tag). Link `…/apps/keepiq/public/send/{token}#k=<base64url key>`. | `src/send/sendCrypto.js:14,73-135` |
| Send password | Argon2id, 65,536 KiB, 3 passes, parallelism 1, 32 bytes, 16-byte salt, UTF-8 password. The derived key wraps the raw key; the body carries `wrappedKey` and `argon2idSalt`. | `src/crypto/argon2.js:18-34,84-128`, `src/store/modules/ephemeralSend.js:56-70` |
| TOTP | RFC 6238. `otpauth://totp/` URI or bare base32. SHA1, SHA256 or SHA512; 6 or 8 digits; default 30 seconds. HOTP refused. | `src/totp/totp.js:7-26,84-110,202-232` |
| Passkey | Item of type `passkey`. JSON in the encrypted `key` field: `credentialId`, `rpId`, `rpName`, `userName`, `userDisplayName`, `userHandle` (base64url), `privateKey` (PKCS#8 PEM), `algorithm` (-7), `counter`, `transports`, `createdAt`. ES256 only, zero AAGUID, `none` attestation, DER signature. | `src/passkey/passkey.js:7-65`, `browser-extension/src/passkey/webauthn.js:7-210` |

Encrypted item fields are `key`, `login` and `additionalFields`, each encrypted directly to the suite's public key. There is no per-item key. `name` and `url` are plaintext on the server (`lib/Db/Secret.php:121-167`). `additionalFields` is one JSON object (`docs/additional-fields.md`).

**Platform primitives.** Each platform supplies RSA-OAEP, AES-GCM, PBKDF2, HMAC, ECDSA P-256 and a secure random source through `expect`/`actual`:

- Android: `javax.crypto` and `java.security` (all present from API 26).
- iOS: Security framework `SecKey` for RSA-OAEP-SHA256 and P-256, CryptoKit for AES-GCM and HMAC, CommonCrypto for PBKDF2.

Only Argon2id has no platform primitive, so it is a library on both sides.

**Test vectors are the contract.** A script in the web app writes vectors with the real web modules into `tests/vectors/crypto/*.json`: an envelope with its password, field ciphertexts (empty, one chunk, several chunks, multibyte text), a Send with and without a password, TOTP codes and a passkey assertion. `mobile/shared/src/commonTest` reads the same files on both platforms. CI also runs the other direction: the core writes ciphertext, and a vitest test decrypts it with the web modules. A format change that is not mirrored fails both suites.

### D3. Pairing with Login Flow v2

The extension asks for a manually created app password (`browser-extension/src/background/router.js:712-726`). On a phone that means switching apps and copying a long string, so the apps use Nextcloud Login Flow v2:

1. The user types the server address. Plain `http` is refused, as in `extension-pairing`.
2. `POST {server}/index.php/login/v2` returns a login URL and a poll token.
3. The app opens the login URL in the system browser (`ASWebAuthenticationSession` on iOS, a Custom Tab on Android). The user signs in with the server's own login, including SSO and two-factor.
4. The app polls `POST {server}/index.php/login/v2/poll` until it receives `server`, `loginName` and `appPassword`. The poll stops after 20 minutes, or when the user cancels.
5. The app calls `POST /api/v1/extension/pair` with that app password, like the extension does, and stores the account.

Manual entry of an app password stays available as a fallback, for servers where the login page will not open in a browser tab.

Requests follow the extension's contract (`browser-extension/src/lib/api.js:216,270-311`): `{server}/index.php/apps/keepiq{path}`, `Authorization: Basic`, `OCS-APIRequest: true`, no cookies. An OCS envelope with `meta.statuscode >= 400` is an error even under HTTP 200. Unpairing calls `/api/v1/extension/unpair` and `DELETE /ocs/v2.php/core/apppassword`.

Up to 5 accounts per device, the same limit as the extension.

**Open point for the build:** `extension#pair` may record the client as a browser extension in the device list. If so, a small backend change adds a client kind (`ios`, `android`), so the user sees "Keepiq for Android" there. Task 2.4 checks it.

### D4. Unlock and key custody

- **First unlock:** the master password derives the unlock key (D2), which opens the private-key envelope. The app holds the decrypted private key in memory only, as the extension does (`browser-extension/src/lib/vault.js:16-32`).
- **Biometric unlock:** after a master-password unlock, the user can turn on Face ID, Touch ID or a fingerprint. The 32-byte unlock key is wrapped with a key that never leaves the secure hardware:
  - iOS: a Keychain item with `kSecAttrAccessibleWhenUnlockedThisDeviceOnly` and `.biometryCurrentSet`, so adding a new fingerprint or face invalidates it.
  - Android: an AndroidKeyStore AES key with `setUserAuthenticationRequired(true)` and `setInvalidatedByBiometricEnrollment(true)`, StrongBox when the device has it. Opened through `BiometricPrompt` with a `CryptoObject`.
- **PIN unlock:** for devices without biometrics, or by choice. The unlock key is wrapped with Argon2id(PIN), the same as `browser-extension/src/lib/pin-unlock.js:20-127`: 6 to 64 characters, wiped after 5 wrong tries. Unlike the extension, the wrapped key survives an app restart. It is still bound to the device, because the wrapping blob is itself stored under a non-biometric Keychain or Keystore key.
- **The AutoFill extension and the app share the unlock.** On iOS both read the same Keychain access group, so one biometric prompt in the fill sheet is enough. On Android the services run in the app process.
- **Locking:** an idle timer with the extension's choices (1, 5, 15, 30, 60, 240 minutes; default 15), capped by the organisation's `maxIdleMinutes` from `/api/v1/extension/policy`. The app also locks when the device locks, and when the suite's `unlockKeyEpoch` changes (a master-password change elsewhere), as `browser-extension/src/background/vault-sync.js:143-171` does. On an epoch change, the biometric and PIN wraps are deleted, because they wrap an unlock key that no longer opens the envelope.
- **Two-factor block:** when `/api/v1/suites` or the manifest reports `unlockBlocked`, the app explains why and does not offer unlock.

### D5. Sync and the offline store

There is no changes-since endpoint. The apps follow the extension (`browser-extension/src/lib/api.js:518-547`, `vault-sync.js:84-133`):

- The app fetches `GET /api/v1/offline/manifest` when it opens, when it returns to the foreground, after every write, and every 15 minutes while it is open.
- Between full syncs, a cheap check (`GET /secrets?sort=updated_at&direction=desc&limit=1`) compares the newest `updatedAt` and `total`.
- On Android, a WorkManager job refreshes in the background about every 6 hours on an unmetered network, so autofill has fresh data. iOS uses `BGAppRefreshTask` for the same job, at the system's discretion.
- When offline caching is disabled for the organisation (the manifest answers 428), the app keeps nothing on disk. It works online only, and autofill offers only what the current unlocked session has loaded.

**The store** is SQLite through SQLDelight, encrypted with SQLCipher. Its key is random, and is kept under a non-biometric Keychain or Keystore key, so the store is unreadable off the device. On top of that, the item ciphertext stays as the server sent it (RSA chunks). A stolen and unlocked phone database still holds no plaintext secrets. Names and URLs are plaintext on the server, but are encrypted again in the store, as `offline-readonly-cache` asks.

**The autofill index** needs names and URLs without the master password, because the system asks for suggestions before the user unlocks:

- iOS: `ASCredentialIdentityStore` holds service identifiers (domains and app bundle ids), user names and passkey identities. The system keeps that index; secret values never go into it.
- Android: the AutofillService reads the domain index from the store and shows a locked "Keepiq" entry, without account names, until the user authenticates.

The index is rebuilt after each sync, and cleared on unpair and on a suite change.

**Edits:** the online path writes through `PUT /secrets/{id}` and `POST /secrets`. Offline edits are not in v1; an edit made while offline is refused with a clear message. The web app's `offline-edit-queue` can be adopted later.

### D6. System autofill

**iOS (17+):** a credential provider extension (`ASCredentialProviderViewController`).

- Passwords: `prepareCredentialList(for:)` shows matches for the domain or app. `provideCredentialWithoutUserInteraction(for:)` fills directly when the vault is unlocked within the timeout; otherwise the system shows the extension's unlock screen.
- One-time codes: iOS 18 lets a provider supply codes (`ASOneTimeCodeCredential`). On iOS 17 the app copies the code to the clipboard after a password fill, with a 60-second expiry, as the browser extension does.
- Saving new logins: iOS has no API for a third-party provider to capture a login typed into another app. The app offers "Add login" in the extension's list and in the app instead. On iOS 18, a passkey created through Keepiq is saved directly (D7).

**Android (9+):** an `AutofillService`.

- It reads the `AssistStructure`, finds user name, password and one-time-code fields by autofill hints, HTML attributes and view ids, and matches by app package or web domain.
- An app is matched by its package name and signing certificate, through Digital Asset Links when the app declares a website. A package with a different signing certificate never gets a website's login.
- Package visibility: the app keeps `QUERY_ALL_PACKAGES`. Measured on 2026-10-05 in CI (`PackageVisibilityTest`, mobile-e2e run 37290748291):
  - Keepiq reads the asking app's package and signing certificate with `PackageManager.getPackageInfo(..., GET_SIGNING_CERTIFICATES)` (`AppIdentities`), and its Digital Asset Links statement with `getApplicationInfo`.
  - From Android 11 the package manager hides other apps. The automatic-visibility list (developer.android.com/training/package-visibility/automatic) has no autofill case. The system, not the asking app, binds the autofill service. The AOSP autofill service (Android 14) calls no `grantImplicitAccess`.
  - Without the permission, on API 34, the other app was hidden before the fill (the control), the lookup during the fill was `BLOCKED` in the `AppsFilter` log, and Keepiq offered nothing. On API 28 there is no filtering, and the fill worked.
  - `SystemAutofillTest` could not show this. Its forms live in the test APK, and Android makes an instrumentation APK and its target visible to each other. `PackageVisibilityTest` fills a separate APK (`:android:otherapp`) instead.
  - Google Play restricts the permission: the Play listing needs a permission declaration that explains autofill. F-Droid accepts it.
  - The passkey provider (D7) should not need it: Credential Manager hands the provider the caller's package and signing info (`CallingAppInfo`), so it reads no other package. Its own e2e test confirms this once it lands.
- Inline suggestions in the keyboard on Android 11+; the dropdown otherwise.
- Locked: one "Unlock Keepiq" entry that opens the authentication activity, then returns the dataset.
- Saving: `onSaveRequest` offers to save a new or changed login, with the same never-save list as the extension.
- Browsers: Chrome, Firefox, Brave, Edge and Samsung Internet expose the web domain in compatibility or native mode. The supported list is in the user guide.
- Android 14+: passwords are also offered through the Credential Manager provider (D7), so apps that use Credential Manager see Keepiq in their own sheet.

### D7. Passkey provider

**iOS 17+:** the same extension implements `prepareInterfaceToProvideCredential(for: ASPasskeyCredentialRequest)` and `prepareInterface(forPasskeyRegistration:)`. Passkey identities go into `ASCredentialIdentityStore` with their rpId, user name, credentialId and userHandle.

**Android 14+:** a `CredentialProviderService` from `androidx.credentials` (Apache 2.0, no Play Services). It handles `BeginGetCredentialRequest` and `BeginCreateCredentialRequest` for public-key credentials and passwords.

- The F-Droid flavour uses the same `androidx.credentials` module.
- It must not pull in `credentials-play-services-auth`. That artifact is only needed by apps that call Credential Manager on Android 13 and lower, and a provider does not need it.

**Both platforms:**

- Creation follows the extension exactly: ES256, P-256, zero AAGUID, `none` attestation, 16-byte credentialId, flags UP, UV and AT.
- The new passkey is saved with `POST /secrets` as a `passkey` item.
- An assertion signs with the stored key. A non-zero counter goes up by one and is written back with `PUT`, as `browser-extension/src/passkey/orchestrator.js:133-143` does.
- The rpId comes from the operating system's verified origin: the domain the system or browser attests for a website, or the app's associated domain. The app never takes an rpId from page content.
- A request that cannot be met (another algorithm, the vault locked and the user cancels) returns the platform's "no credential" result, so the system can try another provider.

### D8. Distribution

| Store | Build | Identity |
|---|---|---|
| App Store | Xcode archive on a macOS runner, signed with the Conduction team, uploaded with fastlane | bundle id `nl.conduction.keepiq` (to confirm) |
| Google Play | AAB, `play` flavour, upload key in GitHub secrets, Play App Signing | application id `nl.conduction.keepiq` |
| F-Droid | APK, `fdroid` flavour, built by F-Droid's server from a tag | same application id |

**F-Droid rules the build honours:**

- Every dependency is free software and comes from Maven Central or Google's Maven repository. There are no binary jars in the tree.
- No proprietary SDK in any flavour. The `fdroid` flavour differs from `play` only in the update check: Play offers in-app updates, F-Droid relies on its own client. Keeping the flavours this close makes "works on Play, broken on F-Droid" unlikely.
- Reproducible builds:
  - pinned Gradle, JDK and dependency versions, with dependency verification metadata;
  - no build timestamps or random ids in the APK;
  - `SOURCE_DATE_EPOCH` respected.
- With reproducible builds, F-Droid can publish the APK signed with Conduction's key. Users can then switch between the Play and F-Droid builds without reinstalling.
- Store texts and screenshots live in `mobile/fastlane/metadata/android/<locale>/`, the layout F-Droid reads. English and Dutch.
- The listing needs no anti-feature flags. The app talks only to the user's own Nextcloud, and the breach check goes through the server's proxy (`/api/v1/breach-check/range`). It sends no tracking.
- A merge request to `fdroiddata` adds `metadata/nl.conduction.keepiq.yml`, with `AutoUpdateMode` and `UpdateCheckMode: Tags`.

**Versioning:** the apps carry their own version (`mobile-v1.2.3` tags), separate from the server app. They check `apiVersion` from the pair response and refuse an unsupported server with a clear message.

## Risks and mitigations

- **Crypto drift between clients.** Shared vectors in both directions (D2). Item types are data (`GET /api/v1/secret-types`), so the app renders their fields from that list. It writes only the encrypted field shapes the vectors cover: `key`, `login`, `additionalFields` as one JSON object, TOTP and passkey JSON.
- **RSA-4096 on a phone.** One OAEP decrypt costs about 5 to 20 ms on a mid-range phone, and a login item has up to three fields. The list screen decrypts nothing: it shows plaintext names and URLs, and decrypts on open. The AutoFill extension decrypts only the chosen item.
- **iOS extension memory.** It queries the store by domain and never loads the whole vault.
- **No macOS runner.** iOS tasks stay open and are reported (DECISIONS row 20). Android ships on its own.
- **Store review.** Apple and Google ask for a demo account on a live server, and a privacy text. Both are release tasks.
- **Passkeys created on a phone are not synced back by the platform.** They are Keepiq items like any other, synced through Keepiq. The user guide says so.
- **Background refresh is not guaranteed on iOS.** Autofill shows what the last sync stored. The app syncs on every unlock, and the fill sheet offers "Refresh" when an item is missing.

## Open questions for the build (not blocking the spec)

- The final bundle and application ids, and the developer accounts they are registered under.
- Whether the pair endpoint needs a client kind (D3).
- Whether the iOS 18 one-time-code API is adopted in v1 or the clipboard path is enough.
