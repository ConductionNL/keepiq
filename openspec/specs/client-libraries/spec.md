# Client libraries Specification

**Status**: done

**OpenSpec changes:**
- [apps-client-libraries-and-ci](../../changes/archive/2026-10-02-apps-client-libraries-and-ci/) _(archived 2026-10-02)_

## Purpose
Official Go, Python and TypeScript libraries for the Keepiq machine API. A service or job reads and writes its application secrets in a few lines and decrypts only in its own process. Parity row apps-21.

## Requirements

### Requirement: Official libraries for Go, Python and TypeScript

The project MUST ship client libraries for the Keepiq machine API in Go (`sdk/go/`), Python (`sdk/python/`) and TypeScript (`sdk/js/`). Each MUST discover the instance from `/api/v1/app/.well-known/keepiq`, sign the RFC 7523 assertion with the caller's application private key, cache the bearer token until it expires, and offer read by name, read by id, list with an `updatedSince` filter, create and update for the application's own vault.

#### Scenario: Python service reads a secret by name

- **GIVEN** an approved application `billing` with its private key in `/run/secrets/keepiq.pem` and a secret `stripe-key` in its vault
- **WHEN** a Python service calls `Client(url, "billing", key).get_by_name("stripe-key")`
- **THEN** the call MUST return the decrypted value and the secret metadata
- **AND** no request from the library MUST carry the plaintext value or the private key

### Requirement: Libraries decrypt and encrypt only in the calling process

Every library MUST decrypt the `rsa-oaep-sha256-chunked-v1` ciphertext in the calling process, MUST check the envelope's certificate fingerprint against the caller's key before decrypting, and MUST encrypt write-back values with the public half of the caller's own key before sending them to `POST /api/v1/app/secrets` or `PUT /api/v1/app/secrets/{id}`.

#### Scenario: Node job rotates its own value

- **GIVEN** a TypeScript job holding the private key of application `billing`
- **WHEN** it calls `client.update(id, { key: "YOUR_TOKEN_HERE" })`
- **THEN** the request body MUST contain only ciphertext for `key`
- **AND** a later `getById(id)` MUST return `YOUR_TOKEN_HERE`

### Requirement: Libraries follow the machine API contract

Every library MUST send `If-None-Match` with the last ETag and report "not modified" on 304, MUST expose the `Doriath-Lease-Id` and `Doriath-Lease-Expires` headers, and MUST raise a typed ambiguous-name error carrying the candidates' ids and folder paths on 409.

#### Scenario: Ambiguous name is reported with candidates

- **GIVEN** two secrets named `api-token` in the application's vault
- **WHEN** a Go program calls `GetByName("api-token", "")`
- **THEN** the call MUST return an ambiguous-name error listing both candidates' ids and folder paths

### Requirement: One set of conformance vectors binds every implementation

The repository MUST hold shared vectors in `sdk/testdata/` produced by the PHP envelope serializer and the browser crypto. Every library and the CLI MUST decrypt them in CI, and a PHPUnit test MUST decrypt values that each library encrypted, using `DecryptService`. The CLI MUST parse the envelope shape the server sends.

#### Scenario: CLI reads a real envelope

- **GIVEN** an envelope written by `MachineSecretEnvelopeService::serialize()` with `encryption.scheme` and `ciphertext.key`
- **WHEN** the CLI's CI mode decrypts it with the matching application key
- **THEN** it MUST return the plaintext value

### Requirement: Libraries are released from this repository

Each library MUST be tested on every pull request that touches its directory and released on its own tag prefix: `sdk/go/v*` for Go, `sdk-py-v*` for PyPI through trusted publishing, and `sdk-js-v*` for npm with provenance.

#### Scenario: Tagged Python release

- **GIVEN** a maintainer pushes tag `sdk-py-v0.1.0`
- **WHEN** the release workflow finishes
- **THEN** version `0.1.0` of the Python package MUST be on PyPI with a trusted-publishing attestation
