## ADDED Requirements

### Requirement: Card Brand Detection From a Brand Table

The system MUST derive a card's brand from its decrypted number using the brand table `src/cardIdentity/card-brands.json`, which lists per brand its IIN ranges, valid lengths, security code name and length (or none), digit grouping, co-branded networks and checkout option codes. Non-digits MUST be ignored. Of the ranges the number matches, the one covering the fewest numbers MUST decide the brand; when two brands tie for narrowest, or no range matches, the card MUST be shown as "Card" and no brand MUST be invented. A co-branded range MUST be labelled with its more specific brand. The derived brand MUST NOT be stored or sent to the server. The web app and the browser extension MUST derive the same brand for every number in `tests/vectors/cards/brand-vectors.json`.

#### Scenario: A range inside a wider one names the narrower brand

- **GIVEN** card secrets with numbers starting `5019`, `6703`, `2200` and `401178`
- **WHEN** their brands are derived
- **THEN** they MUST be Dankort, Bancontact, Mir and Elo, not Maestro, Maestro, Mastercard and Visa

#### Scenario: An unknown prefix names no brand

- **GIVEN** a card secret whose number is `6999 1234 1234 1234`
- **WHEN** its brand is derived
- **THEN** it MUST be shown as "Card"

#### Scenario: American Express is recognised with its own format

- **GIVEN** a card secret whose number is `378282246310005`
- **WHEN** its brand is derived
- **THEN** it MUST be American Express, with length 15, grouping 4-6-5 and a four-digit CID

### Requirement: Non-Blocking Card Format Hints

The create and edit dialogs MUST hint, without blocking Save, when a card number's length is not one its derived brand issues (or is outside 12 to 19 digits when no brand is derived), when a security code's length differs from the brand's code length, and when a number fails the Luhn check, except for UnionPay. Each hint MUST be associated with its field for assistive technology. Hints MUST be computed in the browser only.

#### Scenario: A three-digit code on an American Express card

- **GIVEN** the create dialog for a card with number `378282246310005`
- **WHEN** the user enters a three-digit security code
- **THEN** a hint MUST say that American Express cards use a 4-digit CID
- **AND** Save MUST stay enabled

#### Scenario: A UnionPay number that fails Luhn

- **GIVEN** the edit dialog for a card whose number starts `62` and fails the Luhn check
- **WHEN** the number field is shown
- **THEN** no Luhn hint MUST be shown

## MODIFIED Requirements

### Requirement: Type-Specific Presentation and Masked Reveal

The system MUST render type-specific labelled fields for `card` and `identity` secrets, masking `number`/`cvv`/`pin` (card) and `bsn` (identity) by default with per-field reveal and copy controls, and MUST render nothing while the vault is locked. A card number MUST be labelled with its derived brand when there is one, MUST be grouped by its brand's grouping for its length when revealed (every four digits for an unknown brand), and when masked MUST show its first group and last four digits with every other digit dotted in the same grouping. A number under 12 digits MUST be fully dotted. Copy MUST copy the digits as stored.

#### Scenario: Sensitive sub-fields are masked until revealed

- GIVEN a `card` or `identity` secret is open with the vault unlocked
- WHEN the detail view renders
- THEN the number/CVV/PIN and BSN MUST be masked and revealed only on explicit control activation

#### Scenario: An American Express number is grouped 4-6-5

- **GIVEN** a `card` secret whose number is `378282246310005`, open with the vault unlocked
- **WHEN** the detail view renders
- **THEN** the number MUST be labelled American Express and masked as `3782 •••••• •0005`
- **AND** revealing it MUST show `3782 822463 10005`
