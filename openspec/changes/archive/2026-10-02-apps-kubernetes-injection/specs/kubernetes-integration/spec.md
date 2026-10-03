## ADDED Requirements

### Requirement: Operator syncs application secrets into Kubernetes Secrets

The Keepiq Kubernetes operator MUST, for each `KeepiqSecret` resource, authenticate as the Keepiq application named by its `KeepiqConnection` through the RFC 7523 token exchange, fetch each listed secret by name through `GET /api/v1/app/secrets/by-name/{name}`, decrypt the envelope inside the operator process with the application private key, and write the chosen fields into the target Kubernetes Secret. The Keepiq server MUST receive no plaintext and MUST NOT be changed for this.

#### Scenario: Platform engineer syncs a database password

- **GIVEN** a `KeepiqConnection` for application `shop-prod` with its private key in Kubernetes Secret `keepiq-app-key`, and a Keepiq secret `db-password` in the `shop-prod` vault
- **WHEN** a platform engineer applies a `KeepiqSecret` that maps field `key` of `db-password` to key `DB_PASSWORD` of target Secret `shop-db`
- **THEN** Kubernetes Secret `shop-db` MUST contain `DB_PASSWORD` with the decrypted value
- **AND** the `KeepiqSecret` MUST report condition `Ready=True`

### Requirement: The application key stays in the cluster

The operator MUST read the application private key only from the Kubernetes Secret named in `KeepiqConnection.privateKeySecretRef`, MUST NOT send it to any endpoint, and MUST check each envelope's certificate fingerprint against that key before decrypting. The operator MUST NOT write any decrypted value to its logs, resource status or events.

#### Scenario: Wrong key is reported, not used

- **GIVEN** a `KeepiqConnection` whose key Secret holds a key that does not match the application's certificate
- **WHEN** the operator reconciles a `KeepiqSecret` on that connection
- **THEN** the `KeepiqSecret` MUST report `Ready=False` with reason `FingerprintMismatch`
- **AND** the target Secret MUST NOT change

### Requirement: Rotated values reach the cluster

The operator MUST poll each item with `If-None-Match` at the resource's `refreshInterval` (default 60 seconds, minimum 10 seconds). When a value changed, it MUST update the target Secret and MUST patch a checksum annotation on each listed restart target so the workload restarts. When nothing changed, it MUST NOT write the target Secret.

#### Scenario: Rotation restarts the workload

- **GIVEN** a `KeepiqSecret` for `db-password` with restart target Deployment `shop-api`
- **WHEN** a machine client writes a new value for `db-password` through `PUT /api/v1/app/secrets/{id}`
- **THEN** Kubernetes Secret `shop-db` MUST hold the new value within one refresh interval
- **AND** Deployment `shop-api` MUST roll out new pods

### Requirement: Errors are visible on the resource

The operator MUST report `Ready=False` with a reason and an event for an unknown name (404), an ambiguous name (409, listing the candidates' ids and folder paths), a refused token and a fingerprint mismatch, and MUST leave the target Secret unchanged in each case.

#### Scenario: Ambiguous name

- **GIVEN** two secrets named `api-token` in the application vault
- **WHEN** a `KeepiqSecret` asks for `api-token` without a folder
- **THEN** the resource MUST report `Ready=False` with reason `AmbiguousName`
- **AND** an event MUST list both candidates' ids and folder paths

### Requirement: Leases are honoured when advertised

When the discovery document advertises lease support, the operator MUST record the `Doriath-Lease-Id` and `Doriath-Lease-Expires` headers in the resource status, MUST renew the lease through `POST /api/v1/app/leases/{id}/renew` before it expires, and MUST refetch after a refused renewal. Against an instance without lease support it MUST work unchanged.

#### Scenario: Lease is renewed before expiry

- **GIVEN** an instance that advertises leases and a lease that expires in two minutes
- **WHEN** the operator's next loop runs
- **THEN** the operator MUST renew the lease and record the new expiry in status

### Requirement: Pods can receive values without a Kubernetes Secret

The Helm chart MUST document a pod recipe in which an init container copies the static `keepiq` CLI from the published CLI image into the pod, and the container starts its original command through `keepiq ci run <names>`, which takes that command after its `--` separator, so values exist only in the process environment.

#### Scenario: Recipe pod reads its password from the environment

- **GIVEN** a pod built from the documented recipe for secret `db-password`
- **WHEN** the pod starts
- **THEN** the main process MUST see `KEEPIQ_DB_PASSWORD` in its environment
- **AND** no Kubernetes Secret in the namespace MUST contain the value

### Requirement: The operator is released from this repository

A tag `k8s-v<version>` MUST publish a signed multi-arch container image and a Helm chart built from `integrations/kubernetes/`. Pull requests touching that directory MUST run its unit, envtest and kind tests.

#### Scenario: Tagged release publishes image and chart

- **GIVEN** a maintainer pushes tag `k8s-v0.1.0`
- **WHEN** the release workflow finishes
- **THEN** image `ghcr.io/conductionnl/keepiq-operator:0.1.0` and chart version `0.1.0` MUST be published
