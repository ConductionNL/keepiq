# Tasks: Emergency Access

> **Status: PARTIAL — backend state-machine slice APPLIED + unit-tested; the
> live-instance-coupled layers (controllers/routes, notifier wiring, frontend,
> e2e) are marked DEFERRED.** The zero-knowledge design is preserved: the
> server only ever stores the owner's vault key material *already re-wrapped*
> under the contact's suite public key (`wrapped_key_material`), never
> plaintext. What ships here is the data layer + service state machine +
> events + background job, all unit-tested without a live instance. The HTTP
> surface, notifications, and Vue UI need a running Nextcloud to build/verify
> and are deferred.

## 1. Data layer

- [x] 1.1 Migration `doriath_emergency_contacts` — `Version000015Date20260707000000`; columns id/owner_id/contact_id/wait_period_hours/level/status/wrapped_key_material(nullable)/contact_suite_fingerprint(nullable)/created_at/confirmed_at(nullable) + indexes.
- [x] 1.2 Migration `doriath_emergency_requests` — same migration; columns id/emergency_contact_id/contact_id/owner_id/status/requested_at/resolved_at(nullable) + indexes.
- [x] 1.3 `EmergencyContactMapper` + `EmergencyRequestMapper` (QBMapper pattern mirroring `SecretRequestMapper`) with the lookup helpers the service needs (findById/findByOwner/findByContact/findActiveForPair/findAllRequested).

## 2. Service layer

- [x] 2.1 `EmergencyAccessService::registerContact` — creates `pending-confirmation`; **rejects self-designation** + unknown level + sub-hour wait period (unit-tested).
- [x] 2.2 `EmergencyAccessService::confirmContact` — owner-only, idempotent; stores wrapped material + contact-suite fingerprint (for staleness) + activates (unit-tested).
- [x] 2.3 `EmergencyAccessService::requestAccess` — contact-initiated; validates an `active` designation exists (403 otherwise); dispatches `EmergencyAccessRequestedEvent` (unit-tested).
- [x] 2.4 `EmergencyAccessService::rejectRequest` — owner-only; `requested → rejected`; dispatches rejected event (unit-tested incl. non-owner 403 + non-pending 409).
- [x] 2.5 `EmergencyAccessService::cancelRequest` — contact self-cancel; `requested → cancelled` (unit-tested).
- [ ] 2.6 Extend `SecretService::get`/`list` authorization to honour a `granted` emergency request — **DEFERRED**: the reusable predicate is implemented (`EmergencyAccessService::hasGrantedAccess`, unit-tested), but wiring it into `SecretService`'s read path needs the controller/session context and is best verified against a live instance. Predicate is ready to inject.
- [ ] 2.7 `takeover` level ownership-transfer reuse — **DEFERRED**: depends on `secret-export-gdpr`'s permanent-delegation transfer path (that change is itself unimplemented). The `takeover` level is modelled + carried on the granted event; the transfer execution is deferred to when that shared path lands.

## 3. Background job

- [x] 3.1 `EmergencyAccessExpiryJob` (extends `TimedJob`, hourly) → delegates to `EmergencyAccessService::processExpiredRequests()`, which transitions only rows past `requested_at + wait_period_hours` to `granted` and dispatches `EmergencyAccessGrantedEvent` (unit-tested: expired grants, fresh untouched).
- [x] 3.2 Registered the job in `appinfo/info.xml` `<background-jobs>`.

## 4. Notifications

- [ ] 4.1 `DoriathNotifier` "emergency access requested" case — **DEFERRED**: the event is dispatched; the notifier case + live NC notification/email need a running instance to verify. Not faked.
- [ ] 4.2 `DoriathNotifier` "emergency access granted" case — **DEFERRED** (same reason).

## 5. Controllers + routes

- [ ] 5.1 `EmergencyContactController` — **DEFERRED**: needs a live instance for route/middleware verification. The service API it would wrap is complete + tested.
- [ ] 5.2 `EmergencyRequestController` — **DEFERRED** (same).
- [ ] 5.3 Register routes in `appinfo/routes.php` — **DEFERRED** (same).
- [ ] 5.4 `#[UserRateLimit]` on the request-creation endpoint — **DEFERRED** with 5.2 (ties to the public-endpoint-rate-limits convention, which is applied in that change).

## 6. Frontend

- [ ] 6.1–6.5 Emergency-contacts settings panel, client-side re-wrap flow, request-access action, owner pending-request banner, post-grant view state — **ALL DEFERRED**: Vue UI + WebCrypto re-wrap need a live instance + design pass; not faked.

## 7. Audit events

- [x] 7.1 `EmergencyAccessRequestedEvent`, `EmergencyAccessRejectedEvent`, `EmergencyAccessGrantedEvent` — typed, dispatched via `OCP\EventDispatcher`, carry only non-sensitive identifiers (owner/contact uid, request id, level) — never key material. Ready for the `add-secret-audit-trail` pipeline to consume.

## 8. Tests

- [x] 8.1 Unit: registration rejects self-designation; confirm idempotent; request/reject/cancel transitions + auth guards — `EmergencyAccessServiceTest` (15 cases, all green).
- [ ] 8.2 Unit: `SecretService` authorization grants a contact access only when a `granted` row exists — **PARTIAL**: the predicate `hasGrantedAccess` is unit-tested (correct owner + contact only); the `SecretService` integration is deferred with 2.6.
- [x] 8.3 Unit: expiry job only transitions rows past their wait period — covered by `testExpirySweepGrantsOnlyExpiredRequests` (expired granted, fresh untouched).
- [ ] 8.4 e2e (Playwright) full flow — **DEFERRED**: needs a live instance + test clock; not faked.

## Gate results (this apply pass)

- phpcs (php:8.3-cli, `--standard=phpcs.xml`) on all 10 new lib files: **PASS** (0 errors).
- phpunit (`-d memory_limit=1G`) `EmergencyAccessServiceTest`: **PASS** 15/15.
- Full app phpunit: 448 pass; 11 pre-existing errors in `JwtAuthServiceTest` (missing `web-token/jwt-framework` in the sandbox vendor — unrelated to this change, present in the main checkout too).
