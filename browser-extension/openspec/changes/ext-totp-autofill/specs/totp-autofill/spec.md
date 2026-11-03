## Purpose

Puts a login's current one-time code on the clipboard after a fill and into the page's one-time-code field, shows it in the login's detail view, and offers it in the inline list on a code field and in the context menu, as Bitwarden does with a login's TOTP. The seed is the login's own encrypted `totp` field (keepiq#1324, keepiq#1325).

## ADDED Requirements

### Requirement: A login's code comes from its own `totp` field
The extension SHALL read a login's TOTP seed only from that login's encrypted `totp` field: an `otpauth://totp/` URI or a bare base32 secret (`src/totp/totp.ts`). A login whose `totp` is null or `''` has no code. Items of the standalone `totp` type are never used for a fill, a copy, the code list or the context menu, and no other item lends a login its code. A seed that does not parse gives no code: nothing is copied or filled. The seed is decrypted in the background, or in the popup for the detail view, only for the request that needs it, and is never shown, stored or sent to a content script or the inline list page. Blocked rows never yield a code, and a blocked row's cached copy holds no `totp` ciphertext.

#### Scenario: Login with a code
- **GIVEN** a login "Test site" for `http://localhost:8100` whose `totp` holds `JBSWY3DPEHPK3PXP`, as the web app's Authenticator key field stores it
- **WHEN** the user fills "Test site"
- **THEN** its current code is the one filled and copied

#### Scenario: Standalone authenticator item
- **GIVEN** a login without a `totp` value and an existing `totp` item for the same site and username
- **WHEN** the user fills the login
- **THEN** no code is filled or copied

#### Scenario: Invalid seed
- **GIVEN** a login whose `totp` decrypts to `not-a-seed`
- **WHEN** the user fills the login
- **THEN** the username and password are filled, and no code is filled or copied

### Requirement: The code is copied after a fill
When "Copy TOTP automatically" is on and a fill from the popup, the inline list, the context menu or the keyboard shortcut filled at least one field, the background SHALL compute the filled login's current code (RFC 6238) and write it to the clipboard, through the offscreen document on Chrome MV3 and the background page on Firefox MV2, then clear the clipboard after the "Clear clipboard" delay from settings unless it is Never. Fill on page load MUST NOT copy a code. The code is computed after the fill, from the seed decrypted for it, and both exist only in background memory until the copy has been written. The copy replaces any pending clear, as every copy does.

#### Scenario: Popup fill copies the code
- **GIVEN** an unlocked vault, a login with a code, "Copy TOTP automatically" on and the clear delay at 30 seconds
- **WHEN** the user fills the login from "Autofill suggestions"
- **THEN** the clipboard holds the login's current code
- **AND** 30 seconds later the clipboard is overwritten

#### Scenario: Setting off
- **GIVEN** "Copy TOTP automatically" off and a login with a code
- **WHEN** the user fills the login
- **THEN** the clipboard is not written

#### Scenario: Fill on page load
- **GIVEN** fill on page load on and a login with a code that fills on load
- **WHEN** the page loads
- **THEN** the clipboard is not written and no code field is filled

#### Scenario: Nothing filled
- **GIVEN** a login with a code and a page where neither a login field nor a code field is found
- **WHEN** the user fills the login from the popup
- **THEN** the clipboard is not written and the popup shows "Unable to autofill on this page. Copy and paste instead."

### Requirement: The code fills a one-time-code field
A fill from the popup, the context menu or the keyboard shortcut, and an inline pick, SHALL also hand each frame that receives the login the login's current code, and the content script fills it into one code group of the frame (Code fields): the anchored list's group for an inline pick, else the focused one, else the first visible, enabled and empty one. A code group that already holds a value is left alone. Fill on page load MUST NOT hand over a code. On a page with only a code field, such a fill fills just the code, and counts as a fill for the toast, last used and the copy. The code is filled whether or not "Copy TOTP automatically" is on.

#### Scenario: Second step after the password
- **GIVEN** a login with a code, and `test-site/otp.html` open after the password step
- **WHEN** the user fills the login from the popup, the context menu or the shortcut
- **THEN** the "Authentication code" field holds the current code and no other field changes

#### Scenario: Login and code on one page
- **GIVEN** a form with a username, a password and a one-time-code field, and a login with a code
- **WHEN** the user fills the login
- **THEN** all three fields are filled

#### Scenario: Code field already filled
- **GIVEN** a code field holding `123456`
- **WHEN** the user fills a login with a code
- **THEN** the code field still holds `123456`

#### Scenario: Login without a code
- **GIVEN** a login without a code and a page with only a code field
- **WHEN** the user fills the login from the popup
- **THEN** nothing is filled and the popup shows "Unable to autofill on this page. Copy and paste instead."

### Requirement: Code fields
The content script SHALL treat as a code group, in document order:
- a run of four to eight visible, enabled, adjacent one-character text, `tel` or `number` inputs in one group;
- a run of two to four such inputs with a `maxlength` of two to four each, adding up to four to eight, of which at least one is classified as a one-time code (`autofill`, Field detection), such as a code in two halves of three;
- else one field classified as a one-time code.

Adjacent means no other usable field between them; text between them, such as a dash, does not count. A code as long as the run's `maxlength`s together is filled split by them, in order; otherwise the whole code goes into the first input. Each input gets the events `fillField()` dispatches. A field whose label, name, id or placeholder speaks of a recovery or backup code (English "recovery", "backup"; Dutch "herstel", "back-up") is not a one-time-code field and is never filled with a code.

#### Scenario: Split code
- **GIVEN** six inputs with `maxlength="1"` in a row, as in `test-site/otp-split.html`
- **WHEN** the code `492039` is filled
- **THEN** the inputs hold `4`, `9`, `2`, `0`, `3`, `9`

#### Scenario: Code in two halves
- **GIVEN** two inputs with `maxlength="3"` and `autocomplete="one-time-code"`, a dash between them, as in `test-site/otp-halves.html`
- **WHEN** the code `492039` is filled
- **THEN** the inputs hold `492` and `039`

#### Scenario: Recovery code field
- **GIVEN** a field labelled "Recovery code" with `autocomplete="one-time-code"`, as in `test-site/recovery-code.html`
- **WHEN** the user fills a login with a code
- **THEN** the field stays empty and no inline list opens on it

### Requirement: The inline list on a code field lists logins with a code
The inline list SHALL open on a code field in its code mode, under the same setting, states, keyboard model, position, frames and visibility guard as on a login field (`inline-list`). For an unlocked vault it lists the logins matching the frame's URL that have a code, chosen and ordered as `autofill` orders logins. Each row shows the login's plaintext `name`, its decrypted `login` when present, and the current code in two halves with the seconds left; the row of a login whose seed does not parse shows "Not a valid authenticator secret" and cannot be picked. The code and countdown are hidden from assistive technology; each option is named "<name>, <username>". When the shortest period ends, the list page asks the background for the rows again, so it never shows an expired code and never computes one itself. Texts in code mode:
- frame title "Keepiq verification codes", listbox name "Keepiq verification codes for <host>";
- no match: "No verification codes for <host>";
- locked: "Unlock Keepiq to see your verification codes", with "Unlock";
- status: "<n> Keepiq verification codes. Press Down Arrow to choose." ("1 Keepiq verification code. Press Down Arrow to choose." for one), "No Keepiq verification codes for <host>" or "Keepiq is locked. Press Down Arrow to unlock.".

The codes exist in the background while it answers and in the list page while it is open, never in storage or the content script.

#### Scenario: Two logins with a code
- **GIVEN** logins "Work" (`alice@work.test`) and "Personal" (`alice`) for `example.test`, both with a code, "Personal" filled earlier, and a login "Old" without a code
- **WHEN** the user focuses the empty code field on `https://example.test/2fa`
- **THEN** the list shows "Personal" with `alice` and its code, then "Work" with `alice@work.test` and its code, and not "Old"

#### Scenario: Code changes while open
- **GIVEN** the list open with a 30-second code that has 3 seconds left
- **WHEN** the period ends
- **THEN** the row shows the new code within a second

#### Scenario: Locked vault on a code field
- **GIVEN** a locked vault with a login with a code for the site
- **WHEN** the user focuses the code field
- **THEN** the list reads "Unlock Keepiq to see your verification codes" with "Unlock", and no name, username or code

### Requirement: Picking a code fills it
When the user picks a code row, the background SHALL check, as for a login pick, that the row was offered in this list, that it still matches the frame's URL, that the vault is unlocked and that the host frame confirms the list was visible; then decrypt that one login's `totp`, compute the current code at the moment of the pick and send it once, to the list's frame only, without the username or password, where the content script fills the code group of the field the list was opened for. An `https` login on an `http` frame asks "Fill on this insecure site?" first, as for a login pick. The pick records the login's last-used time and counts as interaction; the list closes and focus returns to the field. The pick does not copy the code.

#### Scenario: Pick a code
- **GIVEN** the list open on the code field with "Work" listed
- **WHEN** the user clicks "Work"
- **THEN** the code field holds the current code of "Work"
- **AND** the clipboard is not written

#### Scenario: Pick on a split code
- **GIVEN** the list open on the first of six one-character inputs
- **WHEN** the user picks a login
- **THEN** the six inputs hold the code's six digits

### Requirement: "Copy verification code" in the context menu
The extension SHALL add "Copy verification code" to the "Keepiq" context menu, after Copy password, listing the logins matching the tab that have a code, as Copy username lists logins: one entry for one, a submenu of at most 10 by last used then name for several, disabled when none matches. Picking one decrypts that login's `totp`, computes the current code and copies it, clearing after the "Clear clipboard" delay. While locked the menu still offers only "Unlock vault", as `autofill` specifies.

#### Scenario: Copy from the menu
- **GIVEN** an unlocked vault with one login with a code matching the tab
- **WHEN** the user right-clicks a field and picks Copy verification code
- **THEN** the clipboard holds that login's current code

#### Scenario: No login with a code
- **GIVEN** no login with a code matches the tab
- **WHEN** the user opens the Keepiq menu
- **THEN** Copy verification code is shown disabled

### Requirement: The detail view shows a login's code
The item detail view SHALL show a login's current code with its countdown and a copy button, as it does for an Authenticator item, when the login's `totp` holds a value, and "Not a valid authenticator secret" for a seed that does not parse. It MUST NOT show the seed. The popup decrypts `totp` through `item.decrypt` and drops it when the view closes, as every decrypted field.

#### Scenario: Login with a code
- **GIVEN** a login whose `totp` holds a valid seed
- **WHEN** the user opens its detail view
- **THEN** it shows the current code, the seconds left and a copy button, and no seed

### Requirement: Use-only copies
A use-only copy of a login with a code SHALL be listed in the code list and Copy verification code, and its code filled and copied, as for any login, since Keepiq's `use-only-shares` lets a client show and copy a use-only copy's current code. The seed is never shown, copied or sent outside the background and the popup's memory.

#### Scenario: Use-only copy
- **GIVEN** a use-only copy of a login with a code
- **WHEN** the user picks it in the code list
- **THEN** the code field holds its current code, and no surface shows the seed or the password

### Requirement: "Copy TOTP automatically" setting
The extension SHALL show "Copy TOTP automatically" in the Autofill view, below "Clear clipboard", with the help text "If a login has a verification code, copy it to your clipboard when you autofill the login." It is on by default, stored per account in `settings.<accountId>` in `storage.local`, kept through a logout and removed with the account. Each change applies to the next fill.

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
While the active account is locked or logged out, no surface of this capability SHALL show, send or store a seed, a code or a login's name or username. Specifically:
- the inline list's code mode shows only the locked state; `inline.open` counts no rows and `inline.state` answers `locked` with no rows;
- `fill.execute` is never sent, so no content script gets a code;
- the context menu offers only "Unlock vault";
- the keyboard shortcut opens the popup;
- a code copied while unlocked is cleared by the lock (above);
- no storage area holds a seed or a code at any time, so none is readable after a lock.

A lock while a code list is open closes it, as `inline-list` specifies for every list.

#### Scenario: Locked code field
- **GIVEN** a locked vault with a login with a code for the site
- **WHEN** the user focuses the code field, right-clicks it and presses the shortcut
- **THEN** no code, login name or username appears in the list, the menu or the page, and the popup opens on the unlock view

#### Scenario: Storage after a fill
- **GIVEN** a fill that copied and filled a code
- **WHEN** a test reads `storage.local` and `storage.session`
- **THEN** neither holds the seed or the code

### Requirement: Supported Keepiq versions
The capability SHALL work on every supported Keepiq version without a version check: the `totp` field is in Keepiq 0.3.12 (keepiq#1325), the minimum, and the capability reads only the cached snapshot. A row without the field, as from a server that does not send it, has no code; no request is sent for it.

#### Scenario: Minimum supported Keepiq
- **GIVEN** an account on Keepiq 0.3.12 with a login whose `totp` holds a seed
- **WHEN** the user fills the login
- **THEN** the code is copied and filled, and no request is sent
