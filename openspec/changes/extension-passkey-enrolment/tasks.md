# Tasks: passkey enrolment and unlock in the browser extension

Nothing here starts before Ruben approved the design. Each decision in design.md (D1 to D6) can be changed at review; the tasks follow whatever is approved.

## 0. Before building

- [x] 0.1 Read the merged unlock lane (verify-before-release) and align the route names and response shape of D5 with it.
- [ ] 0.2 Read the Chrome Web Store and Edge Add-ons ids of the published Keepiq listings, and record the Firefox origin of `keepiq@conduction.nl` from a real Firefox 150 ceremony, as the test vectors for the pinned list.
  - TODO (11 Nov 2026): no listing was reachable (addons.mozilla.org has no add-on `keepiq@conduction.nl`, the Chrome Web Store has no `keepiq` listing), so `TrustedExtensionOrigins::PINNED_STORE_ORIGINS` is empty and only admin-added origins pass. Fill it in, with a test vector per entry, once the listings are published.
- [ ] 0.3 Confirm in Chrome 122+ and Firefox 150+ that an extension tab can run `create()` and `get()` with the paired server host as relying party id once host permission is granted, and that the client data origin is the extension origin. Record the result in `browser-extension/README.md`.
  - TODO (11 Nov 2026): needs a real Chrome 122+ and Firefox 150+ with an authenticator; not run yet.

## 1. Server

- [x] 1.1 Add `keepiq_webauthn_challenges` and an `ExtensionChallengeStore`: issue (replaces a pending one for uid, token and purpose), take by atomic delete, 300 seconds, a background job that removes expired rows (D4). Verify: `ExtensionChallengeStoreTest` (single use, parallel take, other token, expiry, purpose).
- [x] 1.2 Add `TrustedExtensionOrigins` (pinned list plus admin list, switch, fails closed on a broken value) and the `TrustedExtensionOrigin` ceremony step, and give `WebAuthnVerifier` an extension step list with that step in place of `CheckOrigin` (D1, D2). Verify: `WebAuthnVerifierTest` with fixtures for a pinned origin, an admin origin, an untrusted extension origin and an https origin; `TrustedExtensionOriginsTest`.
- [x] 1.3 Change `PasskeyService::enroll` and `PasskeyEnrolmentCeremony` for extension credentials: relying party from the request host, ignore a client `rpId`, random user handle, token id binding, store the counter (D1, D3). Verify: `PasskeyServiceTest`.
- [x] 1.4 Extension unlock: options without envelope, the unlock route that takes the challenge, verifies and only then returns the wrapped key, counter rule with `suspended`, token binding, epoch, `IThrottler` on both routes, audit events (D5, D6). Verify: `PasskeyServiceTest`, `PasskeyControllerTest`.
  - Covered by `ExtensionPasskeyTest` (real ceremonies over a software passkey), `PasskeyServiceTest` and `PasskeyControllerTest`. The user handle is derived from the random enrolment challenge (HMAC-SHA256), so it is random per credential without a stored pending value. A suspension is audited (`passkey.suspended`); a notification to the owner is not built yet.
- [x] 1.5 Admin settings `extension_passkeys_enabled` and `extension_passkey_origins` in `AdminSettingsService` with entry validation, and the admin section showing the pinned list and a Firefox add-on id to origin helper (D2). Verify: `AdminSettingsServiceTest`.
  - The Firefox add-on id to origin helper is not built: its derivation needs the Firefox 150 test vector of 0.2 first. The settings tests live in `AdminSettingsAreaTest`.
- [x] 1.6 Listener for `TokenInvalidatedEvent` that deletes bound extension credentials and challenges and writes an audit event (D3). Verify: `TokenInvalidatedListenerTest`.
- [x] 1.7 Migration: `token_id`, `user_handle`, `sign_count` on `keepiq_passkey_credentials`; mark existing extension credentials `stale`. Verify: migration test.
  - `sign_count` came with the web unlock change (keepiq#1626); this step adds `token_id` and `user_handle`.

## 2. Extension

- [x] 2.1 Pairing through Login Flow v2 with the manual app password fallback and the file access note (D3). Verify: `tests/extension/pairing.spec.js` (flow success, closed tab, 20 minute timeout, verified before stored).
  - The tests sit next to the code, as the extension's convention is: `src/accounts/login-flow.test.ts` (flow, closed tab, 20 minute timeout, foreign poll origin) and `src/background/router.test.ts` (verified before stored, an app password that does not verify is deleted again). The file access note shows on the unlock screen until the first unlock.
- [ ] 2.2 Enrolment in an extension tab: server options, `create()` and proof `get()` with the server host as relying party, random user handle, `residentKey` `discouraged`, PRF wrap, post (D1). Verify: vitest for the request shape, including that no master password, raw unlock key or PRF output is sent.
- [ ] 2.3 Unlock in an extension tab: options, `get()`, post the assertion, unwrap only from the server's answer, hand the raw key to the worker, never to storage (D5). Verify: vitest.
- [ ] 2.4 Feature detection: offer the option only where the extension page exposes WebAuthn, a user-verifying platform authenticator is available and PRF is reported; Safari shows the master password only. Verify: vitest.

## 3. Web app

- [x] 3.1 Passkey list: label extension credentials with the browser and show `suspended` with revoke and confirm actions. Verify: store tests.
  - The list shows `suspended` with a warning and keeps Revoke. Extension credentials are labelled "Browser extension" and carry the label the extension gives at enrolment; the server stores no browser name. Confirming a suspended credential is not built: the server cannot check the master password, so a confirm route needs its own design (open question).

## 4. Verification and docs

- [ ] 4.1 The diff check while building, `composer check:strict` and `npm run lint` once before push.
- [ ] 4.2 Manual test on a live instance by the human contributor in Chrome and Firefox: enrol, unlock, revoke the app password in Devices & sessions, remove an admin origin.
- [ ] 4.3 Update `docs/` with the admin setting and the Devices & sessions revocation.
