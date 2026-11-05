# Tasks: card brands, lengths and security codes

## 1. Baseline

- [ ] 1.1 Record today's `cardBrand()` output as `tests/vectors/cards/brand-vectors.json`: one vector per brand and range edge (first and last number of each range, one past each end), plus 12-, 13-, 14-, 15-, 16- and 19-digit numbers, numbers with spaces and dashes, an empty string and unknown prefixes. Each vector holds `number`, `brand` (the id or null), `label`, `lengths`, `gaps` and `code`. Test `cardBrand()` against it as it is. Verify: `tests/vitest/card-identity.spec.js` passes on the unchanged helper.

## 2. Brand table

- [ ] 2.1 Add `src/cardIdentity/card-brands.json` per design D1 for the brands in the proposal, ranges per the research and D7 (no unconfirmed ranges, no Maestro catch-all for `6`, Mastercard exactly 222100–272099 and 51–55, Mir 2200–2204). Verify: a test validates every entry's shape, that ranges are digit strings with start ≤ end, and that `lengths`, `gaps` and `code` agree.
- [ ] 2.2 Add the override annotation for the table to `REUSE.toml` and `LICENSES/MIT.txt` (D7). Verify: `reuse lint`.

## 3. Detection and formatting

- [ ] 3.1 Add `cardBrandInfo(number)` to `src/cardIdentity/cardIdentity.js`, returning the brand entry or `null` per D3, and keep `cardBrand(number)` returning its label or "Card". Verify: every vector in `brand-vectors.json`, and a test that no two brands tie on any vector.
- [ ] 3.2 Update the vectors to the new table and review every changed `brand` one by one (5019, 6703, 2200–2204, the Elo, Verve, RuPay and Hipercard ranges inside Maestro and Discover, the dropped `6` catch-all). List them in `CHANGELOG.md`. Verify: the vector diff in the PR.
- [ ] 3.3 Add `formatCardNumber(number)` and `maskCardNumber(number)` per D5. Verify: vectors for 4-6-5, 4-6-4, 4-5-6, 4-4-4-4, 19 digits, an unknown brand and under 12 digits.
- [ ] 3.4 Add `cardNumberHint(number)` and `securityCodeHint(number, code)` per D6, returning a hint code with its parameters or `null`, and skipping Luhn for UnionPay. Verify: `tests/vitest/card-identity.spec.js`.

## 4. Web app

- [ ] 4.1 `SecretDetailSidebar.vue`: label the number with its brand, and replace `cardNumberFormatted` and `cardNumberMasked` with `formatCardNumber` and `maskCardNumber`. Verify: a sidebar spec with an Amex, a Diners and an unknown-prefix card.
- [ ] 4.2 `SecretPayloadFields.vue`: show the number and security code hints in the create and edit dialogs, linked to their fields with `aria-describedby`, Save enabled throughout; drop the create-only `checkLuhn` prop. Verify: component specs for both dialogs, and `hydra-gate-form-label-association`.
- [ ] 4.3 Translations for the new hints in `l10n/` (en, nl). Verify: `npm run lint`.

## 5. Spec and docs

- [ ] 5.1 Document recognised brands, grouping and the hints in the card section of the user docs in `docs/`. Verify: docs build.
- [ ] 5.2 Run `hydra-gates` (spec coverage: `@spec openspec/specs/card-identity-items/spec.md#...` on every changed method). Verify: gates green.
