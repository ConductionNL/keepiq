Tests sit next to each module (`*.test.ts(x)`); `npm test` runs them, the popup ones in production and StrictMode. Every user-facing string is an `i18n.t()` key in both `locales/en.yml` and `locales/nl.yml`.

## 1. Core: settings, policy, PIN, messages and background

- [x] 1.1 Extend `src/accounts/settings.ts`: `AccountSettings` with one default and validator per key, and `readSettings` validating each key
  - Keys: `vaultTimeout` (15), `vaultTimeoutAction` (`lock`), `clearClipboardMs` (30 000, `0` is Never), `autofillOnPageLoad` (false), `defaultAutofill` (`onPageLoad`), `uriMatch` (`baseDomain`), `inlineSuggestions` (true), `contextMenu` (true), `askToAdd` (true), `askToUpdate` (true), `excludedDomains` ([]), `defaultItemType` (`login`)
  - Clipboard options: 0, 10 000, 20 000, 30 000, 60 000, 120 000, 300 000
  - Export `AUTOFILL_FEATURES: AutofillFeature[] = []` (`pageLoad`, `uriMatch`, `inlineSuggestions`, `contextMenu`, `savePrompts`)
  - `writeSettings` reads, merges and writes a patch
  - Create `src/accounts/settings.test.ts`: fresh defaults, an invalid value per key reads as its default, a timeout write keeps `clearClipboardMs`
- [x] 1.2 Make `updateSettings` in `src/accounts/store.ts` take a patch and keep calling `syncNeverLockKey`; read the delay through `readSettings` in the `clipboard.copied` handler in `src/background/requests.ts` and pass it to `scheduleClipboardClear(ms)` in `src/clipboard.ts`; update `store.test.ts`, `clipboard.test.ts` and `requests.test.ts`
- [x] 1.3 Add `compactMode` (false), `showAnimations` (true) and `quickCopy` (true) to `GlobalSettings` and `src/settings/global.ts`, with tests
- [x] 1.4 Create `src/accounts/policy.ts`: read, patch and clear `accountPolicy.<accountId>` = `{ maxIdleMinutes: number | null; exportDisabled: boolean }`, initially `null` and `false`, with tests
- [x] 1.5 In `src/vault/sync.ts`, fetch `GET /api/v1/extension/policy` in `run()` and `refreshPolicy()` beside `fetchPolicy`
  - `fetchPolicy` returns `{ generator, exportDisabled }`: the generator part as today, and `vault_export_disabled` read whatever `policy_enabled` says
  - Store through `storePolicy`'s logged-in guard. A failed request or a `maxIdleMinutes` that is not a positive whole number keeps the stored maximum; a missing or non-boolean `vault_export_disabled` keeps the stored switch
  - Run `syncNeverLockKey` after each write; `undoWrites` clears the record only when the account record is gone
  - Remove the `GET /api/settings/user` read and `VaultSnapshot.defaultSecretType` (`src/vault/types.ts`); fix the path lists in `sync.test.ts` and `write.test.ts`
  - Tests: read on sync and login, `120` is stored, a failure, `0` and `"abc"` keep the stored value, `vault_export_disabled` read with `policy_enabled: false`, a logout during the fetch writes nothing, a maximum under Never deletes `neverLockKey.<id>`
- [x] 1.6 Clear `accountPolicy.<id>` in the account purge in `src/accounts/store.ts`, not in `markLoggedOut`; tests: a 401 keeps it, removal deletes it
- [x] 1.7 Make `effectiveSettings(accountId)` read the stored maximum and return `AccountSettings & { maxIdleMinutes: number | null }`; remove `TIMEOUT_POLICY` and `forcedAction`
  - Numbers become the lower of themselves and the maximum; Never and On browser restart become the maximum; Immediately is not clamped; no maximum means no clamp
  - `enforce()` in `src/vault/timeout.ts` also locks On system lock and Immediately at the maximum
  - Tests in `settings.test.ts` and `timeout.test.ts`: maximum 30 with a stored 4 hours, On system lock at 60, Never under a maximum is not restored from disk, unclamped before the first read
- [x] 1.8 Create `src/vault/pin.ts`: wrap and unwrap the PKCS#8 bytes with a key from `deriveUnlockKey` (`src/crypto/kdf.ts`) over a random 16-byte salt, encoded with `encodeEnvelope`/`decodeEnvelope` (`src/crypto/envelope.ts`); keep the record `pin.<accountId>` = `{ envelope, suiteId, unlockKeyEpoch, failures }` as a JSON string in `sessionValues`, which takes strings only
  - Tests: round trip, a wrong PIN fails, nothing reaches `storage.local`, the memory fallback without `storage.session`
- [x] 1.9 Extend `src/messages.ts` and `src/failure.ts`
  - `UnlockMethod` gains `{ type: 'pin'; pin }`; `Result` failures gain `attemptsLeft?`; `ErrorCode` gains `invalid_pin`, `pin_forgotten` and `pin_key_changed`; `AccountSummary` gains `pin`
  - `PopupRequest` gains `account.settings.get`, `account.settings.set`, `pin.set` and `pin.remove`, each with its `PopupReplies` entry and in `POPUP_REQUEST_KINDS`; add `AutofillFeature`, `AccountSettingsSnapshot` and `PinSetReply` (design.md)
  - `Failure` gains an optional `attemptsLeft`, and `handlePopupMessage` in `src/background/router.ts` copies it into the result; test in `router.test.ts`
- [x] 1.10 PIN unlock and purge in `src/vault/unlock.ts` and `src/accounts/store.ts`
  - `attempt()` handles the PIN method without the stale-suite refetch. A cached suite whose id or epoch differs from the record's forgets the PIN and fails `pin_key_changed`
  - Serialise attempts per account (`createKeyedQueue` in `src/queue.ts`); count the attempt in the record before the unwrap and reset the count on success; a wrong PIN fails `invalid_pin` with `attemptsLeft`, and the fifth forgets the PIN and fails `pin_forgotten`
  - Forget the PIN in `markLoggedOut`, the account purge, `checkSuite`'s changed branch and `blockUnlock`; never in `clearKey`, `lock` or `lockAll`
  - Tests: Lock now and the timeout keep the PIN; logout, a 401, removal, a new epoch and the two-factor block forget it; five wrong PINs; two attempts sent at once both count; a correct PIN after four wrong ones resets the count
- [x] 1.11 Handle the new kinds in `src/background/requests.ts` and `src/background/router.ts`
  - `account.settings.get` builds `AccountSettingsSnapshot`: stored values, `effectiveSettings`, the policy, `pin`, `autofillFeatures: AUTOFILL_FEATURES`, the `autofill_login` shortcut from `browser.commands.getAll()` (`null` when missing or empty), `serverUrl`, `webAppUrl` and the stored `keepiqVersion`; `null` for an unknown account
  - `account.settings.set` validates, patches through `updateSettings` and replies with a fresh snapshot; it replies `'locked'` unless the account is unlocked
  - `pin.set` refuses a locked account and a PIN under four characters, checks the master password against the cached suite envelope, then wraps; `pin.remove` forgets
  - `account.settings.get` is not user activity; the other new kinds are
  - The unlocked snapshot's `defaultSecretType` reads `defaultItemType`; `AccountSummary.pin` reads the PIN record
  - Tests in `requests.test.ts` and `router.test.ts`
- [x] 1.12 Run `npm test`, `npm run typecheck` and `npm run lint`

## 2. Popup: views, components and texts

- [x] 2.1 Create `hooks/useAccountSettings.ts` over `account.settings.get` and `account.settings.set` for the active account; extend `hooks/useSettings.ts` with the new global entries; tests in `hooks.test.tsx`
  - No component imports `src/api` or `src/crypto`, and none touches `browser.storage` (ADR-004)
- [x] 2.2 Open all six sections from `SettingsIndex.tsx` and route them in `Settings.tsx`; update `Settings.test.tsx`
- [x] 2.3 Create `components/PinDialog.tsx` on `ConfirmDialog`: master password and PIN twice, with "Use at least 4 characters", "PINs don't match", "Invalid master password" and a recommendation to use more than 4; test in `components.test.tsx`
- [x] 2.4 Give `components/Select.tsx` an optional `hint` shown under the control and linked with `aria-describedby`; test in `components.test.tsx`
- [x] 2.5 Create `views/settings/AccountSecurity.tsx`, with tests
  - Timeout `Select` with On system lock and Custom (hours and minutes, "Enter at least one minute", "Your organisation allows at most <duration>"); `Select` takes `T extends string`, so map each `VaultTimeout`, numbers included, to a string key such as `'15'` or `'onSystemLock'`
  - Every duration in a note or message reads like "2 h 30 min"
  - With any stored maximum: hide longer options, Never and On browser restart; show a clamped choice with the `hint` "Set by your organisation"; word On system lock with the maximum
  - Never confirmation through `ConfirmDialog` with the ADR-002 warning; the Log out action note
  - Lock now; Log out through `LogOutConfirm`; Unlock with PIN through `PinDialog` and `pin.remove`; biometrics disabled with "Not available yet"; Change master password linking to `webAppUrl`
- [x] 2.6 Change `views/Unlock.tsx`: while `active.pin`, the PIN field first with "Use master password"; "Wrong PIN, <n> attempts left"; after `pin_forgotten` or `pin_key_changed`, the master password field with that code's text; tests in `Unlock.test.tsx`
- [x] 2.7 Create `views/settings/Autofill.tsx`, with tests
  - The five entries with their warnings and notes, `OptionCheckbox` for toggles and `Select` for choices
  - Each entry whose feature is missing from `autofillFeatures` is disabled with "Coming later" and shows its stored value
  - The `autofill_login` shortcut or "Not set"; Change opens `chrome://extensions/shortcuts` with `browser.tabs.create` on Chromium, and shows the steps with a Copy control for `about:addons` on Firefox
  - The clear clipboard options
- [x] 2.8 Create `views/settings/Notifications.tsx`, with tests
  - Ask to add, Ask to update and Excluded domains, "Coming later" while `savePrompts` is missing from `autofillFeatures`
  - Excluded domains hold base domains through `baseDomain` (`src/vault/match.ts`): Add current site from `useCurrentTab()`'s URL (disabled without a host); free text lowercased and given `https://` when it has no scheme; "Already excluded", empty and unreadable entries refused; remove
  - The Keepiq on the web group with its text and an Open Keepiq link to `webAppUrl`
- [x] 2.9 Extend `views/settings/Vault.tsx`, with tests
  - Sync now through `vault.sync`, with "Last sync: <time>", "Never" and "Sync failed, you are offline"
  - Import items and Export vault links to `webAppUrl`; Export disabled with "Turned off by your organisation" while `exportDisabled`
  - "Default item type in this extension" from the cached types without `passkey`, showing Login for a missing type
  - An `ItemForm.test.tsx` case: New starts with the chosen type
- [x] 2.10 Extend `views/settings/Appearance.tsx` and `popup.css`, with tests
  - Compact mode tokens and the read-only Language line "Follows your browser"
  - Show animations off: a root class that stops transitions and animations, except the spinner, which turns slowly as under `prefers-reduced-motion`
  - Show quick copy actions off: `components/ItemCard.tsx` offers no copy menu items; the item detail keeps Copy
- [x] 2.11 Create `views/settings/about-links.ts` with `ABOUT_LINKS` as listed in design.md, and `views/settings/About.tsx`: version from `browser.runtime.getManifest()`, server address, "Keepiq <version>" or "Keepiq version unknown", Help, Report a bug, Contact support, Privacy policy, Keepiq web app, and Rate the extension hidden while the running browser's store address is `null`; tests
- [x] 2.12 Check that every new key exists in both `locales/en.yml` and `locales/nl.yml`, then run `npm test`, `npm run typecheck` and `npm run lint`

## 3. Docs and walk-through

- [x] 3.1 Update `ARCHITECTURE.md`: the module list (`src/accounts/policy.ts`, `src/vault/pin.ts`) and the storage rows for `settings.<id>`, `settings.global`, `accountPolicy.<id>` (local) and `pin.<id>` (session)
- [x] 3.2 Update ext-vault-edit's `specs/item-editing/spec.md` and `design.md`: the New form's default type is the extension's Default item type, not `default_secret_type`
- [x] 3.3 Update ext-accounts-and-unlock's `specs/vault-unlock/spec.md` (Timeout options and defaults) and `design.md` (the `TIMEOUT_POLICY` decision and the `timeout.ts` module line): the organisation maximum per account from ADR-002, no forced action
- [x] 3.4 Update `../docs/browser-extension/privacy.md` (no Keepiq preferences are read; the organisation's lock maximum is; a PIN lives in session storage; drop "when the settings screen comes") and `using.md` (a Settings section, the default type, drop "More settings" from Coming next)
- [ ] 3.5 Run `npm run build` and `npm run build:firefox`, load both builds and walk every settings view
  - Each entry persists across popup close and reopen and takes effect without reloading the extension
  - An admin maximum of 30 minutes in Keepiq's admin settings clamps the timeout after the next sync
  - A PIN survives Lock now, comes back after a browser restart once the master password unlocks, and five wrong PINs fall back to the master password
  - Export shows as turned off for a user in a group with export disabled
  - The autofill and notification-prompt entries show "Coming later"
  - `grep -rn "chrome\." src/ entrypoints/` matches only comments

## 4. PIN across restarts

### Core

- [x] 4.1 Raise `PIN_MIN_LENGTH` in `src/messages.ts` to 6, any characters; update the `enablePin` and `PinDialog` tests
- [x] 4.2 Add the seal to `src/vault/pin.ts`: `pinSeal.<accountId>` = `{ v: 1, salt, iv, ciphertext, suiteId, unlockKeyEpoch }` in `storage.local`
  - Seal key: HKDF-SHA256 over the unwrapped PKCS#8 bytes, with the record's 16-byte salt and info `"keepiq pin seal v1"`, giving a non-extractable AES-256-GCM key; AAD binds `v`, the account id, `suiteId` and `unlockKeyEpoch`
  - Derive it only from PKCS#8 bytes in hand and never store it; never use RSA-OAEP or the public key
  - `sealPin` and `openSeal`; `openSeal` returns `null` for a bad tag, a wrong `v`, or a suite or epoch other than the cached one
  - Tests: round trip; a seal encrypted to the public key, a changed AAD field, a flipped ciphertext byte and another account's seal are all refused
- [x] 4.3 `forgetPin` removes both `pin.<id>` and `pinSeal.<id>` and bumps `pinGeneration`; test every trigger in `unlock.test.ts` and `store.test.ts`: the fifth wrong PIN, `pin.remove`, logout, removal, a 401, `checkSuite`'s changed branch and `blockUnlock`
- [x] 4.4 `enablePin` in `src/vault/unlock.ts` writes the seal beside the session record under the same `pinGeneration` guard; a forget meanwhile leaves neither
- [x] 4.5 After a master-password unlock that finds a seal and no session record, rebuild after the unlock reply: open the seal with the unlocked bytes, wrap the PIN again with a fresh salt and store the session record under the generation read before opening; delete a seal that fails or is stale
  - Tests: the unlock reply does not wait for the rebuild; the same PIN then unlocks; a failed or stale seal is deleted and `pin` reads false; a forget or a new epoch during the rebuild leaves neither copy; five wrong PINs then a restart rebuild nothing
- [x] 4.6 `AccountSummary` gains `pinAfterRestart` (seal without session record); `AccountSettingsSnapshot.pin` is true while either copy exists; tests in `requests.test.ts` and `router.test.ts`

### Popup

- [x] 4.7 `views/Unlock.tsx`: while `pinAfterRestart`, the master password field with "After a browser restart you enter your master password once; then your PIN works again." and no PIN field; tests in `Unlock.test.tsx`
- [x] 4.8 `views/settings/AccountSecurity.tsx` and `components/PinDialog.tsx`: the same restart line, "Use at least 6 characters", and a hint against reusing a phone or bank PIN in place of `pinRecommend`; keys in `locales/en.yml` and `locales/nl.yml`; tests
- [ ] 4.9 Run `npm test`, `npm run typecheck` and `npm run lint`; walk a restart on Chrome and Firefox: master password once, then the PIN

### Security review

- [x] 4.10 Have the seal reviewed before merge: the key comes only from the private key, the AAD covers the account, suite and epoch, no path adopts a seal it did not open, and both copies go on every forget
