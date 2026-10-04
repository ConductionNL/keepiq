## 1. Endpoint

- [x] 1.1 Add `GET /api/v1/app/certificate` (`ApplicationCertificateController::show()`) returning `applicationId`, `suiteId`, `certificate` and `certificateFingerprint` for the Bearer application's active suite, 401 without a token and 404 without an active suite. Verify with `ApplicationCertificateControllerTest`.
- [x] 1.2 Name the path in the discovery document under `certificate`. Verify with `DiscoveryControllerTest`.
- [x] 1.3 Add the bearer-required refusal and the discovery field to `tests/integration/machine-secret-api.postman_collection.json`.

## 2. Docs

- [x] 2.1 Mention the endpoint in `docs/client-libraries.md`, `docs/kubernetes.md` and `sdk/README.md`.
- [x] 2.2 Live check: an approved application's fingerprint from this endpoint equals the `certificateFingerprint` of an envelope it fetches. Verified 4 Oct on a fresh Nextcloud 35.0.1: an application registered with a CSR through `POST /api/v1/admin/applications` (active), a JWT-bearer token from `/api/v1/token`, `GET /api/v1/app/certificate` answered `sha256:a5e40ca2...b9be47` (equal to `sha256` of the certificate DER, and the certificate's public key equals the application key); a written-back secret fetched by name carried `encryption.certificateFingerprint` `sha256:a5e40ca2...b9be47` and the same `suiteId`, and decrypted with the application key.
