## ADDED Requirements

### Requirement: Migration Covers Every Suite-Bound Store

The Suite Migration requirement speaks of migrating "all secrets". Because a user's ciphertext is bound to an EncryptionSuite in six separate stores, a migration that walks `doriath_secrets` alone silently strands the other five. The system MUST therefore treat compromise-recovery migration as complete only when every suite-bound store has been given its disposition. Outstanding work MUST be derivable server-side from the data itself — rows still bound to `old_suite_id` — rather than from a client-reported count, so that a resumed migration knows what remains without trusting the browser.

The disposition of each store is fixed as follows. All fields listed as re-encrypted are stored as RSA ciphertext; plaintext columns (`name`, `url`, `folder_id`, `requested_fields`) are organisational metadata and MUST NOT be touched.

| Store | Suite-bound content | Disposition |
|-------|---------------------|-------------|
| `doriath_secrets` | `key`, `login`, `additional_fields` | Re-encrypt under the new suite; re-point `encryption_suite_id` |
| `doriath_secret_versions` | `key`, `login`, `additional_fields` (own `encryption_suite_id`) | Re-encrypt the bounded window fixed by the `secret-version-history` spec (head plus the N most recent versions, default 5); drop older versions |
| `doriath_attachment_grants` | `wrapped_file_key` (RSA-wrapped per-file AES key) | Re-wrap the rotating owner's own grants under the new suite. Grants belonging to other recipients MUST NOT be altered |
| `doriath_secret_requests` | No ciphertext of its own; `encryption_suite_id` selects the certificate used to encrypt future submissions | Lock for the duration of the migration, then unlock and re-point to the new suite |
| `doriath_link_shares` | `encrypted_secret_snapshot` | Revoke (cascade), unchanged from current behaviour |
| `doriath_emergency_contacts` | `recovery_envelope` | Invalidate, unchanged. The envelope is wrapped to the *grantee's* certificate and escrows the grantor's old private key as its plaintext, so the rotating owner cannot re-wrap it alone; the grantor MUST be prompted to re-establish emergency access (see the `emergency-access` spec) |

Re-encryption of `doriath_secrets`, `doriath_secret_versions` and `doriath_attachment_grants` MUST happen in the browser under the same rules as ordinary migration: the old private key decrypts and the new public key encrypts, both as WebCrypto `CryptoKey` objects, and only ciphertext crosses the wire. RSA has a per-chunk plaintext cap (446 bytes at RSA-4096), so every value MUST be re-chunked against the new key rather than having its existing chunk framing reused.

Owner and suite scoping MUST be enforced server-side on every re-encryption write, resolving the acting user through the Nextcloud `OCP\IUserSession` the surrounding controllers already use: a write MUST be refused unless the target row's current `encryption_suite_id` is the migration's `old_suite_id` and the row is owned by the migration's owner.

#### Scenario: Attachment grants survive the rotation

@e2e exclude Attachment-grant re-wrapping is verified by unwrapping the file key with the new private key — a WebCrypto/DB assertion with no DOM surface; covered by unit tests of the migration driver and PHPUnit on the re-point endpoint.
- **GIVEN** a user owns a secret with an encrypted attachment, and their own attachment grant holds the file key wrapped under their old suite
- **WHEN** compromise recovery migration completes
- **THEN** the owner's grant MUST hold the same file key re-wrapped under the new suite and the shared ciphertext blob MUST NOT be re-uploaded or duplicated
- **AND** grants held by other recipients of that attachment MUST be unchanged

#### Scenario: Version history migrates within its bounded window

@e2e exclude Version-history migration is asserted on stored ciphertext and row counts; the version list UI shows only counts, so the migration itself is not DOM-observable. Covered by PHPUnit and migration-driver unit tests.
- **GIVEN** a secret with a head and 12 prior versions, and a migration window of 5
- **WHEN** compromise recovery migration completes
- **THEN** the head and the 5 most recent versions MUST be re-encrypted under the new suite and re-pointed
- **AND** the 7 older versions MUST be deleted
- **AND** the user MUST be told that older version history was dropped

#### Scenario: Secret requests are locked and re-pointed, not stranded

@e2e exclude The lock/re-point transition is server-side request state; the fill-in page's "temporarily unavailable" surface belongs to the secret-requests spec. Covered by PHPUnit on the request lifecycle.
- **GIVEN** a user has pending SecretRequests when they declare their master password compromised
- **WHEN** the migration starts
- **THEN** those requests MUST be set to `locked` and the fill-in link MUST report the request as temporarily unavailable
- **WHEN** the migration terminates
- **THEN** those requests MUST be unlocked and their `encryption_suite_id` MUST be the new suite

#### Scenario: A store left unprocessed blocks completion

@e2e exclude Outstanding-work detection is a server-side query with no DOM representation beyond the aggregate progress indicator; covered by PHPUnit on the completion endpoint.
- **GIVEN** a migration in which the attachment-grant pass has not yet run, so grants remain bound to `old_suite_id`
- **WHEN** the client requests completion of the migration
- **THEN** the server MUST refuse to mark the migration terminal
- **AND** the migration MUST remain `in_progress` with the write lock held

### Requirement: A Migration Always Has A Way To Terminate

A migration MUST always have a way to terminate. Completion is therefore gated on rows nobody has attempted, NOT on every row still bound to `old_suite_id`. The two are different situations and conflating them makes the write lock inescapable: a record that can never be re-encrypted would hold the migration open forever, leaving the owner permanently unable to write to their own vault.

A row is **unaccounted for** when it is still bound to `old_suite_id` and its owning secret carries no `migration_error`. The system MUST refuse to terminate a migration while any unaccounted-for row exists, because terminating locks the old suite and would take every un-reached row down with it. The refusal MUST name the remaining count and point at resuming.

A row that was attempted and recorded a failure MUST NOT block termination. Terminating with such rows present MUST require an explicit acknowledgement from the client stating how many records it accepts losing, and the count MUST match what the server observes; an absent or mismatched acknowledgement MUST be refused. This makes locking a secret out of the vault a decision the owner made, never a side-effect of a client calling completion — a run in which every record failed would otherwise silently lock an owner out of everything.

Only a failure to decrypt the EXISTING ciphertext with the old key may be recorded as a per-record failure. A re-encryption that does not survive its round-trip check MUST NOT be recorded, because the original decrypted successfully and is therefore readable: the fault lies in the new key material, it will recur on every record, and the run MUST stop instead. It follows that finalisation can only ever remove access from rows that were already unreadable under the old key.

#### Scenario: Unattempted rows refuse termination and point at resuming

@e2e exclude Server-side query and status transition; covered by PHPUnit on the completion path.
- **GIVEN** a migration whose client stopped before processing every record, leaving rows with no `migration_error`
- **WHEN** completion is requested
- **THEN** the server MUST refuse, MUST leave the old suite `active`, and MUST keep the migration `in_progress`
- **AND** the refusal MUST report how many records remain and state that the migration can be resumed

#### Scenario: An unrecoverable record does not trap the vault

@e2e exclude Terminal status transition and suite locking are server-side; covered by PHPUnit on the completion path.
- **GIVEN** a migration in which every remaining row on `old_suite_id` has a recorded `migration_error`
- **WHEN** completion is requested WITHOUT an acknowledgement
- **THEN** the server MUST refuse and MUST state how many records would lose access
- **WHEN** completion is requested WITH an acknowledgement matching that count
- **THEN** the migration MUST terminate as `completed_with_errors`, the old suite MUST be locked, and the write lock MUST be released
- **AND** the response MUST identify the secrets that lost access

#### Scenario: A round-trip failure halts rather than sacrificing the record

@e2e exclude Injected at the crypto layer; no DOM path induces it. Covered by unit tests of the migration pipeline.
- **GIVEN** a record whose existing ciphertext decrypts correctly but whose re-encryption does not survive the round-trip check
- **WHEN** the migration processes that record
- **THEN** the failure MUST NOT be recorded as a per-record migration failure
- **AND** the run MUST stop so the new key material can be investigated
- **AND** records already committed MUST remain valid, each having been verified before its own commit

### Requirement: Suite Resolution Is Deterministic During A Migration

For the duration of a compromise-recovery migration an owner legitimately has two `active` EncryptionSuites: the new one, and the old one which must stay readable so the browser can decrypt what it is migrating. Every resolution of "the owner's active suite" MUST therefore be deterministic and MUST select the most recently created active suite, which is always the write target.

Resolution MUST NOT fail merely because more than one suite is active. Treating a legitimate mid-migration state as an error made ordinary writes fail, and reported the one diagnosis that was certainly false — that the owner had no active suite when in fact they had two.

Listings of an owner's suites MUST likewise be ordered newest-first, so that clients selecting "the active suite" from a list bind to the same suite the server would resolve. A client bound to the old suite mid-migration holds the wrong private key for verification, which makes a resumed migration fail on every record.

The system MUST refuse to begin a compromise recovery while a migration is already in progress. Starting a second rotation strands everything the first had not yet reached on a suite that is neither endpoint of the new migration, and no resume can reach it.

#### Scenario: Writes keep working mid-migration

@e2e exclude Suite resolution is a server-side query; covered by PHPUnit.
- **GIVEN** a compromise-recovery migration is in progress, so the owner has two active suites
- **WHEN** the owner's active suite is resolved
- **THEN** the newest active suite MUST be returned rather than an error

#### Scenario: A second rotation is refused

@e2e exclude Guard is server-side and returns a status code; covered by PHPUnit on the recovery endpoint.
- **GIVEN** a migration is already in progress
- **WHEN** the owner submits another compromise recovery
- **THEN** the system MUST refuse, MUST create no suite, and MUST start no migration
- **AND** the error MUST direct the owner to resume or abort the existing migration

### Requirement: Re-Encrypted Ciphertext Is Verified Before The Original Is Discarded

The system MUST prove that freshly produced ciphertext decrypts back to the original plaintext under the new private key **before** the original ciphertext is overwritten or discarded. A round-trip that does not yield a byte-identical plaintext MUST be treated as a per-record migration failure: the original ciphertext MUST be left intact, the failure MUST be recorded, and the migration MUST continue with the remaining records.

This applies to every re-encrypted store. No code path may destroy old ciphertext on the strength of a successful encrypt call alone — the only acceptable evidence is a successful decrypt of the new blob.

#### Scenario: Round-trip mismatch leaves the original intact

@e2e exclude A deliberately corrupted re-encryption is injected at the crypto layer; there is no DOM path to induce it. Covered by unit tests of the migration driver.
- **GIVEN** a record whose re-encrypted blob does not decrypt back to the original plaintext
- **WHEN** the migration processes that record
- **THEN** the original ciphertext and its `encryption_suite_id` MUST be left unchanged
- **AND** the record MUST be counted as a migration failure
- **AND** the migration MUST continue with the remaining records

#### Scenario: Verified re-encryption is committed

@e2e exclude Verification is an in-browser WebCrypto assertion preceding the POST; not DOM-observable. Covered by unit tests of the migration driver.
- **GIVEN** a record whose re-encrypted blob decrypts back to the original plaintext under the new private key
- **WHEN** the migration processes that record
- **THEN** the new ciphertext MUST be written and the row re-pointed to the new suite in the same operation

### Requirement: Migration Refuses To Start On An Unusable New Suite

The system MUST refuse to begin a compromise-recovery migration unless the new EncryptionSuite's signed certificate carries the public key that was submitted for it. The check MUST compare the public key inside the issued certificate against the submitted public key (the `certCarriesPublicKey` modulus comparison already implemented in `CertificateAuthorityService`), and MUST run before any record is re-encrypted.

If the check fails, the system MUST NOT create a SuiteMigration record, MUST NOT apply the write lock, MUST NOT alter the old suite's status, and MUST report a distinct error telling the user their vault is untouched and safe to retry.

#### Scenario: Certificate does not carry the submitted key

@e2e exclude The failure requires forcing a certificate/public-key mismatch at the CA layer; no DOM path induces it. Covered by PHPUnit on the recovery endpoint.
- **GIVEN** a compromise recovery in which the issued certificate does not carry the submitted public key
- **WHEN** the user submits the recovery
- **THEN** the system MUST refuse with a distinct error and create no SuiteMigration record
- **AND** the old suite MUST remain `active` with no write lock applied
- **AND** no record MUST have been re-encrypted

#### Scenario: Precondition passes and migration proceeds

@e2e exclude Certificate/public-key agreement is a server-side crypto assertion; covered by PHPUnit on the recovery endpoint.
- **GIVEN** a compromise recovery in which the issued certificate carries the submitted public key
- **WHEN** the user submits the recovery
- **THEN** the SuiteMigration record MUST be created with status `in_progress` and the write lock MUST be applied

### Requirement: Compromise Recovery States That Regained Access Is Not An All-Clear

When a user declares their master password compromised, the system MUST tell them, before they confirm and again once migration terminates, that they should rotate **every secret value** in the vault. The wording MUST make clear that migrating the secrets to a new key restores *access* only, so that they can visit each site and set a new password in an orderly fashion — it MUST NOT be presented as an all-clear, a fix, or a state of safety.

The system MUST NOT report success in terms that imply the vault is secure again. Migration progress and the terminal outcome MUST be presented inside the recovery dialog, alongside this warning and the count of records still awaiting rotation.

#### Scenario: Warning shown before the user confirms

- **GIVEN** a user has opened the compromise-recovery form
- **WHEN** the form is displayed
- **THEN** it MUST state that every secret value should be rotated, and that key rotation restores access so the user can change those values — not that the secrets are safe

#### Scenario: Terminal message is not an all-clear

- **GIVEN** a compromise-recovery migration that has terminated
- **WHEN** the outcome is presented in the recovery dialog
- **THEN** it MUST report how many records were migrated and how many failed
- **AND** it MUST repeat that the migrated secret values are still to be considered exposed and MUST be rotated at their source
- **AND** it MUST NOT claim the vault is now secure

#### Scenario: Progress is visible inside the recovery dialog

- **GIVEN** a compromise-recovery migration is running
- **WHEN** the user watches the recovery dialog
- **THEN** it MUST show live progress and MUST NOT report completion until the migration has actually terminated
