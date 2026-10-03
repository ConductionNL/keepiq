## ADDED Requirements

### Requirement: Single background-only client
All HTTP requests to a Nextcloud or Keepiq server SHALL go through `src/api/client.ts`, bound to one account, and MUST run in the background only. The popup and content scripts never hold an app password after submitting the add or re-login form and never call `fetch` against a server.

#### Scenario: Popup needs server data
- **WHEN** a popup view needs a server response
- **THEN** it sends a typed message to the background and the background performs the request

### Requirement: Request shape
Every request SHALL use the account's origin plus `/index.php/apps/keepiq` as base URL for Keepiq routes, an `Authorization: Basic` header built from the account's uid and app password, `OCS-APIRequest: true`, `Accept: application/json`, `credentials: 'omit'`, and `Content-Type: application/json` with a JSON body on writes (ADR-003). Nextcloud identity routes (`/ocs/v2.php/cloud/user`, `/index.php/avatar/{uid}/{size}`) use the origin as base URL with the same headers.

#### Scenario: Suites request
- **WHEN** the client fetches the suites for `https://cloud.example.org`
- **THEN** the URL is `https://cloud.example.org/index.php/apps/keepiq/api/v1/suites` and the request carries the four headers and no cookies

#### Scenario: OCS identity request
- **WHEN** the client fetches the identity
- **THEN** it requests `<origin>/ocs/v2.php/cloud/user?format=json` and returns the unwrapped `ocs.data` object

### Requirement: 401 is revocation
A 401 response SHALL purge the account's private key, cached suite row, `vaultCache.<accountId>` and app password, mark the account "Logged out" and reject the call with a `SessionRevoked` error whose message is "Session revoked, please log in again" (ADR-002). The identity and settings records stay.

#### Scenario: App password revoked in Nextcloud
- **GIVEN** the account is unlocked
- **WHEN** any request returns 401
- **THEN** `storage.session` has no key for the account, `accounts[<accountId>].appPassword` is `null` and the popup shows the "Log in again" screen

#### Scenario: 401 during add verification
- **GIVEN** no account record exists yet for the credentials
- **WHEN** verification returns 401
- **THEN** the client reports the error without touching storage

### Requirement: 423 is a retryable write lock
A 423 response SHALL be reported as a `VaultWriteLocked` error with the server's `message` and MUST NOT be treated as an authentication failure, MUST NOT purge anything and MUST NOT change the account state (ADR-003).

#### Scenario: Write during key migration
- **WHEN** a write returns 423
- **THEN** the caller receives `VaultWriteLocked` and the account stays "Unlocked"

### Requirement: Network failure is offline
When `fetch` rejects or the response has no HTTP status the client SHALL reject with an `Offline` error. Cached data in `storage.local` and an unlocked key in `storage.session` MUST remain usable.

#### Scenario: Server unreachable while unlocked
- **GIVEN** the account is unlocked with a cached suite row
- **WHEN** a request fails at the network level
- **THEN** the caller receives `Offline` and the account stays "Unlocked"

### Requirement: Other error responses
For any other non-2xx status the client SHALL reject with an `ApiError` carrying the status and the `message` field from the `{"message": "<text>"}` body (ADR-003). When the body is not that JSON shape the message MUST be the HTTP status text, and a 404 with a non-JSON body on a Keepiq route MUST be exposed as `KeepiqNotInstalled`.

#### Scenario: Validation error
- **WHEN** the server returns 400 with `{"message": "name is required"}`
- **THEN** the caller receives `ApiError` with status 400 and that message

#### Scenario: Keepiq app missing
- **WHEN** `GET /api/v1/suites` returns 404 with an HTML body
- **THEN** the caller receives `KeepiqNotInstalled`

### Requirement: Typed response shapes
The client SHALL export TypeScript types for the shapes in ADR-003: suite rows, secret rows in both the normal and the `blocked: true` shape, folder rows, secret-type rows, the paginated `{items, total, page, limit}` envelope, the offline manifest, and user settings. Payloads MUST be typed `unknown` at the fetch boundary and narrowed once, in the client.

#### Scenario: Blocked secret row
- **WHEN** a secret row has `blocked: true`
- **THEN** the type exposes `blockedReason` and no `key`, `login` or `additionalFields`

### Requirement: Logged-out account never hits the network
A client bound to an account whose app password is `null` SHALL reject every call with `SessionRevoked` without sending a request.

#### Scenario: Background job on a logged-out account
- **GIVEN** the account is "Logged out"
- **WHEN** any module requests data through its client
- **THEN** no request is sent and `SessionRevoked` is returned
