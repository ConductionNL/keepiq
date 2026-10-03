## 1. Module and resources

- [x] 1.1 Create the Go module `integrations/kubernetes/` on controller-runtime, importing `sdk/go/`; if `sdk/go/` does not exist yet, extract it from `cli/internal/client` and `cli/internal/crypto` first. Verify with `go vet ./...` and `go test ./...` in both modules. Done: module `integrations/kubernetes/` (controller-runtime v0.18.5, k8s 0.30) importing `sdk/go` through a `replace`; `sdk/go` came from #914. `go vet` and `go test` green in both.
- [x] 1.2 Define the `KeepiqConnection` and `KeepiqSecret` CRDs with validation (refresh minimum, field syntax). Verify with envtest tests that invalid resources are rejected. Done: `charts/keepiq-operator/crds/` with validation (refresh at least 10s via CEL, default 60s, field pattern, unique targetKey as a list map, restart kinds). `TestCRDValidation` (envtest, kube-apiserver 1.30) proves six invalid resources are rejected.

## 2. Reconcile

- [x] 2.1 Implement the reconcile loop: token cache, by-name fetch with ETag, fingerprint check, in-memory decrypt, target Secret with owner reference, requeue. Verify with envtest tests against an httptest stub serving envelopes from `sdk/testdata/`. Done: `internal/controller/keepiqsecret_controller.go`; envtest tests against `sdk/go/keepiqtest` (envelopes from `sdk/testdata/`): sync, owner reference, no rewrite when unchanged, cached token, recreate after delete.
- [x] 2.2 Set `Ready` conditions and events for 404, 409 with candidates, token refusal and fingerprint mismatch, never including a value. Verify with envtest tests that assert status and events contain no plaintext. Done: reasons SecretNotFound, AmbiguousName (event lists ids and folders), TokenRefused, FingerprintMismatch (needs the optional `certificateSecretRef`, see POLICY.md), each test asserts no plaintext in status, events or request bodies.
- [x] 2.3 Patch a checksum annotation on each restart target when a value changes. Verify with an envtest test that the Deployment template annotation changes once per rotation. Done: `TestRotationRestartsTheWorkloadOnce`.
- [x] 2.4 Renew leases before expiry and refetch after a refused renewal. Verify with envtest tests against a stub that advertises leases and one that does not. Done: `TestLeasesAreRenewedAndRefetchedAfterRefusal`, `TestWithoutLeasesNothingIsRenewed`.

## 3. Distribution

- [x] 3.1 Add the Helm chart with namespaced RBAC by default and a cluster-wide option. Verify with `helm lint` and `helm template` snapshot tests in CI. Done: `charts/keepiq-operator/`; `test/chart.sh` runs `helm lint` and two `helm template` snapshots (green locally with helm 3.15.4).
- [x] 3.2 Document the no-Secret recipe (init container with the CLI image, `keepiq ci run` wrapper) in the chart README and the docs site. Verify with a kind test that starts a pod with the recipe and reads the value from the process environment. Done: chart README and `docs/kubernetes.md`; the CLI gains `keepiq install <path>` for the distroless init container. Verified locally with docker (init container installs the CLI into a volume, `ci run` in busybox sees the value, not the key). `test/kind.sh` is the kind test. Owed: its first run in CI.
- [x] 3.3 Add `.github/workflows/integrations-kubernetes.yml`: tests on pull requests, kind test, signed multi-arch image and OCI chart on `k8s-v*` tags. Verify with a dry run of the workflow on a pull request. Done: `.github/workflows/integrations-kubernetes.yml`. Owed: the dry run on the pull request and the first `k8s-v*` release (signed image, OCI chart).
- [x] 3.4 Add a live test gated by `KEEPIQ_LIVE_URL` that syncs one secret from a real instance. Verify manually against the dev instance. Done: `TestLiveSyncFromARealInstance`, skipped without `KEEPIQ_LIVE_*`. Owed (live): run it against the dev instance with a registered application.

## Acceptance criteria

- A `KeepiqSecret` naming an application secret produces a Kubernetes Secret with the decrypted value within one refresh interval.
- Changing the value in Keepiq updates the Kubernetes Secret within one refresh interval and, when configured, restarts the named Deployment.
- No request from the operator to Keepiq carries plaintext, and no status, event or log line carries a value.
- A 409 for an ambiguous name leaves the target Secret unchanged and shows the candidates in an event.
- The recipe pod reads the value from its process environment and no Kubernetes Secret holds it.
- A tagged release publishes a signed image and a Helm chart.
