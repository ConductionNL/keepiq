## Context

ext-autofill built the login fill pipeline: `classifyField()` in `src/autofill/fields.ts`, the frame registry from `page_ready` in `src/autofill/frames.ts`, the orchestrator `fill()` in `src/autofill/fill.ts`, the inline list (`inline-session.ts`, `inline-host.ts`, `entrypoints/inline-list/`), the context menu in `src/menus.ts`, `useFill` in the popup and `lastUsed.<id>`. Card and identity items already render and edit in the popup; `src/vault/payloads.ts` reads their `key` JSON (`parsePayload`, `text`, `cardBrand`, `cardLast4`) as the web app does: a card is `{number, expiry, cvv, pin, cardholder}`, an identity `{firstName, lastName, address, phone, email, bsn}`, both every member a string and `expiry` free text hinted `MM/YY` (Keepiq `card-identity-items`, web app `src/cardIdentity/cardIdentity.js`).

Constraints: one item per fill to the content script and nothing else (ADR-002, config rule); every API on Chrome MV3 and Firefox MV2; the popup is React, injected pages vanilla DOM (ADR-004); no request reveals the vault (ADR-002, ADR-003).

## Goals / Non-Goals

**Goals:**
- Fill a card or identity from the popup, the inline list and the context menu, through one orchestrator.
- Detect card and identity fields, split and combined, in English and Dutch, and format values per field.
- Give each frame only the members its fields need; ask before a card reaches an insecure frame or any item reaches a cross-site frame.
- Keep the page from learning anything about the user's cards and identities before a pick.

**Non-Goals:**
- Capturing a submitted card or identity; "New card" and "New identity" entries; master password re-prompt (`ISSUE-item-reprompt`); identity members Keepiq does not store (`ISSUE-identity-fields`).
- An inline list inside frames with a cross-origin ancestor (ext-autofill's frame rule stays).
- Taking Chrome's card autofill in the default password manager entry (Open Questions).

## Decisions

- **Kind from the cached type, not the name.** `walletKind(row, types)` in `src/autofill/wallet.ts` is `card` or `identity` when the row's `typeId` is the system type of that name in the cached types, so a user type called "card" is not one.
- **Not matched by site.** Cards and identities are candidates on every `http(s)` page, as Bitwarden lists them; `walletCandidates(kind, snapshot, lastUsed)` drops blocked rows and sorts by last used then name. `candidatesFor()` stays logins only, so the toolbar badge keeps counting logins.
- **Roles extend `classifyField()`, not a second classifier.** `FieldRole` gains the card and identity roles of the spec; the same signals and precedence apply, with the card guard first: a field with card evidence never takes a login role, and a group whose only password fields are card fields is `other`. One classifier keeps the inline list, fill and capture in agreement, as ext-autofill decided for logins.
- **Card wording needs company.** A login token (`username`, `email`, `one-time-code` and the like) on a field stops card wording on it, so a "Security code" one-time-code field stays a code and "Library card number" stays a login's username. Wording alone counts only beside a `cc-*` token, a second strong card role or a split number, or as "Name on card" or a password-typed CVC/CVV/CSC; a group with a login password takes no card role from wording unless two strong roles agree.
- **Card and identity roles need a field the user can see.** A stricter test than for logins (`isSeen` in `fields.ts`) applies to both the probe and the write: no `aria-hidden` or `inert` ancestor, opacity of at least 0.1 across the ancestors, at least 10 px on each side, not clipped, and in the viewport the field must be the one a hit test finds at its centre, looking through its own labels and the Keepiq list. The rule is reachable by scrolling = seen: a scrolling box counts as its own box, which must be at least 10 px on each side; a field in a box that hides its overflow must lie inside it; a `position: fixed` field, and any field on a page whose root or body hides its overflow on an axis (the viewport's), counts only within the viewport on that axis; a field in the viewport scrolled out of an uncovered scrolling box is judged by that reach, not by the hit test. An overlay inside the same scrolling box that scrolls along over a clipped field is not caught, as with any field below the fold. A page can still draw an in-view field the user cannot see; the test stops accidental and honeypot fills, not a determined page.
- **`isQualifying(field)` returns the list kind** (`'login' | 'code' | 'card' | 'identity' | null`); a field qualifies for at most one, login first, then card, then code, then identity. `'code'` is the one-time-code list of ext-totp-autofill, which the design had not listed. Identity needs two identity roles in the group, and an identity fill uses the first group with two, else the first with any.
- **One mode, not a second field.** `InlineMode` is widened to `'login' | 'code' | 'card' | 'identity'` instead of a parallel `list`, so a combination like mode `code` with list `card` cannot exist.
- **Probe, then send per frame.** A wallet fill sends `fill.probe { kind, anchorKey? }` to the top frame and each recorded `http(s)` frame in parallel, skipping a frame that does not reply within 500 ms; each replies with the roles of its chosen group. Without a registry record (the MV3 worker restarted) only the top frame is probed, as for logins. Bitwarden collects page details from every frame the same way; the difference is that values go out per frame.
- **Frame trust by base domain.** A frame on the top frame's base domain (`baseDomain()` of `src/vault/match.ts`) takes part silently, matching the login rule; any other needs the cross-site answer naming its host. Hosts come from the registry's `sender.url`, never from page content.
- **Questions before decryption.** `fill()` computes `{ insecure, frameHosts }` from the probe; when either applies and the request has no matching confirmation, it replies `needsFormConfirm` and decrypts nothing. The confirmed request is re-probed and re-checked, so a frame added in between is asked about again. The background keeps no state between the two requests. An answer carries the top frame's origin and counts as no answer for any other origin, so a "yes" given for one site cannot be replayed on another, or on the same host over another scheme or port.
- **Insecure means not a secure context.** `http:` except `localhost`, `*.localhost`, `127.0.0.0/8` and `[::1]`, which browsers treat as secure, so the test site at `http://localhost:8100` fills without a question. The e2e test reaches an insecure host through Chromium's `--host-resolver-rules`.
- **One decrypt, members computed in the background.** After the questions, `fill()` decrypts `key` once, `parsePayload()` reads it, and `membersFor(kind, payload, roles)` returns only the values for those roles: `number` as digits, `expMonth` as two digits and `expYear` as four from `parseExpiry()`, `brand` from `cardBrand()`, `fullName`, `streetAddress` and `addressLine1` derived. `pin` and `bsn` are never read into a value. The content script formats per field (`src/autofill/format.ts`), since only it sees placeholders, `maxlength` and options.
- **`<select>` filling.** `fillSelect(select, candidates)` picks the first option whose trimmed value or text matches a candidate case-insensitively, sets `selected`, then dispatches `input` and `change`, as `fillField()` does for inputs.
- **Popup questions through `ConfirmDialog`.** `useFill` shows the insecure question, then the cross-site one; `ConfirmDialog` (ext-vault-edit) already takes several actions, so "Only {host}" is a second action with no change to it. The cross-site question counts Fill and "Only {host}" only 500 ms after it appeared (the passkey window's guard), so one double click cannot answer both questions; Cancel and Escape count at once. The confirmed `fill.request` carries `confirmed: { insecure?: true, frames: 'all' | 'sameSite', frameHosts, origin }`, built by `answered(previous, question)` in `src/autofill/form-answers.ts` from the question's `origin`; `answeredOnlyHost()` sets `frames: 'sameSite'`, and a later answer keeps an earlier `'sameSite'`, so "Only {host}" is never widened. `form-answers.ts` stands apart from `wallet.ts` so the list page does not load the public suffix list.
- **Context menu questions in the page.** For a menu fill the background sends `fill.confirmForm { insecure, host, frameHosts }` to the top frame, which asks with `window.confirm()` in turn and answers one boolean; Cancel at either cancels. `confirm()` has two buttons, so "Only {host}" exists only in the popup and the list. As for logins, a spoofed answer can only make the user be asked, never release a member without the background's own check.
- **Inline list per kind.** `inline.open` takes the widened `mode`; the session in `inlineSession.<tabId>` stores it. For card and identity the background answers `height` without vault work: one row when locked, else `INLINE_WALLET_ROWS` (3) whatever the count, so the size tells the page nothing. The height rule (`inlineFixedRows()`) and the frame titles live once in `src/messages.ts`, so the list page can never size differently from what the background decided. `inline.state` decrypts each listed card's or identity's `key` to send a card's `{ brand, last4 }` (`InlineRow.card`, worded by the list page as "Visa, *1111", with a screen-reader label "{name}, {brand} ending in {last4}") or an identity's `detail` (a full name or email), skipping use-only copies; rows live only in the list page. A pick goes through `fill()` with `anchorKey`, so the list's frame fills the anchored group and other frames follow the frame plan; questions come back as `needsFormConfirm` and are asked in the list.
- **Menus.** `src/menus.ts` adds `autofill-card` and `autofill-identity` parents with `card:<itemId>` and `identity:<itemId>` children, rebuilt on the same events as today; left out when the vault holds none of that kind.
- **Settings.** Four per-account booleans, all default `true`: `inlineCards`, `inlineIdentities`, `vaultCards`, `vaultIdentities`; `AUTOFILL_FEATURES` gains `cards` and `identities`, which deliver them in the Autofill view.
- **Last used.** A wallet fill that filled at least one field writes `lastUsed.<id>` like a login fill; no new storage.

## Storage

| Datum | Lives in | Cleared by |
| --- | --- | --- |
| `inlineCards`, `inlineIdentities`, `vaultCards`, `vaultIdentities` | `settings.<id>`, `storage.local` | Account removal |
| Last fill time of a card or identity | `lastUsed.<id>`, `storage.local` | Logout, account removal; ids the vault no longer holds on the next write |
| The list's kind | `inlineSession.<tabId>`, `storage.session` (memory on Firefox below 115) | As the session (ext-autofill) |
| Probe replies, frame plan, answers, decrypted payload and members | Background memory, one fill request | End of the request |
| List rows (name, detail) | List page memory; background while answering | List close |
| Members a frame received | Content script memory | After writing the fields |

## Message contract

```ts
type FormKind = 'card' | 'identity'
type FormRole =
	| 'cardholder' | 'number' | 'expiry' | 'expMonth' | 'expYear' | 'securityCode' | 'brand'
	| 'fullName' | 'firstName' | 'lastName' | 'email' | 'phone' | 'streetAddress' | 'addressLine1'
/** Only the members the receiving frame's roles need. `expMonth` "01"–"12", `expYear` four digits, `number` digits only. */
type FormValues = Partial<Record<'cardholder' | 'number' | 'expMonth' | 'expYear' | 'cvv' | 'brand'
	| 'fullName' | 'firstName' | 'lastName' | 'email' | 'phone' | 'streetAddress' | 'addressLine1', string>>

/** Popup or list confirmation. `frames: 'sameSite'` is "Only {host}". `origin` is the question's, echoed back; an answer for another top origin counts as none. */
type FormConfirmation = { insecure?: true; frames: 'all' | 'sameSite'; frameHosts: string[]; origin: string }
/** `host` is for display, `origin` is what the answer must echo. */
type FormQuestion = { insecure: boolean; host: string; origin: string; frameHosts: string[] }

// fill.request gains `confirmed?: FormConfirmation`; FillResult gains `filled: 'form'` and `needsFormConfirm?: FormQuestion`.
// inline.pick gains `confirmed?: FormConfirmation`; InlinePickReply gains `{ needsFormConfirm: FormQuestion }`.

/** Background → one frame. Request/response. No vault data. */
type FillProbe = { kind: 'fill.probe'; form: FormKind; anchorKey?: string }   // reply: { roles: FormRole[]; origin: string }, no roles when the origin differs from the recorded frame's
/** Background → one frame. Request/response. The only message carrying card or identity data. */
type FillExecuteForm = { kind: 'fill.executeForm'; form: FormKind; values: FormValues; origin: string; anchorKey?: string }   // reply: { filled: number }
/** Background → the top frame, context menu fills only. Request/response, reply: boolean. */
type FillConfirmForm = { kind: 'fill.confirmForm' } & FormQuestion

type InlineMode = 'login' | 'code' | FormKind   // was 'login' | 'code'

/** Content script → background: the list kind comes from the field. */
type InlineOpen = { kind: 'inline.open'; mode: InlineMode; webauthn?: boolean }
/** `card`: a card's brand and last four, worded by the list page; absent for a use-only copy or a number under four digits. `detail`: an identity's full name or email, null for a use-only copy; never on a login or card row. */
type InlineRow = { id: string; name: string; username: string | null; card?: { brand: string | null; last4: string }; detail?: string | null }

// VaultSnapshotReply gains `formIds: string[]`: every unblocked card and identity on an `http(s)` tab, so the detail view can offer Fill for one the Vault view setting left out of the suggestions; the popup cannot tell a system "card" type from a user type named "card", the background can.
// InlineStateReply already carries `mode`; it now takes the widened type. `INLINE_MODES` in messages.ts must list 'card' and 'identity'.

const INLINE_WALLET_ROWS = 3
```

`BackgroundToContent` gains `FillProbe`, `FillExecuteForm` and `FillConfirmForm`. Every listener keeps the `unknown` cast at the boundary; content scripts still send only the kinds `acceptedFrom()` allows.

## Module layout

- `src/autofill/fields.ts`: card and identity roles in `classifyField()`, the card guard, `isQualifying()` returning the kind, `fillSelect()`.
- `src/autofill/format.ts` (content): expiry patterns, month and year forms, option candidates, split number, brand aliases.
- `src/autofill/wallet.ts` (background): `walletKind()`, `walletCandidates()`, `parseExpiry()`, `membersFor()`, `isInsecureUrl()`, the frame plan.
- `src/autofill/execute.ts`: answers `fill.probe`, `fill.executeForm` and `fill.confirmForm`; origin and anchor checks as for logins.
- `src/autofill/fill.ts`: the wallet branch of `fill()` (probe, questions, decrypt, per-frame send, last used).
- `src/autofill/inline-session.ts`, `src/autofill/inline-host.ts`, `entrypoints/inline-list/`: the kind, fixed height, rows with `card` or `detail`, in-list questions, texts per kind.
- `src/autofill/form-answers.ts`: `answered()` and `answeredOnlyHost()`.
- `src/menus.ts`: the two submenus.
- `src/background/requests.ts`: `snapshot()` appends cards and identities to the suggestion ids per the Vault view settings, and lists `formIds`.
- `src/accounts/settings.ts`: four keys, two features; the Autofill view renders the entries.
- Popup: `useFill.tsx` (questions and their guard), `ItemDetail.tsx` (Fill for card and identity on `http(s)` tabs), `Settings.tsx` and `Autofill.tsx` (the four entries; a vault-view change reloads the list).
- `test-site/`: one page per fill case (tasks.md).

## Risks / Trade-offs

- [The cross-site question on every Stripe or Adyen checkout is friction] → Accepted for now: it names the host, so the user decides once per fill; see Open Questions for remembering it.
- [A same-site frame is trusted silently] → The same base-domain trust logins get; a compromised subdomain could already receive logins.
- [Heuristics miss a custom checkout] → Fill the roles found, the "Unable to autofill" toast when none, fixtures on the test site.
- [Expiry is free text] → Parse the common forms; an unreadable one leaves only the expiry empty.
- [Dutch forms split street and house number, and ask for postcode and city] → Not fillable from Keepiq's one `address` text; `ISSUE-identity-fields`.
- [Chrome's own card dropdown covers the card list] → The default password manager entry does not take card autofill yet (Open Questions).
- [`inline.state` decrypts every card's `key` for the rows] → Vaults hold a handful of cards; use-only copies are skipped, and nothing is cached.
- [A use-only card's members reach the page on fill] → By definition: use-only means fill only, as a use-only login's password.
- [The page adds a card field after the probe] → It gets nothing in that fill; a new cross-site host is asked about again.
- [A hostile page hides card fields to harvest members] → Fields hidden by opacity, position, size, clipping, covering or `aria-hidden`/`inert` ancestors get no role (see the visibility decision); a field drawn in view but made invisible (transparent text, a `pointer-events:none` cover, an invisible iframe) still passes, and the page is the one the user chose to fill.

## Open Questions

- Cross-site frames: ask each fill naming the hosts (this design), fill silently like Bitwarden, or remember an answer per top site and frame host in `storage.local`?
- Cards on an insecure frame: ask (this design), or refuse like Chrome?
- Card and identity list height: fixed three rows (this design), or by count like the login list?
- Should "Make Keepiq your default password manager" also turn off Chrome's `autofillCreditCardEnabled` in this change? Users who turned the entry on already would then show it off until they turn it on again.
- Identity address: fill only `street-address` and `address-line1` from Keepiq's one text (this design), or also parse a Dutch postcode and city from it until `ISSUE-identity-fields` lands?
