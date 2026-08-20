# Tasks: Emergency Access

## 1. Data layer

- [x] 1.1 Migration: `doriath_emergency_contacts` table (`id`, `owner_id`, `contact_id`, `wait_period_hours`, `level` enum `view|takeover`, `status` enum `pending-confirmation|active|revoked`, `wrapped_key_material` nullable text, `created_at`, `confirmed_at` nullable)
- [x] 1.2 Migration: `doriath_emergency_requests` table (`id`, `contact_id`, `owner_id`, `emergency_contact_id` FK, `status` enum `requested|rejected|granted|cancelled`, `requested_at`, `resolved_at` nullable)
- [x] 1.3 `EmergencyContactMapper` + `EmergencyRequestMapper` (standard `QBMapper` pattern matching `SecretMapper`/`LinkShareMapper`)

## 2. Service layer

- [x] 2.1 `EmergencyAccessService::registerContact(ownerId, contactId, waitPeriodHours, level)` — creates `pending-confirmation` row; reject self-designation (`ownerId === contactId`)
- [x] 2.2 `EmergencyAccessService::confirmContact(id, ownerId, wrappedKeyMaterial)` — owner-only, idempotent; fetches contact's current active suite fingerprint and stores it alongside the wrapped blob so staleness is detectable
- [x] 2.3 `EmergencyAccessService::requestAccess(contactId, ownerId)` — contact-initiated; validates an `active` EmergencyContact relationship exists; creates `requested` row; dispatches owner notification
- [x] 2.4 `EmergencyAccessService::rejectRequest(id, ownerId)` — owner-only; transitions to `rejected`
- [x] 2.5 `EmergencyAccessService::cancelRequest(id, contactId)` — contact-initiated self-cancel
- [x] 2.6 Extend `SecretService::get`/`list` authorization: if the requesting user is not the owner, check for a `granted` EmergencyAccessRequest naming them as contact for that owner before falling through to the existing ForbiddenException path
- [x] 2.7 `takeover` level: reuse the ownership-transfer helper from `secret-export-gdpr`'s design (permanent-delegation transfer path) rather than writing a new one

## 3. Background job

- [x] 3.1 `EmergencyAccessExpiryJob` (extends the same `TimedJob` base the link-share/secret-request expiry jobs use): finds `requested` rows past `requested_at + wait_period_hours`, transitions to `granted`, dispatches `EmergencyAccessGrantedEvent`
- [x] 3.2 Register the job in `Application::register()` / `info.xml` background-jobs section

## 4. Notifications

- [x] 4.1 `DoriathNotifier` case for "emergency access requested" (to owner, with reject action) — mirror the existing `SecretRequest` notification pattern
- [x] 4.2 `DoriathNotifier` case for "emergency access granted" (to both owner and contact)

## 5. Controllers + routes

- [x] 5.1 `EmergencyContactController` — `index` (list my configured contacts + contacts-of), `create` (register), `confirm` (PUT), `destroy` (revoke) — all `#[NoAdminRequired]`, owner/contact-scoped per method
- [x] 5.2 `EmergencyRequestController` — `create` (contact requests), `approve`/`reject` naming convention matching `SecretRequestController`, `index` (list pending for me as owner or as contact)
- [x] 5.3 Register routes in `appinfo/routes.php` under a clearly commented "Emergency access" section
- [x] 5.4 `#[UserRateLimit]` on the request-creation endpoint (authenticated; ties to the `public-endpoint-rate-limits` change's convention)

## 6. Frontend

- [x] 6.1 Emergency-contacts settings panel (owner view): list configured contacts, wait period, status (needs-confirmation / active), add/revoke
- [x] 6.2 Confirmation flow: when a contact's suite is confirmed available, browser performs the re-wrap client-side (WebCrypto, same path as `implement-user-sharing`'s share encryption) and POSTs ciphertext
- [x] 6.3 "Request access" action (as a contact) on the relevant owner's entry, with a plain-language explanation of the wait period and that the owner will be notified
- [x] 6.4 Pending-request banner for owners (mirrors `MigrationBanner.vue`'s pattern) with a one-click reject action
- [x] 6.5 Post-grant view state: contact sees the owner's vault (view) or full ownership controls (takeover) once granted

## 7. Audit events (consumed by add-secret-audit-trail once built)

- [x] 7.1 `EmergencyAccessRequestedEvent`, `EmergencyAccessRejectedEvent`, `EmergencyAccessGrantedEvent` — typed, dispatched via `OCP\EventDispatcher`, carrying only non-sensitive identifiers (owner/contact uid, request id, timestamps) — never key material

## 8. Tests

- [x] 8.1 Unit: registration rejects self-designation; confirm is idempotent; request/reject/cancel/expiry state transitions
- [x] 8.2 Unit: `SecretService` authorization grants access to a contact only when a `granted` row exists, and only for the correct owner
- [x] 8.3 Unit: expiry job only transitions rows past their wait period, leaves others untouched
- [x] 8.4 e2e (Playwright): full flow — owner designates + confirms contact, contact requests, owner rejects (access denied), contact requests again, wait period elapses (test clock), contact gains view access
