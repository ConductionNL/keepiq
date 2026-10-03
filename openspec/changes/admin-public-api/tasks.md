## 1. Endpoints

- [x] 1.1 Add `lib/Controller/AdminIndexController.php` with `GET /api/v1/admin` returning `apiVersion`, the served versions and every path. Verify with a PHPUnit test for the payload and the route-reachability hydra gate.
- [x] 1.2 (offboarding and suite list in `AdminPeopleController`; members is `MemberOverviewController::index()` from #965, People-guarded, documented and in the index; reinstate left out, it needs a fresh password since keepiq#865) Add the People endpoints: members, offboarding, suite list and reinstate, each guarded by the People area. Verify with PHPUnit tests for success and for a refused Audit-only user.
- [x] 1.3 Add the Policies endpoints (`GET`, `PUT /api/v1/admin/policies`) on `AdminSettingsService`. Verify with PHPUnit tests that validation errors match the admin screen's errors.
- [x] 1.4 Add the Applications endpoints (list, approve, reject, delete). Verify with PHPUnit tests for each status change and the no-admin-idor hydra gate.
- [x] 1.5 Add the Audit endpoints (audit events, compliance reports, SIEM sinks). Verify with PHPUnit tests, including that no response carries a SIEM sink secret in plain form.
- [x] 1.6 Add `#[UserRateLimit]` to every write endpoint and leave force revocation out. Verify with a PHPUnit test that no `/api/v1/admin` route maps to `forceRevoke`.

## 2. Contract

- [x] 2.1 Write `docs/api/admin-v1.openapi.json` for every v1 path, including HTTP Basic with the `OCS-APIRequest` header. Verify with an OpenAPI 3.1 schema lint in CI.
- [x] 2.2 Add `tests/Unit/Contract/AdminApiContractTest.php` that compares the document with `appinfo/routes.php`. Verify by removing one path locally and watching the test fail.
- [ ] 2.3 (written: the collection seeds its own Audit-only account through the provisioning API and `authorizedgroups/saveSettings`; not run locally, CI Newman owed) Add `tests/integration/admin-api.postman_collection.json` and its seed step (service account, delegation, app password). Verify with `tests/integration/run-newman.sh` in the CI Newman job.

## 3. Documentation

- [x] 3.1 Add an "Admin API" page under `docs/tutorials/admin/` that renders the document and explains the service account setup and the versioning rule. Verify with the docs build (`npm run build` in `docs/`).

## Acceptance criteria

- `GET /api/v1/admin` returns `apiVersion` `1` and lists every v1 path.
- A service account whose group holds only the Audit area can read audit events with an app password and is refused `PUT /api/v1/admin/policies`.
- A script can approve a pending application and offboard a leaver without touching the web interface.
- No admin API response contains a private key, a certificate private part, a secret value or ciphertext.
- The contract test fails when a v1 route and the OpenAPI document disagree.
- Force revocation is not reachable through `/api/v1/admin`.
