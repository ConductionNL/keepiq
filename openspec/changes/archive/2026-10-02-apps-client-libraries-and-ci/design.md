# Design: client libraries and CI integrations

## Context

Read at development `4c214a9d`.

- `cli/go.mod` is the module `github.com/ConductionNL/keepiq/cli`, Go 1.22, stdlib only.
- `cli/internal/client/client.go:137` `Discover()`, `:151` `MachineToken()` and `:215` `FetchByName()` implement discovery, the assertion and the by-name read; `cli/internal/crypto/crypto.go:139` `DecryptField()` and `:199` `SignRS256()` implement the crypto. They are `internal` and cannot be imported from outside `cli/`. There is no encrypt function: the CLI is read-only by design (`openspec/specs/keepiq-cli/spec.md`, "Read-only vault access in v1").
- `cli/internal/client/client.go:201` `MachineEnvelope` expects a top-level `scheme` and `payload.value`, and `cli/ci.go:65` refuses any other scheme. The server sends `encryption.scheme` and `ciphertext.key`, `ciphertext.login`, `ciphertext.additionalFields` (`lib/Service/MachineSecretEnvelopeService.php:129` to `:150`). The CLI's unit test fakes the shape the CLI expects (`cli/internal/client/client_test.go:28`), so it cannot see the difference; only the token exchange has a live probe (`cli/internal/client/live_token_test.go`).
- `cli/internal/crypto/testdata/webcrypto_envelope.json` holds a browser-produced vector (wrapped key, field, plaintext).
- `lib/Service/DecryptService.php` and `lib/Service/EncryptService.php` are the stateless PHP crypto (ADR-003); `openspec/config.yaml` requires cross-implementation round-trip tests.
- `.github/workflows/cli-release.yml` builds six binaries and attaches them to `cli-v*` releases, with no checksum file and no container image.
- `cli/ci.go:97` `cmdCIRun()` injects values into a child process environment only; the CLI spec forbids writing decrypted values to a file.

## Goals / Non-Goals

**Goals:**

- A developer in Go, Python or TypeScript reads and writes their application's secrets in a few lines, with decryption in their own process.
- One recipe, one set of vectors, byte-identical across PHP, the browser, Go, Python and TypeScript.
- A pipeline gets secrets from Keepiq with a single step and no plaintext in the pipeline configuration.

**Non-Goals:**

- Libraries for Java, .NET, Ruby, Rust or PHP outside Nextcloud. They can follow on the same vectors.
- User vault access in the libraries. Human access stays in the browser and the CLI's human mode.
- OIDC federation for CI (trusting GitHub or GitLab tokens instead of an application key). It would replace authentication only; decryption still needs the application private key, so the pipeline would hold that key anyway.
- A GitHub Marketplace listing, which requires a dedicated repository with `action.yml` at its root.

## Decisions

### D1: Libraries live in `sdk/`, one directory per language

`sdk/go/` becomes module `github.com/ConductionNL/keepiq/sdk/go`, released with tags `sdk/go/vX.Y.Z`, stdlib only, so the CLI stays dependency free. `sdk/python/` is a `pyproject.toml` package depending only on `cryptography`. `sdk/js/` is a TypeScript package with no runtime dependency, using WebCrypto (`globalThis.crypto.subtle`, Node 20 and later, browsers). Package names are reserved at first release; the working names are `keepiq-sdk` on PyPI and `@conduction/keepiq-sdk` on npm.

Alternative considered: one Rust core with bindings, as Bitwarden does. Rejected: three small native implementations of one documented recipe are easier to audit than a native build chain in every consumer.

### D2: One surface in every language

`Client(url, applicationId, privateKeyPem)` with: `getByName(name, folder?)`, `getById(id)`, `list(updatedSince?)`, `create(fields)`, `update(id, fields)`. Reads return decrypted fields plus metadata and the lease; writes encrypt with the public half of the caller's own key after checking it against the envelope fingerprint. Errors are typed: not found, ambiguous (with candidates), unauthorized, not modified. Tokens are cached until expiry; ETags are sent with `If-None-Match`.

### D3: Parse the envelope the server sends, and prove it

The Go extraction replaces the CLI's `MachineEnvelope` with the server's shape (`format`, `secret`, `encryption`, `ciphertext`). The conformance vectors in `sdk/testdata/` are produced from the real serializer: a PHPUnit fixture generator writes an envelope for a test application key, and the browser vector is moved from `cli/internal/crypto/testdata/`. Every library and the CLI decrypt the same vectors; a PHPUnit test decrypts vectors that each library encrypted, through `DecryptService`. The test key is a throwaway, labelled test-only and allow-listed by path for secret scanning.

### D4: The GitHub Action runs a command by default, exports only on request

`integrations/github-action/action.yml` is a composite action with inputs `url`, `application-id`, `private-key` (from a GitHub secret, passed as `KEEPIQ_APP_KEY`), `secrets` (names, one per line, optional `NAME=ENV_VAR`), `run` and `export-env` (default false). It downloads the CLI for the runner's OS and architecture from the matching `cli-v*` release and checks it against the release checksums. With `run`, it executes `keepiq ci run` around that command, so values never touch disk. With `export-env: true`, it masks each value with `::add-mask::` (per line for multi-line values) and appends it to `$GITHUB_ENV`; the input's description says this writes the value to the runner's environment file. With neither, the step fails with a message naming both options.

Alternative considered: export by default, as some competitor actions do. Rejected: the CLI spec promises no plaintext on disk; export stays a deliberate choice.

### D5: A GitLab CI template included by URL

`integrations/gitlab-ci/keepiq.gitlab-ci.yml` defines a hidden job `.keepiq` whose `before_script` installs the checked CLI. A job `extends: .keepiq` and wraps its command with `keepiq ci run`. GitLab cannot mask values fetched at run time, so the template only offers the wrapped form. Projects include it with `include: remote:` pointing at the file on a `cli-v*` tag. A CI/CD Catalog component needs its own GitLab project and is left for later.

### D6: Release and test per directory

`cli-release.yml` adds `SHA256SUMS` to each `cli-v*` release and pushes `ghcr.io/conductionnl/keepiq-cli` (static binary on a distroless base). New workflows test and release each library on its own tag prefix (`sdk/go/v*`, `sdk-py-v*`, `sdk-js-v*`): Python through PyPI trusted publishing, TypeScript through npm with provenance. The action and template are tested by a workflow that runs them against a stub Keepiq server serving the vectors.

## Security and zero-knowledge

- The server never sees plaintext: libraries send ciphertext on write and receive ciphertext on read. No server change.
- Plain on the caller's side: decrypted values in process memory (and, with `export-env`, in the runner's environment file). Encrypted: everything that crosses the network.
- The application private key is supplied by the caller and never sent; only a signed assertion leaves the process.
- The action verifies the CLI binary's checksum before running it, so a tampered download is refused.

## Risks / Trade-offs

- Three libraries triple the maintenance of the recipe. The shared vectors make any drift fail CI in the language that drifted.
- Fixing the CLI's envelope parser changes CLI behaviour. Today the parser cannot read a server envelope, so no working pipeline depends on the old shape.
- The action exists only as a path in this repository, so it is not discoverable in the Marketplace.

## Seed data

None in the app. The PHPUnit fixture generator creates a throwaway application key and envelope for the vectors; nothing is written to a dev database.

## Migration

None. No server change, so no table, column or `<version>` bump.
