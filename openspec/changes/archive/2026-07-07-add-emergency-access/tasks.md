## 0. Scope Note (read first)

A new break-glass capability that stays zero-knowledge: recovery = a grantor's private key **hybrid-encrypted to a trusted grantee's public certificate**, built client-side (reuse the user-sharing encrypt-to-recipient primitive and the link-sharing hybrid-envelope shape). The server stores only the grantee-encrypted envelope and gates its release on `approved` + caller-is-grantee. v1 access level is **view** (no takeover). Verify against HEAD before coding: the user-sharing re-encrypt path, the link-sharing envelope crypto, the encryption-suites Session Mechanism + rotation/revocation events, `NotificationService::SUBJECT_SETTING_MAP`, `AuditEventTypes`, and the audit forbidden-key whitelist.

**Implementation note (applied 2026-07-07):** Backend — `doriath_emergency_contacts` table (migration `Version000018`), `EmergencyContact` entity + mapper, `EmergencyAccessService` (designate/request/decline/approve-by-timeout/fetch-envelope/revoke + rotation/revocation invalidation), `EmergencyAccessController` (8 `#[NoAdminRequired]` routes; grantor/grantee IDOR guards live in the service; fetch-envelope refuses wrong-state and wrong-caller identically — no oracle), `ApproveElapsedEmergencyRequests` TimedJob, two listeners on suite rotation/revocation, 7 `AuditEventTypes` + whitelist entries (non-sensitive keys only; envelope/key never recorded), 2 `NotificationService` subjects + `DoriathNotifier` rendering. Frontend — `src/crypto/emergencyEnvelope.js` (hybrid AES-256-GCM + RSA-OAEP-to-grantee), `emergencyAccess` store (raw private key re-derived transiently from the session blob + master password, discarded after the envelope is built — only ciphertext leaves the browser), `EmergencyAccessView` page (registered in registry.js + manifest nav/page). **Zero-knowledge preserved:** the server stores only the grantee-encrypted envelope and never a usable key. Tests: PHPUnit `EmergencyAccessServiceTest` (17 — full state machine, approved+grantee release gate with identical wrong-state/wrong-caller refusal, rotation/revocation invalidation, per-transition audit actor, no-envelope-in-audit) + vitest `emergencyEnvelope` (4 — grantee opens, non-grantee cannot, no raw key on the wire, malformed rejected). **e2e status:** the crypto/wire/DB-dispatch scenarios carry `@e2e exclude` and are covered by PHPUnit + vitest; a live Playwright run of the DOM designate/request/decline/recover flow is **deferred** — the worktree is not deployed and deploying to the shared dev instance is prohibited. The server contract (the real security surface) is fully unit-covered.

## 1. Data model + migration

- [x] 1.1 Add a `doriath_emergency_contact` entity + mapper: grantor user id, grantee user id, access level (`view`), wait-period days, state (`granted` | `requested` | `declined` | `approved`), requested_at, created/updated timestamps, and the grantee-encrypted recovery-envelope blob (nullable until built). Model the request on this row or a small companion table.
- [x] 1.2 One migration creating the table(s). No change to `doriath_secret`.

## 2. Backend — lifecycle service + controller

- [x] 2.1 `EmergencyAccessService`: designate (validate grantee has an active EncryptionSuite; store envelope; state `granted`), revoke (delete envelope + cancel request), request (state `requested`, set `requested_at`, notify grantor), decline (leave `requested`), and fetch-envelope (only if `approved` AND caller is grantee).
- [x] 2.2 Approval transition: a background job (or a lazy check on fetch) flips `requested → approved` when `now >= requested_at + wait_period` and no decline occurred; record `emergency_access.approved` (actor system).
- [x] 2.3 Controller + routes for designate / revoke / request / decline / fetch-envelope; all session-authenticated. The fetch-envelope route MUST refuse unless `approved` and caller-is-grantee (return the same refusal for wrong-state and wrong-caller — no oracle).
- [x] 2.4 Enforce v1 access level = `view` only (reject `takeover`).

## 3. Key-change invalidation (encryption-suites hooks)

- [x] 3.1 Listener on suite rotation (compromise recovery): invalidate the grantor's recovery envelopes and flag re-establish needed; audit `emergency_access.invalidated`.
- [x] 3.2 Listener on suite revocation: clear the grantor's recovery envelopes; also invalidate envelopes encrypted to a grantee whose suite is revoked.

## 4. Audit + notifications

- [x] 4.1 Add `AuditEventTypes`: `EMERGENCY_ACCESS_GRANTED` (`emergency_access.granted`), `_REQUESTED`, `_DECLINED`, `_APPROVED`, `_ACCESSED`, `_REVOKED`, `_INVALIDATED`; dispatch typed events with the correct actor (grantor / grantee / system per the spec) and the relationship as object. Confirm no key material or secret value enters any entry (extend the whitelist only with non-sensitive relationship keys).
- [x] 4.2 Add `NotificationService` subjects: grantor notified on `emergency_access.requested` (actionable veto) and on `emergency_access.accessed`; wire a decline action from the request notification. Add a user-setting toggle category consistent with the existing `SUBJECT_SETTING_MAP` pattern.

## 5. Frontend

- [x] 5.1 User-settings pane: designate/revoke emergency contacts (pick a Nextcloud user with an active suite), set the wait period; build the recovery envelope client-side (hybrid-encrypt the grantor's private key to the grantee's public cert; discard raw key bytes after).
- [x] 5.2 Grantee flow: "request emergency access" against a grantor who granted it; after approval, fetch the envelope, decrypt with the grantee's own in-session private key, recover the grantor's private key in-browser, and present a read-only view of the grantor's vault.
- [x] 5.3 Grantor decline action (from the notification / settings) within the wait window; re-establish prompt after a key change.

## 6. Tests

- [x] 6.1 vitest: the recovery envelope is built in-browser (hybrid AES-256-GCM + RSA-OAEP to grantee cert); the raw private key never appears in any HTTP request body; only the grantee-encrypted envelope is sent.
- [x] 6.2 vitest: a grantee decrypts an envelope with their own private key and recovers the grantor's private key; a non-grantee cannot.
- [x] 6.3 PHPUnit: state machine — designate→granted; request→requested + grantor notified; decline before deadline→no release; elapse→approved; fetch gated on approved + grantee (wrong state and wrong caller both refused identically); revoke deletes envelope + cancels request.
- [x] 6.4 PHPUnit: suite rotation invalidates envelopes + audits `invalidated`; suite revocation clears envelopes; grantee suite revocation invalidates envelopes to that grantee.
- [x] 6.5 PHPUnit: each lifecycle transition dispatches exactly one audit event with the correct actor; no entry contains the envelope, private key, or a secret value (assert against the forbidden-key whitelist).
- [x] 6.6 PHPUnit: grantor is notified on request and on access; the fetch-envelope route rejects unauthenticated and non-grantee callers.

## 7. Quality Gates

- [x] 7.1 `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) passes; fix pre-existing issues in touched files in the same batch.
- [x] 7.2 Run hydra gates on the diff (route-auth, route-reachability, no-admin-idor / semantic-auth on the new controller, spec-coverage `@spec openspec/changes/add-emergency-access/...`, notification-dialect, forbidden-patterns).
- [x] 7.3 Frontend lint + vitest pass.

## Acceptance Criteria

- A grantor can designate one or more emergency contacts (grantees with active suites) with a configurable wait period and `view` access; a grantee without a suite is rejected.
- The recovery envelope is built entirely client-side (grantor's private key hybrid-encrypted to the grantee's public cert); the server stores only the grantee-encrypted envelope and never a usable key.
- A break-glass request notifies the grantor and starts the wait timer; the grantor can decline within the window and release nothing.
- On timeout without decline the request becomes `approved`; the envelope is released only to the named grantee only when `approved`; the grantee recovers the grantor's key in their own browser and can read the vault; the grantor is notified on access.
- Revoking a contact deletes the envelope and cancels any pending request.
- Suite rotation invalidates envelopes (prompt re-establish); suite revocation clears them; a revoked grantee suite invalidates envelopes to that grantee.
- Every lifecycle event is audited with the correct actor and no key/secret material; the grantor is notified on request and access.
- The vault remains zero-knowledge — the server gains no new ability to read any secret.
