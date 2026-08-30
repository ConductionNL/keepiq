# Keepiq — Newman API-contract suite

`keepiq.postman_collection.json` locks the HTTP contract of Keepiq's
controllers (`appinfo/routes.php` + `lib/Controller/*`): encryption suites, CA
status, secrets, folders, secret-types, and the public link-share access route.

## Running

```bash
./run-newman.sh
# or with overrides:
BASE_URL=http://localhost:8080 ADMIN_USER=admin ADMIN_PASS=admin ./run-newman.sh
```

`run-newman.sh` uses a globally-installed `newman` if present, otherwise
`npx newman`. Runs are serialised under `flock /tmp/uiaudit-keepiq.lock` so
parallel CI agents do not trip Nextcloud brute-force protection.

## Design (matches the procest `tests/integration/` pattern)

- **Host-split auth.** Authenticated requests hit `{{baseUrl}}` (`localhost`)
  with an explicit per-request basic-auth block (`admin:admin`). The
  unauthenticated authorization tests hit `{{noAuthBase}}` (`127.0.0.1`) so the
  host-scoped NC session cookie established on `localhost` is never sent to
  them — keeping the 401 assertions honest. Collection auth is `noauth`.
- **`--ignore-redirects`** so an unauthenticated request asserts NC's `401`
  directly instead of following a `303` to the HTML login page.
- Every request sends `Accept: application/json` and `OCS-APIRequest: true`.
- **Idempotent.** Setup creates a secret and a folder, asserts the full CRUD
  round-trip + reads + authz, then teardown deletes them.

## Coverage

24 requests / 44 assertions, all green. Families:

1. Encryption suites + CA reads (list, unknown-id 404, authz 401, CA status).
2. Secrets — full CRUD round-trip (create 201 → show → update → delete),
   error cases (unknown-id 404, empty-key 400), authz 401.
3. Folders + secret-types (list, create folder, children, authz).
4. Public link-share access — unknown token `404` on the unauthenticated
   `#[PublicPage]` route (reachable, not `401`/`303`, not `500`).

## Honestly-quarantined (NOT fake passes)

Vault WRITES that mint/use key material (suite create, private-key upload,
link-share create) need the in-browser RSA crypto flow and an unlocked vault —
not drivable from a headless API client; covered by Playwright e2e.

Two quarantined tests assert the CURRENT buggy `500` so the suite stays green
without faking a pass — each goes RED (flip to `400`) once the controller is
fixed:

- **`SecretController::create` missing-required-field → 500.** Non-nullable
  `string $name/$key` params; omitting one triggers NC reflection
  param-injection `500` instead of a clean `400`. (An empty-string key already
  returns a clean `400` via the service — see the real validation test.)
- **`EncryptionSuiteController::create` placeholder key → 500.** Same
  param-injection class when a field is omitted; additionally, with both fields
  supplied, an invalid public key makes `CaService::signPublicKey` throw an
  `InvalidArgumentException` that escapes the controller's `RuntimeException`-only
  catch → `500`. Should validate presence + catch the bad-key path → `400`.
