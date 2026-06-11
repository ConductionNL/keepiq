## 0. Dependency Note (read first)

This change layers a consumer contract on `implement-application-mgmt`
(Application entity, JwtAuthService + middleware, `POST /api/v1/token`, base
`GET /api/v1/app/secrets[/{id}]` endpoints) and `implement-secrets`
(Secret/Folder entities, folder-path derivation) — land those first. It
coordinates with `add-secret-audit-trail` (`application.token_issued` /
`application.secret_retrieved` events; dispatch sites live there). The
OpenConnector-side resolver is OUT OF SCOPE here: it is a separate openspec
change in the openconnector repo, built and CI-verified against the Newman
collection shipped in section 5. This change has no frontend surface.

## 1. Backend — Discovery and Addressing

- [ ] 1.1 Create `DiscoveryController` with `#[PublicPage]` endpoint `GET /api/v1/app/.well-known/doriath` returning the versioned discovery document (apiVersion, tokenEndpoint, grantType, assertion requirements incl. canonical audience URL, secret endpoint paths, envelopeFormats) — no instance-private data
- [ ] 1.2 Add `GET /api/v1/app/secrets/by-name/{name}` to `ApplicationApiController` (bearer-token auth via the existing JwtAuthMiddleware): exact case-sensitive name match within the authenticated application's vault, optional `folder` query (slash-path resolved via the folder tree); 404 on zero, envelope on one, 409 with candidate list `{id, name, folderPath, updatedAt}` on many — never silent first-match
- [ ] 1.3 Implement folder-path resolution helper (path string → folder id within the app vault) reusing the secrets spec's derived-path traversal

## 2. Backend — Envelope, ETag, Rotation

- [ ] 2.1 Implement the `doriath-machine-secret-v1` envelope serializer: format identifier, plaintext-safe metadata (id, name, url, folderPath, type, createdAt, updatedAt, keyUpdatedAt nullable), `encryption` block (suiteId, sha256 certificate fingerprint, scheme identifier naming the existing ADR-003 encrypt path), base64 ciphertext fields; apply it to by-id, by-name, and list-item responses
- [ ] 2.2 Implement strong ETag derivation (hash over id + updated timestamp + ciphertext digest) on by-id and by-name reads; honor `If-None-Match` with 304 and no body
- [ ] 2.3 Add `updated_since` (ISO 8601, validated) filtering to the application secret list endpoint
- [ ] 2.4 Verify/extend mapper indexes so by-name and updated_since queries are indexed (owner + name; owner + updated_at)

## 3. Backend — Write-Back

- [ ] 3.1 Add `POST /api/v1/app/secrets` and `PUT /api/v1/app/secrets/{id}` (bearer-token auth): accept plaintext-safe metadata + fields pre-encrypted with the application's own certificate; validate metadata and ciphertext envelope shape only; PUT replaces ciphertext and advances updatedAt (and keyUpdatedAt when the key blob changes, if the password-health column exists)
- [ ] 3.2 Assert no delete route exists on the machine surface (deletion stays with application-mgmt's human/admin cascade); document why in the controller docblock

## 4. Backend — Token Endpoint Hardening (normative per design D7)

- [ ] 4.1 Enforce assertion lifetime ≤ 300 s and validity-window checks in JwtAuthService (reject expired/not-yet-valid)
- [ ] 4.2 Implement `jti` single-use replay cache (`OCP\ICache`, TTL = assertion lifetime); replay → 400
- [ ] 4.3 Refuse token issuance for pending/rejected/deleted applications and applications whose suite is revoked or compromised
- [ ] 4.4 Apply Nextcloud brute-force throttling to failed token exchanges
- [ ] 4.5 Enforce own-vault scoping on every `/api/v1/app/*` query (all mappers keyed by the token's application id); cross-vault id/name requests return the same 404 as nonexistent (no existence oracle)
- [ ] 4.6 Register all routes in `appinfo/routes.php` before the SPA catch-all; run hydra gates (route-auth, semantic-auth, no-admin-idor, spec-coverage)

## 5. Contract Artifacts

- [ ] 5.1 Create `tests/integration/machine-secret-api.postman_collection.json` covering: discovery shape; token exchange happy path; replayed assertion rejected; pending application refused; throttling on repeated failures; by-name resolution (one match, folder-scoped match, 404, 409 with candidates); envelope shape assertions (format, encryption block, fingerprint, ciphertext-only); ETag 304 flow; updated_since rotation flow; write-back create + rotate; cross-vault 404 scoping
- [ ] 5.2 Add collection setup helpers (seed an approved application with a generated key pair, sign assertions in a pre-request script) and wire the collection into the existing Newman CI run
- [ ] 5.3 Version the collection alongside `apiVersion` so the openconnector repo can pin it

## 6. Internationalization

- [ ] 6.1 No UI strings (headless machine API); ensure error response messages are stable English contract strings, not translated (documented as part of the contract)

## 7. Unit Tests (PHP)

- [ ] 7.1 DiscoveryController test: document shape, apiVersion, no instance-private fields, public accessibility
- [ ] 7.2 By-name resolution tests: exact/case-sensitive match, folder scoping, 404 / single / 409-with-candidates, scoped strictly to the token's application
- [ ] 7.3 Envelope serializer tests: all fields, nullable keyUpdatedAt, fingerprint correctness against the suite certificate, ciphertext-only guarantee (assert no plaintext-capable field exists)
- [ ] 7.4 ETag tests: stable across no-op reads, changes on ciphertext/metadata update, If-None-Match → 304 without body
- [ ] 7.5 updated_since tests: boundary instant, invalid format → 400
- [ ] 7.6 Write-back tests: shape validation, metadata-only server checks, PUT advances updatedAt, no delete route registered
- [ ] 7.7 JwtAuthService hardening tests: lifetime bound, replay rejection, status/suite refusals, throttling annotation present; own-vault 404 indistinguishability

## 8. E2E

- [ ] 8.1 Machine API behaviour is API-only: covered by the Newman collection (5.1) per the Playwright-UI / Newman-API division — no Playwright specs for this capability
- [ ] 8.2 Annotate all spec scenarios per gate-19 with `@e2e exclude` (machine-to-machine API contract, no UI surface; covered by the Newman collection and PHPUnit), each directive on its own line

## 9. Documentation

- [ ] 9.1 Add `docs/integration-openconnector.md`: the `doriath://{applicationId}/{folderPath}/{name}` reference format, full resolution algorithm (discovery → token → by-name → local decrypt), key-custody guidance (private key lives in the consumer's credential storage, never in shareable/exported configs), re-registration recovery after key loss, token/value logging prohibition, and the 409-ambiguity operator playbook
- [ ] 9.2 Document the envelope format and decryption procedure (scheme identifier → ADR-003 path) precisely enough to implement a consumer from docs alone
- [ ] 9.3 Update `docs/FEATURES.md` status for the sister-app integration row; cross-link the openconnector-side change once it exists in that repo
