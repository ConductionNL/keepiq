---
kind: code
---

# Card brands, lengths and security codes

## Why

A payment card's number is not always sixteen digits and its security code is not always three. American Express is 15 digits grouped 4-6-5 with a four-digit CID on the front, Diners Club is often 14 digits grouped 4-6-4, Maestro runs from 12 to 19, UATP is 15 grouped 4-5-6, and Bancontact has no security code at all. Keepiq's card helpers assume a Visa-style card throughout:

- `cardBrand()` in `src/cardIdentity/cardIdentity.js` knows five brands, and two of its rules are wrong: `^2[2-7]` calls a Mir card (2200–2204) Mastercard, and `^(50|56|57|58|63|67)` calls Elo, Verve, Dankort and Bancontact cards Maestro. JCB, Diners Club and UnionPay are never recognised.
- `SecretDetailSidebar.vue` groups a revealed number in fours and masks it as `1234 •••• •••• 5678`, so an Amex shows as `3782 8224 6310 005` and every masked card looks like sixteen digits.
- The create dialog hints at a failed Luhn check, but says nothing about a number too short or too long for its brand, or a three-digit code on an Amex.
- The web app derives a brand but never shows it, although the spec's user story has the card recognised by brand and last four.

The browser extension mirrors these rules ("Same rules as the web app's `cardBrand`"), so it carries the same gaps. Its change follows this one and uses the brand table introduced here.

## What Changes

- **A brand table.** `src/cardIdentity/card-brands.json` lists each brand with its IIN ranges, valid lengths, security code name and length, digit grouping and the option codes checkout pages use for it. It covers Visa, Mastercard, American Express, Discover, Diners Club, JCB, UnionPay, Maestro, Mir, Elo, Hiper, Hipercard, Troy, Verve, RuPay, Dankort, Bancontact and UATP.
- **Detection by narrowest range.** The most specific matching range decides the brand, so Elo inside Visa, Dankort inside Maestro and RuPay inside Discover's `65` are labelled correctly. When two ranges are equally specific the brand stays "Card", never a guess. Maestro loses its catch-all for every number starting with 6.
- **Co-branded cards** (Visa/Dankort, Bancontact/Maestro, UnionPay on Discover, Troy on Discover) get one label and list the other networks they run on, which only the extension's checkout fill uses.
- **Grouping by brand.** A revealed number is grouped as its brand writes it (4-6-5 for Amex), and the masked number shows as many groups as the card has digits.
- **The brand next to the number** in the detail sidebar.
- **More non-blocking hints** in the create and edit dialogs: a number whose length the brand does not issue, and a security code whose length the brand does not use. The Luhn hint stays, and for UnionPay it is not shown, as some UnionPay cards fail the check. None of the hints blocks saving.
- **Shared test vectors** in `tests/vectors/cards/`, which both the web app and the extension test against, so the two derive the same brand for the same number.

## Capabilities

### Modified Capabilities

- `card-identity-items`: brand detection from a brand table, grouping and masking by brand, and length and security code hints.

## Impact

- **Backend**: none. The card payload stays ciphertext in `key` (ADR-003) and the server never sees a card number. Brand, grouping and hints are derived in the browser and never stored, as before.
- **Payload**: unchanged. No `brand` member is added, so the mobile apps, import, export and CXF are unaffected.
- **Frontend**: new `src/cardIdentity/card-brands.json`; changes to `src/cardIdentity/cardIdentity.js`, `SecretPayloadFields.vue` and `SecretDetailSidebar.vue`.
- **Tests**: new `tests/vectors/cards/brand-vectors.json`; `tests/vitest/card-identity.spec.js` extended.
- **Dependency**: none. Why `credit-card-type` is not used is design decision D2.
- **Labels that change**: a few saved cards get a different brand than today, among them 5019 (Maestro → Dankort), 6703 (Maestro → Bancontact), 2200–2204 (Mastercard → Mir) and Elo and Verve ranges inside Maestro. Labels are display only, so nothing is migrated.
- **Security**: no new data leaves the browser and nothing new is stored. The brand table holds public IIN ranges only.
