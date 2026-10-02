## ADDED Requirements

### Requirement: A login can carry its own TOTP seed

The system MUST let a vault user store a TOTP seed on a login-type secret, as an `otpauth://totp/` URI or a base32 secret, entered in the Authenticator key field of the create and edit dialogs or read from a QR image in the browser. The seed MUST be stored inside the secret's RSA-encrypted additional fields under the key `totp` and MUST NOT be sent to the server in any other form. Additional fields named `otp` or `otpauth` on existing secrets MUST be read as the seed.

#### Scenario: A vault user adds a seed to a login

- **GIVEN** a vault user editing their login for `example.com` in the edit dialog
- **WHEN** the user pastes an `otpauth://totp/` URI into the Authenticator key field and saves
- **THEN** the request body carries only the encrypted additional fields blob
- **AND** reopening the login shows the Authenticator key as set

#### Scenario: A seed is refused under the password's rules

- **GIVEN** a login that belongs to another user, a secret that belongs to an application, and an id that does not exist
- **WHEN** a signed-in vault user reads or writes the additional fields of each
- **THEN** all three answer the same 404 and nothing is written, stamped or logged
- **AND** a signed-out caller gets 401 and a blocked encryption suite withholds the additional fields wherever it withholds the password

### Requirement: The login shows a live code

When a login's decrypted additional fields carry a seed, the secret detail sidebar MUST show the current code with its countdown and a copy button, computed in the browser by the existing TOTP generator, and MUST NOT list the raw seed among the additional fields. A seed that cannot be parsed MUST show the invalid-seed state and no code.

#### Scenario: A vault user reads the code of an imported login

- **GIVEN** a vault user who imported a Bitwarden export whose login for `example.com` carries `login.totp`
- **WHEN** the user opens that login in the secret detail sidebar on /secrets
- **THEN** a 6-digit code and a countdown are shown
- **AND** the seed text is not shown in the Additional fields box

#### Scenario: A malformed seed

- **GIVEN** a login whose `totp` additional field holds text that is not a TOTP URI or base32 secret
- **WHEN** the user opens the login
- **THEN** the code row shows that the seed is invalid and shows no code

### Requirement: The extension fills the login's own code

When the browser extension fills a login that carries a seed, it MUST compute the code from that login's seed in the service worker and use it for the one-time code field fill and the copied code. Only when the login carries no seed MUST it fall back to a separate `totp` secret matched by host.

#### Scenario: Two logins for one site

- **GIVEN** an unlocked extension and two logins for `example.com`, each with its own seed
- **WHEN** the user fills the second login on the sign-in page
- **THEN** the one-time code filled and copied is the second login's code
