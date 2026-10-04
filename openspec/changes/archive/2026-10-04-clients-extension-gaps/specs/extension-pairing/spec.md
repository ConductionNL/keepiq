## ADDED Requirements

### Requirement: A server address is https and stored clean
When an account is paired, the extension MUST store the server address as scheme, host, port and any Nextcloud subfolder, without a page path, query or fragment, so an address pasted from a Nextcloud page works. It MUST refuse plain http, except for a local development host (`localhost`, `127.0.0.1`, `::1`, or a name ending in `.localhost`, `.test` or `.local`), before any request is made. It MUST refuse an address with a user name or password in it. An account stored over plain http before this rule MUST NOT send its app password; the popup asks to disconnect it and connect again over https.

#### Scenario: Paste a page address
@e2e exclude Browser-extension worker. Covered by tests/extension/pairing.spec.js ("stores the server address without the page path it was pasted with") and ("stores %s as %s").
- **GIVEN** the pairing form
- **WHEN** the user pastes `https://one.example/index.php/apps/keepiq/vault`
- **THEN** the account is stored with the address `https://one.example`

#### Scenario: Plain http
@e2e exclude Browser-extension worker. Covered by tests/extension/pairing.spec.js ("refuses a plain http server before sending the app password") and ("will not use an account paired over http before this rule").
- **GIVEN** the pairing form, or an account paired over http earlier
- **WHEN** the address is `http://plain.example`
- **THEN** no request is made and the user is told Keepiq needs https

### Requirement: Requests carry the app password and no cookies
Every request to the server MUST authenticate with the account's app password only and MUST be sent without cookies, so a Nextcloud browser session never rides along.

#### Scenario: No cookies
@e2e exclude Browser-extension worker. Covered by tests/extension/pairing.spec.js ("sends every request without cookies").
- **GIVEN** a paired and unlocked account
- **WHEN** the extension talks to the server
- **THEN** every request is sent with `credentials: 'omit'`

### Requirement: A revoked app password signs the account out
When the server answers 401 to a stored account's app password, the extension MUST sign that account out: lock it, delete the stored app password, the vault snapshot and the generator state, and keep the account with its address and user. A signed-out account MUST NOT unlock. The popup MUST say the app password was revoked or changed, show "(signed out)" in the account switcher, and offer to sign in again with a new app password, which is verified before it is stored. A 401 while pairing is a wrong password and signs nothing out.

#### Scenario: The app password is revoked
@e2e exclude Browser-extension worker. Covered by tests/extension/pairing.spec.js ("signs the account out: locked, password and snapshot gone, account kept").
- **GIVEN** an unlocked account
- **WHEN** the server answers 401 to its app password
- **THEN** the account is locked and marked signed out, its app password and snapshot are gone, and unlocking is refused

#### Scenario: Sign in again
@e2e exclude Browser-extension worker and popup. Covered by tests/extension/pairing.spec.js ("signs in again with a new app password, verified first") and ("shows the signed-out view in the popup and signs in from there").
- **GIVEN** a signed-out account
- **WHEN** the user enters a new app password in the popup
- **THEN** a refused password changes nothing, and an accepted one is stored and the account can unlock
