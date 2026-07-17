# doriath-cli

## ADDED Requirements

### Requirement: Single self-contained binary distribution

The Doriath CLI SHALL be distributed as a single statically-linked binary requiring no runtime and no Nextcloud app installation, cross-compiled for Linux, macOS, and Windows on amd64 and arm64. The binary SHALL provide shell completion scripts for bash, zsh, and fish, and every read command SHALL support a `--output json` structured mode.

#### Scenario: Binary runs with no runtime dependency

- **GIVEN** a clean CI base image with neither PHP nor Node installed
- **WHEN** the `doriath` binary is placed on the path and invoked with `--version`
- **THEN** it MUST run and report its version without requiring any external runtime or Nextcloud app install

#### Scenario: JSON output on a read command

- **GIVEN** an authenticated CLI session
- **WHEN** a read command is invoked with `--output json`
- **THEN** the command MUST emit a single structured JSON object to stdout suitable for `jq` parsing, with no human-formatting noise

#### Scenario: Shell completion is emitted

- **GIVEN** the installed binary
- **WHEN** the user runs the completion subcommand for bash, zsh, or fish
- **THEN** a valid completion script for that shell MUST be printed to stdout

### Requirement: Human mode preserves zero-knowledge decryption

In human mode the CLI SHALL authenticate to a Nextcloud instance using a Nextcloud **app-password** (never the login password), and SHALL unlock the vault by deriving the master-password key and decrypting the stored EncryptionSuite private-key blob **entirely within the CLI process**. All secret decryption SHALL occur client-side in the CLI process; no plaintext secret value and no master-password-derived key material SHALL be transmitted to the server (ADR-003).

#### Scenario: Unlock decrypts the private key locally

- **GIVEN** a logged-in CLI holding a Nextcloud app-password
- **WHEN** the user runs `unlock` and enters the correct master password
- **THEN** the CLI MUST derive the key and unwrap the EncryptionSuite private-key blob in-process
- **AND** no HTTP request issued by the flow MUST contain the master password or the derived key

#### Scenario: Show decrypts a secret in-process

- **GIVEN** an unlocked human-mode session
- **WHEN** the user runs `show <secret>`
- **THEN** the CLI MUST fetch the ciphertext envelope and decrypt it locally with the cached private key
- **AND** the server MUST at no point return or receive a plaintext secret value

#### Scenario: Login password is not accepted

- **GIVEN** a user configuring `login`
- **WHEN** they attempt to authenticate with their Nextcloud login password instead of an app-password
- **THEN** the CLI MUST require an app-password and MUST NOT persist the login password

### Requirement: Timeout-bounded session cache

The CLI SHALL cache the unlocked private key in an OS-keyring-backed or memory-only session with a configurable inactivity timeout, so that repeated read commands do not re-prompt for the master password within the timeout window. The cached key SHALL NOT be written to a plaintext file at rest, and `logout` SHALL clear the cached session immediately.

#### Scenario: Cached session avoids re-prompt

- **GIVEN** an unlocked session with a 15-minute timeout
- **WHEN** the user runs a second read command 2 minutes later
- **THEN** the command MUST succeed without re-prompting for the master password

#### Scenario: Session expires after inactivity

- **GIVEN** an unlocked session whose inactivity timeout has elapsed
- **WHEN** the user runs a read command
- **THEN** the CLI MUST require the master password again before decrypting

#### Scenario: Logout clears the session

- **GIVEN** an unlocked session
- **WHEN** the user runs `logout`
- **THEN** the cached private key MUST be dropped and the next read command MUST require unlock

### Requirement: CI mode authenticates as an application via RFC 7523

In CI mode the CLI SHALL authenticate as an application using the existing RFC 7523 JWT-bearer flow — signing an RS256 assertion with the application private key, exchanging it for the opaque short-lived bearer token, and fetching application secrets by name or id — and SHALL decrypt the returned `doriath-machine-secret-v1` envelope locally with the application private key. The application private key SHALL be supplied by the operator (file, environment, or OS keyring reference) and SHALL NOT be stored by Doriath or embedded in any shareable configuration.

#### Scenario: Fetch and decrypt an application secret

- **GIVEN** a CI environment holding a valid application private key
- **WHEN** the CLI exchanges a JWT assertion for a bearer token and fetches a secret by name
- **THEN** it MUST receive the encrypted envelope and decrypt it locally with the application private key
- **AND** no plaintext value MUST be returned by the server

#### Scenario: Self-configures from the discovery document

- **GIVEN** only the instance base URL
- **WHEN** the CLI starts a CI-mode fetch
- **THEN** it MUST derive the token endpoint, grant type, assertion requirements, and secret endpoints from the public discovery document without any hard-coded contract URL

#### Scenario: Certificate-fingerprint mismatch fails fast

- **GIVEN** an application private key that does not match the envelope's certificate fingerprint (e.g. after re-registration)
- **WHEN** the CLI attempts to decrypt
- **THEN** it MUST fail fast with a fingerprint-mismatch error rather than surfacing a bare decrypt exception

### Requirement: CI-mode output never writes plaintext to disk

The CLI SHALL provide three CI output modes — shell env-export lines, `--output json`, and direct exec-with-injected-environment (`doriath run -- <cmd>`) — and SHALL NOT write any decrypted secret value to a file at rest. The `run` mode SHALL inject the resolved secrets into the child process environment only, leaving the parent environment and the filesystem untouched.

#### Scenario: run injects into the child environment only

- **GIVEN** a resolvable set of application secrets
- **WHEN** the user runs `doriath run -- <cmd>`
- **THEN** `<cmd>` MUST see the secrets in its own environment
- **AND** no decrypted value MUST be written to any file, and the parent shell environment MUST be unchanged

#### Scenario: env-export warns about shell exposure

- **GIVEN** a CI-mode fetch requesting env-export output
- **WHEN** the CLI emits `export NAME='value'` lines to stdout
- **THEN** it MUST also warn on stderr that the values are now present in the shell environment/history

### Requirement: CI mode honours leases when advertised

When the instance's discovery document advertises lease support (`machine-secret-leases`), the CLI SHALL read the `Doriath-Lease-Id` and `Doriath-Lease-Expires` response headers on a machine fetch, respect the lease TTL, and renew the lease via the documented renewal endpoint before expiry for a long-running fetch or poll loop. When lease support is NOT advertised, the CLI SHALL operate without lease handling and remain fully functional.

#### Scenario: Lease is renewed before expiry

- **GIVEN** a lease-aware instance and a long-running `doriath run` holding an active lease
- **WHEN** the lease approaches its expiry within the policy window
- **THEN** the CLI MUST renew the lease via the renewal endpoint before it expires, up to the policy maximum TTL

#### Scenario: Works against a lease-unaware instance

- **GIVEN** an instance whose discovery document does not advertise lease support
- **WHEN** the CLI performs a CI-mode fetch
- **THEN** it MUST succeed without attempting any lease read or renewal

### Requirement: Read-only vault access in v1

The CLI v1 SHALL expose read-only access to the vault and SHALL NOT provide any command that creates, edits, or deletes a secret. This scoping SHALL be documented, with the rationale that a write in Doriath's sharing model requires client-side re-wrapping under every recipient's public key.

#### Scenario: No write command exists in v1

- **GIVEN** the v1 CLI binary
- **WHEN** the user inspects the available commands
- **THEN** no create, edit, update, or delete command MUST be present, and the read-only scope MUST be documented
