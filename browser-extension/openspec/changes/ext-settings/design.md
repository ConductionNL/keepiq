## Context

ext-accounts-and-unlock stores each account's timeout and action in `settings.<accountId>` (`src/accounts/settings.ts`) and enforces them in `src/vault/timeout.ts`. ext-vault-browse owns sync and the clipboard clear, which already reads `clearClipboardMs` from the same record. ext-generator fetches `GET /api/settings/policy` on every sync and login and stores it per account. ext-theme built the section list, Appearance, `settings.global` and `settings.get`/`settings.set`. ext-i18n is live. This change fills in the remaining views, the rest of the per-account record, the organisation idle maximum and PIN unlock. The behaviours behind most Autofill and Notifications entries arrive with ext-autofill; this change only stores and shows the values.

Server facts, verified in the Keepiq app (ADR-003): `ExtensionController::policy` answers `{maxIdleMinutes}`, one of 1, 5, 15, 30, 60 or 240, and 240 when the admin set none. `GET /api/settings/policy` carries `vault_export_disabled` for the calling user. Both shipped in 0.3.8. The web app's Personal settings, import and export are dialogs with no URL of their own.

## Goals / Non-Goals

**Goals:**
- One validated per-account record that every change reads through, written by patch.
- The organisation's idle maximum obeyed as ADR-002 describes, offline included.
- PIN unlock that survives a browser restart after one master-password unlock, with nothing on disk that opens the private key for less than the master password.
- Bitwarden's Settings tab structure and defaults (ADR-001), with the deviations in the proposal.

**Non-Goals:**
- Timeout enforcement beyond the clamp (ext-accounts-and-unlock).
- Autofill, inline menu, context menu and the save prompts (ext-autofill).
- Folder CRUD (ext-vault-edit), in-extension import or export, biometrics.
- Any of Keepiq's own user preferences (`/api/settings/user`).

## Decisions

- **Extend `src/accounts/settings.ts`, no new schema module.** `AccountSettings` gains every account-scoped entry, each with a default and a validator in one table; `readSettings` validates per key. `clearClipboardMs` keeps its name and unit (milliseconds, `0` is Never). The background's `clipboard.copied` handler reads it through `readSettings` and passes it to `scheduleClipboardClear(ms)`, so `src/clipboard.ts` (in the offscreen bundle) stays free of the settings code and `tldts`. Global entries (compact mode, animations, quick copy) join `GlobalSettings` in `src/settings/global.ts`.
- **Writes patch.** `writeSettings` and `updateSettings(accountId, patch)` read, merge and write, so a key a write does not name survives. Every write still goes through `updateSettings`, which calls `syncNeverLockKey`.
- **The organisation policy is stored like the generator's, but outlives a logout.** `src/accounts/policy.ts` keeps `accountPolicy.<accountId>` = `{ maxIdleMinutes: number | null; exportDisabled: boolean }`, initially `null` and `false`, in `storage.local`.
  - `src/vault/sync.ts` fetches `GET /api/v1/extension/policy` beside `fetchPolicy` in `run()` and `refreshPolicy()`.
  - `fetchPolicy` returns `{ generator, exportDisabled }`, reading `vault_export_disabled` whatever `policy_enabled` says.
  - A failed request, a `maxIdleMinutes` that is not a positive whole number, or a missing or non-boolean `vault_export_disabled` keeps that half as stored.
  - Writes go through `storePolicy`'s logged-in guard and are followed by `syncNeverLockKey`.
  - The record survives `markLoggedOut`, so a new login is never unclamped. It goes in the account purge, and in `undoWrites` only when the account record itself is gone.
- **`effectiveSettings(accountId)` applies the stored maximum.** It returns `Promise<AccountSettings & { maxIdleMinutes: number | null }>`, and `TIMEOUT_POLICY` and `forcedAction` go. A number becomes the lower of itself and the maximum, Never and On browser restart become the maximum, and Immediately and On system lock stay while `enforce()` also locks them at the maximum, so a pop-out window kept open cannot outlast it. Keepiq has no forced action.
- **PIN session copy in `src/vault/pin.ts`.** It derives the wrapping key with `deriveUnlockKey` (PBKDF2-SHA256, 600 000 iterations, `src/crypto/kdf.ts`) from a random 16-byte salt and stores the wrapped PKCS#8 bytes with `encodeEnvelope`/`decodeEnvelope` (`src/crypto/envelope.ts`). The record `pin.<accountId>` = `{ envelope, suiteId, unlockKeyEpoch, failures }` is stored as a JSON string through `sessionValues` (`src/vault/key-store.ts`), which takes strings only and already falls back to background memory on Firefox below 115.
- **Setting a PIN proves the master password.** `pin.set` decrypts the cached suite envelope with the master password, the same check unlock makes, and wraps the bytes that come out.
- **PIN unlock is an `UnlockMethod`.** `{ type: 'pin'; pin }` runs through `attempt()` in `src/vault/unlock.ts`, skipping the stale-suite refetch, since a failed unwrap is a wrong PIN.
  - Attempts on one account are serialised. Each is counted in the record before the unwrap, and the count resets on success, so concurrent tries cannot outrun the limit.
  - A wrong PIN fails `invalid_pin` with `attemptsLeft`; the fifth forgets the PIN and fails `pin_forgotten`. A cached suite whose id or epoch differs from the record's forgets the PIN and fails `pin_key_changed`.
  - `Failure` (`src/failure.ts`) gains an optional `attemptsLeft`, which `handlePopupMessage` copies into the result.
- **The PIN survives a restart as a seal in `storage.local`.** `pinSeal.<accountId>` = `{ v: 1, salt, iv, ciphertext, suiteId, unlockKeyEpoch }` holds the PIN under AES-256-GCM.
  - The seal key is HKDF-SHA256 over the unwrapped PKCS#8 bytes, with the record's salt and info `"keepiq pin seal v1"`. The AAD binds `v`, the account id, `suiteId` and `unlockKeyEpoch`.
  - The key is derived only while the PKCS#8 bytes are in hand, in `enablePin` and in the master-password unlock, and is never stored. Never seal with RSA-OAEP or the public key: anyone who can write storage could forge such a seal, and the next master-password unlock would adopt their PIN.
  - `enablePin` writes the seal beside the session copy, under the same `pinGeneration` guard.
  - A master-password unlock that finds a seal and no session copy rebuilds after the unlock reply, so the 600 000-iteration PBKDF2 does not slow the unlock. It opens the seal, checks the suite and epoch, wraps again with a fresh salt and stores the session copy under the generation it read first. A seal that fails to open or is stale is deleted.
  - `PIN_MIN_LENGTH` is 6, any characters.
- **The PIN purge never hooks `clearKey`,** which every lock runs. `forgetPin` removes both copies. It is called from `markLoggedOut` (the timeout action, a 401), the account purge (Log out, removal), `checkSuite`'s changed branch, `blockUnlock`, `pin.remove` and the fifth wrong PIN. A browser restart or extension reload clears only the session copy.
- **Account settings get their own messages.** `settings.get` and `settings.set` stay global, because the theme reads them before an account exists. `account.settings.get` and `account.settings.set` carry the account id the popup shows, like `VaultWrite`.
- **Default item type is local.** It is stored as a type name in `settings.<accountId>`, default `login`. The snapshot reply's `defaultSecretType` reads it, so ext-vault-edit's New form is unchanged. The `GET /api/settings/user` read in `src/vault/sync.ts` and `VaultSnapshot.defaultSecretType` go. Revisit this if a Keepiq release lets an admin set a default type; that release gets a `KEEPIQ_FEATURES` gate.
- **Links open the web app root.** They open `webAppUrl` (`${serverUrl}/index.php/apps/keepiq/`). `serverUrl` keeps a subfolder, and the `index.php` form works with and without pretty URLs.
- **Views reuse the popup's components.** They use `OptionCheckbox` for toggles, `Select` for choices, `ConfirmDialog` for the Never warning, and `LogOutConfirm` for Log out. `PinDialog` is the only new component: master password and PIN twice in a `ConfirmDialog`.
  - `Select` gains a `hint` prop for "Set by your organisation". It takes `T extends string`, so the timeout control maps each `VaultTimeout`, numbers included, to a string key such as `'15'` or `'onSystemLock'`.
- **Autofill entries wait for ext-autofill.** `AUTOFILL_FEATURES` in `src/accounts/settings.ts` lists the delivered behaviours (`pageLoad`, `uriMatch`, `inlineSuggestions`, `contextMenu`, `savePrompts`) and is empty here. The snapshot carries it, and each entry whose behaviour is missing renders disabled with "Coming later".
- **Keyboard shortcut is read, not owned.** The background reads the `autofill_login` command from `browser.commands.getAll()`, `null` when it is missing or its shortcut is empty. Chromium opens `chrome://extensions/shortcuts` with `browser.tabs.create`, and Firefox gets instructions, detected with `import.meta.env.BROWSER === 'firefox'` (vitest can set `FIREFOX` only as a string).
- **Excluded domains hold base domains.** Entries go through `baseDomain` in `src/vault/match.ts`, the public-suffix rule ext-autofill matches and excludes with. Free text is lowercased and given `https://` when it has no scheme, so a host or URL reduces the same way. The background validator refuses any entry that isn't its own base domain. Add current site uses the URL from `useCurrentTab()`; the `tabs` permission is already declared.
- **About** reads the extension version with `browser.runtime.getManifest()` in the view, and the server's Keepiq version as the raw string from the account snapshot. Its addresses are one constant, `ABOUT_LINKS` in `entrypoints/popup/views/settings/about-links.ts`:
  - Help: `https://keepiq.conduction.nl/`
  - Report a bug: `https://github.com/ConductionNL/keepiq/issues/new?template=bug-report.yml`
  - Contact support: `mailto:support@conduction.nl`
  - Privacy policy: `https://keepiq.conduction.nl/docs/browser-extension/privacy/`, published from `../docs/browser-extension/privacy.md`
  - Keepiq web app: the account's `webAppUrl`
  - Rate the extension: per store, `null` until publication
- **Texts are codes until the popup.** Every string is an `i18n.t()` key in `locales/en.yml` and `locales/nl.yml`; the background returns codes.

## Module layout

Core:
- `src/accounts/settings.ts`: the full `AccountSettings`, validators, patch writes, `effectiveSettings` with the maximum, `AUTOFILL_FEATURES`.
- `src/accounts/policy.ts` (new): read, patch and clear `accountPolicy.<accountId>`.
- `src/accounts/store.ts`: `updateSettings` takes a patch; `markLoggedOut` forgets the PIN; the purge forgets the PIN and the policy.
- `src/failure.ts`: optional `attemptsLeft`.
- `src/vault/pin.ts` (new): derive, wrap, unwrap, the session record and its failure count; the seal, its rebuild and `forgetPin` over both copies.
- `src/vault/unlock.ts`: the PIN method; PIN purge in `checkSuite` and `blockUnlock`.
- `src/vault/timeout.ts`: On system lock at the maximum.
- `src/vault/sync.ts`: the extension policy fetch, `fetchPolicy` returning `{ generator, exportDisabled }`, no `/api/settings/user`.
- `src/vault/types.ts`: drop `defaultSecretType`.
- `src/clipboard.ts`: `scheduleClipboardClear(ms)` takes the delay; the background reads it.
- `src/settings/global.ts`: the new global entries.
- `src/messages.ts`, `src/background/requests.ts`, `src/background/router.ts`: the contract below. `URI_MATCHES` and `CLEAR_CLIPBOARD_OPTIONS` live in `src/messages.ts`, like `THEMES`, so the popup lists them without the settings module's storage code.

Popup:
- `views/settings/AccountSecurity.tsx`, `Autofill.tsx`, `Notifications.tsx`, `ExcludedDomains.tsx`, `About.tsx` (new); `views/settings/timeout.ts` (timeout keys and durations) and `domains.ts` (excluded-domain rules, loaded lazily for the public suffix list) (new); `Vault.tsx`, `Appearance.tsx`, `Settings.tsx`, `SettingsIndex.tsx` (edited).
- `views/Unlock.tsx`: PIN field first, "Use master password", attempts left; the restart line while only the seal exists.
- `components/PinDialog.tsx`, `hooks/useAccountSettings.ts`, `views/settings/about-links.ts` (new); `components/Select.tsx` (`hint`); `components/ItemCard.tsx` (quick copy off); `hooks/useSettings.ts`: `SettingsProvider` in `<App />` replaces `useTheme`, one read that applies and mirrors the theme and appearance for every screen.
- `popup.css`: compact tokens, no-animation class.
- `locales/en.yml`, `locales/nl.yml`.

## Message contract

```ts
export type UnlockMethod =
	| { type: 'masterPassword'; masterPassword: string }
	| { type: 'pin'; pin: string }

// ErrorCode gains 'invalid_pin' (carries attemptsLeft), 'pin_forgotten' (the fifth wrong PIN) and 'pin_key_changed'.
export type Result = { ok: true; state: PopupState } | { ok: false; code: ErrorCode; attemptsLeft?: number }

export interface AccountSummary {
	// existing fields
	pin: boolean                // the session copy exists, so the PIN field can work
	pinAfterRestart: boolean    // only the seal exists: master password once, then the PIN
}

// Added to PopupRequest, with these PopupReplies:
//   'account.settings.get' → AccountSettingsSnapshot | null (null: unknown account)
//   'account.settings.set' → AccountSettingsSnapshot | 'locked' | null ('locked' unless the account is unlocked; null: unknown account)
//   'pin.set' → PinSetReply, 'pin.remove' → null
| { kind: 'account.settings.get'; accountId: string }
| { kind: 'account.settings.set'; accountId: string; patch: Partial<AccountSettings> }
| { kind: 'pin.set'; accountId: string; pin: string; masterPassword: string }
| { kind: 'pin.remove'; accountId: string }

export interface AccountSettingsSnapshot {
	accountId: string
	values: AccountSettings           // as stored, validated
	effectiveTimeout: VaultTimeout    // after the organisation maximum
	maxIdleMinutes: number | null     // null until first read
	exportDisabled: boolean
	pin: boolean                        // either copy exists
	autofillFeatures: AutofillFeature[] // AUTOFILL_FEATURES; empty until ext-autofill
	shortcut: string | null             // of `autofill_login`
	serverUrl: string
	webAppUrl: string
	keepiqVersion: string | null      // raw, for display only
}

export type PinSetReply = { ok: true } | { ok: false; code: 'invalid_master_password' | 'pin_too_short' | 'locked' }
```

`account.settings.get` and `settings.get` are not user activity; the other new kinds are.

## Risks / Trade-offs

- [The server answers 240 when the admin set nothing] → Accepted: the cap applies to everyone, so Never and On browser restart are offered only before the first read.
- [A stored choice above a lowered maximum looks lost] → It stays stored and is shown clamped with "Set by your organisation"; a higher maximum restores it.
- [A maximum arrives while the Never key is on disk] → `syncNeverLockKey` runs after every policy write.
- [Anyone with the running browser can read the session copy, on Firefox through about:debugging, and try PINs offline; a 6-digit PIN falls within minutes on a GPU] → The five-try limit binds the unlock screen only. The minimum is 6 characters of any kind, and the dialog advises against a reused phone or bank PIN.
- [A forged seal would plant the forger's PIN] → The seal key comes from the private key, which only the master password opens, and GCM's tag rejects anything else (ADR-002).
- [Someone with write access to `storage.local` restores an old seal after five wrong PINs or after the PIN was turned off, and the next master-password unlock re-enables it] → Accepted: that attacker could already set the timeout to Never, so the seal adds no new power; a seal for a stale suite or epoch is deleted.
- [Whoever opens the private key can read the PIN] → Accepted: they already hold the vault; the dialog advises against reusing a PIN from elsewhere.
- [An MV3 worker restart mid-unlock loses an in-memory failure count] → The count lives in the session record and is written before the unwrap.
- [A stored default type is gone from the server] → Read as Login, never written back unprompted.
- [Firefox cannot open `about:addons` from `tabs.create`] → Instructions plus a Copy control.

## Open Questions

- Store URLs for the Chrome Web Store and AMO are unknown until publication.
