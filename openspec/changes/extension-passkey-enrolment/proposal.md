---
kind: code
---

# Passkey enrolment and unlock in the browser extension

## Why

keepiq#1614 made the server verify every passkey enrolment with the WebAuthn library Nextcloud core ships (web-auth/webauthn-lib 4.9): a session-held, user-bound, single-use challenge pair that lives 5 minutes, and checks of the client data type, challenge and origin, the relying party hash, user presence, the algorithm, attestation `none` and a proof assertion. That closed enrolment of unverified credentials, and it also closed enrolment from the browser extension, for three reasons found while reading the code:

1. **The origin check refuses the extension.** An extension page reports `chrome-extension://<id>` or `moz-extension://<hash>` as its origin. The library's `CheckOrigin` step requires `https` and an origin host inside the relying party id, so every extension ceremony fails.
2. **The relying party is claimed by the client.** `PasskeyService::enroll` takes the extension's `rpId` from the request body and verifies against it. Today nothing on the server decides which extension may enrol.
3. **The challenge cannot reach the extension.** The challenge pair is kept in the Nextcloud session (`PasskeyChallengeStore`, `ISession`). The extension authenticates every request with its app password and sends no cookies (`extension-pairing`), so each request has a fresh session and the challenge it was given is gone by the next call.

The spec `extension-biometric-unlock` also chose the extension's own origin as relying party (archived change `2026-10-02-clients-extension-unlock-lock-and-accounts`, D3). Firefox refuses an extension id as relying party id, so that choice cannot work on Firefox. Chrome 122 and Firefox 150 instead let an extension use a domain it holds host permission for as relying party id, with its own deterministic origin in the client data. The extension already gets host permission for its Keepiq server when an account is added.

A follow-up lane makes unlock release the vault envelope only after a verified assertion. This change designs how the extension takes part in both ceremonies under those rules. It is a design for Ruben's review (2026-11-11); nothing is built until it is approved.

## What changes

- **Relying party.** The extension enrols and unlocks with the Nextcloud host as relying party id, the same id the web app and core's own passkeys use. The server decides the relying party; the request no longer names it.
- **Trusted extension origins.** The server accepts an extension ceremony only when its client data origin is exactly one of the trusted extension origins: the official store builds, pinned in Keepiq's code, plus origins an admin adds. No wildcards. An empty or unreadable list, or the admin switch turned off, refuses every extension ceremony and leaves the master password working.
- **Server-side challenges for app-password clients.** Extension challenges are stored server-side, bound to the user, the Nextcloud app password (token id) and the purpose, single-use and valid 5 minutes.
- **Pairing through Nextcloud Login Flow v2.** The extension gets its app password from core's login flow, as the mobile apps do. Pasting an app password stays as a fallback.
- **Extension credentials follow the app password.** Each extension credential records the app password it was enrolled under. Revoking that app password in Nextcloud's Devices & sessions revokes the credential and its envelope.
- **Unlock is verify-before-release.** The extension asks for unlock options, gets a challenge and its credential ids and PRF salts but no envelope, runs the assertion, and gets the wrapped unlock key only after the server verified the assertion, user verification and the signature counter. Failures are throttled with Nextcloud's `IThrottler`.

## Capabilities

### New capabilities

- `extension-passkey-trust`: the trusted extension origin list, how it fails closed, the server-side challenge store for app-password clients, the binding of extension credentials to their app password, and throttling.

### Modified capabilities

- `extension-biometric-unlock`: the relying party becomes the Nextcloud host, enrolment and unlock are verified on the server, and the envelope is released only after a verified assertion.
- `extension-pairing`: an account is added through Nextcloud Login Flow v2, with pasting an app password as the fallback.

## Impact

- **Backend**: `WebAuthnVerifier` (a ceremony step list with a trusted-origin step for extension ceremonies), `PasskeyEnrolmentCeremony`, `PasskeyChallengeStore` (a second, token-bound store), `PasskeyService` (relying party from the server, token binding, unlock release), `PasskeyController` (extension challenge and unlock routes), `AdminSettingsService` and the admin settings section, a listener for core's `TokenInvalidatedEvent`, and a migration that adds `token_id`, `user_handle` and `sign_count` to `keepiq_passkey_credentials` and creates the challenge table.
- **Extension**: pairing through Login Flow v2, the enrolment and unlock window, the relying party id and origin used in the ceremonies, the unlock call.
- **Web app**: the passkey list shows which browser an extension credential belongs to; no change to the web app's own passkeys.
- **Security**: the server, not the client, decides the relying party and which extensions are trusted. The zero-knowledge model is unchanged: the master password, the raw unlock key and the PRF output never reach the server.
- **Nextcloud**: builds on core's Login Flow v2, app passwords, Devices & sessions, `IThrottler`, `ISecureRandom`, `ITimeFactory` and the WebAuthn library core ships. No second login, no Keepiq-owned session.
- **Feature tier**: V1 (`docs/FEATURES.md`, passkey vault login).
- **OpenConnector**: none; it uses Keepiq's application tokens, not passkeys.
- **Not in this change**: Safari (how stable its extension origin is, and whether its extension pages may use a host-permission relying party, is not verified yet), passwordless sign-in to Nextcloud itself from the extension, and offline passkey unlock.
