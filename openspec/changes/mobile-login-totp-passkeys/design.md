# Design: one-time codes and passkeys on a login, in the mobile apps

## Context

- keepiq#1325 gave a secret two more encrypted value fields, `totp` and `passkeys`. Each is null when it holds no value, `''` when explicitly not set, and RSA ciphertext otherwise. `POST /api/v1/extension/pair` lists `totp-field` and `passkeys-field` in its `capabilities` on a server that stores them.
- `passkeys` holds a JSON array of credentials in the canonical schema of the `passkey-item-type` spec. Every credential has a `createdAt`: the web app and imports set it to the moment of saving when the source has none (`src/passkey/passkey.js` `buildPasskeyCredential`), a Bitwarden import takes the export's `creationDate`, and the mobile provider writes it when it creates one.
- The web app's change `vault-login-totp-codes` is the reference for behaviour; its decisions are cited as web D1 to D14.
- The mobile apps (`clients-mobile-apps`) share one Kotlin core. Today it reads codes and passkeys only from standalone `totp` and `passkey` items, and writes a passkey counter back with a plain `PUT /api/v1/secrets/{id}`.

## Goals / Non-Goals

**Goals**
- The Android app shows, fills and signs with codes and passkeys stored on a login, and saves new passkeys onto a login.
- A login keeps its code and passkeys through sync, the offline store and every edit the app makes.
- Standalone Authenticator and Passkey items keep working.

**Non-Goals**
- Servers without the fields. The app is used by internal testers only, so it requires them, as the browser extension does.
- The iOS screens. iOS gets what the shared core gives it.
- Share-save in the mobile apps, and scanning a QR code for the key. Both get their own issue.

## Decisions

**D1. Pairing checks capabilities, not a version.** `PairResponse` reads `capabilities`, and pairing refuses a server that lacks `totp-field` or `passkeys-field`, asking the user to have Keepiq updated (its own message, `MissingCapabilityException.MESSAGE`, not the API-version check's). A capability is what the app actually needs, and a development server reports a version like `0.3.9-unstable` that a version check would refuse although it has the fields. Accounts paired before this change are checked again before a write of a login's `totp` or `passkeys` (`KeepiqApi.requireLoginFields`): the same pair call, asked once per `KeepiqApi`, refuses the write with the pairing message, before a passkey is made. The check runs at the write and not at unlock, because the passkey provider and the iOS extension write without going through the unlock screen.

**D2. The fields stay ciphertext until used.** `VaultRow`, `StoredSecret` and `AutofillEntry` carry `totp` and `passkeys` as the server sent them. Nothing in the list, the index or the store decrypts them; the detail screen, the code fill and the passkey provider decrypt only the item they act on.

**D3. The store's first migration forces a full sync.** `1.sqm` adds two nullable columns to `secret` and resets the freshness check in the sync state (no newest `updatedAt`, a total of -1). Without that, the check would see nothing new and the columns would stay empty until each item changed. The rest of the sync state stays, so a phone updated while offline still opens its stored vault.

**D4. The opened item.** `ItemCodec.open` decrypts `totp` into `DecryptedItem.totp` for any type whose field holds a value; a standalone Authenticator item still takes its code from `key`. `passkeys` becomes a list of `PasskeySummary` with the account and the added date, never a private key. A use-only copy decrypts `totp` for its code and not `passkeys` (web D7).

**D5. Writes send a field only when it changes.** `ItemParts` gains `totp`; `changed` and `updateBody` send it whenever the key field chose Replace or Remove, `''` to clear, never an encrypted empty string (web D2), and never when the field was left untouched. The draft never holds the saved seed, so it cannot compare: a Replace with the same key is sent too. `createBody` sends `totp` and `passkeys` only when set. The edit form never sends `passkeys`.

**D6. Passkeys are shown on the detail screen only.** The list and search show nothing about them, unlike the web app's marker (web D13). Each passkey is one row: "Passkey", with the value "Added" and its `createdAt` formatted in the phone's locale. When a login holds more than one, each row also names its account (`userName`, else `userDisplayName`). A credential without a readable date shows "Passkey saved". No site, credential id or key is shown.

**D7. Deleting a passkey.** Where the user may edit the login and the app is online, each passkey row offers Delete behind a confirmation. The login is fetched fresh, every entry with that credential id and rpId is removed, and the rest is sent as `passkeys`, or `''` when none remain (web D5), with the fetched row's `updatedAt` as `baseUpdatedAt`; when the server refuses that as stale (409), the login is read and changed once more, and a second refusal fails (`PasskeysWrite`). Focus then moves to the next passkey row, else the Edit button.

**D8. The Authenticator key is write-only.** On a login the edit screen offers an Authenticator key field. It takes a base32 secret or an `otpauth://totp/` URI and refuses one `Totp.parse` cannot read, so a key that saves always gives a code. A saved key is never loaded into the form: the field shows that a key is saved and offers Replace and Remove, and Remove sends `''`. On another type whose `totp` holds a value, an Authenticator item included, it offers Remove only (web D3, D6); the detail screen shows that value's code on any such item, on an Authenticator item under its own label ("Code from the item's own key") next to the code of its `key`. A typed key is masked until the user shows it, like a password, and while the field is on screen the screen is marked not important for autofill (`View.IMPORTANT_FOR_AUTOFILL_NO_EXCLUDE_DESCENDANTS`), so another autofill service is never offered the seed to save; the web field sets `autocomplete="off"` for the same reason. Screenshots follow the app's posture: the app sets no `FLAG_SECURE` anywhere, so a key shown on screen can appear in a screen recording or the recents thumbnail, as a shown password can. That is app-wide and outside this change.

**D9. No new Authenticator or Passkey items.** The type list for a new item leaves out `totp` and `passkey`. Existing Authenticator items still open, edit and fill as today (web D10).

**D10. Code fill takes a login's own code.** `AutofillIndex.candidates(target, totp = true)` offers the standalone Authenticator items it offers today and the same-site logins whose `totp` holds a value, by the same site rules. `AutofillSites.code` decrypts the login's `totp`, or `key` for a standalone item.

**D11. Signing with a passkey on a login.** `Passkeys.candidates` offers each credential of a same-site login's `passkeys` next to the standalone items, each with an id of its own (item and credential id) that the platform's choice carries back, and the iOS identity store lists them too, with the same pre-filter (the registrable domain of the item's address) and the same check that the credential's rpId equals the request's. A `PasskeyChoice` knows its source and credential id. A passkey on a login signs from the login fetched fresh: the credential with the chosen id and rpId is taken from the fresh `passkeys` and signed with, so a non-zero counter is the stored one plus one, and a credential that is gone refuses the sign-in whatever its counter (`PasskeyGoneException`). The one exception: when the fresh read gets no answer at all (a transport failure, `ServerUnreachableException`), a credential with a counter of zero signs from the index, as it writes nothing. Every other failure of the fresh read, including an answer whose `passkeys` cannot be decrypted or read, fails the sign-in. A non-zero counter is written by replacing only that credential's counter in the fresh value and sending only `passkeys` with the row's `updatedAt` as `baseUpdatedAt`, so a passkey added elsewhere in the meantime is kept; on a stale refusal the login is read and signed once more. A standalone item's counter is written to `key` as today. A failed write fails the sign-in, as in the extension. So a passkey on a use-only or read-only login, which the sheet offers too, cannot sign once its counter is above zero: the server refuses the write. One with a counter of zero signs, as it writes nothing.

**D12. A new passkey goes onto the one matching login.** The excluded-credentials check covers both sources. The new credential goes onto a same-site login, not linked to an app, whose decrypted user name equals the passkey's `userName` (trimmed, ignoring case), when exactly one such login exists, it is neither use-only nor read-only, and its `passkeys` is empty or an array (a value that is not an array is never rewritten, as `withoutPasskey` in `src/passkey/loginPasskeys.js` does); that login is fetched fresh and the credential appended to its `passkeys`, with `baseUpdatedAt` as in D7. When the matched login is gone when read fresh (404, deleted elsewhere since the index was built), or the fresh row is one the server takes no passkeys on (now use-only, read-only or blocked, or not owned by a user), a new login is made instead; when the fresh `passkeys` is no longer an array, the registration fails and nothing is written. Otherwise a new login is created, named the rpName else the rpId, with the rpId as its address, `userName` as its user name, an empty password and `[credential]` as its `passkeys`, the rule web D14 uses for an import. The app no longer looks up the `passkey` type.

## Security

`totp` and `passkeys` are RSA-encrypted on the device with the holder's public key, using the existing chunked encryption, and decrypted on the device only. The offline store and the autofill index keep the server's ciphertext. A seed is never shown after saving, a passkey's private key is never shown, and a code is generated in memory only.

## Risks / Trade-offs

- An account paired before this change against a server without the fields is refused at its first write of a login's code or passkeys (D1), not at unlock, so reading and filling keep working there.
- A counter written back on a shared login reaches only the user's own copy until the mobile apps use share-save, as with a standalone passkey today.
- Matching on the user name (D12) attaches a passkey to the wrong login only when two people's logins on one site share a user name; then there are two matches and a new login is made.

## Migration

The store's first schema migration (D3). Nothing in a vault is moved.
