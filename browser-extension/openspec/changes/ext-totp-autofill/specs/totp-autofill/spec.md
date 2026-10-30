## Purpose

Puts the current code of a login's authenticator item on the clipboard after a fill and into the page's one-time-code field, as Bitwarden does with a login's TOTP. In Keepiq the seed lives in a separate `totp` item, so the extension pairs the two.

## ADDED Requirements

### Requirement: A login pairs with one authenticator item
The extension SHALL pair a filled login with a `totp` item in the background, from the active account's snapshot, as follows:
- the candidates are the `totp` items matching the URL of the frame being filled, under the URI match rule from settings, as `autofill` matches logins; blocked rows and use-only copies are never candidates;
- of those, the items whose decrypted `login` equals the filled username, both trimmed and compared case-insensitively; when none does, the items whose `login` is empty;
- exactly one item pairs; none or several pair nothing.

The decrypted `login` values exist only in background memory during the pairing. Nothing about a pairing is stored. An item whose decrypted `key` is not a valid seed (`src/totp/totp.ts`) pairs, and then yields no code: nothing is copied or filled, and no other item is tried.

#### Scenario: Imported pair
- **GIVEN** a login "GitHub" (`url` `https://github.com`, username `alice`) and a `totp` item "GitHub (TOTP)" with the same `url` and `login`, as the Bitwarden importer creates them
- **WHEN** the user fills "GitHub" on `https://github.com/login`
- **THEN** "GitHub (TOTP)" is paired

#### Scenario: Authenticator item without a username
- **GIVEN** a login for `example.test` with username `alice`, and one `totp` item for `example.test` with an empty `login`
- **WHEN** the user fills the login
- **THEN** the `totp` item is paired

#### Scenario: Two accounts on one site
- **GIVEN** logins `alice` and `bob` for `example.test`, and `totp` items for `example.test` with `login` `alice` and `bob`
- **WHEN** the user fills `bob`
- **THEN** the `totp` item with `login` `bob` is paired

#### Scenario: Ambiguous
- **GIVEN** a login `alice` for `example.test` and two `totp` items for `example.test`, both with an empty `login`
- **WHEN** the user fills the login
- **THEN** nothing is paired, and no code is copied or filled

#### Scenario: Other site's authenticator
- **GIVEN** a login for `example.test` and a `totp` item whose `url` is `https://other.test`
- **WHEN** the user fills the login on `https://example.test/`
- **THEN** nothing is paired

#### Scenario: Use-only authenticator copy
- **GIVEN** a login for `example.test` and the only matching `totp` item is a use-only copy
- **WHEN** the user fills the login
- **THEN** nothing is paired

### Requirement: The paired code is copied after a fill
When "Copy TOTP automatically" is on and a fill from the popup, the inline list, the context menu or the keyboard shortcut filled at least one field, the background SHALL compute the paired item's current code (RFC 6238, `src/totp/totp.ts`) and write it to the clipboard, through the offscreen document on Chrome MV3 and the background page on Firefox MV2, then clear the clipboard after the "Clear clipboard" delay from settings unless it is Never. Fill on page load MUST NOT copy a code. The code is computed after the fill, from the seed decrypted for it, and both exist only in background memory until the copy has been written. The copy replaces any pending clear, as every copy does.

#### Scenario: Popup fill copies the code
- **GIVEN** an unlocked vault, a login with a paired `totp` item, "Copy TOTP automatically" on and the clear delay at 30 seconds
- **WHEN** the user fills the login from "Autofill suggestions"
- **THEN** the clipboard holds the item's current code
- **AND** 30 seconds later the clipboard is overwritten

#### Scenario: Setting off
- **GIVEN** "Copy TOTP automatically" off and a login with a paired `totp` item
- **WHEN** the user fills the login
- **THEN** the clipboard is not written

#### Scenario: Fill on page load
- **GIVEN** fill on page load on and a login with a paired `totp` item that fills on load
- **WHEN** the page loads
- **THEN** the clipboard is not written and no code field is filled

#### Scenario: Nothing filled
- **GIVEN** a login with a paired `totp` item and a page where neither a login field nor a code field is found
- **WHEN** the user fills the login from the popup
- **THEN** the clipboard is not written and the popup shows "Unable to autofill on this page. Copy and paste instead."

#### Scenario: Invalid seed
- **GIVEN** a login whose paired `totp` item's `key` decrypts to `not-a-seed`
- **WHEN** the user fills the login
- **THEN** the username and password are filled and the clipboard is not written

### Requirement: The paired code fills a one-time-code field
A fill from the popup, the context menu or the keyboard shortcut, and an inline pick, SHALL also hand each frame that receives the login the paired item's current code when that item matches the frame, and the content script fills it into one code group of the frame (Code fields): the anchored list's group for an inline pick, else the focused one, else the first visible, enabled and empty one. A code group that already holds a value is left alone. Fill on page load MUST NOT hand over a code. On a page with only a code field, such a fill fills just the code, and counts as a fill for the toast, last used and the copy. The code is filled whether or not "Copy TOTP automatically" is on.

#### Scenario: Second step after the password
- **GIVEN** a login with a paired `totp` item, and `test-site/otp.html` open after the password step
- **WHEN** the user fills the login from the popup, the context menu or the shortcut
- **THEN** the "Authentication code" field holds the current code and no other field changes

#### Scenario: Login and code on one page
- **GIVEN** a form with a username, a password and a one-time-code field, and a login with a paired `totp` item
- **WHEN** the user fills the login
- **THEN** all three fields are filled

#### Scenario: Code field already filled
- **GIVEN** a code field holding `123456`
- **WHEN** the user fills the paired login
- **THEN** the code field still holds `123456`

#### Scenario: No pair
- **GIVEN** a login with no paired `totp` item and a page with only a code field
- **WHEN** the user fills the login from the popup
- **THEN** nothing is filled and the popup shows "Unable to autofill on this page. Copy and paste instead."

### Requirement: Code fields
The content script SHALL treat as a code group either one field classified as a one-time code (`autofill`, Field detection) or a run of four to eight visible, enabled, adjacent one-character text, `tel` or `number` inputs in one group, in document order. A code that has as many characters as the run has inputs is filled one character per input, in order; otherwise the whole code goes into the first input. Each input gets the events `fillField()` dispatches. A field whose label, name, id or placeholder speaks of a recovery or backup code (English "recovery", "backup"; Dutch "herstel", "back-up") is not a one-time-code field and is never filled with a code.

#### Scenario: Split code
- **GIVEN** six inputs with `maxlength="1"` in a row, as in `test-site/otp-split.html`
- **WHEN** the paired code `492039` is filled
- **THEN** the inputs hold `4`, `9`, `2`, `0`, `3`, `9`

#### Scenario: Recovery code field
- **GIVEN** a field labelled "Recovery code" with `autocomplete="one-time-code"`, as in `test-site/recovery-code.html`
- **WHEN** the user fills a login with a paired `totp` item
- **THEN** the field stays empty and no inline list opens on it

### Requirement: The inline list on a code field lists authenticator items
The inline list SHALL open on a code field in its code mode, under the same setting, states, keyboard model, position, frames and visibility guard as on a login field (`inline-list`). For an unlocked vault it lists the `totp` items matching the frame's URL, chosen and ordered as `autofill` orders logins, without blocked rows and use-only copies. Each row shows the item's plaintext `name`, its decrypted `login` when present, and the current code in two halves with the seconds left; the row of an item without a valid seed shows "Not a valid authenticator secret" and cannot be picked. The code and countdown are hidden from assistive technology; each option is named "<name>, <username>". When the shortest period ends, the list page asks the background for the rows again, so it never shows an expired code and never computes one itself. Texts in code mode:
- frame title "Keepiq verification codes", listbox name "Keepiq verification codes for <host>";
- no match: "No verification codes for <host>";
- locked: "Unlock Keepiq to see your verification codes", with "Unlock";
- status: "<n> Keepiq verification codes. Press Down Arrow to choose." ("1 Keepiq verification code. Press Down Arrow to choose." for one), "No Keepiq verification codes for <host>" or "Keepiq is locked. Press Down Arrow to unlock.".

The codes exist in the background while it answers and in the list page while it is open, never in storage or the content script.

#### Scenario: Two authenticator items
- **GIVEN** `totp` items "Work" (`login` `alice@work.test`) and "Personal" (no `login`) for `example.test`, "Personal" filled earlier
- **WHEN** the user focuses the empty code field on `https://example.test/2fa`
- **THEN** the list shows "Personal" with its code, then "Work" with `alice@work.test` and its code

#### Scenario: Code changes while open
- **GIVEN** the list open with a 30-second item whose code has 3 seconds left
- **WHEN** the period ends
- **THEN** the row shows the new code within a second

#### Scenario: Locked vault on a code field
- **GIVEN** a locked vault with a `totp` item for the site
- **WHEN** the user focuses the code field
- **THEN** the list reads "Unlock Keepiq to see your verification codes" with "Unlock", and no name, username or code

### Requirement: Picking an authenticator item fills its code
When the user picks a code row, the background SHALL check, as for a login pick, that the row was offered in this list, that it still matches the frame's URL, that the vault is unlocked and that the host frame confirms the list was visible; then decrypt that one item's `key`, compute the current code at the moment of the pick and send it once, to the list's frame only, where the content script fills the code group of the field the list was opened for. An `https` item on an `http` frame asks "Fill on this insecure site?" first, as for a login. The pick records the item's last-used time and counts as interaction; the list closes and focus returns to the field. The pick does not copy the code.

#### Scenario: Pick a code
- **GIVEN** the list open on the code field with "Work" listed
- **WHEN** the user clicks "Work"
- **THEN** the code field holds the current code of "Work"
- **AND** the clipboard is not written

#### Scenario: Pick on a split code
- **GIVEN** the list open on the first of six one-character inputs
- **WHEN** the user picks an item
- **THEN** the six inputs hold the code's six digits

### Requirement: "Copy verification code" in the context menu
The extension SHALL add "Copy verification code" to the "Keepiq" context menu, after Copy password, listing the `totp` items matching the tab as Copy username lists logins: one entry for one, a submenu of at most 10 by last used then name for several, disabled when none matches. Picking one decrypts that item's `key`, computes the current code and copies it, clearing after the "Clear clipboard" delay. Use-only and blocked items are not listed. While locked the menu still offers only "Unlock vault", as `autofill` specifies.

#### Scenario: Copy from the menu
- **GIVEN** an unlocked vault with one `totp` item matching the tab
- **WHEN** the user right-clicks a field and picks Copy verification code
- **THEN** the clipboard holds that item's current code

#### Scenario: No authenticator item
- **GIVEN** no `totp` item matches the tab
- **WHEN** the user opens the Keepiq menu
- **THEN** Copy verification code is shown disabled

### Requirement: "Copy TOTP automatically" setting
The extension SHALL show "Copy TOTP automatically" in the Autofill view, below "Clear clipboard", with the help text "If a login has an authenticator item, copy its verification code to your clipboard when you autofill the login." It is on by default, stored per account in `settings.<accountId>` in `storage.local`, kept through a logout and removed with the account. Each change applies to the next fill.

#### Scenario: Default
- **GIVEN** a newly added account
- **WHEN** the user opens the Autofill view
- **THEN** "Copy TOTP automatically" is on

### Requirement: A lock clears a copied code
When the extension has written a code to the clipboard, from a fill or the context menu, and the clear for it has not run, a lock or logout of the account it came from SHALL clear the clipboard at once, whatever the "Clear clipboard" delay, Never included. The pending state is the account id under `clipboardCode` in `storage.session` (background memory on Firefox below 115), never the code. It is removed when the clear runs, when any other copy replaces the clipboard, and on lock and logout; a browser restart drops it with `storage.session`.

#### Scenario: Lock before the delay
- **GIVEN** a code copied after a fill and the clear delay at 2 minutes
- **WHEN** the vault locks 10 seconds later
- **THEN** the clipboard is overwritten at once

#### Scenario: Another copy first
- **GIVEN** a code copied after a fill, then a password copied in the popup
- **WHEN** the vault locks
- **THEN** the password's own clear applies and the lock does not clear the clipboard

### Requirement: Nothing reachable while locked yields a code
While the active account is locked or logged out, no surface of this capability SHALL show, send or store a seed, a code or a `totp` item's name or username. Specifically:
- the inline list's code mode shows only the locked state; `inline.open` counts no rows and `inline.state` answers `locked` with no rows;
- `fill.execute` is never sent, so no content script gets a code;
- the context menu offers only "Unlock vault";
- the keyboard shortcut opens the popup;
- a code copied while unlocked is cleared by the lock (above);
- no storage area holds a seed or a code at any time, so none is readable after a lock.

A lock while a code list is open closes it, as `inline-list` specifies for every list.

#### Scenario: Locked code field
- **GIVEN** a locked vault with `totp` items for the site
- **WHEN** the user focuses the code field, right-clicks it and presses the shortcut
- **THEN** no code, item name or username appears in the list, the menu or the page, and the popup opens on the unlock view

#### Scenario: Storage after a fill
- **GIVEN** a fill that copied and filled a code
- **WHEN** a test reads `storage.local` and `storage.session`
- **THEN** neither holds the seed or the code

### Requirement: Older Keepiq servers
The capability SHALL work on every supported Keepiq version, since it reads only the cached snapshot and the `totp` system type exists in every release from 0.1.4 on. A server whose snapshot has no `totp` type, or no `totp` items, pairs and lists nothing and sends no request.

#### Scenario: Minimum supported Keepiq
- **GIVEN** an account on Keepiq 0.3.12 with a login and its imported `totp` item
- **WHEN** the user fills the login
- **THEN** the code is copied and filled, and no request is sent
