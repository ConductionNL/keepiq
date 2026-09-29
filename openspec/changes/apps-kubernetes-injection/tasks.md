## 1. Module and resources

- [ ] 1.1 Create the Go module `integrations/kubernetes/` on controller-runtime, importing `sdk/go/`; if `sdk/go/` does not exist yet, extract it from `cli/internal/client` and `cli/internal/crypto` first. Verify with `go vet ./...` and `go test ./...` in both modules.
- [ ] 1.2 Define the `KeepiqConnection` and `KeepiqSecret` CRDs with validation (refresh minimum, field syntax). Verify with envtest tests that invalid resources are rejected.

## 2. Reconcile

- [ ] 2.1 Implement the reconcile loop: token cache, by-name fetch with ETag, fingerprint check, in-memory decrypt, target Secret with owner reference, requeue. Verify with envtest tests against an httptest stub serving envelopes from `sdk/testdata/`.
- [ ] 2.2 Set `Ready` conditions and events for 404, 409 with candidates, token refusal and fingerprint mismatch, never including a value. Verify with envtest tests that assert status and events contain no plaintext.
- [ ] 2.3 Patch a checksum annotation on each restart target when a value changes. Verify with an envtest test that the Deployment template annotation changes once per rotation.
- [ ] 2.4 Renew leases before expiry and refetch after a refused renewal. Verify with envtest tests against a stub that advertises leases and one that does not.

## 3. Distribution

- [ ] 3.1 Add the Helm chart with namespaced RBAC by default and a cluster-wide option. Verify with `helm lint` and `helm template` snapshot tests in CI.
- [ ] 3.2 Document the no-Secret recipe (init container with the CLI image, `keepiq ci run` wrapper) in the chart README and the docs site. Verify with a kind test that starts a pod with the recipe and reads the value from the process environment.
- [ ] 3.3 Add `.github/workflows/integrations-kubernetes.yml`: tests on pull requests, kind test, signed multi-arch image and OCI chart on `k8s-v*` tags. Verify with a dry run of the workflow on a pull request.
- [ ] 3.4 Add a live test gated by `KEEPIQ_LIVE_URL` that syncs one secret from a real instance. Verify manually against the dev instance.

## Acceptance criteria

- A `KeepiqSecret` naming an application secret produces a Kubernetes Secret with the decrypted value within one refresh interval.
- Changing the value in Keepiq updates the Kubernetes Secret within one refresh interval and, when configured, restarts the named Deployment.
- No request from the operator to Keepiq carries plaintext, and no status, event or log line carries a value.
- A 409 for an ambiguous name leaves the target Secret unchanged and shows the candidates in an event.
- The recipe pod reads the value from its process environment and no Kubernetes Secret holds it.
- A tagged release publishes a signed image and a Helm chart.
