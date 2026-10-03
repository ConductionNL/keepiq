## ADDED Requirements

### Requirement: URL matching runs in the background on the plaintext url
The extension SHALL decide which vault items match a page in the background, using the item's plaintext `url` and the page URL of the tab or frame as reported by the browser (`tab.url`, `sender.url`), never a URL supplied by page content. Items with an empty `url`, items with `blocked: true`, and items whose type is not `login` MUST never match. Only `http:` and `https:` pages are matchable; matching results live in memory and are recomputed per request.

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

### Requirement: Match rule is the global default from settings
The extension SHALL apply the "URI match detection" default from settings to every item, with the options Base domain, Host, Starts with, Exact, Regular expression and Never, and Base domain as the shipped default. Base domain MUST be public suffix aware. A `url` that is not a valid regular expression MUST never match under the Regular expression rule. There is no per-item override (Keepiq has one `url` per item).

#### Scenario: Base domain ignores subdomains but respects the public suffix
- **GIVEN** the default rule and an item with url `https://accounts.example.co.uk/login`
- **WHEN** the page is `https://www.example.co.uk/`
- **THEN** the item matches
- **AND** an item with url `https://a.github.io` does not match the page `https://b.github.io`

#### Scenario: Host rule includes the port
- **GIVEN** the Host rule and an item with url `https://example.test:8443/`
- **WHEN** the page is `https://example.test/`
- **THEN** the item does not match

#### Scenario: Starts with, Exact and Never
- **GIVEN** an item with url `https://example.test/app/`
- **WHEN** the page is `https://example.test/app/login`
- **THEN** the item matches under Starts with and does not match under Exact
- **AND** under Never no item matches any page

#### Scenario: Invalid regular expression
- **GIVEN** the Regular expression rule and an item whose url is `https://[`
- **WHEN** matching runs on any page
- **THEN** the item does not match and no error is shown

### Requirement: Fill from the popup suggestions section
The extension SHALL fill the active tab when the user clicks a card in the popup's "Autofill suggestions" section or the Fill button on a matching item's detail view. The background MUST decrypt `login` and `key` for that one item, send exactly one `{username, password}` to the content script, and drop the plaintext once the content script has replied. The popup closes after a successful fill. Fill requires an unlocked vault.

#### Scenario: Card click fills username and password
- **GIVEN** an unlocked vault, a page with a login form, and one matching item
- **WHEN** the user clicks the item's card in "Autofill suggestions"
- **THEN** the username and password fields are filled and `input` and `change` events are dispatched
- **AND** the popup closes
- **AND** the plaintext no longer exists in the background or content script

#### Scenario: No login form on the page
- **GIVEN** a matching item and a page without recognisable login fields
- **WHEN** the user fills from the popup
- **THEN** the popup stays open and shows "Unable to autofill on this page. Copy and paste instead."

#### Scenario: Locked vault
- **GIVEN** a locked vault
- **WHEN** the user opens the popup
- **THEN** the unlock view is shown and no fill surface is offered

### Requirement: Fill covers the top frame and matching subframes only
The extension SHALL deliver the credential to the top frame and to subframes whose own URL matches the item under the active match rule. Frames whose URL does not match MUST NOT receive the credential. Frame URLs come from `sender.url` and `sender.frameId` of each frame's content script, never from page content.

#### Scenario: Login form inside a same-site iframe
- **GIVEN** a page on `https://example.test/` embedding a login form in an iframe on `https://login.example.test/` and an item matching under Base domain
- **WHEN** the user fills
- **THEN** the iframe's fields are filled

#### Scenario: Cross-origin iframe that does not match
- **GIVEN** a page embedding an iframe on `https://ads.other.test/` with a password field
- **WHEN** the user fills an item for `example.test`
- **THEN** the iframe receives no message containing the credential

### Requirement: Insecure page warning
The extension SHALL warn before filling an item whose `url` is `https:` into a page served over `http:`. From the popup the warning is a confirmation in the popup; from the context menu, the shortcut or page load the background MUST ask the top frame's content script for confirmation before sending the credential. Declining cancels the fill and nothing is sent.

#### Scenario: User declines
- **GIVEN** an item with url `https://example.test/` and the page `http://example.test/login`
- **WHEN** the user fills from the popup and declines the warning
- **THEN** nothing is decrypted or sent to the content script

#### Scenario: User accepts from the context menu
- **GIVEN** the same item and page
- **WHEN** the user picks the item in the context menu and accepts the confirmation shown in the page
- **THEN** the fill proceeds

### Requirement: Field detection in the content script
The content script SHALL locate username and password fields by input type, `autocomplete` values (`username`, `current-password`, `email`), and name, id, placeholder and label heuristics, traversing open shadow roots. Hidden fields (`type=hidden`, not rendered, zero size, `aria-hidden="true"`) MUST be ignored. A form with only a username field, or only a password field, is filled partially. Fields are never submitted by the extension.

#### Scenario: Multi-step login with username only
- **GIVEN** a page showing only an email field with `autocomplete="username"`
- **WHEN** the user fills
- **THEN** the username is filled and the password is discarded

#### Scenario: Hidden honeypot password field
- **GIVEN** a form with a visible password field and a second password field with `display: none`
- **WHEN** the user fills
- **THEN** only the visible password field receives the value

#### Scenario: Fields inside an open shadow root
- **GIVEN** a login form rendered inside an open shadow root
- **WHEN** the user fills
- **THEN** the fields are found and filled

### Requirement: Context menu on editable fields
The extension SHALL add a "Keepiq" context menu on editable fields with the entries Autofill (a submenu of items matching the current tab, at most 10, then "Open Keepiq"), Copy username, Copy password, and Generate password and copy. Copy username and Copy password use the matching items as a submenu when more than one matches. When no item matches, the Autofill entry reads "No matching logins". The menu MUST be rebuilt on tab activation, on tab URL change and on vault change. When the vault is locked, the menu shows a single "Unlock vault" entry that opens the popup. The menu is hidden when the "Show context menu" setting is off.

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

### Requirement: Copy from the context menu clears after the configured delay
The extension SHALL copy the decrypted username or password, or a freshly generated password, to the clipboard when picked from the context menu, and MUST clear the clipboard after the "Clear clipboard" delay from settings when one is set. On Chrome MV3 the write happens in an offscreen document; on Firefox MV2 in the background page. The plaintext lives in memory only for the duration of the copy.

#### Scenario: Copy password with a clear delay
- **GIVEN** a matching item and the clear delay set to 30 seconds
- **WHEN** the user picks Copy password
- **THEN** the clipboard holds the password
- **AND** 30 seconds later the clipboard is cleared if it still holds that value

#### Scenario: Generate password and copy
- **GIVEN** an unlocked vault
- **WHEN** the user picks Generate password and copy
- **THEN** a password generated with the saved generator options is on the clipboard

### Requirement: Keyboard shortcut fills the last used login
The extension SHALL declare a manifest command bound to `Ctrl+Shift+L` (`Cmd+Shift+L` on macOS) that fills the active tab with the matching item most recently used on that site, or the single matching item when none has been used. When no item matches or the vault is locked, the command opens the popup instead.

#### Scenario: Several matches, one used before
- **GIVEN** three matching items of which one was filled earlier
- **WHEN** the user presses the shortcut
- **THEN** the previously used item is filled

#### Scenario: No match
- **GIVEN** no item matches the tab
- **WHEN** the user presses the shortcut
- **THEN** the popup opens

### Requirement: Last used is recorded locally
The extension SHALL record the time of each successful fill per item id in `storage.local`, scoped to the account, cleared on logout and account removal. It orders "Autofill suggestions" and the context menu by last used then name and selects the shortcut and page-load candidate.

#### Scenario: Order after a fill
- **GIVEN** items "Work" and "Personal" both matching the tab, neither used
- **WHEN** the user fills "Personal" and reopens the popup
- **THEN** "Personal" is listed first

### Requirement: Fill on page load is opt-in and unambiguous
The extension SHALL fill on page load only when the "Autofill on page load" setting is on, the vault is unlocked, the top frame reports a password field, and exactly one item matches or one of the matching items was used before on that site. Otherwise nothing is filled and no prompt is shown. The setting ships off.

#### Scenario: Setting off
- **GIVEN** the setting off and one matching item
- **WHEN** a login page loads
- **THEN** nothing is filled

#### Scenario: Ambiguous matches
- **GIVEN** the setting on and two matching items never used
- **WHEN** a login page loads
- **THEN** nothing is filled

### Requirement: Content script holds no vault state
The content script MUST receive vault data only as the single `{username, password}` of one fill after an explicit user action or a permitted page-load fill, and MUST clear those values from memory after writing them into the fields. The background MUST verify `sender.tab`, `sender.frameId` and `sender.url` on every message from a content script and ignore messages whose sender does not correspond to the tab being served.

#### Scenario: Forged request from page context
- **GIVEN** page script that posts a message shaped like `fill.request`
- **WHEN** the background receives it without a matching `sender.tab` and extension `sender.url`
- **THEN** it is ignored and no credential is decrypted

#### Scenario: Plaintext lifetime
- **GIVEN** a completed fill
- **WHEN** the content script has written both fields
- **THEN** it holds no reference to the credential and a later message cannot read it back
