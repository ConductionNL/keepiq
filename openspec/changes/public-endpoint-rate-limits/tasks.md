# Tasks: Public endpoint rate limits

## 1. Baseline + precedent review

- [ ] 1.1 Read the `AnonRateLimit`/`UserRateLimit` usage in `openbuild/lib/Controller/ApplicationCreationController.php` and `launchpad/lib/Controller/PublicShareController.php` for the fleet's established limit-sizing convention on comparable public/token endpoints
- [ ] 1.2 Confirm `info.xml` `<dependencies><nextcloud min-version=...>` already satisfies the NC version where these attributes are available (NC 24+); bump the floor if needed and note any compat impact

## 2. ApplicationTokenController

- [ ] 2.1 Add `use OCP\AppFramework\Http\Attribute\AnonRateLimit;` import
- [ ] 2.2 Add `#[AnonRateLimit(limit: 10, period: 60)]` to `exchange()`
- [ ] 2.3 Confirm the existing `jti` replay-rejection and assertion-expiry checks in the token exchange still run BEFORE any rate-limit rejection would matter for legitimate retries (no double-penalizing a client for its own clock skew)

## 3. LinkShareAccessController

- [ ] 3.1 Add `#[AnonRateLimit(limit: 15, period: 60)]` to `show()` and `confirm()`
- [ ] 3.2 Confirm the existing `recordFailedAttempt`/auto-delete domain counter and the new request-volume rate limit compose correctly (rate limit rejects with 429 before the domain counter increments on an over-limit burst — no double-counting a rate-limited request as a "failed attempt")

## 4. SecretRequestFillController

- [ ] 4.1 Add `#[AnonRateLimit(limit: 20, period: 60)]` to `show()` and `fill()`

## 5. ApplicationSecretsController

- [ ] 5.1 Add `#[AnonRateLimit(limit: 30, period: 60)]` to `index()` and `show()`
- [ ] 5.2 Confirm the limit is generous enough for a legitimate polling machine client (cross-check against the (currently unimplemented) `openconnector-secret-store-api` change's rotation-polling cadence assumptions, so this doesn't collide with future polling design)

## 6. ApplicationController::create

- [ ] 6.1 Add `#[AnonRateLimit(limit: 10, period: 60)]` to `create()`
- [ ] 6.2 Confirm this only matters when `anonymous_application_registration_enabled` is on; document that admins enabling anonymous registration inherit this rate limit

## 7. Documentation

- [ ] 7.1 Add a short "Rate limiting" section to `docs/` (or extend the existing security-notes doc if one exists) listing each public endpoint and its configured limit + rationale, so a future PR loosening a limit has to consciously edit documented intent

## 8. Tests

- [ ] 8.1 Unit/integration test per endpoint: N+1 requests within the period from the same caller trigger a 429 on request N+1
- [ ] 8.2 Confirm existing token-exchange, link-share-access, and secret-request-fill functional tests still pass under the new limits (test fixtures must not themselves exceed the configured burst count)
