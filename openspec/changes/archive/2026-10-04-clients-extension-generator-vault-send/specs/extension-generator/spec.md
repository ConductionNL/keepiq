## ADDED Requirements

### Requirement: Generator tab in the popup
The popup MUST offer a Generator tab that makes passwords (length 8 to 128, special characters, excluded characters) and passphrases (4 to 12 words, separator, capitals, a digit) in the extension with the web app's generator, applying the account's org password policy, and MUST let the user copy the result. When the policy switches passphrases off, the tab MUST offer passwords only.

#### Scenario: Generate a password and a passphrase
@e2e exclude Browser-extension popup, not part of the Nextcloud UI the Playwright suite drives. Covered by tests/extension/popupVaultSendGenerator.spec.js ("makes a password and a passphrase in the popup") on the real popup and router, and checked live in Chromium.
- **GIVEN** an unlocked extension
- **WHEN** the user opens the Generator tab and switches to Passphrase
- **THEN** the output shows a 20-character password, then five words separated by hyphens
- **AND** no request is made to `/api/v1/generate-key`

### Requirement: Suggest a strong password in a sign-up field
When a field for a new password gets focus on a page, the extension MUST offer to use a strong password. On the user's own click it MUST fill a password generated under the org policy into that field and its confirmation field. A script-dispatched click MUST be ignored, and a field for the current password MUST NOT get the offer.

#### Scenario: Sign up on a site
@e2e exclude In-page content script of the browser extension. Covered by tests/extension/passwordSuggest.spec.js and checked live in Chromium on a sign-up form.
- **GIVEN** a page with a new-password field and a confirmation field
- **WHEN** the user focuses the first field and clicks "Use a strong password"
- **THEN** both fields hold the same generated password

#### Scenario: A page script clicks the offer
@e2e exclude In-page content script of the browser extension. Covered by tests/extension/passwordSuggest.spec.js ("ignores a click the page script made").
- **GIVEN** the offer is shown
- **WHEN** page script dispatches a click on it
- **THEN** nothing is generated or filled
