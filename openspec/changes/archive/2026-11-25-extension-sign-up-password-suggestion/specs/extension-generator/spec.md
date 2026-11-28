## MODIFIED Requirements

### Requirement: Suggest a strong password in a sign-up field
When a field for a new password gets focus on a page, the extension MUST offer to use a strong password. On the user's own click it MUST fill a password generated under the org policy into that field and its confirmation field. A script-dispatched click MUST be ignored, and a field for the current password MUST NOT get the offer.

#### Scenario: Sign up on a site
@e2e exclude In-page content script of the browser extension. Covered by browser-extension/e2e/password-suggest.spec.ts in Chromium, and walked live in Firefox on a sign-up form.
- **GIVEN** a page with a new-password field and a confirmation field
- **WHEN** the user focuses the first field and clicks "Use a strong password"
- **THEN** both fields hold the same generated password

#### Scenario: A page script clicks the offer
@e2e exclude In-page content script of the browser extension. Covered by browser-extension/e2e/password-suggest.spec.ts ("a click the page script dispatches on the offer fills nothing").
- **GIVEN** the offer is shown
- **WHEN** page script dispatches a click on it
- **THEN** nothing is generated or filled

#### Scenario: Change a password on a site
@e2e exclude In-page content script of the browser extension. Covered by the vitest field-role tests in browser-extension/src/autofill/ and browser-extension/e2e/password-suggest.spec.ts.
- **GIVEN** a change-password form with a current-password field, a new-password field and a confirmation field
- **WHEN** the user focuses the current-password field
- **THEN** no offer is shown
- **AND** focusing the new-password field shows "Use a strong password"
