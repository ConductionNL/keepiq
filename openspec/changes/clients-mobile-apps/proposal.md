---
kind: code
---

# Native iOS and Android apps

## Why

People keep their passwords in Keepiq, and then pick up their phone. Today the phone can only open the vault as an installed web app (`mobile-pwa`). That web app cannot register with the operating system as a password provider. So a user who signs in to an app or a website on the phone has to open Keepiq, copy the password and switch back. Passkeys stored in Keepiq cannot be used on a phone at all.

The decision on record said no native apps. `openspec/specs/mobile-pwa/spec.md` chose the web app as "the pragmatic v1 alternative", and parity row `clients-10` is `decided-no` on that record (gap decision of 2026-09-27). That was right for v1. It no longer is: the browser extension (`clients-extension-*`, archived 2026-10-04) now covers the desktop, and the phone is the one place a user cannot reach a stored password where they need it. Four competitors rate yes.

On 2026-10-04 the product owner reversed the decision. They asked for an iOS and an Android app, with the Android app on F-Droid as well as Google Play. This change specifies both.

### Matrix rows (`keepiq` `openspec/parity/capabilities.json`)

| row | capability | Today |
|---|---|---|
| `clients-10` | Use native mobile apps with system autofill on iOS and Android. | `decided-no`: the PWA cannot register as a system autofill provider, and no native app exists |

### Demand

- `clients-10`: the product owner's request of 2026-10-04 ("zodat mensen hun opgeslagen wachtwoord ook mobiel kunnen gebruiken").

### Competitors rated yes

- `clients-10`, bitwarden: native apps with system autofill per https://bitwarden.com/download/
- `clients-10`, onepassword: https://support.1password.com/android-autofill/ and https://support.1password.com/ios-autofill/
- `clients-10`, passbolt: native apps with autofill, https://www.passbolt.com/docs/user/basic-features/mobile/autofill-chrome-android
- `clients-10`, keeper: https://docs.keeper.io/user-guides/autofill-setup-for-ios and the Android page

## Decisions taken with the product owner (2026-10-04)

- **Technology:** a shared Kotlin Multiplatform core for crypto, the API client, sync and the offline store. The screens are native: SwiftUI on iOS, Jetpack Compose on Android.
- **Repository:** the apps live in the keepiq repository, in `mobile/`, next to `browser-extension/` and `cli/`.
- **Scope of v1:** fill passwords in apps and browsers through the system, view and edit the vault, passkeys, Send and the generator.
- **Minimum versions:** iOS 17 and Android 9 (API 28). Passkeys on Android need Android 14 (API 34); on older Android the app fills passwords and codes only.
- **Distribution:** the App Store, Google Play and F-Droid.

## What Changes

- **A shared core** in `mobile/shared/`: the vault crypto byte-compatible with the web app and the extension, the API client, pairing, sync and an encrypted offline store.
- **Pairing and unlock:** connect a phone to a Nextcloud account with Nextcloud Login Flow v2, unlock with the master password, then with Face ID, Touch ID, a fingerprint or a PIN.
- **System autofill:** on iOS an AutoFill credential provider extension; on Android an Autofill service. Both fill passwords and one-time codes, and offer to save new logins.
- **Passkeys:** the apps act as a passkey provider (iOS 17 and Android 14), using the passkeys already stored by the web app and the extension.
- **Vault:** browse, search, view, copy, create and edit items and folders, readable offline.
- **Send and the generator:** create and open a Send, generate passwords and passphrases under the organisation policy.
- **Release:** builds for the App Store, Google Play and F-Droid. The F-Droid build has no proprietary dependency, builds from source and is reproducible.
- **Records:** `mobile-pwa` stops ruling out native apps, and row `clients-10` moves from `decided-no` to `specified`.

## Capabilities

### New Capabilities

- `mobile-shared-core`
- `mobile-pairing-and-unlock`
- `mobile-system-autofill`
- `mobile-passkey-provider`
- `mobile-vault`
- `mobile-send-and-generator`
- `mobile-release`

### Modified Capabilities

- `mobile-pwa`: the requirement "Offline and Native Boundaries" no longer forbids a native app or a store listing. Those now belong to the `mobile-*` capabilities.

## Impact

- **New code:** `mobile/` (Gradle KMP project, an Xcode project, Android app module), plus CI workflows for both platforms.
- **Backend:** none required for v1. The apps use the same endpoints as the browser extension. Any gap found while building is a separate backend change, named in tasks.md.
- **Web app and extension:** none. The core must read and write the same ciphertext, so the crypto test vectors are shared and run on all three clients.
- **Risks:**
  - An iOS build needs a macOS runner and an Apple developer account (the same block as the Safari extension, DECISIONS row 20). Without them the iOS tasks stay open and are reported; Android still ships.
  - Store review can refuse a password manager for missing privacy texts or a test account. The release tasks carry both.
  - F-Droid builds on its own servers. A dependency that pulls in Google Play Services, Firebase or a closed binary blocks the listing, so CI checks the F-Droid build flavour on every PR.
  - A crypto mismatch would make items unreadable on one client. Shared test vectors guard it, and v1 writes only the item shapes the vectors cover.
