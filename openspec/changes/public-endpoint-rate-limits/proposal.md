---
kind: code
---

# Proposal: NC-native rate limiting on public/unauthenticated endpoints

## Why

Doriath exposes 8 methods across 5 controllers with `#[PublicPage]` — reachable by anonymous callers, no Nextcloud session required — and none of them carry Nextcloud's own `#[AnonRateLimit]` / `#[UserRateLimit]` attributes:

- `ApplicationTokenController::exchange` (`lib/Controller/ApplicationTokenController.php:76`) — the JWT-bearer token-exchange endpoint (`POST /api/v1/token`), the single highest-value target in the whole app: a successful forced/guessed exchange yields a bearer token good for reading an application's secrets
- `ApplicationSecretsController::index`/`show` (`lib/Controller/ApplicationSecretsController.php:76,112`) — bearer-authenticated but the route itself is `#[PublicPage]` so anonymous traffic reaches the controller before `JwtAuthMiddleware` validates the header
- `SecretRequestFillController::show`/`fill` (`lib/Controller/SecretRequestFillController.php:83,147`) — public token-based recipient fill-in flow
- `ApplicationController::create` (`lib/Controller/ApplicationController.php:193`) — anonymous application self-registration (opt-in via admin flag, but still an open POST when enabled)
- `LinkShareAccessController::show`/`confirm` (`lib/Controller/LinkShareAccessController.php:71,105`) — the two-phase public link-share access protocol

`LinkShareAccessController` already has bespoke, well-designed application-level brute-force counting (`recordFailedAttempt`, uniform 404s to prevent token enumeration — see the controller's own docblock). That is a good *domain* control (auto-deleting a link share after N failed attempts), but it is not a *request-volume* control: nothing stops a caller from hammering `GET /api/v1/public/link-shares/{token}` or `POST /api/v1/token` at high frequency to brute-force tokens/assertions before the domain-level counter or JWT expiry windows even engage, or to simply flood the endpoint for denial-of-service.

This is an established, already-adopted fleet pattern — `#[AnonRateLimit(limit: N, period: N)]` / `#[UserRateLimit(...)]` from `OCP\AppFramework\Http\Attribute` are in production use on exactly this class of endpoint in decidesk (`ParticipationController`), launchpad (`KioskController`, `PublicShareController`), openbuild (4 controllers), and softwarecatalog (`IntakeController`) — verified via `grep -rl AnonRateLimit */lib/Controller/*.php` across apps-extra. Doriath, despite being the fleet's dedicated credential-custody app and therefore carrying the highest blast radius per successful brute-force, is the one app in this set that has not adopted it.

## What Changes

- Add `#[AnonRateLimit(limit: ..., period: 60)]` to every `#[PublicPage]` controller method, sized per endpoint sensitivity:
  - `ApplicationTokenController::exchange` — tight (e.g. `limit: 10, period: 60`): token exchange is the highest-value target and legitimate callers retry infrequently
  - `LinkShareAccessController::show`/`confirm` — tight (e.g. `limit: 15, period: 60`): legitimate recipients make a handful of requests per session, not bursts
  - `SecretRequestFillController::show`/`fill` — moderate (e.g. `limit: 20, period: 60`)
  - `ApplicationSecretsController::index`/`show` — moderate (e.g. `limit: 30, period: 60`): legitimate bearer-authenticated machine clients may poll; the limit protects the pre-auth surface, not steady-state usage
  - `ApplicationController::create` — moderate (e.g. `limit: 10, period: 60`), only relevant when the admin has opted into anonymous registration
- Confirm Nextcloud's rate-limit middleware is active in the app's target NC version range (verify against `info.xml` min-version; the attribute has been available since NC 24) and that the bucket is keyed appropriately (per-IP for anonymous, per-user where a session exists)
- Document the chosen limits in `docs/` (or the app's security notes) so future contributors don't casually loosen them
- Not BREAKING: legitimate traffic patterns for every affected endpoint stay well under the proposed limits; only abusive/scripted request volumes are affected
