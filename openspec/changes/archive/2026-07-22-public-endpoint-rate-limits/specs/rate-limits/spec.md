---
status: proposed
---

# Public Endpoint Rate Limits

## Purpose

Every `#[PublicPage]` (anonymous-reachable) endpoint in Doriath carries a Nextcloud-native `#[AnonRateLimit]` bound, closing the gap between the app's existing domain-level brute-force counters (link-share failed-attempt tracking) and framework-level request-volume protection — consistent with the fleet pattern already in production in decidesk, launchpad, openbuild, and softwarecatalog.

## ADDED Requirements

### Requirement: Token exchange is rate-limited

`POST /api/v1/token` (`ApplicationTokenController::exchange`) SHALL be rate-limited via `#[AnonRateLimit]` independent of the JWT assertion's own expiry/replay checks.

#### Scenario: Excess token-exchange attempts are rejected

- **GIVEN** the configured limit for the token-exchange endpoint has been reached for a given caller within the current period
- **WHEN** one more exchange request arrives before the period resets
- **THEN** the response MUST be HTTP 429, and no JWT assertion validation MUST occur for that request

### Requirement: Public link-share access is rate-limited independent of the domain brute-force counter

`GET /api/v1/public/link-shares/{token}` and `POST /api/v1/public/link-shares/{token}/confirm` (`LinkShareAccessController`) SHALL carry `#[AnonRateLimit]` in addition to the existing per-token failed-attempt counter.

#### Scenario: Rate limit engages before the domain failed-attempt counter on a rapid burst

- **GIVEN** a caller sends requests to the link-share access endpoint faster than the configured rate limit allows
- **WHEN** the rate limit is exceeded
- **THEN** the response MUST be HTTP 429 and the request MUST NOT be counted as a domain-level "failed attempt" against the link share's usage/failure counters

### Requirement: Public secret-request fill-in is rate-limited

`GET /api/v1/public/secret-requests/{token}` and `POST /api/v1/public/secret-requests/{token}/fill` (`SecretRequestFillController`) SHALL carry `#[AnonRateLimit]`.

#### Scenario: Excess fill-in attempts are rejected

- **GIVEN** the configured limit for the secret-request fill-in endpoint has been reached for a given caller
- **WHEN** one more request arrives before the period resets
- **THEN** the response MUST be HTTP 429

### Requirement: Bearer-authenticated application-secrets endpoints are rate-limited at the public boundary

`GET /api/v1/app/secrets` and `GET /api/v1/app/secrets/{id}` (`ApplicationSecretsController`) SHALL carry `#[AnonRateLimit]` sized for legitimate machine-client polling, since the route is `#[PublicPage]` and reaches the controller before `JwtAuthMiddleware` validates the bearer token.

#### Scenario: Unauthenticated flood is rejected before bearer validation cost

- **GIVEN** a caller with no valid bearer token sends requests to the application-secrets endpoint faster than the configured limit
- **WHEN** the limit is exceeded
- **THEN** the response MUST be HTTP 429

### Requirement: Anonymous application self-registration is rate-limited when enabled

`POST /api/v1/applications` (`ApplicationController::create`) SHALL carry `#[AnonRateLimit]`, applying whenever the admin has opted into `anonymous_application_registration_enabled`.

#### Scenario: Excess anonymous registration attempts are rejected

- **GIVEN** anonymous application registration is enabled on an instance
- **WHEN** a caller exceeds the configured registration rate limit
- **THEN** the response MUST be HTTP 429 and no pending-application row MUST be created for that request
