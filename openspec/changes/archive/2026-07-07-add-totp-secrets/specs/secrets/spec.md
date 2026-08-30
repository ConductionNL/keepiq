## MODIFIED Requirements

### Requirement: Secret Types
Every secret MUST have a type. The type is a UI hint only — it drives how the UI labels and presents fields but does not affect server-side validation or the underlying data model. If no type is specified at creation, the `login` system type MUST be used as default.

There are **seven** built-in system types: `login`, `api_key`, `ssh_key`, `certificate`, `note`, `database`, and `totp` (labelled "Authenticator (TOTP)"). The `totp` type marks a secret whose encrypted `key` field holds a TOTP seed — an `otpauth://totp/...` URI (RFC 6238 / Key Uri Format) or a bare base32 secret — stored as ciphertext exactly like any other secret value; it drives the UI to render a client-side one-time-code generator (see the "Client-Side TOTP Code Generation" requirement). Introducing the `totp` type MUST NOT add a database column or migration: the seed lives in the existing `key` blob, so a `totp` secret is indistinguishable from any other secret to the server.

System types are built-in and cannot be modified or deleted. Users may create their own types (visible only to them). Administrators may create global types visible to all users on the instance.

#### Scenario: Create secret with type
- GIVEN a user creates a secret and specifies a type
- WHEN the secret is stored
- THEN the secret MUST reference the specified SecretType

#### Scenario: Create secret without type
- GIVEN a user creates a secret without specifying a type
- WHEN the secret is stored
- THEN the secret MUST default to the `login` system type

#### Scenario: TOTP is a seeded system type
- GIVEN the app's secret-type seeding has run
- WHEN the system secret types are listed
- THEN `totp` ("Authenticator (TOTP)") MUST be present as a system type alongside the other six
- AND it MUST be a system type that cannot be modified or deleted

#### Scenario: TOTP secret stores its seed as ciphertext in the existing key field
- GIVEN a user creates a secret of type `totp` with an `otpauth://totp` seed
- WHEN the secret is stored
- THEN the seed MUST be persisted in the existing encrypted `key` field as ciphertext
- AND no new database column MUST be introduced for the seed
- AND the server MUST NOT be able to distinguish the `totp` secret's ciphertext from any other secret's `key`

#### Scenario: User creates custom type
- GIVEN a user creates a SecretType with scope `user`
- THEN the type MUST be available only to that user when creating or filtering secrets

#### Scenario: Admin creates global type
- GIVEN an admin creates a SecretType with scope `global`
- THEN the type MUST be available to all users on the instance

#### Scenario: Delete system type blocked
- GIVEN a system SecretType (scope `system`)
- WHEN a user or admin attempts to delete or modify it
- THEN the system MUST return an error

#### Scenario: Delete custom type with secrets assigned
- GIVEN a user-scoped SecretType has secrets assigned to it
- WHEN the user deletes the type
- THEN all secrets of that type MUST fall back to the `login` system type

## ADDED Requirements

### Requirement: Client-Side TOTP Code Generation
The system MUST generate the current TOTP one-time code for a secret of type `totp` entirely in the browser, from the decrypted seed, while the vault is unlocked (the owner's `CryptoKey` is in session per the encryption-suites Session Mechanism requirement). The plaintext seed, any HMAC key derived from it, and the generated code MUST NEVER be transmitted to the server or persisted in `localStorage`, `sessionStorage`, IndexedDB, or any other storage. When the vault locks (manual lock, session timeout, all tabs closed), all TOTP state (seeds, derived keys, codes, timers) MUST be discarded — matching the password-health no-leak contract.

The generator MUST parse an `otpauth://totp/...` URI to obtain the base32 secret, `algorithm` (SHA1 default, SHA256, or SHA512), `digits` (6 default or 8), and `period` (30 seconds default), and MUST also accept a bare base32 secret treated with those defaults. It MUST compute the code per RFC 6238 (HMAC over the time counter, RFC 4226 dynamic truncation) using WebCrypto, display the code with a live countdown to the next time window, and offer copy-to-clipboard.

A `totp` secret whose decrypted `key` is not a parseable `otpauth://totp` URI or bare base32 secret MUST display an explicit invalid-seed state and MUST NOT display a code — the system MUST NEVER show a fabricated or best-guess code.

TOTP seeds MUST be excluded from password-health strength, reuse, and breach analysis (high-entropy machine material, not a password).

#### Scenario: Current code is generated in the browser
@e2e exclude In-memory WebCrypto computation over the decrypted vault — asserting the RFC 6238 code value and that no HTTP request or browser-storage write carries the seed/HMAC-key/code is a wire-shape and cryptographic-vector assertion, not a DOM flow; covered by vitest (RFC 6238 published test vectors + no-leak request/storage guard).
- **GIVEN** the vault is unlocked and a `totp` secret holds a known `otpauth://totp` seed
- **WHEN** the TOTP generator computes the code for a fixed timestamp matching an RFC 6238 test vector
- **THEN** it MUST produce the vector's expected code
- **AND** no HTTP request and no browser-storage write issued by the generator MUST contain the seed, a derived HMAC key, or the code

#### Scenario: Locking the vault discards TOTP state
@e2e exclude Memory/timer-lifecycle contract — asserting seeds, derived keys, codes, and countdown timers are dropped is not DOM-observable; covered by vitest (TOTP store reset on lock hook).
- **GIVEN** a `totp` secret's code is being displayed with a running countdown
- **WHEN** the user locks the vault
- **THEN** all seeds, derived keys, generated codes, and countdown timers MUST be discarded from memory

#### Scenario: Invalid seed shows an error, never a fabricated code
@e2e exclude Parser contract over decrypted in-memory value — asserting the invalid-seed branch renders no code is covered by vitest (parser + component test with a malformed seed).
- **GIVEN** a `totp` secret whose decrypted `key` is not a valid `otpauth://totp` URI or base32 secret
- **WHEN** the secret is viewed with the vault unlocked
- **THEN** the UI MUST show an explicit "not a valid authenticator secret" state
- **AND** it MUST NOT display any one-time code

#### Scenario: TOTP seed is excluded from password-health analysis
@e2e exclude Engine-guard contract — asserting `totp`-typed secrets are skipped by strength/reuse/breach analysis is covered by vitest (health engine excludes totp type).
- **GIVEN** the vault contains a `totp` secret and the password-health analysis runs
- **WHEN** the health engine processes the vault
- **THEN** the `totp` secret's seed MUST NOT be scored for strength, counted for reuse, or breach-checked
