## ADDED Requirements

### Requirement: Supported platform versions
The iOS app MUST run on iOS 17 and later. The Android app MUST run on Android 9 (API 28) and later. Passkeys MUST be offered on iOS 17+ and Android 14+ only, and the app MUST say so on older Android instead of hiding the setting.

#### Scenario: Install on Android 9
- **GIVEN** a phone on Android 9
- **WHEN** the user installs the app from Google Play or F-Droid
- **THEN** it installs, pairs, unlocks and fills passwords and codes

### Requirement: Published on the App Store, Google Play and F-Droid
The iOS app MUST be published on the App Store. The Android app MUST be published on Google Play and on F-Droid under the same application id. Each store listing MUST carry English and Dutch texts, screenshots and a privacy text, kept in `mobile/fastlane/metadata/`.

#### Scenario: Find the app in F-Droid
- **GIVEN** the F-Droid client with the main repository
- **WHEN** the user searches for Keepiq
- **THEN** the app is listed with its description, screenshots and source link, and installs

#### Scenario: Switch between Play and F-Droid
- **GIVEN** the app installed from Google Play
- **WHEN** the user installs the F-Droid build over it
- **THEN** it updates in place without losing the paired accounts, because both builds carry the same signature

### Requirement: F-Droid build rules
The `fdroid` build MUST be built from source by F-Droid's build server from a release tag. It MUST contain only free software, MUST NOT contain Google Play Services, Firebase, tracking or advertising code or binary blobs, and MUST build reproducibly so F-Droid can publish the developer-signed APK. CI MUST build the `fdroid` flavour and check its dependency tree on every pull request that touches `mobile/`.

#### Scenario: A proprietary dependency is caught
- **GIVEN** a pull request that adds a dependency pulling in `com.google.android.gms`
- **WHEN** CI checks the `fdroid` flavour
- **THEN** the check fails and names the dependency

#### Scenario: Reproducible APK
- **GIVEN** a release tag
- **WHEN** the `fdroid` APK is built twice on clean machines
- **THEN** the two unsigned APKs are byte-identical

### Requirement: Release builds from tags
A `mobile-v<major>.<minor>.<patch>` tag MUST build the signed iOS archive, the Play bundle and the F-Droid source release. Signing keys MUST live in GitHub secrets, never in the repository. The apps MUST carry their own version, separate from the server app, and check the server's `apiVersion` when pairing.

#### Scenario: Tag a release
- **GIVEN** a `mobile-v1.0.0` tag on `main`
- **WHEN** the release workflow runs
- **THEN** a signed Play bundle and a signed iOS archive are produced and uploaded to the stores' test tracks, and the tag is what F-Droid builds from
