---
kind: code
---

# Client libraries and CI integrations

## Why

A Python service, a Node job or a CI pipeline that needs a Keepiq application secret has to implement the RFC 7523 assertion, the envelope and the chunked RSA decryption itself. There is one Go CLI and nothing else.

| Row | Capability | What keepiq does today |
|---|---|---|
| apps-18 | Use a ready-made GitHub Actions or GitLab CI integration | No ready-made GitHub Actions or GitLab CI step exists; a pipeline would have to install and script the keepiq CLI itself. |
| apps-21 | Use client libraries for common programming languages | There is one cross-compiled CLI binary, not per-language client libraries; a Python or Node consumer would call the documented HTTP+JWT API directly with no official SDK. |

Matrix: keepiq `openspec/parity/capabilities.json`

### Demand

No demand row.

### Competitors rated yes

- Bitwarden (apps-18): "bitwarden/clients@web-v2026.9.0 bitwarden_license/bit-web/src/app/secrets-manager/integrations/integrations.component.ts:25 GitHub Actions, :32 GitLab CI/CD, :39 Ansible | docs: https://bitwarden.com/help/github-actions-integration/ ..."
- 1Password (apps-18): "https://developer.1password.com/docs/ci-cd/github-actions/ : load secrets into GitHub Actions with secret references"
- Keeper (apps-18): "https://docs.keeper.io/keeperpam/secrets-manager/integrations/github-actions : Keeper Secrets Manager GitHub Action; GitLab integration at https://docs.keeper.io/keeperpam/secrets-manager/integrations/gitlab-plugin"
- HashiCorp Vault (apps-18): "hashicorp/vault@v2.1.1 go.mod:158 vault-plugin-auth-jwt bundled for GitHub and GitLab OIDC tokens | docs: https://developer.hashicorp.com/vault/docs/platform/github-actions (hashicorp/vault-action, separate repo) ..."
- Bitwarden (apps-21): "bitwarden/clients@web-v2026.9.0 bitwarden_license/bit-web/src/app/secrets-manager/integrations/integrations.component.ts:45 C#, :51 C++, :57 Go, :63 Java, :70 JS WebAssembly, :76 php, :82 Python, :88 Ruby, :18 Rust (bitwarden/sdk-sm) | docs: https://bitwarden.com/help/secrets-manager-sdk/ ..."
- 1Password (apps-21): "https://developer.1password.com/docs/sdks/ : SDKs for Go, JavaScript and Python"
- Keeper (apps-21): "https://docs.keeper.io/keeperpam/secrets-manager/developer-sdk-library : Python, Java/Kotlin, JavaScript, .NET, Go, Ruby, Rust, PowerShell SDKs"
- HashiCorp Vault (apps-21): "hashicorp/vault@v2.1.1 api/auth.go official Go client package api/ in-tree | docs: https://developer.hashicorp.com/vault/api-docs/libraries ..."

### Missing half

apps-21 is partial. Built: the Go command-line client in `cli/`. Missing: client libraries for common languages.

## What Changes

- Three client libraries for the machine API: Go (`sdk/go/`, extracted from `cli/internal/`), Python (`sdk/python/`) and TypeScript for Node and browsers (`sdk/js/`).
- Each library discovers the instance, signs the RFC 7523 assertion, caches the token, reads by name, id and list, decrypts locally, writes back values encrypted to the application's own key, honours ETags and leases, and reports the 409 candidates.
- One set of conformance vectors in `sdk/testdata/`, produced by the PHP serializer and the browser crypto, that every library and the CLI must pass.
- The CLI moves onto the Go library. Its envelope parser follows the envelope the server actually sends.
- A GitHub Action in `integrations/github-action/`, used as `ConductionNL/keepiq/integrations/github-action@<tag>`. It runs a command with secrets in its environment, or, when the workflow opts in, exports masked values to later steps.
- A GitLab CI template in `integrations/gitlab-ci/`, included by URL, that installs the CLI and wraps a job's command with `keepiq ci run`.
- The CLI release publishes checksums and a container image `ghcr.io/conductionnl/keepiq-cli`.
- No change to the Keepiq server.

## Capabilities

### New Capabilities

- `client-libraries`: official Go, Python and TypeScript libraries for the Keepiq machine API, decrypting only in the calling process.
- `ci-integrations`: a GitHub Action and a GitLab CI template that bring Keepiq application secrets into pipelines.

### Modified Capabilities

None.

## Impact

- **Backend**: none. A PHPUnit test decrypts library-produced vectors with `DecryptService` to prove the round trip.
- **Frontend**: none.
- **Database**: none.
- **Security**: plaintext exists only in the calling process or the pipeline step. The application private key never leaves the caller. The GitHub Action masks every value before any later step can print it.
- **Cross-app**: the Kubernetes operator, the rotation runner and the Terraform provider build on `sdk/go/`.
