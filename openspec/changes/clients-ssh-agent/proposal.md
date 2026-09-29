---
kind: code
---

# An SSH agent in the Keepiq command-line client

## Why

Keepiq stores SSH keys as a secret type, but nothing hands them to `ssh` or `git`. A developer has to reveal the private key, write it to a file and load it into another agent, which defeats the point of keeping it in the vault. The three competitors that rate yes all run an agent that serves vault keys directly.

| Row | Capability | What Keepiq does today |
|---|---|---|
| clients-13 | Sign in over SSH with keys kept in the vault through an SSH agent. | SSH keys can be stored as secrets, but nothing exposes them to an SSH agent. |

Matrix: keepiq `openspec/parity/capabilities.json`

Not built. `ssh_key` exists as a seeded secret type (`lib/Repair/SeedSecretTypes.php:64`) and as a CXF mapping (`src/cxf/cxf.js:355`, private key in the `key` field, public key in the additional fields), but no code in `lib`, `src`, `cli` or `browser-extension` speaks the agent protocol. The decision places the agent in the existing Go CLI in `cli/`, which is a native binary already, so no desktop app is needed. The product records no native app (`openspec/specs/mobile-pwa/spec.md:11` and `:49`).

### Demand

No demand row.

### Competitors rated yes

- Bitwarden: "bitwarden/clients@web-v2026.9.0 apps/desktop/desktop_native/core/src/ssh_agent/mod.rs, named_pipe_listener_stream.rs (Windows), peercred_unix_listener_stream.rs (Unix); bitwarden/server@v2026.9.1 src/Core/Vault/Enums/CipherType.cs:11 SSHKey Note: Desktop app runs an SSH agent serving SSH key items, with per-use approval."
- 1Password: "https://developer.1password.com/docs/ssh/agent/ : SSH Agent uses keys saved in 1Password, private key 'never even leaves the 1Password app'"
- Keeper: "https://docs.keeper.io/keeperpam/privileged-access-manager/ssh-agent : 'Keeper's built-in SSH agent' serves SSH keys stored in the vault (documented under KeeperPAM)"

## What Changes

- A new subcommand `keepiq ssh-agent` runs an OpenSSH-compatible agent on a Unix socket on Linux and macOS.
- The agent unlocks the vault in its own process (master password at the terminal, or through `ssh-add -X` when started locked), decrypts the user's `ssh_key` secrets in memory, and answers identity-list and sign requests.
- Optional per-use confirmation through `SSH_ASKPASS` (`--confirm`), an idle lock that drops every decrypted key (`--idle`), and a folder filter (`--folder`).
- The agent refuses to add or remove keys: the vault is the only source, matching the CLI's read-only v1.
- The CLI takes its first dependencies, `golang.org/x/crypto` (SSH key parsing, signing and the agent protocol) and `golang.org/x/sys` (peer credential checks), and CI adds `govulncheck`.
- Documentation for running the agent as a systemd user service or a launchd agent.

## Capabilities

### New Capabilities

- `cli-ssh-agent`: an SSH agent in the Keepiq CLI that serves vault SSH keys with in-process decryption.

### Modified Capabilities

None. The `keepiq-cli` requirements stay as they are; this change adds a subcommand with its own capability.

## Impact

- **Backend**: none. The agent reads the same routes `keepiq list` and `keepiq show` read (`/api/v1/suites`, `/api/v1/secrets`, `/api/v1/secret-types`).
- **Frontend**: none.
- **Database**: none; no migration, no `<version>` bump.
- **Security**: decrypted SSH private keys exist only in the agent's memory while it is unlocked; the socket is owner-only; the server sees nothing it does not see for `keepiq show`.
- **Cross-app**: none.
- **Release**: `cli-release.yml` builds the new subcommand for Linux and macOS; on Windows the subcommand reports that it is not supported yet.
