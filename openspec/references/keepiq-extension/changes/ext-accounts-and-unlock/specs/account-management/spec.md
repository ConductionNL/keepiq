## ADDED Requirements

### Requirement: First-run add account screen
The popup SHALL show an "Add account" screen when no account is stored, with three fields: Server URL, Username and App password. Help text MUST tell the user to create a dedicated app password under Nextcloud Settings, Security, and never to enter the Nextcloud login password. Once a valid server URL is typed the help text MUST link to `<origin>/index.php/settings/user/security` on that server. Nothing is stored until verification succeeds.

#### Scenario: Fresh profile
- **GIVEN** `storage.local` has no `accounts` entry
- **WHEN** the user opens the popup
- **THEN** the "Add account" screen is shown with all three fields empty and the "Add account" button disabled until every field has a value

#### Scenario: Security settings link follows the server URL
- **GIVEN** the user typed `cloud.example.org` in Server URL
- **WHEN** the field loses focus
- **THEN** the help text links to `https://cloud.example.org/index.php/settings/user/security` and opens it in a new tab

### Requirement: Server URL normalisation
The extension SHALL accept a bare host, a full origin, or any URL under `/index.php/apps/keepiq` as the Server URL, and MUST store only the origin (`scheme://host[:port]`). A bare host defaults to `https`. `http` MUST be rejected unless the host is `localhost`, `127.0.0.1`, or ends in `.test` or `.local`.

#### Scenario: Keepiq web app URL pasted
- **WHEN** the user enters `https://cloud.example.org/index.php/apps/keepiq/vault`
- **THEN** the account is verified and stored with origin `https://cloud.example.org`

#### Scenario: Plain http on a public host
- **WHEN** the user enters `http://cloud.example.org`
- **THEN** the screen shows "Use https for this server" and no request is sent

#### Scenario: Plain http on a dev host
- **WHEN** the user enters `http://stable35.test:8080`
- **THEN** the origin `http://stable35.test:8080` is accepted

### Requirement: Host permission requested at add time
The extension SHALL request `<origin>/*` as an optional host permission from the popup, inside the click handler of "Add account", before verifying the credentials. If the user declines, the extension MUST show "Keepiq needs permission to reach <host>" and MUST NOT store anything or send any request.

#### Scenario: Permission granted
- **WHEN** the user clicks "Add account" and accepts the browser's permission prompt
- **THEN** verification starts against that origin

#### Scenario: Permission declined
- **WHEN** the user rejects the permission prompt
- **THEN** the form keeps its values, the error is shown and `storage.local` is unchanged

### Requirement: Credentials verified before storing
The extension SHALL verify an account by calling `GET /ocs/v2.php/cloud/user` and then `GET /api/v1/suites` (ADR-003) with the entered credentials, from the background. Only when both succeed and an active suite exists MUST the account be stored. The uid and display name come from the identity response, not from the typed username.

#### Scenario: Valid account
- **GIVEN** the credentials belong to a Nextcloud user with Keepiq installed and one `active` suite
- **WHEN** verification runs
- **THEN** the account is stored, becomes the active account and the popup shows the unlock screen for it

### Requirement: Distinct verification errors
The extension SHALL show a distinct message for each verification failure and MUST keep the form values so the user can correct one field. Failures: host unreachable ("Could not reach <host>"), identity route not answering with the OCS JSON envelope ("<host> does not look like a Nextcloud server"), 401 ("Wrong username or app password"), 404 on the suites route ("Keepiq is not installed on <host>"), and no suite with `status === 'active'` ("Open the Keepiq web app once and set a master password, then try again").

#### Scenario: Bad app password
- **WHEN** the identity call returns 401
- **THEN** "Wrong username or app password" is shown and the App password field is cleared

#### Scenario: Nextcloud without Keepiq
- **WHEN** the identity call succeeds and the suites call returns 404
- **THEN** "Keepiq is not installed on <host>" is shown

#### Scenario: Keepiq without a suite
- **WHEN** the suites call returns an array with no `active` row
- **THEN** the message tells the user to open the Keepiq web app and set a master password

#### Scenario: Server offline
- **WHEN** the identity request fails at the network level
- **THEN** "Could not reach <host>" is shown

### Requirement: Account record storage
The extension SHALL store each account in `storage.local` under `accounts[<accountId>]` with `origin`, `uid`, `displayName`, `email`, `avatarDataUrl` and `appPassword`, plus `activeAccountId` and a per-account `settings.<accountId>` entry. The app password is cleared by logout, account removal and a 401 response (ADR-002). The record and settings are cleared by account removal only.

#### Scenario: Account added
- **WHEN** verification succeeds
- **THEN** `accounts[<accountId>]` holds the six fields, `activeAccountId` is `<accountId>` and `settings.<accountId>` holds the default timeout settings

### Requirement: Account limit and duplicates
The extension SHALL allow at most 5 accounts, as in Bitwarden, and MUST reject a second account with the same origin and uid.

#### Scenario: Sixth account
- **GIVEN** 5 accounts are stored
- **WHEN** the user opens the account switcher
- **THEN** "Add account" is disabled with the hint "Maximum of 5 accounts reached"

#### Scenario: Same user on the same server
- **GIVEN** an account for `https://cloud.example.org` with uid `alice` exists
- **WHEN** verification of a new account resolves to the same origin and uid
- **THEN** "This account is already added" is shown and no second record is created

### Requirement: Avatar fetched and cached
The extension SHALL fetch `GET /index.php/avatar/{uid}/64` with the account's credentials from the background after a successful add, store it as a data URL in `accounts[<accountId>].avatarDataUrl` in `storage.local`, and render it in the popup's top-right corner for the active account. When the fetch fails the extension MUST render the display name's initials instead and retry on the next successful login. The cached avatar is cleared with the account record.

#### Scenario: Avatar available
- **WHEN** the avatar request returns an image
- **THEN** the header shows it as a 32 px circle and `avatarDataUrl` is set

#### Scenario: Avatar unavailable
- **WHEN** the avatar request returns a non-2xx status or fails
- **THEN** the header shows the initials disc and `avatarDataUrl` is `null`

### Requirement: Account switcher panel
Clicking the header avatar SHALL open a panel listing every stored account with avatar or initials, display name, server host and one status label: "Unlocked", "Locked" or "Logged out". The panel MUST offer per-account "Lock" (only when Unlocked) and "Log out", plus "Add account", "Lock all" and "Log out all". The active account MUST be marked.

#### Scenario: Two accounts, one unlocked
- **GIVEN** account A is unlocked and active, account B is locked
- **WHEN** the user opens the switcher
- **THEN** A shows "Unlocked" with a "Lock" action and is marked active, B shows "Locked" without a "Lock" action, and both show "Log out"

#### Scenario: Account whose app password was revoked
- **GIVEN** account B received a 401 earlier
- **WHEN** the user opens the switcher
- **THEN** B shows "Logged out"

### Requirement: Exactly one active account
The extension SHALL keep exactly one active account in `storage.local` `activeAccountId`. Selecting another account in the switcher MUST change the active account and re-render the popup for it. Switching MUST NOT lock or log out the previous account.

#### Scenario: Switch to a locked account
- **GIVEN** A is unlocked and active, B is locked
- **WHEN** the user selects B
- **THEN** the popup shows the unlock screen for B, and A stays unlocked in the switcher

### Requirement: Log out removes the account
"Log out" on an account SHALL purge its app password, private key, cached suite row, `vaultCache.<accountId>`, `settings.<accountId>` and its `accounts[<accountId>]` record (ADR-002). Logging out the active account MUST make the next remaining account active, or show the "Add account" screen when none is left. The browser host permission for the origin is kept.

#### Scenario: Log out the only account
- **WHEN** the user clicks "Log out" on the only account
- **THEN** `storage.local` has no key for that account and the popup shows the "Add account" screen

#### Scenario: Log out the active account among several
- **GIVEN** A is active and B exists
- **WHEN** the user logs out A
- **THEN** B is active and the popup renders B's current state

### Requirement: Lock all and Log out all
"Lock all" SHALL lock every unlocked account without changing the active account. "Log out all" SHALL ask for confirmation and then remove every account, clearing `accounts` and `activeAccountId`.

#### Scenario: Lock all
- **GIVEN** A and B are unlocked
- **WHEN** the user clicks "Lock all"
- **THEN** both show "Locked" and the popup shows the unlock screen for the active account

#### Scenario: Log out all confirmed
- **WHEN** the user clicks "Log out all" and confirms
- **THEN** the "Add account" screen is shown and no account data remains in `storage.local` or `storage.session`

### Requirement: Re-login after revocation
When an account is in the "Logged out" state (app password cleared by a 401 or by the timeout action "Log out") the extension SHALL keep its identity record and settings and, when it is the active account, show a "Log in again" screen with the account's display name, server host and a single App password field. Submitting MUST run the same verification as adding, without the host permission prompt, and MUST NOT create a second record.

#### Scenario: Session revoked
- **GIVEN** the active account's app password was revoked in Nextcloud
- **WHEN** any request returns 401
- **THEN** the popup shows "Session revoked, please log in again" above the App password field

#### Scenario: New app password accepted
- **WHEN** the user enters a valid app password on the "Log in again" screen
- **THEN** the account is stored with the new app password, status becomes "Locked" and the unlock screen is shown
