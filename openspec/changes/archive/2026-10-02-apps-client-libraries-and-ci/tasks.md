## 1. Go library and vectors

- [x] 1.1 Extract `cli/internal/client` and `cli/internal/crypto` into module `sdk/go/`, move the CLI onto it, and keep both stdlib only. Verify with `go vet ./...` and `go test ./...` in `sdk/go/` and `cli/`. Done: `sdk/go/{client,crypto}` (moved from `cli/internal/`), module `github.com/ConductionNL/keepiq/sdk/go`; the CLI requires it through a `replace` to `../sdk/go`. `go vet` and `go test` green in both (golang:1.22).
- [x] 1.2 Replace the envelope struct with the server's shape (`encryption.scheme`, `ciphertext.*`) and fix the CLI's scheme check. Verify with a Go test against an envelope written by `MachineSecretEnvelopeService::serialize()`, and manually with `keepiq ci fetch` against the dev instance. Done on development by 2cfb1e89 (#807); `cli/ci_test.go` decrypts the serializer envelope, and the CI mode now runs on `keepiq.Client`. Owed (live): `keepiq ci fetch` against the dev instance.
- [x] 1.3 Create `sdk/testdata/` with vectors from the PHP serializer (fixture generator in `tests/Unit/`) and the moved browser vector; allow-list the test key by path for secret scanning. Verify with the generator test and a gitleaks run. Done: `sdk/testdata/` holds the serializer envelope (generator `tests/Unit/Service/MachineEnvelopeCliFixtureTest.php`), the moved browser vector, `vector_plaintexts.json` and `stub_server.py`; `.gitleaks.toml` allow-lists `^sdk/testdata/`. Owed: a gitleaks run (not installed here).
- [x] 1.4 Add encrypt, list with `updatedSince`, by-id, create, update, typed errors and lease reporting to `sdk/go/`. Verify with Go tests against an httptest stub and a PHPUnit test that decrypts Go-encrypted vectors with `DecryptService`. Done: `sdk/go/keepiq.go` with `keepiq_test.go` against an httptest stub; `tests/Unit/Service/SdkEncryptedVectorsTest.php` decrypts `encrypted_by_go.json` with DecryptService.

## 2. Python and TypeScript

- [x] 2.1 Build `sdk/python/` with the D2 surface on `cryptography`. Verify with `pytest` on the shared vectors and the PHPUnit round trip for Python-encrypted vectors. Done: `sdk/python/` (unittest-style tests that pytest runs, 14 green); `encrypted_by_python.json` decrypted by DecryptService.
- [x] 2.2 Build `sdk/js/` in TypeScript on WebCrypto with no runtime dependency. Verify with vitest on the shared vectors in Node 20 and the PHPUnit round trip for TypeScript-encrypted vectors. Done: `sdk/js/` (vitest, 15 green on Node 22, `tsc --noEmit` clean); `encrypted_by_js.json` decrypted by DecryptService.
- [x] 2.3 Add release workflows for `sdk/go/v*`, `sdk-py-v*` (PyPI trusted publishing) and `sdk-js-v*` (npm with provenance). Verify with a dry run of each workflow on a pull request. Done: `.github/workflows/sdk.yml`. Owed: the dry run on the pull request, and the first real publish (PyPI trusted publisher and npm token need setting up by a maintainer).

## 3. CI integrations

- [x] 3.1 Add `SHA256SUMS` and the `ghcr.io/conductionnl/keepiq-cli` image to `cli-release.yml`. Verify with a workflow dry run and `docker run ghcr.io/conductionnl/keepiq-cli --version`. Done: `cli-release.yml` `checksums` and `image` jobs, `cli/Dockerfile`; the image was built locally from a linux binary and `--version` answered. Owed: the workflow dry run and the first push to ghcr.io on a `cli-v*` tag.
- [x] 3.2 Add `integrations/github-action/action.yml` with the `run` and `export-env` modes, checksum check and masking. Verify with a workflow that runs the action against a stub server and asserts the value is masked in the log. Done: `integrations/github-action/`; `integrations/test/run.sh` passes 7 action checks locally (run mode, no file holds the value, mapping, masked export, both options named, tampered binary refused, missing version). `.github/workflows/integrations.yml` also runs it as a real action. Owed: reading the masked value in that run's log.
- [x] 3.3 Add `integrations/gitlab-ci/keepiq.gitlab-ci.yml` with the `.keepiq` hidden job. Verify with `gitlab-ci-local` or a GitLab lint call in the same workflow. Done: `integrations/gitlab-ci/keepiq.gitlab-ci.yml`; `run.sh` runs its `.keepiq` before_script and a wrapped job (2 checks). Owed: a GitLab CI lint call (needs a GitLab token).
- [x] 3.4 Document the libraries, the action and the template on the docs site. Verify with the docs build in `docs/`. Done: `docs/client-libraries.md`, `docs/ci-integrations.md`, `sdk/README.md`. Owed: the docs build (runs in documentation.yml; docs/node_modules not installed here).

## Acceptance criteria

- A Python script with an application id and private key reads a secret by name in under ten lines, and the value never crosses the network in plain form.
- Every library and the CLI decrypt the shared vectors, and `DecryptService` decrypts what each library encrypts.
- `keepiq ci fetch` decrypts an envelope from a real Keepiq instance.
- A GitHub workflow step using the action runs a command with a Keepiq secret in its environment and nothing is written to disk.
- With `export-env: true`, later steps see the value and the log shows it masked.
- A GitLab job extending `.keepiq` runs its command with a Keepiq secret in its environment.
