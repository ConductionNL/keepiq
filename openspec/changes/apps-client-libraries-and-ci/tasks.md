## 1. Go library and vectors

- [ ] 1.1 Extract `cli/internal/client` and `cli/internal/crypto` into module `sdk/go/`, move the CLI onto it, and keep both stdlib only. Verify with `go vet ./...` and `go test ./...` in `sdk/go/` and `cli/`.
- [ ] 1.2 Replace the envelope struct with the server's shape (`encryption.scheme`, `ciphertext.*`) and fix the CLI's scheme check. Verify with a Go test against an envelope written by `MachineSecretEnvelopeService::serialize()`, and manually with `keepiq ci fetch` against the dev instance.
- [ ] 1.3 Create `sdk/testdata/` with vectors from the PHP serializer (fixture generator in `tests/Unit/`) and the moved browser vector; allow-list the test key by path for secret scanning. Verify with the generator test and a gitleaks run.
- [ ] 1.4 Add encrypt, list with `updatedSince`, by-id, create, update, typed errors and lease reporting to `sdk/go/`. Verify with Go tests against an httptest stub and a PHPUnit test that decrypts Go-encrypted vectors with `DecryptService`.

## 2. Python and TypeScript

- [ ] 2.1 Build `sdk/python/` with the D2 surface on `cryptography`. Verify with `pytest` on the shared vectors and the PHPUnit round trip for Python-encrypted vectors.
- [ ] 2.2 Build `sdk/js/` in TypeScript on WebCrypto with no runtime dependency. Verify with vitest on the shared vectors in Node 20 and the PHPUnit round trip for TypeScript-encrypted vectors.
- [ ] 2.3 Add release workflows for `sdk/go/v*`, `sdk-py-v*` (PyPI trusted publishing) and `sdk-js-v*` (npm with provenance). Verify with a dry run of each workflow on a pull request.

## 3. CI integrations

- [ ] 3.1 Add `SHA256SUMS` and the `ghcr.io/conductionnl/keepiq-cli` image to `cli-release.yml`. Verify with a workflow dry run and `docker run ghcr.io/conductionnl/keepiq-cli --version`.
- [ ] 3.2 Add `integrations/github-action/action.yml` with the `run` and `export-env` modes, checksum check and masking. Verify with a workflow that runs the action against a stub server and asserts the value is masked in the log.
- [ ] 3.3 Add `integrations/gitlab-ci/keepiq.gitlab-ci.yml` with the `.keepiq` hidden job. Verify with `gitlab-ci-local` or a GitLab lint call in the same workflow.
- [ ] 3.4 Document the libraries, the action and the template on the docs site. Verify with the docs build in `docs/`.

## Acceptance criteria

- A Python script with an application id and private key reads a secret by name in under ten lines, and the value never crosses the network in plain form.
- Every library and the CLI decrypt the shared vectors, and `DecryptService` decrypts what each library encrypts.
- `keepiq ci fetch` decrypts an envelope from a real Keepiq instance.
- A GitHub workflow step using the action runs a command with a Keepiq secret in its environment and nothing is written to disk.
- With `export-env: true`, later steps see the value and the log shows it masked.
- A GitLab job extending `.keepiq` runs its command with a Keepiq secret in its environment.
