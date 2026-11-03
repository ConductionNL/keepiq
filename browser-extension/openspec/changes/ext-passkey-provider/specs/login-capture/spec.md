## MODIFIED Requirements

### Requirement: Never for this site adds an excluded domain
The extension SHALL, on "Never for this site", append the page's base domain to the excluded domains list in `storage.local` (from ext-settings, cleared on account removal), in the same write queue as every other settings change, close the bar and drop the capture. Excluded domains suppress save and update bars and the passkey provider (`passkey-provider`), whose requests on those sites go to the browser untouched; autofill still works there.

#### Scenario: Excluded domain stays fillable
- **GIVEN** `example.test` in excluded domains and a matching item
- **WHEN** the user submits a login on that site
- **THEN** no bar is shown
- **AND** the item is still offered in "Autofill suggestions"

#### Scenario: Excluded domain gets no passkey window
- **GIVEN** `example.test` in excluded domains
- **WHEN** `https://example.test` calls `navigator.credentials.create({ publicKey })`
- **THEN** no Keepiq window opens and the browser's own dialog does
