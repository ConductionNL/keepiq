## Purpose

Fills one card or identity item into a page's payment, address or personal-details form from the popup, the inline list and the context menu, as Bitwarden does, while each frame receives only the members its fields ask for.

## ADDED Requirements

### Requirement: Cards and identities are suggested without site matching
The extension SHALL treat an item as a card or identity when its `typeId` is the system `card` or `identity` type in the cached types. Cards and identities MUST NOT be matched against the page URL, as in Bitwarden: every card and every identity of the active account is a candidate on every `http:` or `https:` page, whatever its `url`. Rows with `blocked: true` MUST never be offered. A use-only copy is offered and fillable, since use-only means fill only (ADR-003), but no surface shows any member of it. Candidates are ordered by last used, then name, from `lastUsed.<accountId>` (ext-autofill). Choosing candidates reads only the cached plaintext `typeId`, `name`, `blocked` and `useOnly` and decrypts nothing.

#### Scenario: Card with an unrelated url
- **GIVEN** an unlocked vault with a card whose `url` is `https://bank.test`
- **WHEN** the user opens the popup on `https://shop.test/checkout`
- **THEN** the card is listed under "Autofill suggestions"

#### Scenario: Blocked card
- **GIVEN** a card row with `blocked: true`
- **WHEN** any fill surface lists cards
- **THEN** the row is not listed

#### Scenario: Browser page
- **GIVEN** the active tab is `about:blank` or `chrome://newtab/`
- **WHEN** the popup opens
- **THEN** no card or identity is offered and the section shows "Autofill is not available on this page"

### Requirement: Card field roles
The content script SHALL give a field one card role, as part of `classifyField()` in the `autofill` capability, by weighing the same signals (type, `autocomplete` token, name, id, placeholder, label, `maxlength`, `inputmode`) in English and Dutch:
- `cardholder`: token `cc-name`; "name on card", "cardholder", "naam op (de) kaart", "kaarthouder".
- `number`: token `cc-number`; "card number", "kaartnummer", "creditcardnummer"; a run of up to four `number` fields in a row whose `maxlength` values together hold 14 to 19 digits is a split number.
- `expiry`: token `cc-exp`; "expiry", "expiration", "valid thru", "MM/YY", "vervaldatum", "geldig tot".
- `expMonth` and `expYear`: tokens `cc-exp-month` and `cc-exp-year`; "month", "maand", "MM"; "year", "jaar", "YY", "YYYY", "JJ", "JJJJ"; on `<input>` and `<select>`.
- `securityCode`: token `cc-csc`; "CVC", "CVV", "CVV2", "CID", "security code", "card code", "beveiligingscode", "controlegetal"; also when `type=password`.
- `brand`: token `cc-type`; "card type", "card brand", "kaarttype"; on `<input>` and `<select>`.

Hidden, disabled and read-only fields MUST be ignored, as for logins. A field named only "PIN" is never given a card role.

#### Scenario: Checkout form with tokens
- **GIVEN** a form with fields marked `cc-name`, `cc-number`, `cc-exp` and `cc-csc`
- **WHEN** the fields are classified
- **THEN** they get the roles `cardholder`, `number`, `expiry` and `securityCode`

#### Scenario: Dutch labels without tokens
- **GIVEN** fields labelled "Naam op de kaart", "Kaartnummer", "Vervaldatum" and "Beveiligingscode"
- **WHEN** the fields are classified
- **THEN** they get the same four roles

#### Scenario: Split number
- **GIVEN** four text fields in a row with `maxlength="4"` and the first labelled "Card number"
- **WHEN** the fields are classified
- **THEN** the four together are one split `number`

### Requirement: Identity field roles
The content script SHALL give a field one identity role by the same signals:
- `fullName`: token `name`; "full name", "your name", "naam", "volledige naam".
- `firstName`: token `given-name`; "first name", "given name", "voornaam".
- `lastName`: token `family-name`; "last name", "surname", "family name", "achternaam".
- `email`: token `email` or `type=email`; "email", "e-mail", "e-mailadres".
- `phone`: tokens `tel` and `tel-national` or `type=tel`; "phone", "telephone", "mobile", "telefoon", "telefoonnummer", "mobiel".
- `streetAddress`: token `street-address`, an `<input>` or `<textarea>`; "address", "adres".
- `addressLine1`: token `address-line1`; "address line 1", "street", "straat", "straat en huisnummer".

A field in a group classified as a login, and a field with a login role (username, password, one-time code), MUST NOT get an identity role, except the username of a sign-up group when it is an email field, which gets `email`. A field with a card role keeps it.

#### Scenario: Address form
- **GIVEN** a form with "Voornaam", "Achternaam", "E-mailadres", "Telefoon" and "Adres"
- **WHEN** the fields are classified
- **THEN** they get `firstName`, `lastName`, `email`, `phone` and `streetAddress`

#### Scenario: Login form
- **GIVEN** a login form whose username is `type=email`
- **WHEN** the fields are classified
- **THEN** no field gets an identity role

#### Scenario: Sign-up form
- **GIVEN** a sign-up form with "First name", an email username and a new password
- **WHEN** the fields are classified
- **THEN** "First name" gets `firstName`, the email username gets `email`, and the password fields get no identity role

### Requirement: Members filled per role
The extension SHALL fill each role from one member, and nothing else:
- `cardholder` ← `cardholder`; `number` ← `number` with every non-digit removed; `securityCode` ← `cvv`.
- `expiry`, `expMonth`, `expYear` ← `expiry`, parsed as month and year (Expiry formatting).
- `brand` ← the brand `cardBrand()` in `src/vault/payloads.ts` derives from `number`; nothing when it derives none.
- `firstName`, `lastName`, `email`, `phone` ← the member of that name; `fullName` ← `firstName` and `lastName` joined by a space, trimmed.
- `streetAddress` ← `address`, its line breaks joined with ", " in an `<input>` and kept in a `<textarea>`; `addressLine1` ← the first line of `address`.

The card's `pin` and the identity's `bsn` MUST NOT be filled, sent to a content script or offered by any fill surface. An empty member fills nothing and leaves the field as it was. A `key` that is not a JSON object (`parsePayload()` returns `null`) has no members and fills nothing.

#### Scenario: PIN and BSN stay in the vault
- **GIVEN** a card with a PIN, an identity with a BSN, and a page with fields labelled "PIN" and "BSN"
- **WHEN** the user fills the card and then the identity
- **THEN** neither field is filled and no message to a content script carries the PIN or the BSN

#### Scenario: Full name
- **GIVEN** an identity with first name "Anna" and last name "de Vries", and a form with one "Full name" field
- **WHEN** the user fills it
- **THEN** the field holds "Anna de Vries"

#### Scenario: Multi-line address in a single-line field
- **GIVEN** an identity whose address is "Kerkstraat 1" and "1234 AB Utrecht" on two lines, a field marked `street-address` and one marked `address-line1`
- **WHEN** the user fills it into a form with one, then the other
- **THEN** the first holds "Kerkstraat 1, 1234 AB Utrecht" and the second "Kerkstraat 1"

### Requirement: Expiry formatting
The background SHALL parse `expiry` as a month from 1 to 12 and a year, accepting `MM/YY`, `MM/YYYY`, `M/YY` and `M/YYYY` with `/`, `-`, `.` or spaces as separator, `MMYY`, `MMYYYY` and `YYYY-MM`; a two-digit year means 20YY. An expiry it cannot parse MUST fill no expiry field, while the other roles still fill. The content script formats per field:
- `expMonth` `<input>`: two digits ("03"), or one digit for a `maxlength` of 1 or a placeholder "M".
- `expYear` `<input>`: four digits, or two for a `maxlength` of 2 or a placeholder "YY" or "JJ".
- `<select>` for month or year: the option whose value or text, trimmed and case-insensitive, equals the two-digit, one-digit, four-digit or two-digit form, or for a month its English or Dutch name or three-letter abbreviation; no matching option fills nothing.
- `expiry`: the pattern in its placeholder or label (`MM/YY`, `MM / YY`, `MM-YYYY`, `MMJJ` and the like, separator and year length as written), else by `maxlength` (4 `MMYY`, 5 `MM/YY`, 6 `MMYYYY`, 7 `MM/YYYY`), else `MM/YY`.

#### Scenario: Month and year selects
- **GIVEN** a card with expiry "3/2027", a month `<select>` with options "01" to "12" and a year `<select>` with options "2026" to "2035"
- **WHEN** the user fills the card
- **THEN** "03" and "2027" are selected and `input` and `change` are dispatched

#### Scenario: Combined field with a four-digit year
- **GIVEN** a card with expiry "03/27" and a field with placeholder "MM / YYYY"
- **WHEN** the user fills the card
- **THEN** the field holds "03 / 2027"

#### Scenario: Unreadable expiry
- **GIVEN** a card whose expiry is "end of 2027"
- **WHEN** the user fills it into a form with number and expiry fields
- **THEN** the number is filled and the expiry fields stay empty

### Requirement: Split numbers and brand fields
A split `number` SHALL be filled in order, each field taking as many digits as its `maxlength` allows, and fills nothing when the digits do not fit the fields exactly. A `brand` `<select>` takes the option whose value or text matches the derived brand or a common code (Visa: "visa", "vi"; Mastercard: "mastercard", "master card", "mc"; American Express: "amex", "american express", "ax"; Discover: "discover", "di"; Maestro: "maestro"); a `brand` `<input>` takes the brand's name.

#### Scenario: Four-part number
- **GIVEN** a card number "4111 1111 1111 1111" and a four-part split number
- **WHEN** the user fills the card
- **THEN** each part holds "4111" or "1111" in order

#### Scenario: Brand select
- **GIVEN** a Mastercard number and a brand `<select>` with options "VISA", "MC" and "AMEX"
- **WHEN** the user fills the card
- **THEN** "MC" is selected

### Requirement: Fill from the popup
The extension SHALL list cards after the matching logins and identities after the cards in "Autofill suggestions" while their Vault view settings are on, and fill the active tab when the user clicks such a card or its Fill button, or the Fill button on a card's or identity's detail view on an `http:` or `https:` tab. A blocked item has no Fill button. Fill requires an unlocked vault. The popup closes after a fill that filled at least one field; a popped-out window stays open. When no frame has a field for the item's kind, nothing is decrypted or sent and the popup shows "Unable to autofill on this page. Copy and paste instead."

#### Scenario: Card from the suggestions
- **GIVEN** an unlocked vault with one card and a checkout page with number, expiry and CVC fields
- **WHEN** the user clicks the card in "Autofill suggestions"
- **THEN** the three fields are filled with `input` and `change` events and the popup closes

#### Scenario: Identity from its detail view
- **GIVEN** an identity open in the popup's detail view and an address form in the tab
- **WHEN** the user clicks Fill
- **THEN** the address form is filled

#### Scenario: Page without card fields
- **GIVEN** a page with only a login form
- **WHEN** the user fills a card from the popup
- **THEN** nothing is decrypted, nothing is filled and the popup shows "Unable to autofill on this page. Copy and paste instead."

#### Scenario: Locked vault
- **GIVEN** a locked vault
- **WHEN** the user opens the popup on a checkout page
- **THEN** the unlock view is shown and no card or identity is offered

### Requirement: Each frame gets only its own members
For a card or identity fill the background SHALL ask the top frame and each recorded `http:` or `https:` frame of the tab (the frame registry of ext-autofill) with `fill.probe`, carrying only the item's kind, which roles its chosen group holds: the group of the focused field when it has fields of that kind, else the first group in DOM order that has, or for an inline pick the group the list was opened for. The background then MUST send each frame that reported roles one `fill.executeForm` with only the members for those roles, after the questions below were answered, and send nothing to the others. Frames whose URL is on the top frame's base domain take part without a question; frames on another base domain take part only after the cross-site question. The background decrypts `key` once, after the probes and the questions, only while the vault is unlocked, and drops the plaintext when the frames have replied. Each content script fills nothing when its `location.origin` differs from the origin in the message, and drops the values after writing them.

#### Scenario: Name in the page, number in a payment iframe
- **GIVEN** a checkout on `https://shop.test` with a "Name on card" field, and a frame on `https://pay.shop.test` with number, expiry and CVC fields
- **WHEN** the user fills a card from the popup
- **THEN** the top frame receives only `cardholder` and the frame receives only `number`, `expMonth`, `expYear` and `cvv`
- **AND** no question is asked

#### Scenario: Frame without card fields
- **GIVEN** a checkout with a same-site frame holding only a newsletter field
- **WHEN** the user fills a card
- **THEN** that frame receives `fill.probe` and no card member

#### Scenario: Frame navigated after the probe
- **GIVEN** a frame that answered the probe and then navigated to another origin
- **WHEN** the background sends its members
- **THEN** the content script there fills nothing

### Requirement: Cross-site frames are named and asked about
When a frame on another base domain than the top frame reported roles, the extension SHALL ask before filling, naming each such frame's host: titled "Also fill on {hosts}?" (nl "Ook invullen op {hosts}?") with "Part of this form comes from {hosts}, not from {host}. Keepiq fills it there only if you agree." (nl "Een deel van dit formulier komt van {hosts}, niet van {host}. Keepiq vult het daar alleen in als je dat goedkeurt."), and the choices "Fill", "Only {host}" (nl "Alleen {host}") and "Cancel". "Only {host}" fills the top frame and the same-site frames; Cancel fills nothing. The popup and the inline list ask in their own UI; for the context menu the background asks through the top frame's `confirm()` with the same text, where "OK" means Fill and "Cancel" fills nothing. The answer holds for that fill only. When the background, after a confirmed answer, finds a frame with roles on a host the user was not asked about, it MUST ask again and fill nothing until then.

#### Scenario: Stripe card iframe
- **GIVEN** a checkout on `https://shop.test` whose card number, expiry and CVC are in frames on `https://js.stripe.com`
- **WHEN** the user fills a card from the popup
- **THEN** the popup asks "Also fill on js.stripe.com?"
- **AND** "Fill" fills the three fields and "Cancel" fills nothing

#### Scenario: Only the page itself
- **GIVEN** the same checkout with a "Name on card" field in the top frame
- **WHEN** the user answers "Only shop.test"
- **THEN** only the name is filled and no message to the Stripe frames carries a card member

#### Scenario: Frame added after the answer
- **GIVEN** the user answered "Fill" for `js.stripe.com`
- **WHEN** the page has meanwhile added a frame on `https://ads.test` with a card number field
- **THEN** the extension asks again, naming `ads.test`, and fills nothing before the answer

### Requirement: Insecure frames ask before a card is filled
A card fill SHALL ask first when the top frame or any frame taking part is served over `http:` on a host other than `localhost`, a `127.0.0.0/8` address or `[::1]`: titled "Fill this card on an insecure site?" (nl "Deze kaart toch invullen op een onbeveiligde site?") with "This site is not secure. Anyone on the network could read the card details that are filled in." (nl "Deze site is niet beveiligd. Iedereen op het netwerk kan de ingevulde kaartgegevens lezen."), "Fill" and "Cancel", in the popup's and the inline list's own UI and through the top frame's `confirm()` for the context menu. It is asked before the cross-site question; Cancel decrypts and sends nothing. An identity fill asks no such question, as in Bitwarden and the browsers' own address autofill.

#### Scenario: Card on an http page
- **GIVEN** a checkout on `http://shop.test`
- **WHEN** the user fills a card from the popup and answers "Cancel"
- **THEN** nothing is decrypted or sent

#### Scenario: Local development site
- **GIVEN** a checkout on `http://localhost:8100`
- **WHEN** the user fills a card
- **THEN** no insecure question is asked

#### Scenario: Identity on an http page
- **GIVEN** an address form on `http://shop.test`
- **WHEN** the user fills an identity
- **THEN** it is filled without a question

### Requirement: Card and identity inline list
The content script SHALL open the inline list of ext-autofill, with the same host, key hand-off, positioning, keyboard use, visibility guard, frame rule and closing, on an empty qualifying card or identity field on focus, and on a trusted click in one, with `inline.open` naming the kind. A field qualifies for the card list when it has a card role other than `brand`, and for the identity list when it has an identity role in a group with at least two identity roles. The background opens it only when an account is logged in, "Show autofill suggestions on form fields" is on, the kind's "Show cards as suggestions" or "Show identities as suggestions" is on, and the frame is `http:` or `https:`. Opening never depends on whether the vault holds any card or identity.

When unlocked, each row shows the item's plaintext `name` and below it, for a card, its brand and last four digits ("Visa, *1111", or "Card, *1111" when no brand is derived), and for an identity its full name, or its email without a name. A use-only copy, and an item without members, shows the name alone. The rows exist only in the background while answering and in the list page while it is open. With no item of the kind the list reads "No cards in Keepiq" (nl "Geen kaarten in Keepiq") or "No identities in Keepiq" (nl "Geen identiteiten in Keepiq"); locked, it reads "Unlock Keepiq to see your cards" (nl "Ontgrendel Keepiq om je kaarten te zien") or "Unlock Keepiq to see your identities" (nl "Ontgrendel Keepiq om je identiteiten te zien") with "Unlock", one row tall, and nothing is listed, counted or decrypted. Unlocked, the list is three rows tall whatever the number of items, scrolling beyond three. Its frame is titled "Keepiq cards" or "Keepiq identities", its listbox is named "Keepiq cards" or "Keepiq identities", and on opening it announces "{n} Keepiq cards. Press Down Arrow to choose." (nl "{n} Keepiq-kaarten. Druk op pijl-omlaag om te kiezen."), the identity equivalent, the no-item text, or "Keepiq is locked. Press Down Arrow to unlock."

#### Scenario: Card number field
- **GIVEN** an unlocked vault with cards "Work Visa" and "Personal", "Personal" filled earlier
- **WHEN** the user focuses an empty card number field in the top frame
- **THEN** the list shows "Personal", then "Work Visa" with "Visa, *1111", and is three rows tall

#### Scenario: Newsletter email
- **GIVEN** a form with only an "Email" field and a "Subscribe" button
- **WHEN** the user focuses the empty field
- **THEN** no list opens

#### Scenario: Setting off
- **GIVEN** "Show cards as suggestions" off and "Show identities as suggestions" on
- **WHEN** the user focuses a card number field, then a "First name" field in an address form
- **THEN** no list opens on the card field and the identity list opens on the name field

#### Scenario: Card field inside a cross-site payment iframe
- **GIVEN** a card number field in a frame on `https://js.stripe.com` inside `https://shop.test`
- **WHEN** the user focuses it
- **THEN** no list opens, as for every frame with a cross-origin ancestor

### Requirement: Picking from the card or identity list
A pick SHALL go through the inline pick of ext-autofill (session key, offered id, unlocked vault, `inline.verify` with the press), then through the frame plan of this capability: the list's frame fills the group the list was opened for, and other frames take part as for a popup fill. The insecure and cross-site questions are asked inside the list, focus on "Cancel", Escape cancelling. The background re-checks the offered id, the lock state and the frames on the confirmed pick. After a fill the list closes and focus returns to the field without reopening it; the pick counts as interaction for the vault timeout and records last used.

#### Scenario: Pick a card
- **GIVEN** the card list open on the number field of a same-site checkout
- **WHEN** the user picks "Personal" with Enter
- **THEN** the checkout's card fields are filled, the list closes and focus is back in the number field

#### Scenario: Vault locks before the pick
- **GIVEN** the card list open with rows
- **WHEN** the vault locks on its timeout
- **THEN** the list closes and nothing can be picked from it

### Requirement: A locked vault on a payment or address form
While the active account is locked, the extension SHALL offer on a payment or address form only ways to unlock: the popup shows the unlock view, the context menu shows only "Unlock vault", and the card or identity list shows its locked text with "Unlock". Nothing is matched, counted, decrypted or sent to a content script while locked. The page can learn only what it learns on a login form: that an account is logged in, that the kind's suggestions setting is on, and that the vault is locked, from the list being present and one row tall. It cannot learn whether any card or identity exists.

#### Scenario: Locked card list
- **GIVEN** a locked vault with three cards
- **WHEN** the user focuses a card number field
- **THEN** the list reads "Unlock Keepiq to see your cards" with "Unlock", one row tall, and has the same size as for a vault without cards

#### Scenario: Unlock and fill
- **GIVEN** the locked card list
- **WHEN** the user activates "Unlock", unlocks in the popup and focuses the number field again
- **THEN** the list shows the cards

### Requirement: What the page can learn before a fill
Before the user picks an item, the page SHALL be able to learn nothing about the user's cards and identities: their number, names, brands and members stay in the background and in the list page, the list's height does not depend on them, and `fill.probe` carries only the kind. After a fill, a frame learns the members written into its own fields, and no other.

#### Scenario: Same height with one or eight cards
- **GIVEN** the same checkout page, an unlocked vault with one card, then with eight
- **WHEN** the card list opens on the number field
- **THEN** the list's host has the same size both times

### Requirement: Cards and identities only by explicit choice
Cards and identities SHALL be filled only after the user picked one in the popup, the inline list or the context menu. Fill on page load and the `autofill_login` shortcut MUST never fill a card or identity, as in Bitwarden.

#### Scenario: Page load
- **GIVEN** "Autofill on page load" on and a vault with one card
- **WHEN** a checkout page loads
- **THEN** nothing is filled

### Requirement: Stored data for card and identity fill
The extension SHALL store for this capability only:
- the four suggestion settings in `settings.<accountId>`, `storage.local`, cleared by account removal;
- the last fill time of a card or identity in `lastUsed.<accountId>`, `storage.local`, written only while unlocked and cleared on logout and account removal;
- the inline list session's kind beside its existing fields in `inlineSession.<tabId>`, `storage.session` (background memory on Firefox below 115), with no plaintext, cleared as ext-autofill specifies.

The probe replies, the frame plan, the questions' answers and the decrypted members live in background memory for one fill request only; the list rows in the list page while it is open; the members a frame receives in its content script until written. Nothing decrypted is written to any storage.

#### Scenario: After a fill
- **GIVEN** a completed card fill
- **WHEN** `storage.local` and `storage.session` are read
- **THEN** they hold no card member, brand, last four digits or answer to a question
