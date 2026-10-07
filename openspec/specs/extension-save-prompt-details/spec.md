# extension-save-prompt-details Specification

## Purpose
The finer behaviour of the extension's save prompt: which login to update, an update after a redirect, and a save that confirms.

## Requirements

### Requirement: Update the one login that is meant
A submitted login MUST be offered as an update only when exactly one saved login for the site has its username and another password. When several do, which one is meant cannot be told, so nothing MUST be offered. An update MUST read the saved login again first and change its password only; name, address and username stay. When the login is gone by then, the capture MUST be saved as a new login instead.

#### Scenario: One or several
@e2e exclude Pure capture classification. Covered by tests/extension/savePromptDetails.spec.js ("updates the one saved login with the username, and offers nothing when several match").
- **GIVEN** one saved login with the username, or two
- **WHEN** a new password is submitted
- **THEN** the one is offered for update, and with two nothing is offered

#### Scenario: Update or gone
@e2e exclude Browser-extension worker. Covered by tests/extension/savePromptDetails.spec.js ("updates the password only, after reading the login again") and ("saves a new login, with its type, when the one to update is gone").
- **GIVEN** an update offer
- **WHEN** the user takes it, and the login still exists or was deleted meanwhile
- **THEN** only the password and suite change, or a new login is saved

### Requirement: The offer follows the site, not the page
A login form often redirects after submit. The next page of the same site (same registrable domain) in that tab MUST show the waiting offer again, in the top frame only. A page on another site MUST drop it.

#### Scenario: Redirect
@e2e exclude Browser-extension worker and content script. Covered by tests/extension/savePromptDetails.spec.js ("offers again on the next page of the same site"), ("drops the offer when the tab moves to another site, and gives frames none") and ("shows the bar again when the next page loads").
- **GIVEN** a login submitted on login.new.example
- **WHEN** the tab lands on app.new.example, or on another site
- **THEN** the bar shows the offer again, or the offer is gone

### Requirement: A save that confirms
A new login MUST be saved with the login type. After a save or update the extension MUST sync, so the vault shows it at once, and the bar in the page MUST say "Login saved to Keepiq.", "Password updated in Keepiq." or why it could not save.

#### Scenario: Saved
@e2e exclude Browser-extension worker and in-page bar. Covered by tests/extension/savePromptDetails.spec.js ("says saved, updated, or why not") and ("saves a new login, with its type, when the one to update is gone").
- **GIVEN** a save from the bar
- **WHEN** it completes or fails
- **THEN** the bar says which, and the vault syncs
