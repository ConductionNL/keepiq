Every new module, component, hook and view gets a `*.test.ts(x)` next to it. Popup tests use React Testing Library with `fakeBackground` from `entrypoints/popup/testing.ts`. Packages C, D and E run in parallel once B is in; each owns its files alone.

## 1. Contract, dependency and manifest (B, before the rest)

- [x] 1.1 Add the send types, the three `send.*` arms (`PopupRequest`, `PopupReplies`, `POPUP_REQUEST_KINDS`) and the `argon2_unavailable` write error code to `src/messages.ts`, as in design.md.
  - Every arm carries `accountId`. There is no `send.prefillFromItem`.
- [x] 1.2 Add `hash-wasm` 4.12.0, pinned exactly, and declare `content_security_policy` with `'wasm-unsafe-eval'` once in the `manifest` callback of `wxt.config.ts`.
  - No `manifestVersion` is set globally (WXT-AND-BROWSERS.md § 2).
  - `npm run build` and `npm run build:firefox` produce `.output/chrome-mv3/manifest.json` with the `extension_pages` object and `.output/firefox-mv2/manifest.json` with the string, both carrying `'wasm-unsafe-eval'`.
  - `scripts/manifest-csp.test.ts` checks the callback for both browsers.
- [x] 1.3 Write `src/send/expiry.ts` (presets to `ttlSeconds`, custom hours 1 to 720, `MAX_VIEWS_CAP`, `TTL_CAP_SECONDS`) and `src/send/payload.ts` (`serializeCredential` with fixed English labels), with tests.
  - No `browser`, i18n, crypto or network code, so the popup may import them.

## 2. Background (C)

- [x] 2.1 Write `src/send/argon2.ts`: `isArgon2Available()` (compiles the empty WASM module once, cached) and `deriveSendKey(password, salt)` with the ADR-003 parameters, returning a non-extractable AES-GCM `CryptoKey` for encrypt only.
- [x] 2.2 Write `src/send/crypto.ts` (content key, IV-prefixed AES-256-GCM blob, `wrapContentKey`) and `src/send/link.ts` (`buildShareLink(serverUrl, token, rawKey | null)`), add base64url to `src/crypto/base64.ts` and `send` to `src/testing/vectors.ts`.
  - Tests run `../tests/vectors/crypto/send.json`: decrypt `withoutPassword`, unwrap and decrypt `withPassword`, match `argon2idKnownAnswer`, and rebuild both links from `publicBase` and `token`.
  - Extension output round-trips and decrypts with plain WebCrypto.
- [x] 2.3 Write `src/send/api.ts`: `listSends`, `createSend`, `deleteSend` over the API client, checking the row shape.
- [x] 2.4 Write `src/send/handlers.ts` and dispatch `send.*` from `src/background/requests.ts`; export `run` from `src/vault/write.ts`.
  - Handlers go through `run(..., 'reply', accountId)`: `locked`, `account_changed`, `offline` via `recordOutcome`, a 400 as `invalid`, a 404 on delete as success, an HTML 404 as `not_found`, 401 by the ADR-002 logout rule.
  - `send.list` adds `link` to password rows and `passwordProtection`; a password on a create without Argon2, or an Argon2 failure, answers `argon2_unavailable`.
  - Input is checked: blank body, credential without a password, max views, and `ttlSeconds` from 3600 to the cap.
  - A lock, logout or account switch during Argon2id sends nothing (`sessionEpoch` re-check).
  - Tests copy `src/vault/write.test.ts` and the generator block in `requests.test.ts`, and assert `storage.local` and `storage.session` hold no send data after a send with and without a password.

## 3. Popup (D)

- [x] 3.1 Write `entrypoints/popup/hooks/useSends.tsx` (`SendProvider`, `useSends()`) on `request()` and `useShell().accountId`, after `useGeneratorHistory.ts`.
  - No import of `src/send/crypto.ts`, `argon2.ts`, `api.ts`, `link.ts`, `handlers.ts`, `src/crypto/` or `src/api/` under `entrypoints/popup/`.
- [x] 3.2 Write `components/SendRow.tsx` and `views/send/SendList.tsx`, `NewSend.tsx` and `SendCreated.tsx`.
  - `SendRow`: Copy link when the row has a `link` or the session `Map` holds one, otherwise "Link no longer available"; Expired and Never expires states; short 24-hour dates; copy via `useClipboard`.
  - `SendList`: `EmptyState`, `Banner` with Retry, Remove through `ConfirmDialog`, focus to the next row after a removal.
  - `NewSend`: `SubTabs` type switch, `TextArea`, `TextField` (`type="password"`, `type="number"`), `Select`, validation, offline and Argon2-unavailable disabled states, busy state reported to Shell, `useUnsavedChangesGuard`, `PageFooter`.
  - `SendCreated`: the link in a read-only `TextField` with Copy link, the note for sends with and without a password, Done.
- [x] 3.3 Wire the Send tab into `views/Shell.tsx`: `SendProvider`, `sendPage` in title, Back and page logic, leaving blocked while a create runs, the Header New button on the Send tab; add `ItemActions.send` in `shell-context.ts` and the Send action in `components/ItemCard.tsx` and `views/ItemDetail.tsx`.
  - `items.send(item)` calls `item.decrypt` with `['login','key']` and opens `NewSend` prefilled.
  - Hidden for non-login types; disabled with the reason for blocked, use-only, an expiring share and offline.
  - On a `locked` reply the popup calls `refreshState()`.
- [x] 3.4 Add every string to `locales/en.yml` and `locales/nl.yml`, the send wording to `entrypoints/popup/errors.ts`, and the styles to `popup.css`; delete `views/Placeholder.tsx` and `common.laterUpdate`, and rewrite the Shell placeholder test.

## 4. Docs (E)

- [x] 4.1 Update `ARCHITECTURE.md` (send modules, message rows, the create flow), `SECURITY.md` (Argon2id, the `wasm-unsafe-eval` CSP, where send data lives), ADR-003 (sends since 0.1.4, `hash-wasm` in the extension), and `../docs/browser-extension/using.md` and `privacy.md` (a Send section, encrypted sends).

## 5. Verification

- [ ] 5.1 Manually verify against the dev backend: create a text send, a password send and a credential send from the extension, open each link in the web app's public page, confirm decryption, the view counter, the password prompt and burn at the last view; copy a password send's link after reopening the popup; remove a send and confirm its link is gone.
  - Inspect `storage.local` and `storage.session` after the flow: no send content, key or link present.
- [ ] 5.2 Run `npm test`, `npm run typecheck`, `npm run lint`, `npm run build` and `npm run build:firefox`, then load `.output/chrome-mv3/` in Chrome and `.output/firefox-mv2/manifest.json` in Firefox and exercise the Send tab in both.
  - `grep -rn "chrome\." src/ entrypoints/` matches only prose.
