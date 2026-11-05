# Design: card brands, lengths and security codes

## Context

- A `card` secret stores `{number, expiry, cvv, pin, cardholder}` as JSON in its encrypted `key` (spec `card-identity-items`). The brand and last four are derived in the browser from the decrypted number and never stored.
- `src/cardIdentity/cardIdentity.js` holds `cardBrand()`, `cardLast4()`, `luhnValid()` and `expiryFormatValid()`. Only `cardLast4` and `luhnValid` are used today: the sidebar masks and groups the number itself, and `cardBrand` is only exercised by its tests.
- The browser extension has its own `cardBrand()` in `browser-extension/src/vault/payloads.ts` with the same rules, used for its popup, inline list and checkout fill, and maps brands to checkout option codes in `browser-extension/src/autofill/format.ts`.
- `tests/vectors/<domain>/` is where the web app keeps test vectors that other clients test against. The extension already reads `tests/vectors/crypto/` and `tests/vectors/generator/` from its tests.
- The ranges were researched on 2026-11-05 against Discover's IIN range summary, Mastercard's BIN lookup FAQ, Wikipedia's payment card number table, braintree/credit-card-type 10.3.1, adyen-web and Baymard's spacing table.

## Goals / Non-Goals

**Goals**
- Recognise the brands people store, including regional ones, without ever labelling a card with a brand it is not.
- Group, mask and hint by what the brand actually issues.
- One table that the web app and the extension both read, and vectors that prove they agree.

**Non-Goals**
- Storing a brand, or letting the user choose one. A co-branded card that its prefix cannot decide (Visa or Mastercard that is also Cartes Bancaires or V PAY) is labelled by its prefix.
- Blocking a save. Every hint stays advisory, as `card-identity-items` §3.2 has it.
- The extension, which follows in its own change; the mobile apps, which show only the last four.
- Formatting the number while it is typed.

## Decisions

**D1. One JSON table is the source of truth.** `src/cardIdentity/card-brands.json` holds a `version`, a `source` note and a list of brands. Each brand has:

- `id`: stable, never shown, reused from credit-card-type where it has one (`visa`, `american-express`, `diners-club`). Code keys on the `id`, never on the label.
- `label`: the brand name, a proper noun and not translated.
- `ranges`: prefixes written as digit strings, either `"34"` or `["622126", "622925"]`, inclusive and compared on as many leading digits as written.
- `lengths`: the number lengths the brand issues.
- `code`: `{ "name": "CID", "size": 4 }`, or `null` for a card without one (Bancontact, UATP).
- `gaps`: where a space goes, by length, with a `default` (Amex `{"default": [4, 10]}`, UATP `[4, 9]`, a 19-digit card `[4, 8, 12, 16]`).
- `luhn`: `false` where the brand's numbers need not pass the Luhn check (UnionPay); absent means they must.
- `selectCodes`: the option values and texts checkout pages use for it (`mc`, `amex`, `cup`, `bcmc`). Only codes seen on real checkouts go in.
- `networks`: for a co-branded entry, the brand ids whose `selectCodes` a checkout falls back to, in order. A co-branded range is an entry of its own (`visa-dankort`, `unionpay-discover`, `rupay-jcb`) with the label of its specific brand.

JSON rather than a JS module, so the extension can carry a byte-identical copy and the mobile apps could read the same file later.

**D2. Not `credit-card-type`, and not `addCard()` on top of it.** We considered depending on braintree's `credit-card-type` (MIT, zero dependencies, about 4 KB gzipped, 10.3.1 of 2026-10-13) and registering the brands it lacks with its `addCard()` API. Checked against the 10.3.1 source and probes run in Node, it does not fit:

- *Precedence is accidental.* The library ranks a match by how many digits its pattern is written with, and settles a tie by registration order, where an added brand comes last. A Dankort range written as `5019` still comes out as Maestro, because Maestro's `[500000, 504174]` is written with six digits. A RuPay `6521` loses to Discover's `65` unless it is rewritten as a range, and a RuPay `[508500, 508999]` ties Maestro and loses unless `changeOrder()` moves it first. What wins an overlap is decided by how each line is spelled, and nothing states it.
- *Its defaults invent brands.* Maestro includes a bare `6`, so `6999 1234 1234 1234` comes out as Maestro, where this spec requires "Card". Using the library means removing or rewriting built-in entries as well as adding ours.
- *We would own the table anyway.* After rewriting ranges at full precision, reordering, removing the catch-all and adding our own select codes, we maintain an IIN table regardless. It would just be written as side effects on a module-global registry, which both the web app and the extension would have to set up identically before first use.
- *Upgrades would change behaviour without a code change.* Its 2025 and 2026 releases added Verve, Naranja, Troy and Elo ranges. Any of them can change which brand wins a tie, and in the extension, which option a checkout's card-type field gets.
- *It is missing what we need most.* RuPay, Dankort, Bancontact, UATP and Maestro UK are absent, as are Diners 3095, Verve's 650002–650027, 13-digit Visa and the select codes. Its 16-digit Diners grouping (4-6-6) also disagrees with Baymard's 4-4-4-4.
- *It serves JavaScript only.* A JSON table can be read by the Kotlin and Swift apps if they ever show a brand.

What we keep from it is its data: our table is seeded from its ranges where the research agrees with them. That is credited in the table's `source` note and in `REUSE.toml` (D7). Its releases are treated as a data feed to compare against by hand, not as a dependency. Revisit this if the library adopts narrowest-range matching or gains the brands above.

**D3. The narrowest range wins; a tie names no brand.** Strip every non-digit first. A range matches when the number has at least as many digits as the range is written with and its leading digits fall inside it. Each matching range is widened to eight digits (start padded with `0`, end with `9`), and the one covering the fewest numbers wins. If two brands tie for narrowest, `cardBrandInfo()` returns `null` and the card is shown as "Card". Array order never breaks a tie, and a test asserts that no two brands in the table tie on any vector.

**D4. Co-branded cards get one label.** A range that is two brands at once (4571 Visa/Dankort, 6703 Bancontact/Maestro, 622126–622925 UnionPay/Discover, 353800–353809 RuPay/JCB) is listed under its more specific brand with `networks` naming the others. The label is the specific brand. Only the extension's checkout fill reads `networks`, to fall back to a brand the page offers.

**D5. Grouping and masking follow `gaps`.** `formatCardNumber()` puts a space at each of the brand's gaps for that length, and every four digits for an unknown brand. `maskCardNumber()` groups the same way, shows the first group and the last four digits and dots everything between, so an Amex masks as `3782 •••••• •0005` and a 14-digit Diners card as `3056 •••••• 5904`. A number under 12 digits stays fully dotted, as now.

**D6. Hints never block.** In the create and edit dialogs, below the field:

- *Number*: "{brand} does not issue card numbers of this length" when the brand is known and its length is not in `lengths`, or "Card numbers have 12 to 19 digits" when no brand is derived. The existing Luhn hint stays, except for UnionPay, whose numbers do not all carry a Luhn check digit.
- *Security code*: "{brand} cards use a {size}-digit {name}" when the length differs from `code.size`, and nothing for a brand without a code.

Each hint names the field it belongs to through `aria-describedby`, and none disables Save. The Luhn hint moves from create-only to both dialogs.

**D7. Provenance and licence.** The table is EUPL-1.2 work of Conduction that includes facts taken from credit-card-type (MIT, © Braintree). `REUSE.toml` gets an override annotation for `src/cardIdentity/card-brands.json` (and, in the extension change, its copy) declaring both copyright holders and `EUPL-1.2 AND MIT`, and `LICENSES/MIT.txt` is added. Ranges the research could not confirm are left out: Hipercard 3841, Bancontact 479658 and 606005, Dankort 3571, RuPay 82 and 100003, Troy's 65 ranges (credit-card-type is their only source; they stay Discover, the network they run on), and 6590–6599 as Discover.

## Security and zero-knowledge

Nothing changes in what is stored or sent. The table is public reference data bundled with the app. Brand, grouping, mask and hints are computed in memory from the decrypted number and are never persisted, logged or sent. The mask shows the same digits as today (first group and last four).

## Risks / Trade-offs

- **Saved cards change label.** Intended, and display-only. The first task records today's output as vectors, so every changed label is reviewed one by one and listed in the changelog.
- **Ranges drift.** Networks hand out new ranges. The table carries a `version`. An unknown range shows "Card" rather than a wrong brand, which is the safe failure.
- **The table can be wrong where the research found no primary source** (Elo's full list, Verve, Troy, RuPay). Those brands are labels and hints only, so a wrong range costs a wrong label, never a blocked save or a lost card.

## Migration

None. No stored data changes.
