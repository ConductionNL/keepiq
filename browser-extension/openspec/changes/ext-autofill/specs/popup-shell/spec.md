## MODIFIED Requirements

### Requirement: Active tab context
The popup SHALL read the active tab's URL only to match and fill autofill suggestions; the header does not show it, as in Bitwarden. When the tab has no `http:` or `https:` URL (new tab page, browser pages, file URLs) the autofill suggestions section shows "Autofill is not available on this page" and offers no item.

#### Scenario: Popup on a website
- **GIVEN** the active tab is `https://app.example.com/login`
- **WHEN** the popup opens on the Vault tab
- **THEN** the header shows only the view title, the pop-out button and the account avatar

#### Scenario: Popup on a browser page
- **GIVEN** the active tab is the browser's new tab page
- **WHEN** the popup opens
- **THEN** the autofill suggestions section shows "Autofill is not available on this page" and offers no item
