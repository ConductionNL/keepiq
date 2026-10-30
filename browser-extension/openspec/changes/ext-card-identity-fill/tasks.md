## 1. Fields and formatting

- [ ] 1.1 Extend `src/autofill/fields.ts`: the card and identity roles of `specs/card-identity-fill` in `classifyField()` (tokens, type, name, id, label, placeholder, `maxlength`, `inputmode`, English and Dutch), the split number, the card guard (a card field never takes a login role; a group whose only password fields are card fields is `other`), `isQualifying()` returning `'login' | 'card' | 'identity' | null` (identity only with two identity roles in the group, never in a login group), and `fillSelect()`
  - Tests: tokens, Dutch labels without tokens, a `type=password` CVV next to a card number (no login role, no login list), a login form with an email username (no identity role), a sign-up email username (`email`), a newsletter email (no list), a "PIN" field (no role), a four-part number, month and year `<select>`s
  - The existing login fixtures in `fields.test.ts` still pass unchanged
- [ ] 1.2 Add `src/autofill/format.ts` (content): expiry patterns from placeholder or label, else by `maxlength`, else `MM/YY`; month and year forms for `<input>` and `<select>` (English and Dutch month names and abbreviations); split number by `maxlength`, nothing when the digits do not fit; brand aliases
  - Tests: every form in the spec's Expiry formatting, "MM / YYYY", "MMJJ", a select without a matching option, a 15-digit Amex over 4-6-5 fields
- [ ] 1.3 Capture: `src/autofill/capture-collector.ts` never reports a card-role field as a username or password
  - Tests: a submitted checkout with a `type=password` CVV sends no `capture.submitted`

## 2. Background pipeline

- [ ] 2.1 Add `src/autofill/wallet.ts`: `walletKind()` by system type, `walletCandidates()` (no site matching, no blocked rows, last used then name), `parseExpiry()`, `membersFor()` (never `pin` or `bsn`), `isInsecureUrl()` (loopback counts secure)
  - Tests: a user type named "card" is not a card; every accepted expiry form, `13/27` and "end of 2027" give `null`; `membersFor()` returns only the asked roles' values and no PIN or BSN for any roles
- [ ] 2.2 Add `FormKind`, `FormRole`, `FormValues`, `FormConfirmation`, `FormQuestion`, `fill.probe`, `fill.executeForm`, `fill.confirmForm`, `confirmed` on `fill.request` and `inline.pick`, `needsFormConfirm`, `list` on `inline.open` and `InlineStateReply`, and `detail` on `InlineRow` to `src/messages.ts`; answer the three new kinds in `src/autofill/execute.ts` (origin and anchor checks as `fill.execute`, values dropped after writing, `confirm()` only in the top frame)
  - `fill.probe` replies roles only and dispatches no event in the page
  - Ends with `npm run build` and `npm run build:firefox`
- [ ] 2.3 Add the wallet branch to `fill()` in `src/autofill/fill.ts`: probe the top frame and recorded `http(s)` frames in parallel (500 ms each; top frame only without a record), frame plan by base domain, `needsFormConfirm` before any decrypt, re-probe and re-check on a confirmed request, one decrypt of `key`, one `fill.executeForm` per frame with only its members, last used after a fill
  - Tests: name in the top frame and card in a same-site frame without a question; a cross-site frame asked about, "Only {host}" sends it nothing; a host added after the answer is asked about; an insecure frame asked about for a card and not for an identity; lock between probe and execute decrypts nothing; no message to a content script carries `pin`, `bsn` or another frame's members

## 3. Surfaces

- [ ] 3.1 Settings: `inlineCards`, `inlineIdentities`, `vaultCards`, `vaultIdentities` (per account, default `true`) in `src/accounts/settings.ts`, `cards` and `identities` in `AUTOFILL_FEATURES`, and the four entries in the Autofill view, the first two disabled while `inlineSuggestions` is off; keys in `locales/en.yml` and `locales/nl.yml`
  - Check the four English labels against Bitwarden's `apps/browser/src/_locales/en/messages.json` (`showInlineMenuCardsLabel`, `showInlineMenuIdentitiesLabel`, `showCardsInVaultViewV2`, `showIdentitiesInVaultViewV2`) and adopt its wording where it differs, updating the specs
- [ ] 3.2 Popup (React, ADR-004): `snapshot()` in `src/background/requests.ts` appends cards and then identities to the suggestion ids per `vaultCards` and `vaultIdentities`; `useFill` asks the insecure and then the cross-site question through `ConfirmDialog`, which gains an optional secondary action for "Only {host}"; `ItemDetail` shows Fill for a non-blocked card or identity on an `http(s)` tab
  - No component imports `src/api` or `src/crypto`
  - Ends with `npm run build` and `npm run build:firefox`
- [ ] 3.3 Inline list: `inline.open` with the list kind from `isQualifying()`; the background opens a card or identity list per `inlineSuggestions` and `inlineCards` or `inlineIdentities`, with height one row when locked and `INLINE_WALLET_ROWS` otherwise, without vault work; `inline.state` builds `detail` (brand and last four, full name or email; none for use-only copies); picks go through the wallet branch with `anchorKey` and ask `needsFormConfirm` in the list; texts and announcements per kind
  - Tests: the card list's height is the same with zero, one and eight cards; the locked list lists, counts and decrypts nothing; a use-only card's row shows its name only
  - Ends with `npm run build` and `npm run build:firefox`
- [ ] 3.4 Context menu in `src/menus.ts`: "Autofill card" and "Autofill identity" submenus (at most 10, last used then name, then "Open Keepiq"), left out when the vault has none, filling through the wallet branch with `fill.confirmForm` for the questions
  - Ends with `npm run build` and `npm run build:firefox`

## 4. Test site and e2e

- [ ] 4.1 Add one test-site page per fill case and link each from `test-site/index.html`:
  - `card.html`: a checkout with `cc-*` tokens and a `type=password` CVC
  - `card-split.html`: month and year `<select>`s, a brand `<select>`, a four-part number and a combined "MM / YYYY" field
  - `card-dutch.html`: Dutch labels without tokens and an "MMJJ" expiry
  - `card-iframe.html`: "Name on card" in the page, the card fields in a same-site frame and in a cross-site frame on `127.0.0.1`, and a button that adds a cross-site frame after load
  - `identity.html`: an address form (name parts, email, phone, a `street-address` textarea, `address-line1`) and a "Full name" form
  - `identity-signup.html`: a sign-up form with first name, an email username and a new password, beside a login form and a newsletter email
- [ ] 4.2 Extend `npm run test:e2e` (Playwright, headless Chromium) over those pages: fill from the card list, the cross-site question and "Only {host}", the frame added after the answer, the insecure question on a host mapped to `127.0.0.1` with `--host-resolver-rules`, no login list or save bar on the CVC, no list on the newsletter email

## 5. Finish

- [ ] 5.1 Update ADR-002 (the inline list may show a card's brand and last four and an identity's full name or email), `ARCHITECTURE.md` (messages, modules, the `inlineSession` kind), `SECURITY.md` (per-frame members, the two questions, PIN and BSN never filled), `../docs/browser-extension/using.md` and `privacy.md`; run `npm test`, `npm run typecheck`, `npm run lint`, `npm run build` and `npm run build:firefox`
  - `grep -rn "chrome\." src/ entrypoints/` matches only comments
  - No new permission in either built manifest
- [ ] 5.2 Manual: load both builds and walk every scenario of `specs/card-identity-fill` on the test site, the popup, the inline list (keyboard and NVDA or VoiceOver), and the context menu; on Firefox also the card list in the top frame and the cross-site question
