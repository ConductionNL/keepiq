## 1. Schema and store

- [ ] 1.1 Create `src/settings/schema.ts` with the `SETTINGS` table (key, scope, default, validator) and the derived `SettingsValues` type for every entry in the spec
  - Defaults match the spec: timeout 15 minutes, action Lock, clipboard Never, theme System default, autofill on page load off
  - Each validator returns the default for an unknown or malformed value
- [ ] 1.2 Create `src/settings/store.ts` reading and writing `settings:<accountId>` and `settings:global` in `storage.local`, patching rather than replacing, and deleting the account record on account removal
- [ ] 1.3 Create `src/settings/policy.ts` with `TimeoutPolicy`, `NO_POLICY` and `applyPolicy` clamping the timeout and forcing the action without rewriting the stored value

## 2. Messages and background

- [ ] 2.1 Add `settings.get`, `settings.set`, `settings.server.set`, `pin.enable`, `pin.disable`, `pin.unlock`, `about.get` and the `SettingsSnapshot`, `ServerSettings`, `PinUnlockResult`, `AboutInfo` types to `src/messages.ts`
- [ ] 2.2 Handle the new kinds in `entrypoints/background.ts` and feed the ADR-002 timeout config from the store through `applyPolicy` on every read
  - Request/response arms return the Promise; nothing new is fire-and-forget
  - The clipboard clear delay is read by ext-vault-browse through the same snapshot

## 3. PIN unlock

- [ ] 3.1 Create `src/settings/pin.ts`: PBKDF2-SHA256 600 000 iterations with a random 16-byte salt, AES-256-GCM wrap of the PKCS#8 bytes, envelope codec, and a placement helper choosing `storage.session`, `storage.local` or the Firefox memory fallback
- [ ] 3.2 Implement `pin.enable`, `pin.disable` and `pin.unlock` in the background with the attempt counter stored beside the blob, purge of blob and counter on lock, timeout, logout, account removal, suite change and the fifth wrong PIN
- [ ] 3.3 Change ext-accounts-and-unlock's `Unlock` view component (`entrypoints/popup/views/Unlock.tsx`) to render a PIN field and "Use master password" link when `useSettings` reports `pin.enabled`, sending `pin.unlock`, with the "PIN disabled after too many attempts" state

## 4. Server-mirrored preferences

- [ ] 4.1 Create `src/settings/server-mirror.ts` for `GET/PUT /api/settings/user`: string encoding of toggles, cache with `fetchedAt` in the account record, stale computation, refresh at the end of the ext-vault-browse sync and on Sync now, online-only writes that store the echoed set

## 5. Popup components, hooks and views

- [ ] 5.1 Create the shared components under `entrypoints/popup/components/`: `SettingRow.tsx` (label, description, control slot), `Toggle.tsx`, `Select.tsx`, `SectionList.tsx`, `PinDialog.tsx`, `WarningDialog.tsx`
  - No component imports `src/api` or `src/crypto` (ADR-004)
- [ ] 5.2 Create the hooks under `entrypoints/popup/hooks/`: `useSettings.ts` over `settings.get` and `settings.set` with `applyPolicy` applied to the returned values, `useServerSettings.ts` with the stale indicator and disabled-offline state over `settings.server.set`, `useTheme.ts` applying the global theme to the document root from `<App />` so unlock and account screens are themed
- [ ] 5.3 Create `entrypoints/popup/views/settings/SettingsIndex.tsx` with the six-section `SectionList`, per-section navigation and back control, wired into the Settings tab of the popup shell
- [ ] 5.4 Create `AccountSecurity.tsx`: timeout options with Custom entry and policy-aware hiding, Never `WarningDialog`, action with Log out note, Lock now, Log out with confirmation, PIN toggle with `PinDialog` and the on-disk `WarningDialog`, biometrics disabled, Change master password link, `session_timeout` shown read-only as "Web app default"
- [ ] 5.5 Create `Autofill.tsx`: the five entries with their warnings and notes, the inline-menu "Coming later" state from `capabilities.inlineMenu`, shortcut display from the snapshot's `shortcut` with the Chromium link and the Firefox instructions, clear clipboard options
- [ ] 5.6 Create `Notifications.tsx`: Ask to add, Ask to update, Excluded domains with Add current site via `activeTab`, free-text add, duplicate and empty rejection, remove; the Keepiq server notifications subsection driven by `useServerSettings` with revert-on-error behaviour
- [ ] 5.7 Create `Vault.tsx`: Sync now with Last sync and the offline failure message, Folders entry opening the ext-vault-edit folder manager, Import and Export links to the web app, Default item type from cached types with the `login` fallback and note
- [ ] 5.8 Create `Appearance.tsx` and extend `entrypoints/popup/popup.css`: theme tokens under `[data-theme]`, compact mode tokens, show animations class, quick copy actions toggle, read-only Language line
- [ ] 5.9 Create `About.tsx`: version and server origin from `about.get`, Help, Report a bug, Privacy policy, Keepiq web app links, Rate the extension hidden while the store constant is `<store-url>`

## 6. Verification

- [ ] 6.1 Add `vitest` with unit tests for schema validation fallbacks, `applyPolicy` clamping and a PIN wrap and unwrap round trip including a wrong-PIN failure
- [ ] 6.2 Run `npm run typecheck`, `npm run lint`, `npm run build` and `npm run build:firefox`, load `.output/chrome-mv3/` and `.output/firefox-mv2/` in both browsers and walk every settings view
  - Each toggle persists across popup close and reopen and takes effect without reloading the extension
  - PIN unlock works, the fifth wrong PIN reverts to master password, and the blob is gone after Lock now
  - Server toggles show the stale indicator with the network disabled and write when online
  - `grep -rn "chrome\." src/ entrypoints/` matches only comments
