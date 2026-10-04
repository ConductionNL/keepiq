## ADDED Requirements

### Requirement: Only the extension's own pages reach the vault
The worker MUST answer vault, account and unlock messages only from the extension's own pages: the popup, the unlock window and the popped-out popup, each as the top frame of its tab. A content script MUST reach only the messages a web page needs (capture, the save offer, passkey requests). A message from another extension MUST be refused.

#### Scenario: A web page asks for the vault
@e2e exclude Browser-extension worker message gate. Covered by tests/extension/accounts.spec.js ("refuses %s from a content script") and ("refuses a message from another extension"), and tests/extension/popupShell.spec.js ("answers the popped-out window, as the top frame of its own tab only").
- **GIVEN** a paired and unlocked account
- **WHEN** a content script or another extension sends a vault message
- **THEN** the worker refuses it and makes no request

### Requirement: Network requests run in the worker only
Only the worker MAY talk to the Keepiq server. The popup, the unlock window and the content scripts MUST NOT make network requests, and MUST NOT import the API client.

#### Scenario: Page code has no network calls
@e2e exclude Static property of the extension source. Covered by tests/extension/baseline.spec.js ("makes network requests from the worker only").
- **GIVEN** the extension's popup, unlock and content-script sources
- **WHEN** they are scanned
- **THEN** none calls fetch, XMLHttpRequest or sendBeacon, and none imports the API client

### Requirement: A send leaves nothing behind
Creating a send MUST NOT write its content, its key or its link to extension storage. Only ciphertext reaches the server.

#### Scenario: After a send
@e2e exclude Browser-extension worker. Covered by tests/extension/baseline.spec.js ("keeps no send content or link in extension storage") and tests/extension/vaultSendGenerator.spec.js ("sends only ciphertext, and the link alone decrypts it").
- **GIVEN** an unlocked account
- **WHEN** a text send is created
- **THEN** neither its text nor its key appears in local or session storage

### Requirement: Disconnecting revokes the app password
Disconnecting an account MUST delete its app password in Nextcloud with that same credential, and MUST remove the account's local data.

#### Scenario: Disconnect
@e2e exclude Browser-extension worker. Covered by tests/extension/unpair.spec.js ("deletes the app password and clears the pairing").
- **GIVEN** a paired account
- **WHEN** the user disconnects it
- **THEN** the app password is deleted in Nextcloud and the pairing is gone

### Requirement: One account per user and server
The extension MUST refuse to pair the same user on the same server twice, whatever trailing slash the address has.

#### Scenario: Pair again
@e2e exclude Browser-extension worker. Covered by tests/extension/baseline.spec.js ("refuses the same user on the same server twice").
- **GIVEN** a paired account
- **WHEN** the same user pairs the same server again
- **THEN** the extension says "This account is already connected."

### Requirement: An idle lock locks and never logs out
The idle delay MUST be one of 1, 5, 15, 30, 60 or 240 minutes, capped by the organisation's maximum. Immediately, Never and a custom delay are not offered: Never would outlive any organisation maximum, and the popup closes on every click, so Immediately would lock on each use. A system lock MUST lock every account. Locking MUST keep the pairing; only a revoked app password logs an account out.

#### Scenario: Idle and system lock
@e2e exclude Browser-extension worker timers. Covered by tests/extension/accounts.spec.js ("locks only the account whose timer expired; an OS lock locks both") and ("refuses an idle delay that is not offered").
- **GIVEN** two unlocked accounts
- **WHEN** one account's timer expires, and later the system locks
- **THEN** only that account locks first, then both, and both stay paired

### Requirement: One match rule
Autofill MUST rank logins with one fixed rule: the exact host first, then the same registrable domain, then a name match. There are no per-item match rules.

#### Scenario: Ranking
@e2e exclude Pure matching function. Covered by tests/extension/match.spec.js ("scores exact host highest, same-site next, name fallback lowest").
- **GIVEN** logins for the exact host, the same site and a name match
- **WHEN** the popup asks for matches
- **THEN** they come back in that order

### Requirement: No site icons are fetched
The extension MUST NOT fetch favicons or other site icons, from the sites or from an icon service. Either would tell a third party which sites the user has accounts on.

#### Scenario: No icon requests
@e2e exclude Static property of the extension source. Covered by tests/extension/baseline.spec.js ("fetches no site icons").
- **GIVEN** the extension's sources
- **WHEN** they are scanned
- **THEN** none loads a favicon or an image from a site
