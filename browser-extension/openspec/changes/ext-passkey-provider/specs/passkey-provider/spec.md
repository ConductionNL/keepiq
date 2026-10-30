## Purpose

Makes Keepiq a passkey provider in the browser, as Bitwarden's extension is: a site's `navigator.credentials.create()` and `.get()` can save a passkey to a login or sign in with one, and anything Keepiq doesn't handle goes to the browser's own authenticator. A login keeps its passkeys in its own encrypted `passkeys` field (keepiq#1324, keepiq#1325): a JSON array of credentials in the canonical schema of Keepiq's `passkey-item-type`.

## ADDED Requirements

### Requirement: The page script takes over publicKey requests only
The extension SHALL run a page script in the page's main world of every `http:` and `https:` frame, before the page's own scripts where the browser allows it, that replaces `navigator.credentials.create` and `navigator.credentials.get`. A call without `publicKey` (password, federated, OTP or identity credentials) MUST go to the browser's original method unchanged. The page script MUST keep the browser's original methods from before the page ran and call those for every fallback. An exception inside the page script MUST end in the original method being called with the page's original arguments, never in a broken ceremony. The page script MUST hold nothing but the one ceremony in flight per call: its options, its request id and its result, dropped when the call settles.

#### Scenario: A password credential request
- **GIVEN** a page calling `navigator.credentials.get({ password: true })`
- **WHEN** the call runs
- **THEN** the browser's original `get` receives exactly those options and nothing is sent to the content script

#### Scenario: The page script fails
- **GIVEN** a page passing options the page script cannot serialise
- **WHEN** it calls `navigator.credentials.create({ publicKey })`
- **THEN** the browser's original `create` runs with the page's options

### Requirement: What crosses between the page and the content script
The page script and the content script SHALL exchange only these, by `window.postMessage` to the same window with a `MessageChannel` port per ceremony, and both MUST treat everything on this channel as written and read by the page:
- page to content: the request kind (`create`, `get`, `available`, `abort`), a request id, and the `publicKey` options serialised with every `BufferSource` as base64url, plus `mediation`;
- content to page: an acknowledgement, then either the credential (its id, type, attachment, client extension results and response fields as base64url), the name of a `DOMException` (`NotAllowedError`, `InvalidStateError` or `AbortError`) or `fallback`.

The content script MUST check the message shape and refuse oversized input (a `user.id` over WebAuthn's 64 bytes, a challenge or credential id over 1024 bytes, more than 64 credential descriptors, any string over 1024 characters), answering `fallback`, so the browser raises the page's own error. When the content script does not acknowledge within 500 ms, the page script MUST call the browser's original method.

#### Scenario: A page posts a forged result
- **GIVEN** a ceremony in flight
- **WHEN** another script on the page posts a message that looks like a result
- **THEN** the page script ignores it, since results come only over the ceremony's own port

#### Scenario: Extension reloaded under the page
- **GIVEN** a page whose content script lost its extension context
- **WHEN** the page calls `navigator.credentials.get({ publicKey })`
- **THEN** the content script answers `fallback`, or no acknowledgement arrives within 500 ms, and the browser's own `get` runs

### Requirement: Origin and RP ID come from the browser
The content script SHALL send the background `passkey.start` with the serialised options and whether its frame has a cross-origin ancestor, computed in the isolated world by the same check the inline list uses; it MUST NOT send an origin, URL, tab or frame. The background MUST take the tab and frame from the message sender and the origin from `sender.origin`, or from `sender.url` where the browser gives no `origin`. The RP ID is `publicKey.rp.id` for `create` and `publicKey.rpId` for `get`, or the origin's host when absent. The `clientDataJSON` origin MUST be that derived origin. The page and the content script never learn more than they sent and the result.

#### Scenario: A page asks for another site's RP ID
- **GIVEN** a page on `https://evil.example` calling `navigator.credentials.get` with `rpId: 'bank.example'`
- **WHEN** the request reaches the background
- **THEN** no passkey is read or decrypted and the request goes to the browser's own `get`, which refuses it

#### Scenario: A subdomain uses its parent RP ID
- **GIVEN** a page on `https://login.bank.example` with `rpId: 'bank.example'`
- **WHEN** the user picks a passkey for `bank.example`
- **THEN** the assertion's client data origin is `https://login.bank.example`

### Requirement: Requests Keepiq does not handle go to the browser untouched
The background SHALL answer `passkey.start` with `fallback` before opening anything or reading the vault when any of these holds:
- "Ask to save and use passkeys" is off for the active account, or there is no active account, or it is logged out;
- the frame's base domain is in the active account's Excluded domains;
- the origin is not `https:`, unless its host is `localhost` or ends in `.localhost`, or the origin is opaque;
- the RP ID is not the origin's host or a registrable suffix of it, or is a public suffix or an IP address;
- the frame has a cross-origin ancestor;
- `create` has no `-7` (ES256) in `pubKeyCredParams`, or asks `authenticatorAttachment: 'cross-platform'`.

Each of these depends on the settings and the request only, never on what the vault holds or whether it is locked.

#### Scenario: Setting off
- **GIVEN** "Ask to save and use passkeys" is off
- **WHEN** a page calls `navigator.credentials.create({ publicKey })`
- **THEN** the browser's own dialog opens and no Keepiq window does

#### Scenario: Excluded domain
- **GIVEN** `example.test` in Excluded domains
- **WHEN** `https://accounts.example.test` calls `navigator.credentials.get({ publicKey })`
- **THEN** the browser's own `get` runs

#### Scenario: Cross-origin iframe
- **GIVEN** a frame from `https://idp.example` inside a page on `https://shop.example`
- **WHEN** the frame calls `navigator.credentials.get({ publicKey })`
- **THEN** the browser's own `get` runs, applying the page's permissions policy

#### Scenario: RSA only
- **GIVEN** a `create` whose `pubKeyCredParams` lists only `-257`
- **WHEN** it reaches the background
- **THEN** the browser's own `create` runs

### Requirement: The passkey window
For every `create` and every `get` without `mediation: 'conditional'` that Keepiq handles, the extension SHALL open one popup window (the popup page at `?passkey=<ceremonyId>`, 380 × 630, as the pop-out) for that ceremony, whatever the vault holds and whether it is locked. One ceremony runs per tab: a new one in the same tab ends the previous one with `NotAllowedError` and takes over its window. The window gets from the background only the ceremony's kind, the RP ID and the requesting host, the `rp.name` and `user.name` the page sent (shown as text), the lock state, the rows to pick from (for a sign-in the login id, credential id, login name and the passkey's user name; for Save the matching logins' id, name and decrypted username) and codes; never ciphertext, a key, the challenge or the credential. Its buttons answer the ceremony; it closes itself when the ceremony ends. Every text in it goes through `i18n.t()` in `en.yml` and `nl.yml`, and it meets WCAG 2.2 AA as the popup does.

#### Scenario: Two requests in one tab
- **GIVEN** a passkey window open for a `get` in a tab
- **WHEN** the page calls `navigator.credentials.get({ publicKey })` again
- **THEN** the first call rejects with `NotAllowedError` and the same window shows the second ceremony

#### Scenario: Closing the window
- **GIVEN** a passkey window
- **WHEN** the user closes it
- **THEN** the page's call rejects with `NotAllowedError`, as Cancel does

### Requirement: Sign in with a passkey
For a `get`, the window SHALL list the passkeys offered for the request under "Sign in with a passkey" and "Use a passkey saved in Keepiq for <rpId>", each row showing the login's name and the passkey's user name. Picking one makes the background sign the assertion and reply to the page, record the login in `lastUsed.<accountId>` and close the window. With no passkey offered, the window reads "No Keepiq passkey for <rpId>". Every state offers "Use your device or hardware key" and Cancel.

#### Scenario: One passkey
- **GIVEN** an unlocked vault with a login "Example" holding a passkey for `example.test`, user name `alice`
- **WHEN** `https://example.test` calls `get` and the user picks "Example, alice"
- **THEN** the page receives a `PublicKeyCredential` whose response verifies against the passkey's public key
- **AND** the window closes

#### Scenario: No passkey
- **GIVEN** an unlocked vault with no passkey for `nothing.test`
- **WHEN** `https://nothing.test` calls `get`
- **THEN** the window reads "No Keepiq passkey for nothing.test" with "Use your device or hardware key" and Cancel, and the page receives nothing until the user picks one

### Requirement: Which passkeys are offered
The background SHALL offer, for the active account only, every entry in the `passkeys` field of every `login` row that is not blocked and not past its `accessExpiresAt`, where the entry parses as a canonical passkey (`credentialId`, `rpId`, `privateKey` present) with `rpId` equal to the request's RP ID and `algorithm` `-7`, and, when `allowCredentials` is not empty, whose `credentialId` is in it. A login may offer several. Matching decrypts `passkeys` in the background only while unlocked and keeps only the login id, the credential id, the login's name and the entry's `userName` (else `userDisplayName`) for the window. A use-only shared copy is offered, since signing is using it. The plaintext `url` is not used to choose passkeys, since the user may have edited it. A row whose `passkeys` is null or `''`, or does not decrypt or parse as an array, offers none; an entry that does not parse is skipped and the others stay.

#### Scenario: Allow list
- **GIVEN** a login holding passkeys A and B for `example.test`
- **WHEN** the request's `allowCredentials` names only B's credential id
- **THEN** only B is listed

#### Scenario: Two logins
- **GIVEN** logins "Work" and "Personal", each holding a passkey for `example.test`
- **WHEN** `https://example.test` calls `get`
- **THEN** both are listed, each under its login's name with its passkey's user name

#### Scenario: Edited URL
- **GIVEN** a login holding a passkey whose `rpId` is `example.test`, its `url` changed to `https://intranet.test`
- **WHEN** `https://example.test` calls `get`
- **THEN** the passkey is listed

#### Scenario: Unreadable entry
- **GIVEN** a login whose `passkeys` holds one entry that does not parse and one for `example.test`
- **WHEN** `https://example.test` calls `get`
- **THEN** only the readable passkey is listed

### Requirement: Standalone passkey items are not used
The extension SHALL NOT offer, sign with or create items of the standalone `passkey` type, since Keepiq clients no longer create them (keepiq#1324). The item detail still shows an existing one, as `item-detail` specifies.

#### Scenario: Standalone item
- **GIVEN** a standalone `passkey` item for `example.test` and no login holding a passkey for it
- **WHEN** `https://example.test` calls `get`
- **THEN** the window reads "No Keepiq passkey for example.test"

### Requirement: Save a passkey
For a `create`, the window SHALL show "Save passkey", the RP ID and the request's `user.name`, then where to save it, as in Bitwarden:
- the logins matching the requesting frame's URL under the URI match rule, as `autofill` matches them, without use-only, read-only and view-only copies, each with its name and username, last used first;
- "Save as new login", with a Name field prefilled with `rp.name` or else the RP ID, and a Folder select (default "No folder", from the cached folders);
- "Use your device or hardware key" and Cancel.

On a choice, the background makes an ECDSA P-256 key pair and builds the canonical credential (a random 16-byte `credentialId`, `rpId`, `rpName`, `userName`, `userDisplayName`, `userHandle` from `user.id`, `privateKey` as base64url PKCS#8, `algorithm` `-7`, `counter` 0, `transports` `["internal"]`, `createdAt`), field order as the web app writes it. A new login is created through the same write path as every create: the `login` type, `url` the frame's origin, no username, an empty password (`key` the encryption of `''`, as the web app's import writes one), the chosen folder, and `passkeys` the JSON array of that one credential. An existing login gets the credential appended to its `passkeys` (The passkeys field is saved whole). When the chosen login already holds a passkey with the same `rpId` and `userHandle`, the window first asks "Replace the passkey for <user name> on <login name>?" with Replace and Cancel; Replace puts the new credential in that entry's place. The page MUST receive the attestation only after the server accepted the save. A failed save keeps the window open with the reason, Retry, "Use your device or hardware key" and Cancel.

#### Scenario: Save as a new login
- **GIVEN** an unlocked vault without a login for `example.test`, and `https://example.test` calling `create` for user `alice`
- **WHEN** the user clicks "Save as new login" with Name "Example"
- **THEN** a login "Example" with `url` `https://example.test`, no username and an empty password exists, its `passkeys` decrypting to one credential the web app's passkey parser accepts
- **AND** the page receives an attestation of format `none` whose public key verifies a later assertion

#### Scenario: Save to an existing login
- **GIVEN** a login "Example" (`alice`) for `example.test` with a password and no passkey
- **WHEN** `https://example.test` calls `create` and the user picks "Example"
- **THEN** that login holds the passkey in its `passkeys`, and its password, username and other fields are unchanged

#### Scenario: Replace
- **GIVEN** a login holding a passkey for `example.test` with the request's user handle
- **WHEN** the user picks that login and confirms Replace
- **THEN** the login holds the new passkey in the old one's place, and its other passkeys are unchanged

#### Scenario: Server refuses
- **GIVEN** the server answers the save with 423
- **WHEN** the user saves the passkey
- **THEN** the window shows the reason worded by `writeErrors.writeLocked` and the page receives nothing yet

### Requirement: The passkeys field is saved whole
Every write of an existing login's `passkeys` (Save to it, Replace, the counter) SHALL go through the value save of ext-share-sync with `passkeys` as the only changed field: a fresh `GET /api/v1/secrets/{id}` whose `passkeys` ciphertext must equal the vault cache's (`unmatched` otherwise), the decrypted array changed in background memory and re-encrypted whole, `baseUpdatedAt` from that fetch, and the copies of a shared login re-encrypted for each holder. Every entry it does not change MUST be written back exactly as stored. On `stale` it syncs, reads again and tries once more. A partner share whose partner does not store `passkeys` refuses the save with `partner_upgrade_required` and the version it needs (keepiq#1325), which the window names. Use-only, read-only and view-only copies refuse with `restricted` or `view_only`.

#### Scenario: Other passkeys kept
- **GIVEN** a login holding passkeys A and B
- **WHEN** A's counter is saved
- **THEN** B is written back byte for byte

#### Scenario: Partner without the field
- **GIVEN** a login shared with a partner whose Keepiq does not store `passkeys`
- **WHEN** the user saves a passkey to it
- **THEN** nothing is written and the window names the Keepiq version the partner needs

### Requirement: A passkey that already exists
On a `create` whose `excludeCredentials` names the `credentialId` of a passkey a login of the active account holds for the RP ID, the window SHALL read "A passkey for this account is already saved in Keepiq" with Close, and the page MUST receive `InvalidStateError` only after the user closed it. The check runs after unlock, so a locked vault never answers it.

#### Scenario: Excluded credential
- **GIVEN** a login holding passkey A for `example.test` and a `create` excluding A's credential id
- **WHEN** the window opens on an unlocked vault
- **THEN** it shows the already-saved message, and the page gets `InvalidStateError` after Close

### Requirement: User presence and verification
Every assertion and attestation SHALL set the user-present flag, since the user acted in the window or the inline list. The user-verified flag MUST be set only when the user entered the master password or PIN during this ceremony: by unlocking from it, or at the "Confirm it's you" step. A request with `userVerification: 'required'` MUST pass that step before signing or saving; the step accepts the master password, or the PIN where the account has one, under the PIN's existing five-try rule. `preferred` and `discouraged` never ask and set the flag only after an unlock within the ceremony. The backup-eligible and backup-state flags are always set, since Keepiq passkeys sync.

#### Scenario: Verification required while unlocked
- **GIVEN** an unlocked vault and a `get` with `userVerification: 'required'`
- **WHEN** the user picks a passkey
- **THEN** the window asks "Confirm it's you" and signs only after the right master password or PIN, with the user-verified flag set

#### Scenario: Unlocking counts
- **GIVEN** a locked vault and a `get` with `userVerification: 'required'`
- **WHEN** the user unlocks in the window and picks a passkey
- **THEN** the assertion is signed without asking again, with the user-verified flag set

#### Scenario: Preferred
- **GIVEN** an unlocked vault and a `get` with `userVerification: 'preferred'`
- **WHEN** the user picks a passkey
- **THEN** the assertion is signed at once with the user-verified flag clear

### Requirement: A locked vault asks to unlock, then continues
When the active account is locked, the window SHALL open on the unlock view (master password, or PIN where set) under "Unlock Keepiq to use a passkey for <rpId>", with "Use your device or hardware key" and Cancel, and show the sign-in or save view after a successful unlock. While locked, the background MUST NOT decrypt, match or count anything for the ceremony, and the window MUST NOT show whether a passkey exists. A lock during the ceremony returns the window to the unlock view.

#### Scenario: Locked sign-in
- **GIVEN** a locked vault with a passkey for `example.test`
- **WHEN** `https://example.test` calls `get`
- **THEN** the window shows the unlock view and no passkey name, user name or count
- **AND** after unlocking it lists the passkey

#### Scenario: Locked, then fallback
- **GIVEN** the window on the unlock view
- **WHEN** the user clicks "Use your device or hardware key"
- **THEN** the browser's own `get` runs with the page's options and the window closes

### Requirement: A page cannot probe the vault
The extension SHALL send a page nothing that depends on the vault before the user acted on that ceremony: for a handled `create` or modal `get`, the page receives a credential, a `DOMException` or the fallback only after a click or key press in the window, in every state (locked, unlocked, with or without a match, an excluded credential). A conditional `get` receives nothing until the user picks a passkey in the inline list or the request ends. The immediate fallbacks depend on settings, the account's logged-in state and the request only. The inline list shows passkey rows only after a trusted focus or click, as for logins, so the page can count matches only after the user acted, as in Bitwarden.

#### Scenario: Probing with an allow list
- **GIVEN** an unlocked vault with no passkey for `example.test`
- **WHEN** the page calls `get` with a made-up `allowCredentials` id and a 1-second timeout
- **THEN** the page's call ends by its own timeout with `NotAllowedError`, exactly as it would with a matching passkey that the user did not pick

#### Scenario: Probing while locked
- **GIVEN** a locked vault
- **WHEN** the page calls `get`
- **THEN** the window opens just as it does for an unlocked vault and the page receives nothing until the user acts

### Requirement: Fallback to the browser
"Use your device or hardware key" SHALL end the Keepiq ceremony and make the page script call the browser's original method with the page's original options and signal, so the browser's own dialog takes over. The page then receives whatever the browser returns.

#### Scenario: Fallback from the list
- **GIVEN** the sign-in window with one passkey listed
- **WHEN** the user clicks "Use your device or hardware key"
- **THEN** the window closes and the browser's own passkey dialog opens for the same request

### Requirement: A ceremony ends cleanly
The extension SHALL end a ceremony and close its window when:
- the user clicks Cancel or closes the window: the page gets `NotAllowedError`;
- the page aborts its `signal`: the page gets the signal's reason, as an `AbortError`;
- its deadline passes: the page's `timeout`, clamped to 30 s–10 min with 5 min by default when user verification is `required` or `preferred`, and to 30 s–3 min with 2 min by default when `discouraged`, as in Bitwarden: the page gets `NotAllowedError`;
- the tab closes or the frame navigates: nothing is sent;
- the active account logs out or changes: the page gets `NotAllowedError`.

A result is delivered only to the frame that started the ceremony, and the content script drops it when that frame's origin changed since.

#### Scenario: Page aborts
- **GIVEN** an open passkey window
- **WHEN** the page aborts the request's `AbortSignal`
- **THEN** the window closes and the page's call rejects with the signal's reason

#### Scenario: Navigation
- **GIVEN** an open passkey window
- **WHEN** the requesting tab navigates to another page
- **THEN** the window closes and nothing is signed

### Requirement: The signature counter
The extension SHALL sign with counter 0 and write nothing when the stored `counter` is 0. For a stored `counter` above 0, it MUST save the login's `passkeys` with that entry's `counter` plus one before replying to the page, and sign with that value. The save is the one of "The passkeys field is saved whole"; after `stale` it rereads the counter. When the save is refused or fails, the window shows the reason with Retry, "Use your device or hardware key" and Cancel, and the page receives no assertion; so a use-only, read-only or view-only copy with a non-zero counter cannot sign.

#### Scenario: Imported passkey
- **GIVEN** a login holding an imported passkey with `counter` 41
- **WHEN** the user signs in with it
- **THEN** the login is saved with that entry's `counter` 42 before the page receives an assertion whose counter is 42

#### Scenario: New passkey
- **GIVEN** a passkey Keepiq saved, `counter` 0
- **WHEN** the user signs in with it
- **THEN** the assertion's counter is 0 and no write is sent

#### Scenario: Offline
- **GIVEN** a passkey with `counter` 5 and no server answering
- **WHEN** the user picks it
- **THEN** the window shows "Could not reach the server" (`writeErrors.offline`) with Retry, and the page receives nothing yet

### Requirement: Assertion and attestation format
The background SHALL build WebAuthn Level 3 responses: `clientDataJSON` with `type`, `challenge` (base64url of the request's challenge), `origin` and `crossOrigin: false`, in that order; authenticator data from SHA-256 of the RP ID, the flags and the counter; for `create` an attested credential with an all-zero AAGUID, the credential id and the EC2 COSE public key, in an attestation object of format `none` whatever attestation the page asked; for `get` an ES256 signature over the authenticator data and the hash of `clientDataJSON`, DER-encoded, and the stored `userHandle`. `authenticatorAttachment` is `platform`; `credProps.rk` is `true` when the page asked for `credProps`; other extensions are left out of the results. The page script returns an object with `PublicKeyCredential.prototype` and its response with `AuthenticatorAttestationResponse.prototype` or `AuthenticatorAssertionResponse.prototype`, with `getTransports`, `getPublicKey`, `getPublicKeyAlgorithm`, `getAuthenticatorData` and `toJSON` working.

#### Scenario: Verified by a relying party library
- **GIVEN** the test site's relying party, which verifies with WebCrypto only
- **WHEN** it registers a passkey through Keepiq and then signs in with it
- **THEN** both the attestation and the assertion verify

### Requirement: The passkey key lives in background memory for one signature
The extension SHALL decrypt a login's `passkeys` only in the background, import the chosen entry's `privateKey` as a non-extractable ECDSA `CryptoKey`, sign, and drop the decrypted JSON and the key when the handler returns. On Save, the generated private key exists in background memory only until it is exported into the credential and encrypted. No passkey key, decrypted credential or signature input is written to `storage.local` or `storage.session`, sent to the popup, the inline list or the content script, or sent to the server except as the encrypted `passkeys`.

#### Scenario: Nothing left after signing
- **GIVEN** a completed sign-in
- **WHEN** `storage.local` and `storage.session` are read
- **THEN** neither holds the passkey's private key, its decrypted credential or the challenge

### Requirement: Where ceremony data lives
The extension SHALL keep each tab's ceremony in `storage.session` under `passkeyCeremony.<tabId>` (background memory on Firefox below 115): a random ceremony id, the account id, tab and frame ids, origin, RP ID, kind, mediation, the request's challenge, `rp.name`, `user` entity, credential id lists, user verification and attachment requirements, deadline, window id, whether the user unlocked or verified within it, and its state. It holds no vault data and no decrypted values. It is removed when the ceremony ends (result, Cancel, fallback, abort, deadline), when a new ceremony starts in the tab, when the tab closes or navigates, on logout and account switch, and on browser restart or extension reload. A lock keeps it, so the window can continue after unlock. The rows the window shows live only in the popup page's memory while it shows them.

#### Scenario: Worker restart
- **GIVEN** an open passkey window on Chrome
- **WHEN** the service worker stops and the user then picks a passkey
- **THEN** the woken worker reads the ceremony from `storage.session` and the page receives the assertion

#### Scenario: Logout
- **GIVEN** an open passkey window
- **WHEN** the user logs out the account from the popup
- **THEN** `passkeyCeremony.<tabId>` is gone, the window closes and the page gets `NotAllowedError`

### Requirement: Availability answers
While the provider would handle a request from the frame (the setting on, an account logged in, the site not excluded, a secure origin without cross-origin ancestors), the page script SHALL answer `PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable()` and `PublicKeyCredential.isConditionalMediationAvailable()` with `true`, as Bitwarden does; otherwise with the browser's own answer. The answer depends on settings and the frame only, never on the vault or the lock.

#### Scenario: Desktop without a platform authenticator
- **GIVEN** the setting on and a browser whose own answer is `false`
- **WHEN** the page calls `isUserVerifyingPlatformAuthenticatorAvailable()`
- **THEN** it resolves `true`

### Requirement: Conditional requests
For `get` with `mediation: 'conditional'`, the background SHALL register the ceremony for its frame without opening a window, and the page script MUST also start the browser's own conditional request; the first to resolve wins and the other is aborted, as in Bitwarden. A pick in the inline list signs as a pick in the window does; when verification is required or the counter save fails, the passkey window opens on that step. A conditional request has no deadline: it ends when the page aborts, the frame navigates or a modal request in the tab replaces it.

#### Scenario: Browser's own passkey wins
- **GIVEN** a conditional `get` and a passkey in the browser's own manager
- **WHEN** the user picks the browser's suggestion
- **THEN** the page receives the browser's credential and the Keepiq ceremony ends without a reply

### Requirement: Works on every supported Keepiq
The provider SHALL use only routes and fields in every Keepiq from `MIN_KEEPIQ_VERSION` on: secret create, the value save through share-save and the reads it needs (ADR-003), and the `passkeys` field, in 0.3.12 (keepiq#1325). Nothing is gated in `KEEPIQ_FEATURES`. A row without the field, as from a server that does not send it, holds no passkey.

#### Scenario: Keepiq at the minimum
- **GIVEN** an account on Keepiq 0.3.12
- **WHEN** the user saves a passkey to a new login and signs in with an imported one whose counter is 3
- **THEN** both succeed, through `POST /api/v1/secrets` and `PUT /api/v1/secrets/{id}/share-save` with `passkeys`

#### Scenario: Development build
- **GIVEN** an account on a Keepiq reporting `0.3.9-unstable.20261007125844`
- **WHEN** the user saves a passkey
- **THEN** it is saved as on a release
