## ADDED Requirements

### Requirement: A login carries several website addresses

The system MUST let a vault user add up to 20 extra website addresses to a login, next to its main address, in the create and edit dialogs and through `additionalUrls` on `POST /api/v1/secrets` and `PUT /api/v1/secrets/{id}`. Extra addresses MUST be stored in plain text like the main address, and the field MUST say so. In-app search, Nextcloud unified search and the browser extension's match MUST consider every address of a login. Recipient copies MUST carry the owner's current list.

#### Scenario: The extension suggests a login on its second address

- **GIVEN** a vault user whose login has main address `https://example.com` and extra address `https://login.example.net`
- **WHEN** the user opens the sign-in page on `login.example.net` and opens the extension popup
- **THEN** the login is listed as a candidate

#### Scenario: A colleague's copy follows the owner's list

- **GIVEN** a login shared with a colleague
- **WHEN** the owner adds an extra address and saves
- **THEN** the colleague's copy lists the new address

### Requirement: Site icons and previews are opt-in per instance

The system MUST show site icons only when an administrator has set a favicon service template and a site preview image in the secret detail sidebar only when an administrator has set a preview service template, both in an admin settings section that states which host or address leaves the instance. Both MUST be empty by default, and with an empty template the browser MUST make no request to any external service for that purpose. Images MUST load with no referrer.

#### Scenario: No preview without an administrator's choice

- **GIVEN** a fresh instance where no preview service is set
- **WHEN** a vault user opens a login in the secret detail sidebar
- **THEN** no preview image is shown and no request goes to an external preview service

#### Scenario: An administrator switches on previews

- **GIVEN** an administrator in the site images section of the Keepiq admin settings
- **WHEN** the administrator sets a preview service URL template and saves
- **THEN** the detail sidebar of a login with an address shows the site's preview image

### Requirement: Open the site's change-password page

The system MUST offer a Change password action on each login with an address, in the secret detail sidebar and on weak, reused and breached findings of the password health report, that opens `https://<host>/.well-known/change-password` for the login's main address host in a new browser tab. The action MUST NOT make any request before the user chooses it.

#### Scenario: A vault user fixes a breached password

- **GIVEN** a vault user whose login for `example.com` is listed as breached in the password health report
- **WHEN** the user chooses Change password on that finding
- **THEN** a new tab opens `https://example.com/.well-known/change-password`
