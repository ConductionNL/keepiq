---
kind: code
---

# Kubernetes secret injection

## Why

Teams that run workloads on Kubernetes have to script the Keepiq machine API themselves to get a credential into a pod. Every competitor that serves machine secrets ships a ready-made Kubernetes integration.

| Row | Capability | What keepiq does today |
|---|---|---|
| apps-17 | Inject secrets into a Kubernetes cluster | No Kubernetes secret injection (operator, CSI driver, sidecar) exists; keepiq's machine surface is a plain HTTP+JWT API a cluster could call itself, but nothing ships to do that integration. |

Matrix: keepiq `openspec/parity/capabilities.json`

### Demand

No demand row.

### Competitors rated yes

- Bitwarden: "bitwarden/clients@web-v2026.9.0 bitwarden_license/bit-web/src/app/secrets-manager/integrations/integrations.component.ts:94 Kubernetes Operator; bitwarden/server@v2026.9.1 src/Api/SecretsManager/Controllers/SecretsController.cs:308 secrets/sync used by the operator | docs: https://bitwarden.com/help/secrets-manager-kubernetes-operator/ ..."
- 1Password: "https://developer.1password.com/docs/k8s/integrations/ : Kubernetes Secrets Injector, Operator and Helm charts"
- Keeper: "https://docs.keeper.io/keeperpam/secrets-manager/integrations/kubernetes-external-secrets-operator : External Secrets Operator provider syncs Keeper secrets into Kubernetes Secrets (also a Secrets Injector)"
- HashiCorp Vault: "hashicorp/vault@v2.1.1 go.mod:160 vault-plugin-auth-kubernetes and :173 vault-plugin-secrets-kubernetes bundled; ui/app/router.js mounts the kubernetes engine UI | docs: https://developer.hashicorp.com/vault/docs/platform/k8s/vso ..."

## What Changes

- A Kubernetes operator in `integrations/kubernetes/`, released as a container image and a Helm chart from this repository.
- Two custom resources: `KeepiqConnection` (instance URL, application id, and a reference to a Kubernetes Secret holding the application private key) and `KeepiqSecret` (which Keepiq secrets, which fields, which target Kubernetes Secret, how often to refresh).
- The operator exchanges an RFC 7523 assertion for a bearer token, fetches each secret by name through the machine API, decrypts it in its own process with the application private key, and writes the target Kubernetes Secret.
- It polls with `If-None-Match`, so a rotated value reaches the cluster within one refresh interval, and it can restart named Deployments when a value changes.
- It reports status conditions and events on each `KeepiqSecret`, and honours machine leases.
- A documented recipe for pods that must not keep a Kubernetes Secret: an init container copies the static `keepiq` CLI into the pod, and the container starts through `keepiq ci run`, so the value lives only in the process environment.
- No change to the Keepiq server.

## Capabilities

### New Capabilities

- `kubernetes-integration`: a Kubernetes operator and an injection recipe that deliver Keepiq application secrets into pods, decrypting only inside the cluster.

### Modified Capabilities

None.

## Impact

- **Backend**: none. The operator uses the existing machine API (`/api/v1/token`, `/api/v1/app/secrets*`, `/api/v1/app/leases*`) and discovery document.
- **Frontend**: none.
- **Database**: none.
- **Security**: the Keepiq server keeps serving ciphertext only. Plaintext exists in the operator's memory and in the target Kubernetes Secret, inside the cluster the application owner controls. The application private key stays in the cluster.
- **Cross-app**: the operator imports the shared Go SDK in `sdk/go/` from change `apps-client-libraries-and-ci`; whichever change lands first extracts that module from `cli/internal/`.
