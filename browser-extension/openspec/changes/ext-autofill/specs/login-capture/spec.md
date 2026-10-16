## ADDED Requirements

### Requirement: Submitted login forms are reported once
The content script SHALL detect a login form submission, defined as a form with a password field that holds a value at submit time or when the page starts navigating after a submit button or Enter key, and report `{username, password}` to the background exactly once per submission. The background takes the URL from `sender.url`, not from the report. Password change forms (two or three password fields, no username) are reported as a password change with the new value. Nothing is reported while the vault is locked or the page is not `http:` or `https:`.

#### Scenario: Standard login submit
- **GIVEN** an unlocked vault and a login page
- **WHEN** the user types a username and password and submits
- **THEN** the background receives one `capture.submitted` with those values and the frame's `sender.url`

#### Scenario: Submit with empty password
- **GIVEN** a login form whose password field is empty
- **WHEN** the form is submitted
- **THEN** nothing is reported

#### Scenario: Locked vault
- **GIVEN** a locked vault
- **WHEN** a login form is submitted
- **THEN** the background discards the report and shows no bar

### Requirement: Captured credentials live in background memory only
The extension SHALL keep a captured credential in background memory keyed by tab id, never in `storage.local` or `storage.session`, and MUST drop it when the user saves, updates or dismisses, when the tab closes, when the vault locks, or five minutes after capture, whichever comes first. The bar UI never receives the password.

#### Scenario: Expiry
- **GIVEN** a capture shown as a bar
- **WHEN** five minutes pass without a decision
- **THEN** the bar is removed and the credential is gone from memory

#### Scenario: Tab closed
- **GIVEN** a pending capture for a tab
- **WHEN** the tab is closed
- **THEN** the credential is dropped

### Requirement: Save bar for a new login
When no item matching the page holds the captured username, and "Ask to add login" is on, and the page's base domain is not excluded, the extension SHALL show a notification bar at the top of the page after the navigation that follows the submit completes, offering a folder choice, "Save", "Never for this site" and a close button. The bar is rendered in an extension page inside an iframe hosted by the content script, so page scripts cannot read or alter it.

#### Scenario: New login on a site with no items
- **GIVEN** the setting on and no item for the site
- **WHEN** the user submits a login and the next page finishes loading
- **THEN** a bar reads "Save this login to Keepiq?" with a folder dropdown defaulting to "No folder"

#### Scenario: Same username already saved
- **GIVEN** an item matching the page whose decrypted `login` equals the captured username and whose `key` equals the captured password
- **WHEN** the user submits
- **THEN** no bar is shown

#### Scenario: Ask to add is off
- **GIVEN** "Ask to add login" off
- **WHEN** the user submits a new login
- **THEN** no bar is shown and the capture is dropped

### Requirement: Save creates the item
The extension SHALL, on "Save", encrypt the username as `login` and the password as `key` with the account's certificate, and `POST /api/v1/secrets` with `name` set to the page host, `url` set to the page origin, `typeId` of the system `login` type and the chosen `folderId`, then run a sync (ADR-002). The bar shows a saved confirmation and closes. On failure the bar shows the server message and keeps the Save button enabled.

#### Scenario: Successful save
- **GIVEN** a save bar with folder "Work" chosen
- **WHEN** the user clicks Save
- **THEN** the item appears in the vault under "Work" with name `login.example.test` and url `https://login.example.test`
- **AND** the bar reads "Login saved" and closes after three seconds

#### Scenario: Server rejects
- **GIVEN** the server returns 423
- **WHEN** the user clicks Save
- **THEN** the bar shows "Vault is temporarily locked for writes. Try again later." and the capture is kept until expiry

### Requirement: Never for this site adds an excluded domain
The extension SHALL, on "Never for this site", append the page's base domain to the excluded domains list in `storage.local` (from ext-settings, cleared on account removal), close the bar and drop the capture. Excluded domains suppress save and update bars only; autofill still works there.

#### Scenario: Excluded domain stays fillable
- **GIVEN** `example.test` in excluded domains and a matching item
- **WHEN** the user submits a login on that site
- **THEN** no bar is shown
- **AND** the item is still offered in "Autofill suggestions"

### Requirement: Update bar for a changed password
When exactly one matching item holds the captured username and its decrypted `key` differs from the captured password, and "Ask to update existing login" is on, the extension SHALL show an "Update password for <item name>?" bar with "Update" and a close button. A password change form is matched by the item whose password was filled or captured earlier on that tab, or by the single matching item.

#### Scenario: Different password for a known username
- **GIVEN** an item for the site with username `alice` and "Ask to update existing login" on
- **WHEN** the user submits `alice` with a new password
- **THEN** a bar offers to update the password of that item

#### Scenario: Several items with the same username
- **GIVEN** two matching items both with username `alice`
- **WHEN** the user submits a new password
- **THEN** no update bar is shown

### Requirement: Update writes only the key
The extension SHALL, on "Update", re-fetch the item with `GET /api/v1/secrets/{id}`, encrypt the captured password and `PUT /api/v1/secrets/{id}` with only `key` in the body, then sync. Other fields are left untouched.

#### Scenario: Successful update
- **GIVEN** an update bar
- **WHEN** the user clicks Update
- **THEN** the request body contains only `key`
- **AND** the bar reads "Password updated" and closes after three seconds

#### Scenario: Item deleted meanwhile
- **GIVEN** the re-fetch returns 404
- **WHEN** the user clicks Update
- **THEN** the bar shows "This login no longer exists" and offers Save instead

### Requirement: Bar messages are trusted only from the extension page
The background MUST accept `capture.save`, `capture.update` and `capture.dismiss` only from a sender whose `sender.url` is the extension's notification page and whose `sender.tab.id` has a pending capture. Messages from the page or from a content script with these kinds are ignored.

#### Scenario: Page script forges a save
- **GIVEN** a pending capture
- **WHEN** a message shaped like `capture.save` arrives from the content script instead of the bar page
- **THEN** it is ignored and nothing is written

### Requirement: Bar follows the tab and dismisses on leaving the site
The extension SHALL show the bar in the tab where the submit happened, re-inject it when that tab navigates within the same base domain while the capture is pending, and dismiss it when the tab navigates to another base domain or the user closes it.

#### Scenario: Redirect chain after login
- **GIVEN** a submit on `https://example.test/login` that redirects twice within `example.test`
- **WHEN** the final page loads
- **THEN** the bar is shown once on the final page

#### Scenario: Navigating away
- **GIVEN** a bar shown on `example.test`
- **WHEN** the user navigates the tab to `other.test`
- **THEN** the bar is gone and the capture is dropped
