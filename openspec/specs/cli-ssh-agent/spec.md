# cli-ssh-agent Specification

## Purpose
TBD - created by archiving change clients-ssh-agent. Update Purpose after archive.

## Requirements

### Requirement: The CLI runs an SSH agent that serves vault SSH keys

The CLI MUST provide `keepiq ssh-agent`, which serves the OpenSSH agent protocol on a Unix socket on Linux and macOS and prints the `SSH_AUTH_SOCK` export for that socket. Once unlocked it MUST offer every `ssh_key` secret the user owns (limited to one folder when `--folder` is given) whose private key parses without a passphrase, with the secret name as the identity comment, and MUST name each skipped key on standard error.

#### Scenario: A developer clones over SSH with a vault key

- **GIVEN** a developer whose vault holds an `ssh_key` secret named "GitHub deploy" and who runs `eval "$(keepiq ssh-agent)"` and enters their master password
- **WHEN** they run `git clone git@github.com:example/repo.git`
- **THEN** `ssh` MUST authenticate with the "GitHub deploy" key through the agent
- **AND** no file containing that private key MUST exist on disk

#### Scenario: A passphrase-protected key is skipped

- **GIVEN** a vault with one plain Ed25519 key and one passphrase-protected key
- **WHEN** the agent unlocks
- **THEN** `ssh-add -l` MUST list only the Ed25519 key
- **AND** standard error MUST name the skipped key

### Requirement: Decryption happens only in the agent process

The agent MUST unwrap the suite private key and decrypt SSH keys inside its own process, using the paired Nextcloud app password to fetch only ciphertext, and MUST NOT send the master password, a derived key, a private key or a signature to the server. It MUST NOT write any decrypted key to disk, and MUST disable core dumps at start.

#### Scenario: The server sees only ciphertext reads

- **GIVEN** an agent unlocking against a Keepiq server
- **WHEN** the requests the agent makes are recorded
- **THEN** they MUST be reads of `/api/v1/suites`, `/api/v1/secret-types` and `/api/v1/secrets`
- **AND** no request body MUST contain the master password or any key material

### Requirement: The agent unlocks from a terminal or through ssh-add

The agent MUST prompt for the master password at start when run from a terminal. When started with `--locked` it MUST hold no keys until an `ssh-add -X` unlock request supplies the master password, and `ssh-add -x` MUST lock it again. While locked it MUST answer identity requests with an empty list and refuse every sign request.

#### Scenario: A service-managed agent is unlocked later

- **GIVEN** an agent started with `--locked` by a systemd user unit
- **WHEN** the developer runs `ssh-add -X` and enters their master password
- **THEN** `ssh-add -l` MUST list their vault keys

#### Scenario: Locking drops the keys

- **GIVEN** an unlocked agent
- **WHEN** the developer runs `ssh-add -x`
- **THEN** a following sign request MUST be refused
- **AND** `ssh-add -l` MUST report no identities

### Requirement: Only modern signature schemes

The agent MUST sign with Ed25519, with ECDSA on P-256, P-384 and P-521, and with RSA only when the request carries the `rsa-sha2-256` or `rsa-sha2-512` flag. An RSA sign request without one of those flags MUST be refused.

#### Scenario: A SHA-1 RSA request is refused

- **GIVEN** an unlocked agent holding an RSA key
- **WHEN** a client asks for an `ssh-rsa` signature without a SHA-2 flag
- **THEN** the agent MUST answer with failure and produce no signature

### Requirement: The socket is private to the user

The agent MUST create its socket directory with mode `0700` and the socket with mode `0600`, MUST refuse to start when the directory exists with another owner or a wider mode, and MUST close any connection whose peer uid differs from its own.

#### Scenario: Another local user is refused

- **GIVEN** an agent run by user `alice`
- **WHEN** a process of user `bob` connects to the socket
- **THEN** the agent MUST close the connection without answering any request

### Requirement: Optional confirmation and idle lock

With `--confirm` the agent MUST run the program in `SSH_ASKPASS` with `SSH_ASKPASS_PROMPT=confirm` and the key name before each signature and sign only on exit status 0; it MUST refuse to start with `--confirm` when `SSH_ASKPASS` is unset. With `--idle <minutes>` (default 60, 0 disables) it MUST drop every decrypted key and the suite key after that many minutes without a sign request.

#### Scenario: A denied confirmation blocks the signature

- **GIVEN** an agent started with `--confirm`
- **WHEN** `ssh` asks for a signature and the user dismisses the confirmation dialog
- **THEN** the agent MUST refuse the signature

#### Scenario: The idle lock clears keys

- **GIVEN** an agent started with `--idle 30` and no sign request for 30 minutes
- **WHEN** `ssh` next asks for a signature
- **THEN** the agent MUST refuse it as locked until the developer unlocks again

### Requirement: The vault is the only key source

The agent MUST answer add-identity, remove-identity and remove-all-identities requests with failure.

#### Scenario: ssh-add cannot add a local key

- **GIVEN** an unlocked agent
- **WHEN** the developer runs `ssh-add ~/.ssh/id_ed25519`
- **THEN** the agent MUST refuse the request and the key MUST NOT be listed
