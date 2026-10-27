## MODIFIED Requirements

### Requirement: URL matching runs in the background on the plaintext url
The extension SHALL decide which vault items match a page in the background, using the item's plaintext `url` and the page URL of the tab or frame as reported by the browser (`tab.url`, `sender.url`), never a URL supplied by page content. Items with an empty `url` and items with `blocked: true` MUST never match. Only `login` items match for filling a login; only `totp` items match for the surfaces of `totp-autofill` (pairing, the inline list on a code field, Copy verification code); items of any other type never match. Only `http:` and `https:` pages are matchable; matching results live in memory and are recomputed per request.

#### Scenario: Item without a url never matches
- **GIVEN** an unlocked vault with a `login` item whose `url` is empty
- **WHEN** the user opens the popup on any page
- **THEN** the item is absent from "Autofill suggestions" and from the context menu

#### Scenario: Extension and browser pages have no matches
- **GIVEN** the active tab shows a `chrome://`, `about:` or extension page
- **WHEN** the user opens the popup
- **THEN** the "Autofill suggestions" section shows "Autofill is not available on this page" and no item is offered

#### Scenario: Blocked rows are never offered
- **GIVEN** a cached row with `blocked: true` whose `url` matches the page
- **WHEN** matching runs
- **THEN** the row is excluded from every fill surface

#### Scenario: Authenticator items are not logins
- **GIVEN** a `totp` item whose `url` matches the page and no `login` item for it
- **WHEN** the user opens the popup
- **THEN** "Autofill suggestions" shows "No items for <host>" and the context menu's Autofill entry reads "No matching logins"
- **AND** Copy verification code lists the `totp` item

### Requirement: Content script holds no vault state
The content script MUST receive vault data only as the single `{username, password, code}` of one fill after an explicit user action or a permitted page-load fill, where `code` is the current code of the one `totp` item paired with that login or picked from the inline list on a code field, or `null`, and is always `null` on a page-load fill. It MUST clear those values from memory after writing them into the fields, and drop a code it has no code field for. It never receives the inline list's rows (see `inline-list`), a seed, or a code for a frame the paired item does not match. The background MUST verify `sender.tab`, `sender.frameId` and `sender.url` on every message from a content script and ignore messages whose sender does not correspond to the tab being served.

#### Scenario: Forged request from page context
- **GIVEN** page script that posts a message shaped like `fill.request`
- **WHEN** the background receives it without a matching `sender.tab` and extension `sender.url`
- **THEN** it is ignored and no credential is decrypted

#### Scenario: Plaintext lifetime
- **GIVEN** a completed fill
- **WHEN** the content script has written both fields
- **THEN** it holds no reference to the credential and a later message cannot read it back

#### Scenario: Code without a code field
- **GIVEN** a login with a paired `totp` item and a login form with no code field
- **WHEN** the user fills the login
- **THEN** the content script fills the username and password, writes the code nowhere and holds no reference to it

#### Scenario: Page-load fill carries no code
- **GIVEN** fill on page load on and a login with a paired `totp` item
- **WHEN** the page loads and the login fills
- **THEN** the `fill.execute` message's `code` is `null`
