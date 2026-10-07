# Design: SSH agent in the Keepiq CLI

## Context

Code at development `4c214a9d`:

- `cli/main.go:33` dispatches subcommands (`login`, `list`, `show`, `get`, `copy`, `ci`, `completion`). `cli/main.go:133` `openHumanSession()` loads the stored pairing, fetches the active suite, unwraps the private key with the master password (`dcrypto.UnwrapPrivateKey`, `cli/internal/crypto/crypto.go:89`), parses it (`:121`) and checks it against the certificate (`:177`). `cli/main.go:119` `promptSecret()` reads without echo.
- `cli/internal/client/client.go:96` `ListSecrets()` fetches every secret row (ciphertext plus index fields) and `:107` `GetSecret()` one row. The `Secret` struct (`:70`) carries `TypeID`, `FolderID`, `Key` and `AdditionalFields`. There is no secret-type lookup yet.
- `cli/internal/crypto/crypto.go:139` `DecryptField()` decrypts one RSA-OAEP chunked field.
- `cli/go.mod` declares `go 1.22` and no dependencies; `cli/README.md` and `.github/workflows/cli-release.yml` call the CLI stdlib-only. `cli-release.yml` runs `go vet` and `go test` and cross-compiles six targets.
- `ssh_key` secrets store the OpenSSH private key in the `key` field and the public key in the encrypted additional fields (`src/cxf/cxf.js:355`). The seeded development key in `lib/Repair/SeedDevelopmentSecrets.php:162` is a truncated placeholder, not a usable key.
- `openspec/specs/keepiq-cli/spec.md` requires read-only v1 and in-process decryption; the session cache is a documented follow-up.

## Goals / Non-Goals

**Goals:**

- `ssh`, `git` and `scp` sign with keys held in the vault, through the standard `SSH_AUTH_SOCK` interface.
- Decrypted private keys never touch the disk and live only while the agent is unlocked.
- Optional per-use confirmation and an idle lock.

**Non-Goals:**

- Windows. A named-pipe listener needs another dependency and its own testing; Windows users can run the agent in WSL until a follow-up change.
- Passphrase-protected OpenSSH keys. The agent skips them and names them; a later change can read a passphrase from an additional field.
- Adding keys to the vault through `ssh-add`. The CLI is read-only in v1.
- A server-side record of each signature. The CLI adds no backend route, as `keepiq-cli` requires.
- A desktop app, a GUI prompt of our own, or a mobile agent.

## Decisions

### D1: A subcommand of the existing CLI

`keepiq ssh-agent [--socket <path>] [--confirm] [--idle <minutes>] [--folder <name>] [--locked]` runs in the foreground on a terminal and prints `SSH_AUTH_SOCK=<path>; export SSH_AUTH_SOCK;`. When its output is not a terminal (`eval "$(keepiq ssh-agent)"`), it asks for the master password, starts a copy of itself in its own session with the password on a pipe, prints the `SSH_AUTH_SOCK` and `SSH_AGENT_PID` exports once that copy listens, and returns, so the command substitution ends (found in the manual Linux run, keepiq#786). The default socket is `$XDG_RUNTIME_DIR/keepiq/agent.sock` on Linux and `$TMPDIR/keepiq-<uid>/agent.sock` on macOS. Running it under a systemd user unit or a launchd agent is documented.

Alternative considered: a separate `keepiq-agent` binary. Rejected: one binary already carries the pairing, the crypto and the release pipeline.

### D2: Two vetted dependencies instead of a hand-written protocol

The agent uses `golang.org/x/crypto/ssh` to parse OpenSSH private keys and sign, `golang.org/x/crypto/ssh/agent` to serve the protocol (`agent.ServeAgent` over a custom `agent.ExtendedAgent`), and `golang.org/x/sys/unix` for peer credentials on macOS. Both are Go project modules, pure Go, so the binary stays static. The README and the workflow comment drop the stdlib-only claim, and the test job adds `govulncheck ./...`.

Alternative considered: implementing the agent protocol and the OpenSSH key format by hand to stay stdlib-only. Rejected: a key parser and a signing protocol are exactly the code that should come from a maintained, audited module.

### D3: Unlock in process, two ways

Started from a terminal, the agent prompts for the master password with the existing `promptSecret()` and calls `openHumanSession()`. Started with `--locked` (for a service manager), it holds no keys until the user runs `ssh-add -X`, which sends a password over the socket as the protocol's unlock request; the agent treats it as the master password. `ssh-add -x` locks it again.

On unlock the agent looks up the `ssh_key` type id through `GET /api/v1/secret-types`, lists the user's secrets, keeps those of that type (and in `--folder`, if set), decrypts each `key` field, and parses it. A key that does not parse or is passphrase-protected is skipped and named on standard error. The public key is derived from the private key, so the encrypted additional fields need not be read. Each identity's comment is the secret name.

### D4: Supported signatures

Ed25519, ECDSA (P-256, P-384, P-521) and RSA with `rsa-sha2-256` and `rsa-sha2-512`. An RSA sign request without one of those flags (the SHA-1 `ssh-rsa` scheme) is refused.

### D5: Socket safety

The agent creates the socket directory with mode `0700` and the socket with `0600`, refuses to start if the directory exists with another owner or a wider mode, and refuses a connection whose peer uid differs from its own (`SO_PEERCRED` on Linux, `LOCAL_PEERCRED` on macOS). At start it disables core dumps (`RLIMIT_CORE` 0, and on Linux `PR_SET_DUMPABLE` 0).

### D6: Confirmation and idle lock

With `--confirm`, before each signature the agent runs the program in `SSH_ASKPASS` with `SSH_ASKPASS_PROMPT=confirm` and the key name, and signs only on exit status 0. If `SSH_ASKPASS` is unset, `--confirm` refuses to start rather than sign unconfirmed. With `--idle <minutes>` (default 60, 0 disables), the agent drops every decrypted key and the unwrapped suite key after that many minutes without a sign request, and answers as a locked agent until the next unlock.

### D7: The vault is the only source

Add-identity, remove-identity and remove-all requests answer with failure. `ssh-add -l` lists vault keys; `ssh-add some_key` fails with a message pointing to the vault.

## Security and zero-knowledge

The server's view does not change: the agent authenticates with the paired Nextcloud app password and fetches the suite envelope and secret ciphertext, exactly as `keepiq show` does. It never sends a master password, a derived key, a private key or a signature to the server (ADR-003).

In the agent process, while unlocked: the RSA suite private key and the parsed SSH private keys. This is inherent to any SSH agent. They are never written to disk, core dumps are disabled, and the idle lock and `ssh-add -x` drop them. The master password is used once to unwrap the suite key and then released.

Stored in plain text on disk: only the existing CLI pairing file (server URL, user, app password, mode `0600`). The socket carries sign requests from processes of the same user, which is the same trust boundary OpenSSH's own agent uses; D5 enforces it.

## Risks / Trade-offs

- **An unlocked agent signs for any process of the same user.** That is the SSH agent model. `--confirm` adds a per-use prompt for users who want it.
- **`ssh-add -X` sends the master password over the local socket.** The socket is owner-only and peer-checked; the alternative (a service manager that cannot prompt) would leave no way to unlock a background agent.
- **New dependencies add supply-chain surface.** Both are Go project modules, pinned in `go.sum`, and checked with `govulncheck` in CI.
- **Windows users wait.** Recorded as a non-goal with WSL as the workaround.

## Seed data

None. Keepiq owns its tables (ADR-001) and has no OpenRegister register. Go tests generate throwaway keys in the test process; no key is committed (gitleaks). The truncated seeded `ssh_key` in `SeedDevelopmentSecrets.php` stays as it is and is expected to be skipped by the agent.

## Migration

None: no table, no column, no `<version>` bump. The CLI release (`cli-v*` tag) carries the new subcommand.
