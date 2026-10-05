# Keepiq mobile apps

Keepiq for Android and Keepiq for iOS, built from one Kotlin Multiplatform core. The design is in `openspec/changes/clients-mobile-apps/` (read `design.md` first). The user guide is `docs/mobile/using.md`, the privacy policy `docs/mobile/privacy.md`.

## Layout

| Path | What it holds |
|---|---|
| `shared/` | The Kotlin Multiplatform core: crypto, pairing, the vault store (SQLCipher), sync, Send, the generator, one-time codes, autofill matching and passkeys. Targets: Android, JVM (tests and the live server test) and iOS (an XCFramework). |
| `android/app/` | The Android app (Jetpack Compose), with the autofill service and the passkey provider. |
| `android/otherapp/` | A second app, used only by the autofill e2e test. It is never released. |
| `ios/` | The iOS app as an XcodeGen spec (`project.yml`): `Keepiq/` (the app), `KeepiqAutofill/` (the AutoFill extension), `Shared/` (code both use), `KeepiqUITests/`. `KeepiqApp/` is a Swift package that checks the framework links. |
| `e2e/` | The end-to-end harness: `server.mjs` (test server front, seeding, recording and replay), `android-run.sh`, `ios-run.sh` and the recorded `fixtures/`. |
| `fastlane/metadata/android/` | The store texts and screenshots, in the layout F-Droid and Google Play read. |
| `gradle/` | The version catalogue (`libs.versions.toml`), dependency verification (`verification-metadata.xml`) and the wrapper. |

## Requirements

- JDK 21. CI uses Temurin 21.
- For Android: the Android SDK, with `ANDROID_HOME` set or `sdk.dir` in `local.properties`. Without it, Gradle builds only `shared` on its JVM target.
- For iOS: macOS with Xcode and `xcodegen` (`brew install xcodegen`). iOS targets are skipped on Linux.

The Gradle wrapper pins Gradle with a checksum. Run every command below from `mobile/`.

## The shared core

```
./gradlew :shared:allTests              # JVM and Android unit tests
./gradlew :shared:jvmTest               # the JVM tests only, the quickest round
./gradlew :shared:iosSimulatorArm64Test # on a Mac
```

### Crypto vectors

The vectors in `tests/vectors/` are the contract between the core and the web app. The `shared` build compiles them into its tests, so `:shared:allTests` checks that the core opens what the web app wrote. The core writes its own output to `shared/build/vectors/kotlin-output.json`. Then check that the web app opens it, from the repository root:

```
npm ci --ignore-scripts
KEEPIQ_KOTLIN_VECTORS=mobile/shared/build/vectors/kotlin-output.json \
  npx vitest run tests/vitest/crypto-vectors.spec.js tests/vitest/crypto-vectors-kotlin.spec.js \
    tests/vitest/generator-vectors.spec.js tests/vitest/autofill-vectors.spec.js
```

`.github/workflows/mobile.yml` runs both directions on every change to either side.

## Android

```
./gradlew :android:app:assembleFdroidDebug         # a debug APK
./gradlew :android:app:testFdroidDebugUnitTest     # screen tests on the JVM (Robolectric)
./gradlew :android:app:assembleFdroidRelease       # the R8-shrunk release APK
```

The release build is signed only when these four variables are set: `KEEPIQ_SIGNING_STORE_FILE`, `KEEPIQ_SIGNING_STORE_PASSWORD`, `KEEPIQ_SIGNING_KEY_ALIAS` and `KEEPIQ_SIGNING_KEY_PASSWORD`. Without them it is unsigned. No key lives in the repository. Add `-Pkeepiq.abiSplits=true` for one APK per processor type next to the universal one.

### Flavours

There are two flavours, `fdroid` and `play`. They differ only in the update check, and today neither has one: F-Droid and Google Play each update the app themselves. An in-app update prompt for Play would need Play Core, which is not free software. Design D8 allows no proprietary library in any flavour, so it stays out of both. If Play ever gets an update prompt, it must be built without one.

The preview releases on GitHub (`.github/workflows/mobile-preview.yml`, tags `mobile-v<x.y.z>-preview.<n>`) and the e2e smoke check use the `fdroid` flavour.

### F-Droid rules

The build keeps to what F-Droid needs to build and publish the app:

- Every dependency is free software from Maven Central or Google's Maven repository. Every version is pinned in `gradle/libs.versions.toml`, and `gradle/verification-metadata.xml` holds the checksum of every artifact. There are no binary jars in the tree.
- No Google Play Services, Firebase, Play Core or other non-free artifact, in either flavour. `.github/workflows/mobile-fdroid.yml` checks the release runtime classpath of both and names any such artifact it finds.
- The build is reproducible. The same workflow builds the unsigned `fdroid` release APK twice, from two clean checkouts at different paths, and compares them byte for byte. On a mismatch it uploads a diffoscope report. To make that hold, the build tools are pinned, the APK carries no dependency metadata blob (`dependenciesInfo`) and no git commit id (`vcsInfo.include = false`), and nothing in the build writes a timestamp.
- Store texts and screenshots live in `fastlane/metadata/android/<locale>/`, in English (`en-US`) and Dutch (`nl-NL`).

A new dependency therefore needs three things: a free licence, a pinned version in the catalogue, and its checksums in the verification metadata. Regenerate the metadata with:

```
./gradlew --write-verification-metadata sha256 -Pkeepiq.android=true resolveAllDependencies
```

Then add the macOS Kotlin/Native toolchain by hand, as the comment in `gradle/verification-metadata.xml` explains.

## iOS

On a Mac:

```
./gradlew :shared:assembleKeepiqSharedDebugXCFramework
cd ios
xcodegen generate
xcodebuild build -project Keepiq.xcodeproj -scheme Keepiq \
  -destination "generic/platform=iOS Simulator" CODE_SIGNING_ALLOWED=NO
```

No `.xcodeproj` is kept in the tree; `xcodegen` makes it from `project.yml`.

## End-to-end tests

`.github/workflows/mobile-e2e.yml` runs them on every pull request that touches `mobile/`, with screenshots and videos as artifacts.

- **Android** runs on an emulator (API 34 and API 28) against a real Nextcloud with Keepiq, from `browser-extension/capture/compose.yaml`. `e2e/server.mjs proxy` puts an https front on it, and the `e2e` build type trusts that run's certificate. `e2e/android-run.sh` installs the app and its tests and runs them. On API 34 it also starts the R8 release build and checks that it stays up.
- **iOS** runs on a simulator. The macOS runners have no Docker, so `e2e/server.mjs replay` answers from the recordings in `e2e/fixtures/server.json`. `e2e/ios-run.sh` runs the UI tests.

To refresh the iOS recordings, start the test server of `browser-extension/capture/compose.yaml`, then seed and record against it from the repository root:

```
node mobile/e2e/server.mjs seed   --upstream http://localhost:8188
node mobile/e2e/server.mjs record --upstream http://localhost:8188 --container <compose project>-nc-1
```

The header of `e2e/server.mjs` lists every option.

## Continuous integration

| Workflow | What it checks |
|---|---|
| `mobile.yml` | Shared core tests, the Android debug build and screen tests, the crypto vectors in both directions, the iOS framework and app compile. |
| `mobile-e2e.yml` | The end-to-end tests on Android and iOS. |
| `mobile-fdroid.yml` | No non-free artifact, and two identical builds of the unsigned `fdroid` release APK. |
| `mobile-preview.yml` | The signed preview APKs, and the GitHub pre-release on a `mobile-v*-preview.*` tag. |
