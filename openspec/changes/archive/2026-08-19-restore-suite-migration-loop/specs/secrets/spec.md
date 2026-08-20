## ADDED Requirements

### Requirement: Possibly-Compromised Flag Lifecycle

The system MUST raise, render and clear the `possibly_compromised_at` flag as set out below. The field is already defined in this spec's data model as "Set during compromise recovery migration; null if not compromised. Signals the user should rotate this secret's value.", and exists on `doriath_secrets` only; what follows fixes its behaviour, which is currently unspecified and unimplemented — nothing in the system ever sets the flag, leaving every consumer of it permanently inert.

**Raise.** The system MUST set `possibly_compromised_at` on every secret migrated by a compromise-recovery migration, at the moment that secret's re-encrypted value is committed. The flag MUST be raised for the migrated row regardless of whether other rows in the same migration failed, and MUST NOT be raised for rows the migration did not touch. Raising it MUST be idempotent: a re-run or a retry MUST NOT overwrite an already-set timestamp.

**Render.** A secret carrying `possibly_compromised_at` MUST be surfaced as a warning that is hard to ignore — visible on the secret itself and in the vault-wide health surface, not only in a report the user must go looking for. The warning MUST say that the stored value should be considered exposed and replaced at its source, and MUST NOT be dismissible in a way that hides it while the flag is still set. The flag is plaintext metadata, not ciphertext, so rendering it requires no decryption and MUST work whether or not the vault is unlocked.

**Clear.** The flag MUST be cleared exactly when the secret's `key` ciphertext is written with a value different from the stored one, whoever writes it. Binding the rule to the write rather than to the writer is deliberate: any future path that replaces the value inherits the clearing without needing its own rule, and no path can replace a value while leaving the warning standing.

It MUST NOT be cleared by a rename, a folder move, a type change, a metadata edit, a share operation, or by the migration itself. It MUST NOT be cleared by a write that re-submits the ciphertext already stored — a client that echoes the whole record back on every save must not silence the warning without the value having changed. Clearing a source secret's flag MUST propagate to shared copies through the existing sync-on-update path.

Note for implementers: secret-request **fulfilment** is expected to be such a write, but currently is not one. `SecretRequestService::fill()` flips the request to `fulfilled` and notifies the requester without ever writing the submitted blobs to the linked Secret row, so there is no key write to clear the flag on — and, more seriously, the filled-in values are discarded. That is a defect in the secret-requests capability rather than in this requirement; the rule above applies unchanged the moment fulfilment writes the value.

#### Scenario: Every migrated secret is flagged

@e2e exclude Flag-raising is asserted on the persisted row at the moment of the re-encryption write; covered by PHPUnit on the re-encryption endpoint and unit tests of the migration driver.
- **GIVEN** a compromise-recovery migration processing a user's secrets
- **WHEN** a secret's re-encrypted value is committed
- **THEN** that secret's `possibly_compromised_at` MUST be set
- **AND** a secret whose re-encryption failed MUST NOT be flagged

#### Scenario: Flag raising is idempotent

@e2e exclude Idempotency of a timestamp write has no DOM surface; covered by PHPUnit on the re-encryption endpoint.
- **GIVEN** a secret already carrying a `possibly_compromised_at` timestamp
- **WHEN** it is processed again by a retry or a subsequent migration
- **THEN** the existing timestamp MUST be preserved rather than overwritten

#### Scenario: A flagged secret warns the user prominently

- **GIVEN** a secret carrying `possibly_compromised_at`
- **WHEN** the user views their vault and opens that secret
- **THEN** a warning MUST be shown on the secret and reflected in the vault health surface, stating that the value should be considered exposed and replaced at its source
- **AND** the warning MUST NOT be dismissible while the flag is still set

#### Scenario: Metadata edits do not clear the flag

@e2e exclude Distinguishing a metadata write from a value write is a service-layer assertion on the persisted row; covered by PHPUnit on the secret-update path.
- **GIVEN** a secret carrying `possibly_compromised_at`
- **WHEN** the owner renames it, moves it to another folder, or shares it
- **THEN** `possibly_compromised_at` MUST remain set

#### Scenario: Replacing the value clears the flag

@e2e exclude Clearing is asserted on the persisted row and on shared copies after sync-on-update; covered by PHPUnit on the secret-update and share-sync paths.
- **GIVEN** a secret carrying `possibly_compromised_at`
- **WHEN** any path writes a `key` ciphertext different from the stored one
- **THEN** `possibly_compromised_at` MUST be cleared on that secret and on every shared copy of it
- **WHEN** a write re-submits the ciphertext already stored, alongside a rename
- **THEN** `possibly_compromised_at` MUST remain set
