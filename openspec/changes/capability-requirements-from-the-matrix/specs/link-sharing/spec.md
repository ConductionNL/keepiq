## ADDED Requirements

### Requirement: A link share can end on a date

The link share dialog MUST let the owner set an optional end date on a new link share, stored as its `expires_at`, and MUST refuse a date in the past. From the end date on, an access with the token MUST answer the same not found error as a revoked link, without revealing that the link existed, and the link share and its encrypted snapshot MUST be deleted. The server MUST check `expires_at` at access time, so a link is closed on its end date even if no cleanup job has run. The usage limit still applies: the link ends at whichever comes first.

#### Scenario: An owner shares a login until Friday

- **GIVEN** an owner in the link share dialog for the secret "Supplier portal"
- **WHEN** the owner creates a password-protected link with a usage limit of 5 and an end date of Friday
- **THEN** the link and its password are shown, and the owner's list of link shares shows the end date

#### Scenario: The link is opened after its end date

- **GIVEN** a link share whose end date has passed and whose usage limit is not reached
- **WHEN** someone opens the link and enters the right password
- **THEN** the page shows that the link is not available and returns no secret data
- **AND** the link share and its snapshot no longer exist

#### Scenario: A past end date is refused

- **GIVEN** an owner in the link share dialog
- **WHEN** the owner submits an end date in the past
- **THEN** no link share is created and the dialog says the date must be in the future
