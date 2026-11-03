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
The extension SHALL apply the "Default URI match detection" setting to every item, with the options Base domain, Host, Starts with, Exact, Regular expression and Never, and Base domain as the shipped default. Base domain MUST be public suffix aware. A `url` that is not a valid regular expression MUST never match under the Regular expression rule. Under the Regular expression rule a `url` is ordinary unless it is written as a pattern: it starts with `^`, has a `?` in its scheme (`https?://`), or has one of the characters `^ $ * + ( ) [ ] { } | \` in its host, the part before its first `/`, `?` or `#` after any `scheme://` (an IPv6 host's brackets aside); a `.`, or anything in the path, query or fragment, does not make it a pattern, so `https://en.wikipedia.org/wiki/Foo_(bar)` stays an address. Only such a `url` is tested as a case-insensitive regular expression against the page URL, anchored at the start of the page URL when it starts with a scheme. An ordinary `url` MUST never be tested as a pattern, whatever its scheme: a web URL matches by Base domain, and any other `url` (`ftp://`, `ssh://`, `androidapp://`, `mailto:`) matches no page, as under every other rule. There is no per-item override (Keepiq has one `url` per item).

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

#### Scenario: Ordinary URL under the Regular expression rule
- **GIVEN** the Regular expression rule and an item whose url is `https://bank.com`
- **WHEN** the page is `https://bank.com.evil.net/` or `https://evil.net/?r=https://bank.com`
- **THEN** the item does not match
- **AND** it matches the page `https://login.bank.com/x`, and an item whose url is `bank.com` does not match `https://bankxcom.net/`
- **AND** an item whose url is `^https://([a-z]+\.)?bank\.com/` matches `https://login.bank.com/x` as a pattern

#### Scenario: Syntax in the query, an IPv6 host, or a ? in the scheme
- **GIVEN** the Regular expression rule
- **WHEN** an item's url is `https://login.example.com/oauth?scope=openid+profile`, or `https://[::1]:8080/`
- **THEN** it matches by base domain, so the first does not match `https://evil.net/https://login.example.com/oauthscope=openidprofile`
- **AND** an item whose url is `https?://bank.com/login` is tested as a pattern and matches `http://bank.com/login`

#### Scenario: Syntax only in the path under the Regular expression rule
- **GIVEN** the Regular expression rule
- **WHEN** an item's url is `https://en.wikipedia.org/wiki/Foo_(bar)`, `https://example.com/c++/login` or `https://bank.com/login*`
- **THEN** it matches by base domain, its own page included, and does not match `https://evil.net/?u=https://bank.com/login`
- **AND** an item whose url is `^https://example\.test/app1/` is tested as a pattern and does not match `https://example.test/app2/login`

#### Scenario: A pattern with a scheme is anchored
- **GIVEN** the Regular expression rule and an item whose url is `https://(www\.)?bank\.com/`
- **WHEN** the page is `https://evil.net/?u=https://bank.com/`
- **THEN** the item does not match
- **AND** it matches `https://www.bank.com/login`

#### Scenario: A url that is no web URL under the Regular expression rule
- **GIVEN** the Regular expression rule and an item whose url is `ftp://files.bank.com`, `ssh://git.bank.com` or `androidapp://com.bank.app`
- **WHEN** the page is `https://evil.net/login?ftp://files.bank.com`, or any other page
- **THEN** the item does not match
- **AND** with autofill on page load on, no page gets it filled

### Requirement: Fill from the popup suggestions section
The extension SHALL fill the active tab when the user clicks a card in the popup's "Autofill suggestions" section or its Fill button, or the Fill button on a matching item's detail view. The background MUST decrypt `login` and `key` for that one item, send exactly one `{username, password}` to the content script, and drop the plaintext once the content script has replied. The popup closes after a fill that filled at least one field; a popped-out window stays open. Fill requires an unlocked vault.

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

#### Scenario: Popped-out window stays open
- **GIVEN** the popup popped out into its own window, a page with a login form, and one matching item
- **WHEN** the user fills from the popped-out window
- **THEN** the fields are filled
- **AND** the popped-out window stays open

#### Scenario: Locked vault
- **GIVEN** a locked vault
- **WHEN** the user opens the popup
- **THEN** the unlock view is shown and no fill surface is offered

### Requirement: Fill covers the top frame and matching subframes only
The extension SHALL deliver the credential to the top frame and to subframes whose own URL matches the item under the active match rule. Frames whose URL does not match MUST NOT receive the credential. Frame URLs come from `sender.url` and `sender.frameId` of each frame's `page_ready`, never from page content, recorded per tab in background memory and forgotten when the tab starts loading a new page. Without a record (the worker restarted), only the top frame receives the credential, after the background has checked that the tab's URL matches. Each content script MUST fill nothing when its own origin differs from the origin the background matched.

#### Scenario: Login form inside a same-site iframe
- **GIVEN** a page on `https://example.test/` embedding a login form in an iframe on `https://login.example.test/` and an item matching under Base domain
- **WHEN** the user fills
- **THEN** the iframe's fields are filled

#### Scenario: Cross-origin iframe that does not match
- **GIVEN** a page embedding an iframe on `https://ads.other.test/` with a password field
- **WHEN** the user fills an item for `example.test`
- **THEN** the iframe receives no message containing the credential

#### Scenario: Frame navigated after it registered
- **GIVEN** a recorded same-site iframe that has since navigated to `https://other.test/`
- **WHEN** the user fills an item for `example.test`
- **THEN** the content script in that frame fills nothing

### Requirement: Insecure page warning
The extension SHALL warn before filling an item whose `url` is `https:` or a bare host into a page served over `http:`. From the popup the warning is a confirmation in the popup; from the context menu, the shortcut or page load the background MUST ask the top frame's content script for confirmation before sending the credential. Declining cancels the fill and nothing is sent. The popup's dialog is titled "Do you still wish to fill this login?" with the warning "Warning: This is an unsecured HTTP page, and any information you submit can potentially be seen and changed by others. This login was originally saved on a secure (HTTPS) page."; the page's `confirm()` reads the same warning ending "…secure (HTTPS) page, <host>.", then "Do you still wish to fill this login?", without ", <host>" when the item names none.

#### Scenario: User declines
- **GIVEN** an item with url `https://example.test/` and the page `http://example.test/login`
- **WHEN** the user fills from the popup and declines the warning
- **THEN** nothing is decrypted or sent to the content script

#### Scenario: User accepts from the context menu
- **GIVEN** the same item and page
- **WHEN** the user picks the item in the context menu and accepts the confirmation shown in the page
- **THEN** the fill proceeds

### Requirement: Field detection in the content script
The content script SHALL classify fields as username, current password, new password (a confirm field included), one-time code or other by weighing signals together: input type, `autocomplete` tokens (`username`, `email`, `current-password`, `new-password`, `one-time-code` and the other WHATWG tokens such as `cc-*`, `tel` and `name`), name, id, placeholder and label, the group's shape (field count, a second or confirm password field) and its submit button's text, traversing open shadow roots. A group is a `<form>`, fields in open shadow roots under it included. Without a `<form>`, each visible password field's anchor is its nearest ancestor, across shadow hosts, holding a keyword button (login, next or sign-up), or the document when none does; password fields with the same anchor form one group, every other field belongs to the deepest anchor containing it, and the username and submit button are searched only within the group. A group without a password field is anchored the same way on the focused field. In every group, `<form>` groups included, the username and submit button are searched only within the group: the username is the closest visible text or email field before the password field in DOM order, or a `tel` field with username evidence (a `username` or `tel` token, or a name or label meaning username, login or phone) when the group has a login or next keyword or a current password, skipping, without a `<form>`, a field whose nearest ancestor holding a subscribe or sign-up button does not contain the password field; the submit button is a `<form>`'s default submit button when its text is a keyword, and otherwise the closest keyword button after the password field, or after the anchoring field. An `autocomplete` token tips the classification but never decides it alone, so strong other evidence can outweigh it. It SHALL classify each group, with or without a `<form>`, as a login (a current password; or a username-only step, which needs username evidence, a `username` or `email` token or a label or name meaning username, login or email, and no sign-up or subscribe keyword, while a login, next or continue keyword counts in its favour; without a `<form>` it also needs such a keyword or a `username` or `email` token), a password change (a current and a new password, with or without a username, or a new password and its confirmation without a username), a sign-up (a username and a new password, usually confirmed, without a current password) or other. `autocomplete="off"` on a field or its form, and equivalents such as `false` or an unknown token, MUST be ignored and never block filling, the inline list, the context menu, the shortcut, fill on page load or login capture. A field classified as a one-time code MUST never receive a username or password. Hidden fields (`type=hidden`, not rendered, zero size, `aria-hidden="true"`), disabled fields and read-only fields MUST be ignored. A group with only a username field, or only a password field, is filled partially. Fields are never submitted by the extension.

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

#### Scenario: Login form with autocomplete off
- **GIVEN** a login form with `autocomplete="off"` on the form and on both fields
- **WHEN** the user fills
- **THEN** the username and password fields are filled

#### Scenario: Login without a form
- **GIVEN** a username and a password field and a `type="button"` "Sign in" button with no `<form>` around them
- **WHEN** the user fills
- **THEN** the group is classified as a login and both fields are filled

#### Scenario: Formless username-only step
- **GIVEN** no `<form>`, an email field labelled "Email address" and a "Next" button
- **WHEN** the user fills
- **THEN** the email field is filled and the password is discarded

#### Scenario: Newsletter form
- **GIVEN** a form with only an "Email" field and a "Subscribe" button
- **WHEN** the fields are classified
- **THEN** the form is not a login and nothing in it is filled

#### Scenario: Login password mislabelled new-password
- **GIVEN** a form with a username field, one password field marked `autocomplete="new-password"` and a "Log in" button
- **WHEN** the user fills
- **THEN** the field is classified as the current password and both fields are filled

#### Scenario: One-time code field
- **GIVEN** a second login step with a text field named `code`, `inputmode="numeric"`, `maxlength="6"` and `autocomplete="one-time-code"`
- **WHEN** the user fills
- **THEN** the field is classified as a one-time code and receives neither the username nor the password

### Requirement: Context menu on editable fields
The extension SHALL add a "Keepiq" context menu on editable fields with the entries Autofill (a submenu of items matching the current tab, at most 10, then "Open Keepiq"), Copy username, Copy password, and Generate password and copy. Copy username and Copy password use the matching items as a submenu when more than one matches, at most 10, and are disabled when none has a username, or none but use-only copies match, respectively. A separator precedes "Open Keepiq". When no item matches, the Autofill entry reads "No matching logins". The menu MUST be rebuilt on tab activation, on tab URL change and on vault change. When the vault is locked, the menu shows a single "Unlock vault" entry that opens the popup. The menu is hidden when the "Enable context menu options" setting is off, when no account is added and when the active account is logged out.

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
The extension SHALL copy the decrypted username or password, or a freshly generated password, to the clipboard when picked from the context menu, and MUST clear the clipboard after the "Clear clipboard" delay from settings unless it is Never. On Chrome MV3 the write happens in an offscreen document; on Firefox MV2 in the background page. The plaintext lives in memory only for the duration of the copy.

#### Scenario: Copy password with a clear delay
- **GIVEN** a matching item and the clear delay set to 30 seconds
- **WHEN** the user picks Copy password
- **THEN** the clipboard holds the password
- **AND** 30 seconds later the clipboard is overwritten

#### Scenario: Generate password and copy
- **GIVEN** an unlocked vault
- **WHEN** the user picks Generate password and copy
- **THEN** a password generated with the saved generator options is on the clipboard
- **AND** it is recorded in the generator history, as `ext-generator` specifies

### Requirement: Keyboard shortcut fills the last used login
The extension SHALL declare a manifest command bound to `Ctrl+Shift+L` (`Cmd+Shift+L` on macOS) that fills the active tab with the matching item most recently used on that site, or the single matching item when none has been used. When no item matches, several match and none of them was used before, or the vault is locked, the command opens the popup instead.

#### Scenario: Several matches, one used before
- **GIVEN** three matching items of which one was filled earlier
- **WHEN** the user presses the shortcut
- **THEN** the previously used item is filled

#### Scenario: Several matches, none used before
- **GIVEN** two matching items, neither filled before
- **WHEN** the user presses the shortcut
- **THEN** nothing is filled and the popup opens

#### Scenario: No match
- **GIVEN** no item matches the tab
- **WHEN** the user presses the shortcut
- **THEN** the popup opens

### Requirement: Last used is recorded locally
The extension SHALL record the time of each successful fill per item id in `storage.local`, scoped to the account, cleared on logout and account removal. It orders "Autofill suggestions", the inline list and the context menu by last used then name and selects the shortcut and page-load candidate.

#### Scenario: Order after a fill
- **GIVEN** items "Work" and "Personal" both matching the tab, neither used
- **WHEN** the user fills "Personal" and reopens the popup
- **THEN** "Personal" is listed first

### Requirement: Fill on page load is opt-in and unambiguous
The extension SHALL fill on page load only when the "Autofill on page load" setting is on, the "Default autofill setting for login items" is "Autofill on page load if enabled" (Keepiq has no per-item setting, so Never applies to every item), the vault is unlocked, the top frame reports a password field, and exactly one item matches or one of the matching items was used before on that site. Otherwise nothing is filled and no prompt is shown. The setting ships off.

#### Scenario: Setting off
- **GIVEN** the setting off and one matching item
- **WHEN** a login page loads
- **THEN** nothing is filled

#### Scenario: Default autofill setting is Never
- **GIVEN** "Autofill on page load" on, "Default autofill setting for login items" set to Never, and one matching item
- **WHEN** a login page loads
- **THEN** nothing is filled

#### Scenario: Ambiguous matches
- **GIVEN** the setting on and two matching items never used
- **WHEN** a login page loads
- **THEN** nothing is filled

### Requirement: Content script holds no vault state
The content script MUST receive vault data only as the single `{username, password}` of one fill after an explicit user action or a permitted page-load fill, and MUST clear those values from memory after writing them into the fields. It never receives the inline list's rows (see `inline-list`). The background MUST verify `sender.tab`, `sender.frameId` and `sender.url` on every message from a content script and ignore messages whose sender does not correspond to the tab being served.

#### Scenario: Forged request from page context
- **GIVEN** page script that posts a message shaped like `fill.request`
- **WHEN** the background receives it without a matching `sender.tab` and extension `sender.url`
- **THEN** it is ignored and no credential is decrypted

#### Scenario: Plaintext lifetime
- **GIVEN** a completed fill
- **WHEN** the content script has written both fields
- **THEN** it holds no reference to the credential and a later message cannot read it back
