## ADDED Requirements

### Requirement: Add an account through Nextcloud Login Flow v2

The extension MUST add an account through Nextcloud Login Flow v2: after the server address passes the https rule, it MUST request host permission for that server, call `POST <server>/index.php/login/v2`, open the returned login URL in a browser tab and poll the returned endpoint with its token until it receives the login name and app password. It MUST stop polling and store nothing when the user closes the tab or 20 minutes pass. It MUST verify the received app password with the pair call before storing it. Entering an app password by hand MUST remain available as a fallback. The extension MUST NOT ask for or store the user's Nextcloud password. After pairing, the extension MUST tell the user that it needs no file access and point to Nextcloud's Devices & sessions, where the app password can be revoked or its file access switched off.

#### Scenario: Sign in through the browser

@e2e exclude Browser-extension worker and core login page; covered by tests/extension/pairing.spec.js cases named in tasks.md 2.1.

- **GIVEN** a reachable https Nextcloud server with Keepiq
- **WHEN** the user enters its address in the extension and signs in on the Nextcloud login page that opens, including two-factor
- **THEN** the extension receives an app password, the pair call succeeds and the account is stored

#### Scenario: The user abandons the login

@e2e exclude Browser-extension worker; covered by tests/extension/pairing.spec.js cases named in tasks.md 2.1.

- **GIVEN** a started login flow
- **WHEN** the user closes the login tab, or 20 minutes pass
- **THEN** the extension stops polling, stores nothing and shows the address form again

#### Scenario: Manual app password

@e2e exclude Browser-extension popup; covered by the existing tests/extension/pairing.spec.js cases.

- **GIVEN** a server whose login page the extension cannot open
- **WHEN** the user chooses to enter an app password
- **THEN** the existing manual pairing works unchanged
