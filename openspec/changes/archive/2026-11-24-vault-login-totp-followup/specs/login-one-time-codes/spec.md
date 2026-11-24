## ADDED Requirements

### Requirement: A clone of a login carries neither the code nor the passkeys

Cloning a login MUST NOT copy its `totp` seed or its `passkeys` into the new secret. The create dialog opened by Clone MUST start with an empty authenticator key and MUST say that passkeys and the one-time code are not copied. A user who wants the same code on the copy enters the key on it themselves. Print never shows the seed or a passkey private key (D4 of `vault-item-clone-preview-and-print`).

#### Scenario: A login with a seed and passkeys is cloned
@e2e exclude Covered at the component level by tests/dialogs/SecretCreateDialog.clone.spec.js ("a clone of a login with a seed and passkeys saves neither"); the clone flow itself is driven in the clone e2e of vault-item-clone-preview-and-print.
- **GIVEN** a login that holds a seed and one passkey
- **WHEN** the user clones it and saves the copy
- **THEN** the create request carries no `totp` and no `passkeys`
- **AND** the dialog said that passkeys and the one-time code are not copied

### Requirement: The Passkey type filter also finds logins with passkeys

When the vault list is filtered on the built-in Passkey type, the list and its total MUST also hold the logins whose `passkeys` field holds ciphertext. The server MUST decide this from the presence of ciphertext only, as the vault list marker does, without decrypting it and without storing a new plaintext flag. Every other type filter keeps matching on the type alone.

#### Scenario: Filtering on Passkey
@e2e exclude Server-side query shape, covered by tests/Unit/Db/SecretListFilterTest.php and tests/Unit/Db/SecretMapperQueriesTest.php; the list UI is unchanged.
- **GIVEN** a standalone Passkey item and a login with one passkey
- **WHEN** the user filters the vault on Passkey
- **THEN** both are listed and the total is 2

#### Scenario: Filtering on another type
@e2e exclude Covered by tests/Unit/Db/SecretListFilterTest.php.
- **GIVEN** a login with one passkey
- **WHEN** the user filters the vault on Login
- **THEN** the login is listed because of its type, and no other row is added for holding passkeys
