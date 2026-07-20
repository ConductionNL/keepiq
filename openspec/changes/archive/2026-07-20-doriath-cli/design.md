# Design — doriath-cli

## Context

Doriath is zero-knowledge end-to-end (ADR-003): the server stores and serves ciphertext only, and every decrypt happens on the client that holds the key. Two clients already exist — the Vue browser vault (humans; master-password-derived key unlocks the EncryptionSuite private key in JS memory) and any RFC 7523 machine consumer such as OpenConnector (`docs/integration-openconnector.md`; the application private key decrypts the `doriath-machine-secret-v1` envelope, `lib/Service/MachineSecretEnvelopeService.php:60`). This change adds a **third client — a shell binary** — that plays both roles, without adding any server surface. It is the missing DevOps front door named in `docs/FEATURES.md:273`/`:471`, validated by Proton Pass's Nov-2025 CI/CD CLI (`docs/FEATURES.md:496`).

The CLI is the decryption boundary. Everything that keeps the browser honest — app-password (not login password) auth, per-session master-password entry, client-side-only decryption, no plaintext at rest — carries into the binary unchanged.

## Goals / Non-Goals

**Goals:**

- A single self-contained binary, no runtime, installable in a CI base image or via `curl | install`.
- Human mode: NC app-password auth + local master-password unlock; `list`/`get`/`show`/`copy`; timeout-bounded session cache; all decryption in-process.
- CI mode: RFC 7523 JWT machine auth; lease-aware fetch/renew when leases are present; env / JSON / `run --` output; never plaintext to disk.
- Shell completion and a uniform `--output json`.

**Non-Goals:**

- No write/edit/delete in v1 (read-only vault access; write needs share fan-out).
- No new backend route, controller, or table.
- No plaintext value written to any file; no server-side storage of the master password or application private key.

## Architecture — binary layout

```
doriath (single static binary)
├── cmd/            command tree (cobra/clap-style)
│   ├── login       human: store instance URL + app-password (OS keyring)
│   ├── unlock      human: master-password → derive key → decrypt suite private key → cache session
│   ├── list        human: list secrets (metadata only; no decrypt)
│   ├── get         human: print one field of one secret (decrypt in-process)
│   ├── show        human: print full secret (decrypt in-process)
│   ├── copy        human: copy a field to the OS clipboard, auto-clear after N s
│   ├── logout      human: drop the cached session + clear keyring session entry
│   ├── run -- CMD  CI/human: exec CMD with secrets injected into its environment
│   └── completion  emit bash/zsh/fish completion script
├── auth/
│   ├── session.go  human: app-password + master-password unlock, session cache
│   └── machine.go  CI: RS256 assertion → token exchange → bearer (RFC 7523)
├── crypto/         WebCrypto-equivalent: master-KDF, AES-unwrap private key,
│                   rsa-oaep-sha256-chunked-v1 decrypt (mirrors the browser + the
│                   openconnector consumer recipe byte-for-byte)
├── api/            thin HTTP client: discovery, token, session + machine reads,
│                   ETag/updated_since, lease headers + renew
└── output/         env-export | json | table | run-exec renderers
```

## Auth models

**Human mode.** `login` stores `{instanceUrl, appPassword}` in the OS keyring (never the NC login password — an app-password is independently revocable from NC Security settings). `unlock` prompts for the master password, derives the key with the **same KDF the browser uses**, fetches the user's stored AES-wrapped EncryptionSuite private-key blob over the session-authenticated API, unwraps it **in-process**, and caches the resulting private key in a **session** — held in the OS keyring session slot or memory-only — with a configurable inactivity timeout (default 15 min) after which the next command re-prompts. Read commands fetch ciphertext envelopes and decrypt with the cached key locally. The server never sees the master password or any plaintext.

**CI mode.** No master password exists — an application's private key is the credential. The CLI signs an RS256 JWT assertion (`iss`=application id, `aud`=`doriath`, `exp` ≤ `iat`+300, unique `jti`), `POST`s it to the token endpoint for an opaque 5-minute bearer, fetches by name/id, and decrypts the envelope with the application private key. The private key is supplied by `--key-file`, an env var, or an OS keyring reference — **never** stored by Doriath and never embedded in a shareable config (the openconnector recipe's custody rule). Discovery (`GET /api/v1/app/.well-known/doriath`) is fetched and cached so the CLI self-configures its endpoints, alg, and lease policy.

## CI-mode lease awareness (additive; `machine-secret-leases`)

When the instance advertises leases in its discovery document (`lease: { supported: true, … }`), a CI-mode fetch reads the `Doriath-Lease-Id` / `Doriath-Lease-Expires` response headers, records the grant, and — for a long-running `doriath run` or a poll loop — **renews before expiry** via `POST /api/v1/app/leases/{leaseId}/renew`, respecting the policy max TTL. When leases are **absent** (older instance), the CLI simply omits lease handling and works exactly as today — lease-awareness is opt-in on the server's advertisement, never assumed.

## Output modes (CI + human)

- **env-export** — `export NAME='value'` lines to stdout for `eval "$(doriath …)"`. Values shell-quoted; a warning to stderr that the values are now in shell history/env.
- **`--output json`** — a structured object per read, for `jq` pipelines and completion.
- **`doriath run -- <cmd>`** — spawn `<cmd>` with the resolved secrets injected into **its** environment only; nothing is written to disk and the parent's env is untouched. This is the pipeline-safe path the `cicd-secret-fetch` journey wants ("nothing stored in the pipeline").

## Declarative-vs-imperative decision

Imperative, per **ADR-001** (`openspec/architecture/adr-001-own-database-tables.md`): Doriath owns its tables and does not use OpenRegister. The CLI is a *client* only — it introduces no register, schema, or seed data, and reads through Doriath's own session and machine endpoints into Doriath's own tables. There is nothing declarative to model.

## Decisions made under uncertainty

- **Language: Go (recommended) vs Rust.** Both produce a single static, cross-compiled binary. Decision: recommend **Go** for the mature cobra/keyring/clipboard ecosystem and simpler cross-compilation, with Rust as the alternative if a smaller binary / stronger memory-zeroing guarantee is prioritised. Recorded as a decision so the implementer commits explicitly; the spec is language-agnostic.
- **Master-key session storage.** Chosen: prefer the **OS keyring** (Keychain/Secret Service/Credential Manager) for the cached session with a memory-only fallback and a configurable timeout, rather than a plaintext session file. A file-on-disk session was rejected — it would put unlocked key material at rest, violating the browser-equivalent posture.
- **Read-only v1 (no writes).** Chosen: ship read-only. A human-mode write must re-wrap the value under every share recipient's public key (the browser's share fan-out) — a large surface that, done wrong, silently corrupts a shared secret. Deferred rather than half-built; CI-mode write-back (`POST/PUT /api/v1/app/secrets`, no fan-out) is the natural first follow-up.
- **App-password, not login password, for human auth.** Chosen: require a Nextcloud **app-password** so the credential is independently revocable and scoped, matching how NC's own CLI-style clients authenticate. The login password is never accepted.
- **Clipboard `copy` auto-clear.** Chosen: `copy` clears the OS clipboard after a bounded interval (default 45 s) to limit exposure, with the interval configurable; a no-clear mode is opt-in.
- **Lease-awareness is advertisement-gated.** Chosen: the CLI reads lease headers/renews **only** when discovery advertises lease support, so one binary works against lease-aware and lease-unaware instances with no flag. Assuming leases always exist would break against older servers.
- **`run --` over writing an env file.** Chosen: inject into a child process environment rather than emit a `.env` file, because the headline `cicd-secret-fetch` outcome is "nothing stored in the pipeline"; an env file on the runner disk defeats that.

## Risks / Trade-offs

- **Secrets in shell env/history via env-export.** → `env-export` warns on stderr; `doriath run --` is documented as the safer default (no shell exposure, no file).
- **KDF / decrypt drift from the browser.** → The CLI's crypto module must match the browser's KDF and the `rsa-oaep-sha256-chunked-v1` scheme (`lib/Service/MachineSecretEnvelopeService.php:60`) exactly; a fingerprint-mismatch fast-fail (the envelope's `certificateFingerprint`) catches a wrong-key decrypt before a bare exception, per the openconnector recipe.
- **Cached session on a shared machine.** → OS-keyring-scoped to the user + inactivity timeout + `logout`; documented not to run `unlock` on a shared/untrusted host.
- **Application private-key custody.** → The key is the operator's credential, supplied by file/env/keyring and never stored by Doriath; re-registration recovery works because `doriath://` references are name-based.

## Migration / Rollout

No server migration. The CLI ships as an independent binary + release pipeline, versioned separately from the app. It works against any instance exposing the existing session and machine APIs; lease-awareness activates automatically when the instance advertises it. `machine-secret-leases` is a soft dependency — the CLI degrades gracefully when it is absent.
