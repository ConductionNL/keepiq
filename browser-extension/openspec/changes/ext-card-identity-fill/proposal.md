---
kind: code
depends_on: [ext-vault-browse, ext-settings, ext-autofill]
chain:
  - ext-accounts-and-unlock
  - ext-vault-browse
  - ext-vault-edit
  - ext-share-sync
  - ext-i18n
  - ext-generator
  - ext-send
  - ext-theme
  - ext-settings
  - ext-autofill
  - ext-toolbar-icon
  - ext-card-identity-fill
---

## Why

Card and identity items can be stored, viewed and edited in the popup, but filling a checkout or an address form still means copying field by field. Bitwarden fills them from the popup, the inline menu and the context menu; Keepiq should too, without giving a payment page more than the one item the user picked.

## What Changes

- Mirrors Bitwarden's card and identity autofill:
  - the popup's "Autofill suggestions" section lists cards and identities on every `http(s)` page, and a click or the detail view's Fill button fills them;
  - the inline list opens on card fields with the user's cards and on personal-details and address fields with their identities;
  - the "Keepiq" context menu gains "Autofill card" and "Autofill identity" submenus.
- Field detection learns card roles (cardholder, number, split number, combined expiry, expiry month and year, security code, brand) and identity roles (full, first and last name, email, phone, street address, first address line), from `autocomplete` tokens, type, name, id, label and placeholder in English and Dutch, as one more signal set of `classifyField()`.
- A field with card evidence is never a login field: a `type=password` security code no longer makes a payment form a login, so it gets no login list, no login fill and no save bar.
- Values are formatted per field: expiry month and year as two or four digits, as a `<select>` option or combined in the field's own pattern; the card number split over several fields; the brand derived from the number, as the popup already does.
- A fill sends each frame only the members its fields ask for, after the user picked the item. The card's PIN and the identity's BSN are never filled.
- A card fill into an insecure frame asks first. A fill that reaches frames on another site than the top page (Stripe, Adyen and other payment iframes) names those sites and asks first.
- Four per-account settings in the Autofill view, all on by default as in Bitwarden: "Show cards as suggestions" and "Show identities as suggestions" (inline list), "Always show cards as Autofill suggestions on Vault view" and "Always show identities as Autofill suggestions on Vault view" (popup section).
- Test-site pages per fill case.
- Out of scope, each a later change or a Keepiq change:
  - saving a card or identity from a submitted form;
  - "New card" and "New identity" in the inline list (the inline list has no "New item", ext-autofill); the context menu's Add card and Add identity come with ext-context-menu;
  - master password re-prompt per item, which needs a field Keepiq does not have (`ISSUE-item-reprompt`);
  - identity members Keepiq does not store: title, middle name, company, username, address lines 2 and 3, city, state, postcode, country (`ISSUE-identity-fields`);
  - identities suggested on sign-up username fields together with a generated password (Bitwarden's account creation inline menu);
  - Chrome's own card autofill in "Make Keepiq your default password manager" (ext-default-password-manager leaves it alone; see design.md Open Questions);
  - a keyboard shortcut and fill on page load for cards and identities, which Bitwarden does not offer either.

## Capabilities

### New Capabilities
- `card-identity-fill`: card and identity field roles, value formatting, the fill pipeline per frame, the insecure and cross-site questions, the suggestions in popup, inline list and context menu, the four settings, and what the content script and the page get.

### Modified Capabilities
- `autofill` (ext-autofill): a field with card evidence never takes a login role; the context menu gains the card and identity submenus; the content script may receive one card's or identity's members instead of a username and password.
- `inline-list` (ext-autofill): which fields open the list now names the login list only and points to the card and identity list.
- `login-capture` (ext-autofill): card fields are never reported as a submitted password.
- `vault-list` (ext-vault-browse, as ext-autofill modified it): the Autofill suggestions section lists cards and identities after the matching logins.
- `settings` (ext-settings): the Autofill view renders the four card and identity entries once delivered.

## Deviations from Bitwarden

- A card fill into an `http:` frame other than loopback asks first. Bitwarden fills cards on insecure pages silently; Chrome refuses card autofill there. A card is the highest-value item a page can receive.
- A fill that reaches a frame on another site than the top page names that site and asks first, each fill. Bitwarden fills every frame. Keepiq's rule is one item per fill to the page the user chose, and an ad or widget frame on a checkout page must not get the card silently.
- Each frame receives only the members its fields ask for. Bitwarden's fill script carries the item to every frame.
- The card and identity inline list has a fixed height, whatever the number of items. Their suggestions are not matched by site, so a count would be the same on every site.
- The inline list does not open in a frame with a cross-origin ancestor, so not inside a Stripe or Adyen iframe; fill from the popup or the context menu reaches those frames. Inherited from ext-autofill.
- Identity fill covers the members Keepiq stores (first and last name, email, phone, address as one text) and never fills the BSN. Bitwarden's identity has separate address parts, company, title, middle name and username; Keepiq's payload has not (`card-identity-items` in the Keepiq app).
- The card's brand is derived from its number, since Keepiq does not store it. The PIN is never filled; Bitwarden's card has no PIN.
- The expiry is parsed from Keepiq's single `expiry` text; an expiry it cannot read leaves the expiry fields empty and fills the rest.
- A card number split over several fields is filled across them. Not in Bitwarden.
- Identity fill never writes a login form or a password field, and the identity list opens only in a group with at least two identity fields, so a newsletter's lone email field gets no list.
- No master password re-prompt: Keepiq has no per-item flag for it (`ISSUE-item-reprompt`).

## Keepiq API used

None. Suggestions read the cached snapshot (`typeId`, `name`, `blocked`, `useOnly`), and a fill decrypts the cached `key` locally (ADR-002). The `card` and `identity` system types are in every Keepiq release from 0.1.4 on, older than `MIN_KEEPIQ_VERSION` (0.3.12), so nothing is gated in `KEEPIQ_FEATURES`.

Nothing in this change needs Keepiq backend work. The two out-of-scope items above would, and are drafted as `ISSUE-identity-fields` and `ISSUE-item-reprompt`; this change does not depend on them.

## Impact

- `src/autofill/fields.ts`: card and identity roles in `classifyField()`, the payment guard, `isQualifying()` per list kind, `fillSelect()`.
- New `src/autofill/wallet.ts` (background: members, expiry parsing, brand, frame plan) and `src/autofill/format.ts` (content: per-field formatting).
- `src/autofill/fill.ts`, `src/autofill/inline-session.ts`, `src/menus.ts`, `entrypoints/content.ts`, `entrypoints/inline-list/`, popup `useFill`, `Suggestions`, `ItemDetail` and the Autofill settings view.
- `src/messages.ts` gains `fill.probe`, `fill.executeForm`, `fill.confirmForm` and the kind on `inline.open`; `src/accounts/settings.ts` gains four keys and two `AUTOFILL_FEATURES`.
- ADR-002 (what the inline list may show), ARCHITECTURE.md, SECURITY.md, `../docs/browser-extension/using.md` and `privacy.md`; keys in `locales/en.yml` and `locales/nl.yml`.
- No new permission: `contextMenus`, `tabs` and the `*://*/*` content script with `allFrames` exist (ext-autofill). The toolbar badge keeps counting logins only (ext-toolbar-icon).
