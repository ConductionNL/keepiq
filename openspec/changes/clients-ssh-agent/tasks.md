# Tasks: SSH agent in the Keepiq CLI

## 1. Dependencies and plumbing

- [ ] 1.1 (blocked, POLICY.md keepiq#786) Dependencies, workflow and wording are in; govulncheck fails on Go 1.22 because the x/crypto versions without reachable advisories need Go 1.25. Add `golang.org/x/crypto` and `golang.org/x/sys` to `cli/go.mod`, add `govulncheck ./...` to the test job in `cli-release.yml`, and update the stdlib-only wording in `cli/README.md` and the workflow comment. Verify: `go vet ./...`, `go test ./...` and `govulncheck ./...` pass in the workflow.
- [x] 1.2 Add `SecretTypes()` to `cli/internal/client/client.go` (`GET /api/v1/secret-types`) and a helper that returns the `ssh_key` type id. Verify: a Go unit test with an `httptest` server. Done: `cli/internal/client/secret_types_test.go`.

## 2. Agent core

- [x] 2.1 Add `cli/sshagent/` with a keyring that loads the user's `ssh_key` secrets (optionally one folder), decrypts and parses each key, skips and names passphrase-protected or unparsable ones, and derives the public keys. Verify: a Go test with generated Ed25519, ECDSA and RSA keys plus one passphrase-protected key. Done: `cli/sshagent/keyring.go`; `TestBuildKeyringKeepsUsableKeysAndNamesTheSkippedOnes`.
- [x] 2.2 Implement `agent.ExtendedAgent`: list, sign (Ed25519, ECDSA, RSA with SHA-2 flags only), lock and unlock (master password), and failure for add and remove. Verify: a Go test drives it through `agent.NewClient` over `net.Pipe`, verifies each signature, and asserts the SHA-1 RSA refusal and the add refusal. Done: `cli/sshagent/agent.go`; tests over `net.Pipe` in `cli/sshagent/agent_test.go`.
- [x] 2.3 Add the idle lock (D6) that drops every decrypted key and the suite key. Verify: a Go test with an injected clock asserts a locked answer after the idle period. Done: `TestIdleLockDropsKeys`.
- [x] 2.4 Add `--confirm` through `SSH_ASKPASS` with `SSH_ASKPASS_PROMPT=confirm`, refusing to start without `SSH_ASKPASS`. Verify: a Go test with stub askpass scripts exiting 0 and 1. Done: `TestConfirmAllowsOnlyOnExitZero`, `TestConfirmNeedsAskpass`.

## 3. Socket and command

- [x] 3.1 Add the socket listener with directory `0700`, socket `0600`, the owner and mode check, and the peer uid check on Linux and macOS; disable core dumps at start. Verify: Go tests for a wrong directory mode and a foreign peer uid (Linux test in CI). Done: `cli/sshagent/socket.go`, `peer_linux.go`, `peer_darwin.go`; `cli/sshagent/socket_test.go`.
- [x] 3.2 Add the `ssh-agent` subcommand (`--socket`, `--confirm`, `--idle`, `--folder`, `--locked`), the `SSH_AUTH_SOCK` output, usage and completion entries, and a "not supported on Windows yet" message behind a build tag. Verify: a Go test for flag parsing; `GOOS=windows go build` succeeds. Done: `cli/sshagent_cmd.go`, `cli/sshagent_flags.go`, `cli/sshagent_cmd_windows.go`; `TestParseAgentFlags`, `TestVaultUnlockerReadsOnlyCiphertext`; `GOOS=windows go build` and `GOOS=darwin go vet` pass.
- [x] 3.3 Add an integration test that starts a throwaway `sshd` on localhost with a generated key in CI, loads the key into a test vault double, and runs `ssh -o IdentityAgent=<socket>` to it. Verify: the test passes in the CLI workflow on Linux. Done: `cli/sshagent/integration_test.go` (real sshd and ssh on localhost; passed in a Debian container with OpenSSH; the workflow installs OpenSSH).

## 4. Documentation

- [ ] 4.1 (written; manual review and a manual `git clone` on macOS and Linux owed) Document the agent in `cli/README.md`: start, `eval`, `ssh-add -X` unlock, `--confirm`, `--idle`, a systemd user unit and a launchd plist, and the Windows status. Verify: manual review with the writing skill, and a manual `git clone` over SSH on macOS and Linux using the agent.

## Acceptance criteria

- `keepiq ssh-agent` serves the user's vault SSH keys to `ssh` and `git` on Linux and macOS through `SSH_AUTH_SOCK`.
- Keys are decrypted only in the agent process; nothing decrypted is written to disk and the server receives no key material.
- The socket is reachable only by the same user; a foreign peer is refused.
- `--confirm` asks before each signature and fails closed; `--idle` drops all decrypted keys after the idle period.
- `ssh-add` cannot add or remove keys through the agent.
