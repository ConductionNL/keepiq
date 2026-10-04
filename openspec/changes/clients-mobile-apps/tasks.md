# Tasks: native iOS and Android apps

Each task names its check. iOS tasks need a macOS runner and an Apple developer account (DECISIONS row 20). Without them they stay open and are reported, and the Android tasks still ship.

## 1. Shared core and crypto contract

- [ ] 1.1 Scaffold `mobile/` as a Gradle Kotlin Multiplatform project with `shared`, `android/app` and an Xcode project in `ios/` that links the `shared` framework. Verify: `./gradlew :shared:allTests` and an iOS simulator build run in CI.
- [x] 1.2 Write the crypto test vectors from the web app's own modules into `tests/vectors/crypto/*.json`, with a script under `tests/vectors/`: envelope and password, field ciphertexts (empty, one chunk, several chunks, multibyte), Send with and without password, TOTP (SHA1, SHA256, SHA512; 6 and 8 digits), passkey assertion. Verify: a vitest test re-reads the vectors with `src/crypto/`. Done: `node tests/vectors/generate-crypto-vectors.mjs`; `tests/vitest/crypto-vectors.spec.js` passes 55 tests, and a control with the Argon2 stub fails the password Send.
- [ ] 1.3 Implement RSA-OAEP chunking, the version 1 envelope with PBKDF2, AES-GCM, Argon2id, TOTP and ES256 in `shared`, with `expect`/`actual` platform primitives. Verify: the vectors pass in `commonTest` on Android and iOS.
- [ ] 1.3.1 Argon2id on iOS: link the reference Argon2 C code (CC0) through cinterop, so a password Send can be created and opened on iOS. Until then the iOS actual throws "not available on this target" and the vector test asserts that refusal. Verify: the Send-with-password vector passes in `iosSimulatorArm64Test`.
- [x] 1.4 Add the reverse direction: the core writes ciphertext for vector inputs, and a vitest test decrypts it with `src/crypto/rsa.js` and `src/send/sendCrypto.js`. Verify: the vitest test in CI. Done: `KotlinVectorsWriterTest` writes `kotlin-output.json`; `tests/vitest/crypto-vectors-kotlin.spec.js` passes 5 tests on a fresh copy, and `mobile.yml` reruns it on the copy written in the same CI run.
- [x] 1.5 Implement the API client (Ktor) with the extension's contract: Basic auth, `OCS-APIRequest`, no cookies, OCS status 400 or more is an error, https only. Verify: unit tests against recorded responses, including a 200 envelope with statuscode 403. Done: `KeepiqApiTest` (10 tests, MockEngine with recorded controller answers) on jvm, including HTTP 200 with statuscode 403, Set-Cookie never sent back, no redirects, http refused; a control that lifts the OCS check fails it.
- [ ] 1.6 Implement the SQLCipher store with a device-bound key, re-encrypted names, URLs and folders, and eviction on unpair, suite change and a 428 manifest. Verify: unit tests, plus a test that opens the database file without the key and finds no plaintext. Partly built: the store logic, sealed names, URLs and folder names, secure delete and eviction pass `VaultStoreTest` (5 tests) on jvm over plain SQLite. The SQLCipher driver and its Keystore-bound key (`openEncryptedDriver`) only compile in CI; the on-device test is open.
- [ ] 1.6.1 SQLCipher store on iOS: link SQLCipher for the SQLDelight native driver and keep its key under a non-biometric Keychain item. Until then iOS has no offline store and works online only. Verify: the store tests on the iOS simulator.
- [x] 1.7 Implement sync from the manifest, the freshness check and the `unlockKeyEpoch` comparison. Verify: unit tests for each trigger and for an epoch change. Done: `VaultSyncTest` (11 tests) on jvm covers start, foreground, timer, after-write, manual, an epoch change, a new suite, the two-factor block and offline caching off; a control without the epoch check fails 2 of them.

## 2. Pairing and unlock

- [ ] 2.1 Login Flow v2 pairing with the system browser, the 20-minute poll limit, and manual app-password entry as fallback. Verify: an end-to-end test on the Android emulator against a test server.
- [ ] 2.2 Master-password unlock with `unlockBlocked` handling. Verify: emulator test for a wrong password and for the two-factor block.
- [ ] 2.3 Biometric unlock (Keychain with `.biometryCurrentSet`, AndroidKeyStore with invalidation on enrolment) and PIN unlock with Argon2id and 5 tries. Verify: instrumented tests on the emulator with a fake biometric; manual check on one iPhone and one Android phone.
- [ ] 2.4 Check what `extension#pair` records about the client. If the device list would call a phone a browser extension, open a backend change that adds a client kind. Verify: the device list on the test server after pairing a phone.
- [ ] 2.5 Auto-lock with the idle choices, the organisation cap and device lock, shared with the iOS extension. Verify: unit tests for the timer and cap; manual device-lock check.
- [ ] 2.6 Unpair: unpair call, app-password revoke and local wipe. Verify: emulator test that the old app password gets 401 afterwards.

## 3. Vault, Send and generator screens

- [ ] 3.1 Compose and SwiftUI screens for accounts, the vault list, folders, search and item detail with reveal, copy and TOTP. Verify: UI tests on the emulator and simulator; accessibility check with TalkBack and VoiceOver on the item detail.
- [ ] 3.2 Create, edit, move and trash for items and folders, with type fields from `/api/v1/secret-types`. Verify: an emulator test that creates a login on the phone and reads it back through the web app's API in the same run.
- [ ] 3.3 Sensitive clipboard with timeout. Verify: unit test of the timeout; manual check that the preview is hidden.
- [ ] 3.4 Offline reading with the last-synced note and refused edits. Verify: emulator test in airplane mode.
- [ ] 3.5 Generator with the organisation policy. Verify: unit tests sharing the web generator's policy cases.
- [ ] 3.6 Send create, list and delete, the share sheet, and opening a Send link in the app. Verify: an emulator test that creates a Send and opens it in the web page.

## 4. System autofill

- [ ] 4.1 Android `AutofillService`: field detection, package and certificate matching, web domains, inline suggestions, the locked entry. Verify: instrumented tests with a test app and a WebView page; manual check in Chrome and Firefox.
- [ ] 4.2 Android save requests with the never-save list. Verify: instrumented test of a sign-up form.
- [ ] 4.3 One-time codes on Android. Verify: instrumented test with a two-step form.
- [ ] 4.4 iOS AutoFill credential provider extension: credential list, fill without interaction when unlocked, unlock screen, identity store upkeep. Verify: UI test on the simulator with a test app and an associated domain.
- [ ] 4.5 One-time codes on iOS: the clipboard path on iOS 17, and the iOS 18 code credential if adopted. Verify: simulator test.
- [ ] 4.6 Rebuild and clear the autofill index on sync, unpair and suite change. Verify: unit tests, plus an emulator test that a deleted item stops being offered.

## 5. Passkeys

- [ ] 5.1 Android 14+ `CredentialProviderService` for passkeys and passwords, without `credentials-play-services-auth`. Verify: instrumented test on an API 34 emulator against a WebAuthn test page; dependency check.
- [ ] 5.2 iOS 17+ passkey registration and assertion in the AutoFill extension. Verify: simulator test against a WebAuthn test page.
- [ ] 5.3 Cross-client check: a passkey created with the browser extension signs in on the phone, and one created on the phone signs in with the extension. Verify: a manual run recorded in the PR, plus the passkey vector in 1.2.

## 6. Release

- [ ] 6.1 `play` and `fdroid` flavours that differ only in the update check; CI builds the `fdroid` flavour and fails on any `com.google.android.gms`, `com.google.firebase` or other non-free artifact. Verify: CI on a PR, plus a control PR that adds a GMS dependency and fails.
- [ ] 6.2 Reproducible Android build: pinned toolchain, Gradle dependency verification, no timestamps. Verify: two clean CI builds give byte-identical unsigned APKs.
- [ ] 6.3 Store metadata in `mobile/fastlane/metadata/` in English and Dutch, with screenshots and the privacy text. Load the `writing` skill first. Verify: `fastlane` metadata check; review by the product owner.
- [ ] 6.4 Release workflow on `mobile-v*` tags: signed Play bundle to the internal track, signed iOS archive to TestFlight. Verify: one dry-run tag on a fork or a test track.
- [ ] 6.5 Merge request to `fdroiddata` with `metadata/nl.conduction.keepiq.yml`. Show the product owner the exact text before posting. Verify: F-Droid's `fdroid lint` and `fdroid build` in their CI.
- [ ] 6.6 Register the app ids and the store listings under the Conduction developer accounts. This needs the product owner. Verify: the listings exist on the test tracks.

## 7. Documentation and close out

- [ ] 7.1 User guide `docs/mobile/using.md`: install, pair, turn on autofill and passkeys per platform, supported browsers, offline behaviour. Load the `writing` skill first. Verify: docs build.
- [ ] 7.2 `mobile/README.md` for developers: layout, building both apps, running the vectors, the F-Droid rules. Verify: a clean checkout builds by following it.
- [ ] 7.3 Update `FEATURES.md` and `privacy.md` with the mobile apps. Verify: docs build.
- [x] 7.4 Set parity row `clients-10` to `specified` and the gap decision to `build`, recording the reversal. Verify: `parity_verify --strict`. Done in the spec PR (2026-10-04).
- [ ] 7.5 Set `clients-10` to `built` when every task is done, or `building` naming the open tasks, and archive the change. Verify: `parity_verify --strict`.
