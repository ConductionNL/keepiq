## ADDED Requirements

### Requirement: One-time code fill on the step after the login

After filling a login that has a matched `totp` secret, the extension MUST keep a one-shot fill intent holding only the tab id, the login's registrable domain, the `totp` secret id and an expiry five minutes out, in `chrome.storage.session` and never in `storage.local` or `storage.sync`. When a visible one-time-code field appears later in that tab, the extension MUST compute the current code and fill it only if the field's frame has the same registrable domain, the intent has not expired, and the vault is unlocked. It MUST then delete the intent. Lock and unpair MUST delete every intent.

#### Scenario: The code page follows the password page

- **GIVEN** an unlocked extension and a vault owner who fills a login on `example.com` whose `totp` secret matches `example.com`
- **WHEN** the site shows a one-time-code field on the next page within five minutes
- **THEN** the extension MUST fill that field with the current code
- **AND** a code field on any later page MUST NOT be filled

#### Scenario: Another site cannot collect the code

- **GIVEN** a pending intent for `example.com` in a tab
- **WHEN** that tab navigates to `attacker.example.net` and the page shows a one-time-code field
- **THEN** the extension MUST NOT compute or fill a code

#### Scenario: A locked vault fills nothing

- **GIVEN** a pending intent for `example.com`
- **WHEN** the vault locks before the code field appears
- **THEN** the intent MUST be deleted and no code MUST be filled
- **AND** the popup MUST still offer the code once the vault owner unlocks

#### Scenario: The intent never holds secret material

- **GIVEN** a pending intent after a login fill
- **WHEN** the test suite reads `chrome.storage.session`
- **THEN** the stored intent MUST contain no seed and no code
