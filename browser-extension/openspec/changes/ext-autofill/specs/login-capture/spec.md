## ADDED Requirements

### Requirement: Submitted login forms are reported once
The content script SHALL detect a login submission, defined as a group (a `<form>` or formless fields, as the `autofill` capability's field detection defines it) with a password field that holds a value at submit time, at a click on the group's submit button, at Enter in its password field, or when the page starts navigating after one of these, and report `{username, password}` to the background exactly once per submission. The group's submit button is the one that field detection picks: in a `<form>`, its default submit button when its text is a keyword, otherwise the closest keyword button after its password field; a click on any other button, such as a "Sign up" link-button in the page header, is not a submission of this group. The background takes the URL from `sender.url`, not from the report. Fields are classified by the signals of the `autofill` capability's field detection, so `autocomplete="off"` never stops a report and no single `autocomplete` token decides. A group classified there as a password change is reported as a password change with the new value and, when it has one, the username; a group classified as a sign-up is reported as a new login. Nothing is reported while the vault is locked or the page is not `http:` or `https:`.

#### Scenario: Standard login submit
- **GIVEN** an unlocked vault and a login page
- **WHEN** the user types a username and password and submits
- **THEN** the background receives one `capture.submitted` with those values and the frame's `sender.url`

#### Scenario: Formless login is captured
- **GIVEN** an unlocked vault, a username and a password field and a `type="button"` "Sign in" button with no `<form>`, sending the login with `fetch`
- **WHEN** the user types both and clicks "Sign in", or presses Enter in the password field
- **THEN** the background receives one `capture.submitted` with those values

#### Scenario: Header button outside the login group
- **GIVEN** a formless login panel with a typed username and password, and a "Sign up" button in the page header outside that panel
- **WHEN** the user clicks the header "Sign up"
- **THEN** nothing is reported

#### Scenario: Login form with autocomplete off
- **GIVEN** an unlocked vault and a login form with `autocomplete="off"` on the form and its fields
- **WHEN** the user submits a username and password
- **THEN** the background receives one `capture.submitted` with those values

#### Scenario: Submit with empty password
- **GIVEN** a login form whose password field is empty
- **WHEN** the form is submitted
- **THEN** nothing is reported

#### Scenario: Locked vault
- **GIVEN** a locked vault
- **WHEN** a login form is submitted
- **THEN** the background discards the report and shows no bar

### Requirement: Only the user's own submissions are reported
Whether a bar follows a submit tells the page whether the username is saved and with which password, so the content script MUST report only a submission the user made: the triggering event is trusted (`isTrusted`), and the page has transient user activation at report time (`navigator.userActivation.isActive`, or, where the browser lacks it, a trusted activation-triggering input within the last five seconds). It SHALL report at most one submission per user activation, so a script cannot submit several usernames on the strength of one click. A script's `click()`, a dispatched event, and `requestSubmit()` or `submit()` without user activation report nothing.

#### Scenario: Script submits a login
- **GIVEN** a login form filled by a page script
- **WHEN** the script calls `requestSubmit()`, or `click()` on the submit button, without the user acting
- **THEN** nothing is reported and no bar is shown

#### Scenario: One click, several submits
- **GIVEN** two login forms on a page, both filled
- **WHEN** the user clicks the first form's submit button and a script submits the second form within the same activation
- **THEN** only the first form is reported

### Requirement: Captured credentials live in background memory only
The extension SHALL keep a captured credential in background memory keyed by tab id, never in `storage.local` or `storage.session`, and MUST drop it when the user saves, updates or dismisses, when the tab closes, when the vault locks, or five minutes after capture, whichever comes first. The bar UI never receives the password. Which item a tab last filled or captured, kept to match password change forms, is background memory too and is forgotten on tab close, lock and account change.

#### Scenario: Expiry
- **GIVEN** a capture shown as a bar
- **WHEN** five minutes pass without a decision
- **THEN** the bar is removed and the credential is gone from memory

#### Scenario: Tab closed
- **GIVEN** a pending capture for a tab
- **WHEN** the tab is closed
- **THEN** the credential is dropped

### Requirement: Save bar for a new login
When no item matching the page holds the captured username, and "Ask to add login" is on, and the page's base domain is not excluded, the extension SHALL show a notification card in the top-right corner of the page, offering a folder choice under a visible "Folder" label, "Never for this site", "Save" and a close button, after the Keepiq logo, which is decorative. It is shown after the navigation that follows the submit completes, or, for a submit that does not navigate (a single-page app), about 300 ms after the submit. The bar is rendered in an extension page inside an iframe hosted by the content script, so page scripts cannot read or alter it. The card sits 12 CSS px from the top and right edges and is 400 px wide, or the viewport width minus 24 px when narrower. Its size depends only on the top frame's viewport (148 px tall, 168 px below a 424 px wide viewport), or is compact in a short viewport (Keyboard and screen reader access to the bar), never on its mode or text; a question or error longer than the card holds scrolls inside it.

#### Scenario: New login on a site with no items
- **GIVEN** the setting on and no item for the site
- **WHEN** the user submits a login on `login.example.test` and the next page finishes loading
- **THEN** a bar reads "Save login for login.example.test to Keepiq?" (nl "Login voor login.example.test opslaan in Keepiq?") with a folder dropdown labelled "Folder" defaulting to "No folder"

#### Scenario: Login in a single-page app
- **GIVEN** the setting on, no item for the site, and a formless login that sends with `fetch` and does not navigate
- **WHEN** the user clicks "Sign in"
- **THEN** the save bar is shown about 300 ms later

#### Scenario: Same username already saved
- **GIVEN** an item matching the page whose decrypted `login` equals the captured username and whose `key` equals the captured password
- **WHEN** the user submits
- **THEN** no bar is shown

#### Scenario: Ask to add is off
- **GIVEN** "Ask to add login" off
- **WHEN** the user submits a new login
- **THEN** no bar is shown and the capture is dropped

### Requirement: Save creates the item
The extension SHALL, on "Save", encrypt the username as `login` and the password as `key` with the account's certificate, and `POST /api/v1/secrets` with `name` set to the page host, `url` set to the page origin, `typeId` of the system `login` type and the chosen `folderId`, then run a sync (ADR-002). While it saves, the bar shows a busy state and, after one second, announces "Saving…". The bar shows a saved confirmation and closes. On failure the bar shows why and keeps the Save button enabled. The reason of a failed save or update takes the question's place, worded by the `notificationBar.errors.*` keys, or the shared `writeErrors.*` and `errors.*` ones (`writeErrorText()` in `entrypoints/notification/bar.ts`).

#### Scenario: Successful save
- **GIVEN** a save bar with folder "Work" chosen
- **WHEN** the user clicks Save
- **THEN** the item appears in the vault under "Work" with name `login.example.test` and url `https://login.example.test`
- **AND** the bar reads "Login saved" and closes after three seconds, or once focus and the pointer have left it

#### Scenario: Server rejects
- **GIVEN** the server returns 423
- **WHEN** the user clicks Save
- **THEN** the bar shows "The vault is temporarily locked for changes. Try again later." (nl "De kluis is tijdelijk vergrendeld voor wijzigingen. Probeer het later opnieuw.") and the capture is kept until expiry

### Requirement: Never for this site adds an excluded domain
The extension SHALL, on "Never for this site", append the page's base domain to the excluded domains list in `storage.local` (from ext-settings, cleared on account removal), in the same write queue as every other settings change, close the bar and drop the capture. Excluded domains suppress save and update bars only; autofill still works there.

#### Scenario: Excluded domain stays fillable
- **GIVEN** `example.test` in excluded domains and a matching item
- **WHEN** the user submits a login on that site
- **THEN** no bar is shown
- **AND** the item is still offered in "Autofill suggestions"

### Requirement: Update bar for a changed password
When exactly one matching item holds the captured username and its decrypted `key` differs from the captured password, and "Ask to update existing login" is on, the extension SHALL show an update card with "Update" and a close button. It asks "Update password for {name} ({username})?" (nl "Wachtwoord van {name} ({username}) bijwerken?"), with the item's name and username; without a username on the item the parenthesised part is left out. Under the question, a row shows the item's name with its username below it, or the name alone, after a generic login icon; it is hidden from screen readers, as it repeats the question, and gives way to a question too long to fit beside it. When the submitting frame's host differs from the top frame's host at the submit, the question ends "on {host}" (nl "op {host}") with the submitting frame's host. A password change form is matched by the item whose password was filled or captured earlier on that tab, or by the single matching item. No update is offered for a use-only or read-only copy, which may not be changed.

#### Scenario: Different password for a known username
- **GIVEN** an item "GitHub" for the site with username `alice` and "Ask to update existing login" on
- **WHEN** the user submits `alice` with a new password
- **THEN** a bar reads "Update password for GitHub (alice)?"

#### Scenario: Update in another site's login frame
- **GIVEN** an item "Provider" with username `alice` for `auth.provider.test`, and a page on `shop.test` with that login in an iframe
- **WHEN** the user submits `alice` with a new password in the iframe
- **THEN** the bar reads "Update password for Provider (alice) on auth.provider.test?"

#### Scenario: Change-password form with a username
- **GIVEN** an item for the site with username `alice` and a form with a username, current, new and confirm password field
- **WHEN** the user submits `alice` with a new password
- **THEN** the form is reported as a password change and a bar offers to update that item's password

#### Scenario: Several items with the same username
- **GIVEN** two matching items both with username `alice`
- **WHEN** the user submits a new password
- **THEN** no update bar is shown

### Requirement: Update writes only the key
The extension SHALL, on "Update", save through `updateItem`: re-fetch the item with `GET /api/v1/secrets/{id}`, encrypt the captured password, and save a patch of only `key` with `baseUpdatedAt` through share-save, which reaches every other copy as `ext-share-sync` specifies, then sync. While it saves, the bar shows a busy state and, after one second, announces "Updating…". Other fields are left untouched. With no edit form behind the save, an item with partner shares fails with `unverified` and nothing is saved, and a view-only copy fails with `view_only`, which the bar words as the item being shared with the user to view only. When the item is gone, by a 404 or because a sync dropped it before the bar loaded, the capture becomes a new login.

#### Scenario: Successful update
- **GIVEN** an update bar
- **WHEN** the user clicks Update
- **THEN** the share-save patch contains only `key`, beside `baseUpdatedAt`
- **AND** the bar reads "Password updated" and closes after three seconds, or once focus and the pointer have left it

#### Scenario: Item shared with a partner organisation
- **GIVEN** an update bar for an item with an active partner share
- **WHEN** the user clicks Update
- **THEN** nothing is saved
- **AND** the bar reads "Edit this login in the Keepiq popup or web app to change its password." (nl "Bewerk deze login in de Keepiq-pop-up of de Keepiq-webapp om het wachtwoord te wijzigen.")

#### Scenario: Item deleted meanwhile
- **GIVEN** the re-fetch returns 404
- **WHEN** the user clicks Update
- **THEN** the bar shows "This login no longer exists. Save it as a new login?" (nl "Deze login bestaat niet meer. Opslaan als nieuwe login?") and offers Save, which saves the capture as a new login

### Requirement: Bar messages are trusted only from the extension page
The background MUST accept `capture.state`, `capture.save`, `capture.update` and `capture.dismiss` only from a sender whose `sender.url` is the extension's notification page and whose `sender.tab.id` has a pending capture. Messages from the page or from a content script with these kinds are ignored.

#### Scenario: Page script forges a save
- **GIVEN** a pending capture
- **WHEN** a message shaped like `capture.save` arrives from the content script instead of the bar page
- **THEN** it is ignored and nothing is written

### Requirement: The bar acts only for its own frame, with its key
Each time the background shows the bar it SHALL issue a random 128-bit key with `capture.show`; the content script posts it to its own bar iframe with the extension origin as `targetOrigin`, and every bar message carries it. The background MUST bind the first bar frame that presents a key in `capture.state`, and answer every other frame, and any message without an issued key, with nothing. The bar page reads nothing from its URL. A copy of the bar page the page frames itself therefore gets no state and can save, update or exclude nothing.

#### Scenario: Page frames the bar page itself
- **GIVEN** a pending capture and the bar shown
- **WHEN** the page frames `notification.html` itself and that copy asks for the state or sends Save
- **THEN** the background answers it with nothing and writes nothing

### Requirement: The bar's buttons act only while it is plainly visible
The extension SHALL apply the inline list's visibility guard (`inline-list` spec, design.md § Visibility guard) to the bar: the host is a popover in the top layer where the browser has the popover API, placed inside the modal dialog or `aria-modal` container the page is in; it closes on any change to the host it did not make and when it stops being `:popover-open`; it is sampled every animation frame with the same untouched checks, the Firefox `<html>` filter exception included. Save, Update and "Never for this site" MUST act only after the background asks the host with `capture.verify` and the host confirms that the bar was visible for the 500 ms before the press and is what the press point hits. The bar page also refuses a press within 500 ms of being shown or moved and, on Chrome, while IntersectionObserver v2 reports it covered. A refused press does nothing, keeps the capture, and the bar says "Keepiq can't do this here because this page changes how it is shown. Close this and add the login from the Keepiq button in the toolbar." (nl "Keepiq kan dit hier niet doen, omdat deze pagina aanpast hoe ze wordt weergegeven. Sluit dit en voeg de login toe via Keepiq in de werkbalk."), since the popup cannot answer a pending capture; the notice replaces the question and controls, leaving only Close, and takes focus. Close needs no guard.

#### Scenario: Page covers the bar
- **GIVEN** a save bar and a page element drawn over its Save button
- **WHEN** a click lands on Save
- **THEN** nothing is saved and the capture is kept

#### Scenario: Page fades the bar
- **GIVEN** a save bar whose host the page restyles to be transparent
- **WHEN** the user clicks where Save was
- **THEN** the bar is closed and nothing is saved

### Requirement: Keyboard and screen reader access to the bar
The bar SHALL be a region labelled "Keepiq notification" (nl "Keepiq-melding") whose question is announced through a status region when it appears, followed by "Press {shortcut} to answer." while the `focus_notification_bar` command has a shortcut, and it MUST never take focus when shown. That command, suggested as Alt+Shift+K (macOS Command+Shift+K), moves focus to the bar's first control while a bar is showing. Escape in the bar acts as Close. When the bar goes while focus is in it, focus returns to the page element that had it before. When the page's focused element sits under the card, overlapping it both vertically and horizontally, also after scrolling with focus kept, the card moves between the top-right and bottom-right corner of the viewport (WCAG 2.4.11), and it stays there until focus lands on an element under it again. Where the card's height would cover more than half of the viewport (as at 400% zoom), it is one compact row of 40 CSS px with an expand control, an arrow button named "Show options" (nl "Opties tonen") that takes focus first and grows the card when pressed; it grows to its full height, at most the viewport height minus 24 px, while focus is in it, shrinking when focus leaves; its height follows only the viewport and the user's focus, never what it says. A modal dialog the page opens after the bar takes the bar inside it, so the bar stays usable. After a save or update the bar stays while focus or the pointer is in it, and closes once they leave.

#### Scenario: Bar appears
- **GIVEN** a screen reader user who submitted a new login
- **WHEN** the bar is shown
- **THEN** its question is announced and focus stays where it was

#### Scenario: Reaching the bar from the keyboard
- **GIVEN** a bar shown and the default shortcut Alt+Shift+K for `focus_notification_bar`
- **WHEN** the user presses it
- **THEN** focus moves to the bar's first control
- **AND** the bar's announcement ended with "Press Alt+Shift+K to answer."

#### Scenario: Shortcut removed
- **GIVEN** the user removed the shortcut of `focus_notification_bar` in the browser's settings
- **WHEN** the bar appears
- **THEN** its announcement names no shortcut

#### Scenario: Short viewport
- **GIVEN** a viewport 160 CSS px tall, as at 400% zoom
- **WHEN** the bar appears
- **THEN** it is 40 CSS px tall
- **AND** it grows to 136 CSS px while focus is in it, and shrinks back when focus moves to the page

#### Scenario: Scrolled under the bar
- **GIVEN** a card in the top-right corner and a focused field below it
- **WHEN** the user scrolls so the field is under the card, focus staying in it
- **THEN** the card moves to the bottom-right corner

#### Scenario: Field beside the card
- **GIVEN** a card in the top-right corner
- **WHEN** focus moves to a field at the same height, left of the card
- **THEN** the card stays where it is

#### Scenario: Focus elsewhere does not flip the bar
- **GIVEN** a bar moved to the bottom for a field it covered at the top
- **WHEN** focus moves to a field in the middle of the page, and then to one at the top
- **THEN** the bar stays at the bottom

#### Scenario: Modal opened after the bar
- **GIVEN** a bar shown
- **WHEN** the page opens a modal dialog and focus moves into it
- **THEN** the bar is inside the dialog and still answers, with what it showed kept where the browser can move it intact

#### Scenario: Closing with Escape
- **GIVEN** focus in the bar, reached from a field on the page
- **WHEN** the user presses Escape
- **THEN** the bar closes, the capture is dropped, and focus returns to that field

#### Scenario: Focused field under the bar
- **GIVEN** a card in the top-right corner
- **WHEN** focus moves to a field the card covers
- **THEN** the card moves to the bottom-right corner

### Requirement: Bar follows the tab and dismisses on leaving the site
The extension SHALL show the bar in the top frame of the tab where the submit happened, never in a subframe, re-inject it when that tab navigates within the same base domain while the capture is pending, and dismiss it when the tab navigates to another base domain or the user closes it. A submit in a subframe is checked, excluded and saved under the subframe's own URL, and the bar follows the top frame's base domain at the time of the submit. A page that starts loading within the 300 ms after the submit shows no bar until the next page completes.

#### Scenario: Redirect chain after login
- **GIVEN** a submit on `https://example.test/login` that redirects twice within `example.test`
- **WHEN** the final page loads
- **THEN** the bar is shown once on the final page

#### Scenario: Login in a cross-site iframe
- **GIVEN** a page on `https://shop.test/` with a login form in an iframe on `https://auth.provider.test/` and no login for `provider.test`
- **WHEN** the user submits a login in the iframe
- **THEN** the top frame shows the save bar for `auth.provider.test`, and Save stores url `https://auth.provider.test`
- **AND** the bar stays while the tab remains on `shop.test`

#### Scenario: Navigating away
- **GIVEN** a bar shown on `example.test`
- **WHEN** the user navigates the tab to `other.test`
- **THEN** the bar is gone and the capture is dropped
