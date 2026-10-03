# Keepiq client libraries

| Directory | Package | Tests |
|---|---|---|
| `go/` | `github.com/ConductionNL/keepiq/sdk/go` | `go vet ./... && go test ./...` |
| `python/` | `keepiq-sdk` | `python -m pytest` |
| `js/` | `@conduction/keepiq-sdk` | `npm install && npm test` |

User documentation: `docs/client-libraries.md` and `docs/ci-integrations.md`.

An application reads its own certificate and `certificateFingerprint` at
`GET /api/v1/app/certificate` (Bearer token; the discovery document names it
under `certificate`). The Go `WithCertificate`, Python `certificate_pem=` and
TypeScript `{ certificatePem }` options take that PEM; each library still
checks it against the key at start.

## Shared vectors (`testdata/`)

Every file in `testdata/` is TEST ONLY. The key in it protects nothing.

| File | Written by | Read by |
|---|---|---|
| `machine_envelope.json` | `tests/Unit/Service/MachineEnvelopeCliFixtureTest.php` (the real serializer and EncryptService) | every library, the CLI |
| `webcrypto_envelope.json` | the browser's WebCrypto | every library, the CLI's crypto |
| `vector_plaintexts.json` | by hand | the vector writers |
| `encrypted_by_<lang>.json` | that library's tests with `KEEPIQ_WRITE_VECTORS=1` | every library, and `DecryptService` in `tests/Unit/Service/SdkEncryptedVectorsTest.php` |
| `stub_server.py` | by hand | the Python tests and `integrations/test/run.sh` |

After regenerating `machine_envelope.json` (its key changes), rewrite all three
`encrypted_by_*.json` files:

```sh
(cd go && KEEPIQ_WRITE_VECTORS=1 go test -run TestGoEncryptedVector .)
(cd python && KEEPIQ_WRITE_VECTORS=1 python -m pytest -k python_vector)
(cd js && KEEPIQ_WRITE_VECTORS=1 npx vitest run)
```

## Releases

`.github/workflows/sdk.yml` releases each library on its own tag:
`sdk/go/vX.Y.Z`, `sdk-py-vX.Y.Z` (PyPI trusted publishing) and `sdk-js-vX.Y.Z`
(npm with provenance). The version in `pyproject.toml` or `package.json` must
match the tag.
