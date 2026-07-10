---
status: proposed
---

# Emergency Access

## Purpose

Mitigate Doriath's own documented highest-severity risk ("Master password lost = data lost", `docs/FEATURES.md:359`) with a zero-knowledge-compatible emergency-access mechanism: an owner designates a trusted contact in advance; the contact can request access; after an owner-cancellable wait period, access is granted — without the server ever holding plaintext key material.

## ADDED Requirements

### Requirement: Emergency contact designation requires two-phase confirmation

Doriath SHALL let a vault owner register an emergency contact with a configured wait period and access level, and SHALL require a separate client-side confirmation step (performed against the contact's then-current EncryptionSuite public key) before any key material is transmitted.

#### Scenario: Registration alone grants no access

- **GIVEN** an owner registers a new emergency contact with a 48-hour wait period
- **WHEN** the registration completes
- **THEN** the contact record status MUST be `pending-confirmation` and the contact MUST NOT be able to request or receive access until the owner separately confirms

#### Scenario: Confirmation wraps key material for the contact's current key only

- **GIVEN** a `pending-confirmation` emergency contact record
- **WHEN** the owner confirms it
- **THEN** the owner's browser MUST fetch the contact's currently active EncryptionSuite public key, re-encrypt the relevant key material against it client-side, and only the resulting ciphertext MUST be sent to the server; the record status becomes `active`

#### Scenario: Self-designation is rejected

- **GIVEN** an authenticated user
- **WHEN** they attempt to register themselves as their own emergency contact
- **THEN** the request MUST be rejected with an error, and no record MUST be created

### Requirement: Access requests start an owner-cancellable wait period

Doriath SHALL let a designated, active emergency contact request access to an owner's vault, notify the owner immediately, and start a wait-period timer that the owner can cancel at any time before it elapses.

#### Scenario: Owner is notified immediately on request

- **GIVEN** an active emergency contact relationship
- **WHEN** the contact submits an access request
- **THEN** the owner MUST receive a notification (in-app + configured channel) within the same request cycle, including a direct reject action

#### Scenario: Owner rejection immediately blocks the request

- **GIVEN** a `requested` emergency access request
- **WHEN** the owner rejects it before the wait period elapses
- **THEN** the request status MUST become `rejected` and the contact MUST NOT gain any access, regardless of elapsed time

#### Scenario: Non-contact cannot request access

- **GIVEN** a user with no active emergency-contact relationship to a given owner
- **WHEN** they attempt to submit an access request for that owner
- **THEN** the request MUST be rejected with a 403/forbidden response

### Requirement: Unrejected requests auto-grant on wait-period expiry

Doriath SHALL run a background job that transitions an emergency access request from `requested` to `granted` once its configured wait period has elapsed without an owner rejection, and grant the configured access level (`view` or `takeover`).

#### Scenario: Expiry job grants access after the wait period

- **GIVEN** a `requested` emergency access request with an 24-hour wait period, requested at time T
- **WHEN** the background job runs at T+25h and the request has not been rejected or cancelled
- **THEN** the request status MUST become `granted` and the contact MUST subsequently be able to read the owner's secrets (for `view` level) via the existing secret-listing/read endpoints

#### Scenario: Expiry job does not touch requests still within their wait period

- **GIVEN** a `requested` emergency access request with a 48-hour wait period, requested 10 hours ago
- **WHEN** the background job runs
- **THEN** the request status MUST remain `requested`

### Requirement: Granted view access is scoped per-object, alongside owner access

Doriath's secret-read authorization SHALL treat a `granted` emergency access request as an additional valid identity for the named owner's secrets, without introducing a parallel access-control mechanism.

#### Scenario: Contact with granted view access can read the owner's secrets

- **GIVEN** a `granted` emergency access request naming a contact for a specific owner, level `view`
- **WHEN** the contact calls the secret list/read endpoints
- **THEN** the response MUST include the owner's secrets, decrypted client-side using the key material wrapped for the contact at confirmation time

#### Scenario: Contact without a granted request cannot read another owner's secrets

- **GIVEN** no `granted` emergency access request exists between a given contact and owner
- **WHEN** the contact calls the secret read endpoint for one of that owner's secret ids
- **THEN** the response MUST be 403/forbidden, identical to the existing non-owner behavior

### Requirement: Every emergency-access state transition is auditable

Doriath SHALL dispatch typed events for request, rejection, and grant so the (separately specced) audit-trail change can persist them.

#### Scenario: Grant dispatches a typed event with no key material

- **GIVEN** an emergency access request transitions to `granted`
- **WHEN** the transition occurs
- **THEN** an `EmergencyAccessGrantedEvent` MUST be dispatched carrying only owner id, contact id, request id, and timestamps — never key material or secret content
