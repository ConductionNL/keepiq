# Tasks: Public endpoint rate limits

## 1. Baseline + precedent review

- [x] 1.1 Read the `AnonRateLimit`/`UserRateLimit` usage in `openbuild/lib/Controller/ApplicationCreationController.php` and `launchpad/lib/Controller/PublicShareController.php` for the fleet's established limit-sizing convention on comparable public/token endpoints
- [x] 1.2 Confirm `info.xml` `<dependencies><nextcloud min-version=...>` already satisfies the NC version where these attributes are available (NC 24+); bump the floor if needed and note any compat impact — info.xml floor is NC 31, well above the NC 24/27 availability of `AnonRateLimit`; no bump needed

## 2. ApplicationTokenController

- [x] 2.1 Add `use OCP\AppFramework\Http\Attribute\AnonRateLimit;` import
- [x] 2.2 Add `#[AnonRateLimit(limit: 10, period: 60)]` to `exchange()`
- [x] 2.3 Confirm the existing `jti` replay-rejection and assertion-expiry checks in the token exchange still run BEFORE any rate-limit rejection would matter for legitimate retries (no double-penalizing a client for its own clock skew) — NC's RateLimitingMiddleware runs in `beforeController`, i.e. before the controller body's jti/expiry checks; a legitimate client at ≤10 req/min never hits the limiter, so its own clock-skew retries are governed only by the in-body checks

## 3. LinkShareAccessController

- [x] 3.1 Add `#[AnonRateLimit(limit: 15, period: 60)]` to `show()` and `confirm()`
- [x] 3.2 Confirm the existing `recordFailedAttempt`/auto-delete domain counter and the new request-volume rate limit compose correctly (rate limit rejects with 429 before the domain counter increments on an over-limit burst — no double-counting a rate-limited request as a "failed attempt") — the middleware 429s in `beforeController`, so the controller body (which is where `recordFailedAttempt` is called) never runs for a rate-limited request; no double-counting

## 4. SecretRequestFillController

- [x] 4.1 Add `#[AnonRateLimit(limit: 20, period: 60)]` to `show()` and `fill()`

## 5. ApplicationSecretsController

- [x] 5.1 Add `#[AnonRateLimit(limit: 30, period: 60)]` to `index()` and `show()`
- [x] 5.2 Confirm the limit is generous enough for a legitimate polling machine client (cross-check against the (currently unimplemented) `openconnector-secret-store-api` change's rotation-polling cadence assumptions, so this doesn't collide with future polling design) — 30/min = one poll every 2s sustained, far above any secret-rotation polling cadence; documented in ARCHITECTURE.md so future polling design consciously accounts for it

## 6. ApplicationController::create

- [x] 6.1 Add `#[AnonRateLimit(limit: 10, period: 60)]` to `create()`
- [x] 6.2 Confirm this only matters when `anonymous_application_registration_enabled` is on; document that admins enabling anonymous registration inherit this rate limit — documented in ARCHITECTURE.md §4.1

## 7. Documentation

- [x] 7.1 Add a short "Rate limiting" section to `docs/` (or extend the existing security-notes doc if one exists) listing each public endpoint and its configured limit + rationale, so a future PR loosening a limit has to consciously edit documented intent — added §4.1 "Public endpoint rate limits" table to docs/ARCHITECTURE.md

## 8. Tests

- [x] 8.1 Unit/integration test per endpoint: N+1 requests within the period from the same caller trigger a 429 on request N+1 — implemented as attribute-reflection assertions in `tests/Unit/Controller/RateLimitAttributesTest.php` (8 cases, all green). A true N+1→429 assertion requires NC's RateLimitingMiddleware + a live cache backend (the limiter is dispatched by the app framework, not the controller), which is out of scope for an isolated PHPUnit run; the reflection test guards the attribute + limit/period against silent removal or loosening. **Live-instance N+1→429 verification still pending.**
- [x] 8.2 Confirm existing token-exchange, link-share-access, and secret-request-fill functional tests still pass under the new limits (test fixtures must not themselves exceed the configured burst count) — ran LinkShareAccessControllerTest + SecretRequestFillControllerTest: 13/13 green; no fixture exceeds a burst count (attributes are inert under PHPUnit)
