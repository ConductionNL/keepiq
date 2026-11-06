## ADDED Requirements

### Requirement: Card fields are never login fields
A field with a card role (`card-identity-fill`) MUST NOT be classified as a username, current password, new password or one-time code, also when it is `type=password`. A group whose only password fields have card roles is classified as other, never as a login, password change or sign-up, so it gets no login list, no login fill and no login capture. A password field without card evidence in the same group keeps its login role.

#### Scenario: Security code as a password field
- **GIVEN** a checkout form with a card number field and a `type=password` field labelled "CVC"
- **WHEN** the fields are classified
- **THEN** the CVC field has the role `securityCode` and the group is not a login
- **AND** filling a login into the page leaves the CVC field empty

#### Scenario: Login and payment on one page
- **GIVEN** a page with a login form and a separate payment form with a `type=password` CVV
- **WHEN** the user fills a login
- **THEN** only the login form's fields are filled

## MODIFIED Requirements

### Requirement: Context menu on editable fields
The extension SHALL add a "Keepiq" context menu on editable fields with the entries Autofill (a submenu of items matching the current tab, at most 10, then "Open Keepiq"), Copy username, Copy password, Autofill card (nl "Kaart invullen"), Autofill identity (nl "Identiteit invullen"), and Generate password and copy. Copy username and Copy password use the matching items as a submenu when more than one matches, at most 10, and are disabled when none has a username, or none but use-only copies match, respectively. Autofill card and Autofill identity are submenus of the account's cards and identities (`card-identity-fill`, not matched by site, blocked rows left out), at most 10 each by last used then name, then "Open Keepiq"; each is left out when the vault holds none, and picking one fills it into the tab as the popup does. A separator precedes "Open Keepiq". When no item matches, the Autofill entry reads "No matching logins". The menu MUST be rebuilt on tab activation, on tab URL change and on vault change. When the vault is locked, the menu shows a single "Unlock vault" entry that opens the popup. The menu is hidden when the "Enable context menu options" setting is off, when no account is added and when the active account is logged out.

#### Scenario: Pick an item from the submenu
- **GIVEN** an unlocked vault and two items matching the tab
- **WHEN** the user right-clicks a field and picks the second item under Autofill
- **THEN** that item is filled into the tab

#### Scenario: More than ten matches
- **GIVEN** twelve items matching the tab
- **WHEN** the user opens the Autofill submenu
- **THEN** the first ten by last-used time then name are listed followed by "Open Keepiq", which opens the popup

#### Scenario: Locked vault
- **GIVEN** a locked vault
- **WHEN** the user right-clicks a field
- **THEN** the Keepiq menu offers only "Unlock vault", which opens the popup on the unlock view

#### Scenario: Autofill card
- **GIVEN** an unlocked vault with two cards and a checkout page
- **WHEN** the user right-clicks the card number field and picks a card under Autofill card
- **THEN** that card is filled into the checkout, after the insecure and cross-site questions where they apply

#### Scenario: No identities
- **GIVEN** an unlocked vault without identities
- **WHEN** the user right-clicks a field
- **THEN** the menu has no Autofill identity entry

### Requirement: Content script holds no vault state
The content script MUST receive vault data only as the single `{username, password}` of one login fill, or as the members one card or identity fill sends to its frame (`card-identity-fill`), after an explicit user action or a permitted page-load fill of a login, and MUST clear those values from memory after writing them into the fields. It never receives the inline list's rows (see `inline-list`), and `fill.probe` carries no vault data. The background MUST verify `sender.tab`, `sender.frameId` and `sender.url` on every message from a content script and ignore messages whose sender does not correspond to the tab being served.

#### Scenario: Forged request from page context
- **GIVEN** page script that posts a message shaped like `fill.request`
- **WHEN** the background receives it without a matching `sender.tab` and extension `sender.url`
- **THEN** it is ignored and no credential is decrypted

#### Scenario: Plaintext lifetime
- **GIVEN** a completed fill
- **WHEN** the content script has written both fields
- **THEN** it holds no reference to the credential and a later message cannot read it back

#### Scenario: Card members after a fill
- **GIVEN** a completed card fill
- **WHEN** the content script has written its fields
- **THEN** it holds no reference to the members and never held the PIN, the BSN or another frame's members
