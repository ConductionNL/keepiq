# Design: Kubernetes secret injection

## Context

Read at development `4c214a9d`.

- The machine API is the only surface an operator needs: discovery at `/api/v1/app/.well-known/keepiq` (`appinfo/routes.php:295`), the RFC 7523 token exchange at `/api/v1/token` (`:299`), list, by-id and by-name reads at `/api/v1/app/secrets*` (`:304` to `:309`), and leases at `/api/v1/app/leases*` (`:319` to `:321`).
- Reads return the `doriath-machine-secret-v1` envelope with ciphertext only and a strong ETag; `If-None-Match` yields 304 (`lib/Service/MachineSecretResponseService.php:98` to `:101`). Lease id and expiry arrive as `Doriath-Lease-Id` and `Doriath-Lease-Expires` headers (`:176`, `:177`).
- `cli/internal/client/client.go:137` `Discover()`, `:151` `MachineToken()` and `:215` `FetchByName()` implement discovery, the assertion and the by-name read in stdlib Go. `cli/internal/crypto/crypto.go:139` `DecryptField()` decrypts the `rsa-oaep-sha256-chunked-v1` scheme. Both packages are `internal`, so Go forbids importing them from outside `cli/`.
- `cli/ci.go:97` `cmdCIRun()` injects fetched values into a child process environment only.
- `.github/workflows/cli-release.yml` builds the CLI for six platforms and attaches binaries to `cli-v*` releases. No container image is published.
- `grep -rli 'kubernetes|k8s|helm' lib src cli browser-extension` finds nothing.

## Goals / Non-Goals

**Goals:**

- A Kubernetes user gets a Keepiq application secret into a pod with one custom resource and no scripting.
- Decryption happens only inside the cluster, with an application key the cluster holds.
- A rotated value reaches the pod without a manual step.

**Non-Goals:**

- A mutating admission webhook that rewrites pods automatically. The recipe in D5 covers the no-Secret case by hand; a webhook can follow as its own change.
- A CSI driver.
- An External Secrets Operator provider. ESO providers live in the ESO repository and would need the RSA decryption there; that is an upstream contribution, not part of this repository.
- Reading user vaults. The operator is a machine client and sees only its application's vault.

## Decisions

### D1: An operator in this repository, on the shared Go SDK

The operator is a Go module at `integrations/kubernetes/` built with controller-runtime. It imports `sdk/go/` (discovery, token, reads, lease headers, decryption), extracted from `cli/internal/` by change `apps-client-libraries-and-ci`, so the CLI, the operator, the runner and the Terraform provider share one implementation of the crypto recipe.

Alternative considered: copy the client and crypto code into the operator. Rejected: the chunked RSA-OAEP recipe must stay byte-identical to the browser's (ADR-003, dual implementation); a second copy is a second place to break it.

### D2: Two custom resources

`KeepiqConnection` (namespaced) holds `url`, `applicationId` and `privateKeySecretRef` (name and key of a Kubernetes Secret with the application private key PEM). `KeepiqSecret` (namespaced) holds `connectionRef`, `target.name`, `refreshInterval` (default 60 seconds, minimum 10), optional `restartTargets` (Deployments or StatefulSets), and `items`: each item names a Keepiq secret (`name`, optional `folder`), a field (`key`, `login` or `additionalFields.<name>`) and the key in the target Secret.

One Keepiq application per namespace or team is the recommended layout, so a namespace can read only its own application vault.

### D3: Reconcile loop

Per `KeepiqSecret`: load the connection and key, get a bearer token (cached until expiry), fetch each item by name with the last ETag, decrypt changed envelopes in memory, check the envelope's certificate fingerprint against the key before decrypting, write the target Secret with an owner reference, patch a checksum annotation on each restart target when a value changed, record lease id and expiry, and requeue after `refreshInterval`. A 404 or a 409 (ambiguous name, with candidates) sets `Ready=False` with the reason and an event. Plaintext is never logged or put in status or events.

### D4: Leases

When discovery advertises leases, the operator fetches the item again before its lease expires; fetching again is the one renewal path, and there is no renew route (keepiq#753). A revoked lease is likewise a signal to fetch again on the next loop. Against an instance without leases it works unchanged.

### D5: A no-Secret recipe with the CLI

For pods that must not keep a value in etcd, the Helm chart documents a pod template: an init container from `ghcr.io/conductionnl/keepiq-cli` copies the static binary into an `emptyDir`, and the app container starts its original command through `/keepiq/keepiq ci run NAME1,NAME2`, which takes that command after its `--` separator. The value then exists only in the child process environment (`cli/ci.go:97`). The application key comes from a Kubernetes Secret mounted as a file (`KEEPIQ_APP_KEY_FILE`).

### D6: Release and test

`.github/workflows/integrations-kubernetes.yml` runs `go vet` and `go test` with envtest on pull requests touching `integrations/kubernetes/**`, and a kind cluster test against a stub Keepiq server that serves envelopes from the shared test vectors in `sdk/testdata/`. On a `k8s-v*` tag it builds a multi-arch image to `ghcr.io/conductionnl/keepiq-operator`, signs it with cosign keyless, and pushes the Helm chart as an OCI artifact to `ghcr.io/conductionnl/charts`. A live test against a real instance runs only when `KEEPIQ_LIVE_URL` is set, as the CLI's live test does.

## Security and zero-knowledge

- The Keepiq server never sees plaintext and never holds the application private key; nothing changes on the server.
- In the cluster: the application private key sits in a Kubernetes Secret; decrypted values sit in the target Kubernetes Secret (sync mode) or only in process memory (D5). The chart's documentation recommends etcd encryption at rest for sync mode.
- The operator's RBAC is namespaced by default: it reads `KeepiqConnection`, `KeepiqSecret` and the referenced key Secret, and writes only target Secrets it owns. A cluster-wide mode is an explicit chart value.
- Every fetch is audited on the Keepiq side as an application read, with lease id when leases are on.

## Risks / Trade-offs

- A Kubernetes Secret is readable by anyone with Secret read rights in the namespace. That is the cluster's access model, and D5 exists for workloads that need more.
- Polling every 60 seconds per `KeepiqSecret` adds load on large clusters. ETag reads are cheap (304, no body), and the interval is configurable.
- A new Go module with controller-runtime brings dependencies the stdlib-only CLI avoided. They stay in `integrations/kubernetes/go.mod` and never enter the CLI binary.

## Seed data

None in the app. The kind test registers its application against the stub server; the live test uses an application the operator of the test instance registers by hand.

## Migration

None. No server change, so no table, column or `<version>` bump.
