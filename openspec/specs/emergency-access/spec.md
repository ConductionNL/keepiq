# emergency-access Specification

## Purpose
TBD - created by archiving change add-emergency-access. Update Purpose after archive.

## Requirements

### Requirement: Designate Emergency Contact
The system MUST allow a vault owner (grantor), while their vault is unlocked, to designate one or more Nextcloud users as emergency contacts. Each designation MUST record the grantee, an access level, and a wait period. The **v1 access level MUST be `view`** (the contact may read the grantor's vault); account takeover is out of scope for v1. The wait period MUST be grantor-configurable (at minimum the options 1, 3, 7, and 30 days; default 7).

A grantee MUST have an active EncryptionSuite; designating a user with no active suite MUST fail with a clear error (the recovery envelope is encrypted to the grantee's public certificate and cannot be built otherwise).

Designation MUST require a verified key proof (see the `vault-key-proof` capability), bound to the grantee, the wait period and the recovery envelope. A designation names who a later rotation escrows the grantor's private key to, and re-designating an existing contact replaces its envelope. With a session alone, an attacker could plant their own account as a grantee and receive the grantor's next key (keepiq#800), or overwrite an envelope and destroy break-glass, which the proof on revocation exists to prevent.

#### Scenario: Designate a contact with a wait period
@e2e exclude State-machine/authorization contract — covered by PHPUnit EmergencyAccessServiceTest.
- **GIVEN** grantor A is unlocked and user B has an active EncryptionSuite
- **WHEN** A designates B as an emergency contact with access level `view` and a 7-day wait period, carrying a verified key proof
- **THEN** the system MUST record the emergency-contact relationship in state `granted`
- **AND** it MUST record the access level and wait period

#### Scenario: Grantee without an EncryptionSuite is rejected
@e2e exclude State-machine/authorization contract — covered by PHPUnit EmergencyAccessServiceTest.
- **GIVEN** user B has never opened Keepiq and has no EncryptionSuite
- **WHEN** grantor A attempts to designate B as an emergency contact
- **THEN** the system MUST return an error indicating the grantee has no encryption suite
- **AND** no emergency-contact relationship MUST be created

#### Scenario: Designation without a key proof is refused
@e2e exclude Middleware enforcement on a session-authenticated route; not DOM-observable. Covered by PHPUnit on the middleware and the attribute-coverage test.
- **GIVEN** an authenticated session for A, and no key proof
- **WHEN** a designation of any grantee, or a re-designation of an existing contact, is requested
- **THEN** the system MUST refuse with `428` and `error: key_proof_required`
- **AND** no contact MUST be created, and an existing contact's envelope MUST be unchanged

### Requirement: Client-Side Recovery Envelope Escrow
On designation, the grantor's browser MUST build the recovery envelope entirely client-side: the grantor's EncryptionSuite private key MUST be hybrid-encrypted (a fresh random AES-256-GCM key encrypts the private key; that AES key is RSA-encrypted to the grantee's public certificate). The raw private-key material MUST exist only transiently in the grantor's browser and MUST be discarded after the envelope is built. The server MUST store only the grantee-encrypted envelope ciphertext and MUST NEVER receive the grantor's private key or a usable key. The grantor's private key MUST NOT be transmitted to the server in any form other than the grantee-encrypted envelope.

#### Scenario: Envelope is built in the browser and only ciphertext is stored
@e2e exclude Client-side WebCrypto escrow — asserting the private key is re-encrypted to the grantee's public cert in-browser and that only the grantee-encrypted envelope (never the raw key) reaches the server is a crypto/wire-shape assertion, not a DOM flow; covered by vitest (envelope builder + no-raw-key-in-request guard).
- **GIVEN** grantor A designates emergency contact B while unlocked
- **WHEN** the recovery envelope is created
- **THEN** the grantor's private key MUST be hybrid-encrypted to B's public certificate in A's browser
- **AND** only the grantee-encrypted envelope ciphertext MUST be sent to and stored by the server
- **AND** the raw private-key material MUST be discarded from the browser after the envelope is built

### Requirement: Break-Glass Request and Wait Timer
The system MUST allow a designated emergency contact to initiate a break-glass request against a grantor who granted them access. Initiating a request MUST move the relationship to state `requested`, record the request time, start the grantor's configured wait period, and notify the grantor. No key material MUST be released at request time.

#### Scenario: Contact requests emergency access
@e2e exclude State-machine/authorization contract — covered by PHPUnit EmergencyAccessServiceTest (designate/request/decline/approve-by-timeout + the approved+grantee release gate with identical wrong-state/wrong-caller refusal). This waiver covers only that server-side state machine, which is not DOM-observable. The DOM flow itself is not excluded, it is uncovered: src/views/EmergencyAccessView.vue is routed at /emergency-access, has an "Emergency access" menu entry and carries data-testid hooks (emergency-access-view, emergency-access-designate, emergency-grantee-input, emergency-wait-select, emergency-master-input), and the E2E Tests (Playwright) job provisions its own throwaway Nextcloud seeded by tests/e2e/ci-seed.sh. A Playwright spec for it is open work and nothing here claims one exists.
- **GIVEN** grantor A has designated B as an emergency contact with a 7-day wait period
- **WHEN** B initiates a break-glass request
- **THEN** the relationship MUST move to state `requested` with the request time recorded and the 7-day timer started
- **AND** the grantor MUST be notified of the request
- **AND** no recovery envelope MUST be released to B

### Requirement: Grantor Decline (Veto)
At any time before the wait period elapses, the grantor MUST be able to decline a pending break-glass request. Declining MUST move the relationship out of `requested` (to `declined` or back to `granted`), MUST NOT release the recovery envelope, and MUST be recordable together with an optional revocation of the contact.

#### Scenario: Grantor declines within the wait window
@e2e exclude State-machine/authorization contract — covered by PHPUnit EmergencyAccessServiceTest (designate/request/decline/approve-by-timeout + the approved+grantee release gate with identical wrong-state/wrong-caller refusal). This waiver covers only that server-side state machine, which is not DOM-observable. The DOM flow itself is not excluded, it is uncovered: src/views/EmergencyAccessView.vue is routed at /emergency-access, has an "Emergency access" menu entry and carries data-testid hooks (emergency-access-view, emergency-access-designate, emergency-grantee-input, emergency-wait-select, emergency-master-input), and the E2E Tests (Playwright) job provisions its own throwaway Nextcloud seeded by tests/e2e/ci-seed.sh. A Playwright spec for it is open work and nothing here claims one exists.
- **GIVEN** B has a break-glass request pending against A and the wait period has not elapsed
- **WHEN** A declines the request
- **THEN** the request MUST be rejected and no recovery envelope MUST be released to B
- **AND** the relationship MUST leave the `requested` state

### Requirement: Approval by Timeout and Grantee View Access
If the wait period elapses on a `requested` relationship without the grantor declining, the system MUST transition it to `approved`. The server MUST release the recovery envelope to the grantee **only** when the relationship is `approved` and the caller is the named grantee; it MUST refuse the envelope in any other state or to any other caller. Once released, the grantee decrypts the envelope with their **own** in-session private key to recover the grantor's private key in their browser, and MAY then read (view) the grantor's secrets. The grantor MUST be notified when the grantee actually accesses the vault.

#### Scenario: Timer elapses and the grantee gains view access
@e2e exclude State-machine/authorization contract — covered by PHPUnit EmergencyAccessServiceTest (designate/request/decline/approve-by-timeout + the approved+grantee release gate with identical wrong-state/wrong-caller refusal). This waiver covers only that server-side state machine, which is not DOM-observable. The DOM flow itself is not excluded, it is uncovered: src/views/EmergencyAccessView.vue is routed at /emergency-access, has an "Emergency access" menu entry and carries data-testid hooks (emergency-access-view, emergency-access-designate, emergency-grantee-input, emergency-wait-select, emergency-master-input), and the E2E Tests (Playwright) job provisions its own throwaway Nextcloud seeded by tests/e2e/ci-seed.sh. A Playwright spec for it is open work and nothing here claims one exists.
- **GIVEN** B has a `requested` relationship against A and the 7-day wait period has elapsed with no decline
- **WHEN** the request is evaluated
- **THEN** the relationship MUST transition to `approved`
- **AND** B MUST be able to fetch the recovery envelope and decrypt it with B's own private key to read A's secrets
- **AND** A MUST be notified when B accesses the vault

#### Scenario: Envelope is refused before approval
@e2e exclude State-machine/authorization contract — covered by PHPUnit EmergencyAccessServiceTest (designate/request/decline/approve-by-timeout + the approved+grantee release gate with identical wrong-state/wrong-caller refusal). This waiver covers only that server-side state machine, which is not DOM-observable. The DOM flow itself is not excluded, it is uncovered: src/views/EmergencyAccessView.vue is routed at /emergency-access, has an "Emergency access" menu entry and carries data-testid hooks (emergency-access-view, emergency-access-designate, emergency-grantee-input, emergency-wait-select, emergency-master-input), and the E2E Tests (Playwright) job provisions its own throwaway Nextcloud seeded by tests/e2e/ci-seed.sh. A Playwright spec for it is open work and nothing here claims one exists.
- **GIVEN** a break-glass request that is still `requested` (wait period not elapsed) or has been `declined`
- **WHEN** the grantee attempts to fetch the recovery envelope
- **THEN** the server MUST refuse to release the envelope

#### Scenario: Envelope is refused to a non-grantee
@e2e exclude State-machine/authorization contract — covered by PHPUnit EmergencyAccessServiceTest (designate/request/decline/approve-by-timeout + the approved+grantee release gate with identical wrong-state/wrong-caller refusal). This waiver covers only that server-side state machine, which is not DOM-observable. The DOM flow itself is not excluded, it is uncovered: src/views/EmergencyAccessView.vue is routed at /emergency-access, has an "Emergency access" menu entry and carries data-testid hooks (emergency-access-view, emergency-access-designate, emergency-grantee-input, emergency-wait-select, emergency-master-input), and the E2E Tests (Playwright) job provisions its own throwaway Nextcloud seeded by tests/e2e/ci-seed.sh. A Playwright spec for it is open work and nothing here claims one exists.
- **GIVEN** an `approved` emergency-access relationship between grantor A and grantee B
- **WHEN** a user other than B attempts to fetch the recovery envelope
- **THEN** the server MUST refuse to release the envelope

### Requirement: Revoke Emergency Contact
The grantor MUST be able to revoke an emergency contact at any time. Revocation MUST delete the recovery envelope and cancel any pending request, and a revoked contact MUST NOT be able to break glass until re-designated (which rebuilds a fresh envelope).

Revocation MUST require a verified key proof (see the `vault-key-proof` capability). The recovery envelope is the only copy of the grantor's private key that survives a replacement of the stored envelope, which makes it the last recovery path out of an account lockout. An attacker holding the grantor's session would otherwise be able to delete the safety net first and destroy the vault second, using the same authority for both.

The requirement is on the grantor-initiated revocation of a designated contact. Envelope clearing that follows from suite revocation or rotation is a consequence of those operations, is governed by *Envelope Invalidation on Key Change*, and is not separately gated here.

#### Scenario: Revoked contact cannot break glass
@e2e exclude State-machine/authorization contract — covered by PHPUnit EmergencyAccessServiceTest (designate/request/decline/approve-by-timeout + the approved+grantee release gate with identical wrong-state/wrong-caller refusal). This waiver covers only that server-side state machine, which is not DOM-observable. The DOM flow itself is not excluded, it is uncovered: src/views/EmergencyAccessView.vue is routed at /emergency-access, has an "Emergency access" menu entry and carries data-testid hooks (emergency-access-view, emergency-access-designate, emergency-grantee-input, emergency-wait-select, emergency-master-input), and the E2E Tests (Playwright) job provisions its own throwaway Nextcloud seeded by tests/e2e/ci-seed.sh. A Playwright spec for it is open work and nothing here claims one exists.
- **GIVEN** A has designated B as an emergency contact
- **WHEN** A revokes B
- **THEN** the recovery envelope MUST be deleted and any pending request cancelled
- **AND** B MUST be unable to initiate or complete a break-glass request until re-designated

#### Scenario: Revocation without a key proof is refused
@e2e exclude Middleware enforcement on a session-authenticated route; not DOM-observable. Covered by PHPUnit on the middleware and the attribute-coverage test.
- **GIVEN** A has designated B as an emergency contact
- **AND** an authenticated session for A
- **WHEN** revocation is requested without a verified key proof
- **THEN** the system MUST refuse with `428` and `error: key_proof_required`
- **AND** the recovery envelope MUST be unchanged and still usable

### Requirement: Envelope Invalidation on Key Change
Because the recovery envelope escrows the grantor's private key as of designation, a change to that key MUST be reflected in the envelopes bound to it.

When the grantor's EncryptionSuite is rotated (compromise recovery), the system MUST migrate each affected recovery envelope where the grantee is reachable: it MUST build a fresh envelope escrowing the grantor's **new** private key, sealed to the grantee's current certificate, and re-point the contact to the new suite while preserving its `granted` state. A contact whose grantee has no active certificate to seal to (the grantee left the instance or revoked their suite) cannot be migrated; the system MUST invalidate that residual contact and MUST tell the grantor it was removed, as set out below. The grantor MUST NOT be required to open the old envelope to do any of this — building a new envelope needs only the new private key, which the grantor holds during rotation, and the grantee's public certificate.

Carrying a contact hands the grantor's **new** key to that grantee, and a compromise recovery runs precisely when someone else may have held the grantor's session. So the carry MUST be the grantor's explicit choice, not a side effect (keepiq#800):

- Before the rotation starts, the system MUST show the grantor the contacts that can be carried and MUST carry only the ones the grantor confirms. None MUST be preselected.
- Only a contact in state `granted` MUST be carried. A contact with a break-glass `requested` or `approved` MUST NOT be carried: the server MUST refuse it, and it is left on the old suite for the completion sweep to invalidate. The grantor re-designates it if they still want it.
- Each re-envelope MUST carry a verified key proof made with the migration's **new** key (see the `vault-key-proof` capability), because it overwrites the contact's envelope. Not the old key: every migration is a compromise recovery, and the old password may be the leaked one. The new key is held by the party who started the migration, so this rules out a leaked password used against a rotation the owner started, but not a rotation the holder of the session and old password started themselves: starting one is proven with the active key.
- A carry MUST be audited as its own event, distinct from a fresh designation, so a carry cannot be mistaken for a planted designation after an incident.

Contacts the grantor did not confirm, or that were refused, are invalidated at completion like any other residual contact. The completion sweep records `grantor_rotation_in_flight` for a contact whose break-glass was in flight and `grantor_rotation` for every other residual contact. It MUST NOT guess the grantor's choice from reachability. The grantor MUST be told about every contact a rotation removed, however the owner's rotation reached completion (initiate, resume, retry or loss acknowledgement). An administrator's compromise force-revoke that ends an open migration is not one of these: it revokes both suites, and revocation clears the contacts as described below. Telling the owner on that path is tracked in keepiq#876.

- **A rotation started from the recovery form** knows which contacts the grantor ticked, also when the form completes it by retrying or by accepting a loss. Its completion screen MUST prompt the grantor to re-establish an unreachable contact (no active certificate, or a failed re-envelope), and MUST name unconfirmed and in-flight contacts without that prompt. A contact whose break-glass was in flight when the rotation completed counts as in flight, also when it was requested after the grantor confirmed.
- **A resumed rotation** carries no contact and doesn't know the ticks. After completion, including a completion by accepting a loss, it MUST read back the contacts the sweep removed. The recovery form's completion screen MUST name them neutrally, without a prompt to re-establish. When the resume banner completes the rotation, the banner MUST say how many were removed and point to Emergency Access; it MUST warn separately, without a nudge to add it back, about a contact whose break-glass was in flight. The neutral note MUST NOT claim the rotation was resumed, because an initiate run whose contacts could not be listed reaches the same screen.
- **The Emergency Access view** MUST NOT offer to re-establish any contact a rotation invalidated. It MUST show a text-only notice on each one that the rotation removed it, and MUST warn about one whose break-glass was in flight. This also covers a completion screen that was closed unread. A `declined` contact has nothing in flight and counts as not confirmed. The system MUST NOT prompt the grantor to re-establish a contact they did not confirm, and MUST warn, rather than prompt, about a contact whose break-glass was in flight, because that is what a planted contact looks like.

Migrating rather than invalidating is possible because the recovery envelope is rebuilt, not re-wrapped: `buildRecoveryEnvelope` takes the grantor's private key and the grantee's public certificate, both of which the grantor has mid-rotation. Sealing to the grantee's *current* certificate is also more correct than preserving the old envelope, which may escrow a key the grantee has since rotated away from.

When the grantor's EncryptionSuite is revoked, existing recovery envelopes MUST be cleared. Revocation is not a key rotation and produces no new key to migrate to, so unlike rotation there is nothing to migrate the envelope to. But clearing is destructive and irreversible — `clearForGrantorRevocation` deletes the rows outright — and revocation of a user suite is the last-resort route for an owner who has lost their master password, exactly the owner most likely to still need their emergency contact. The system MUST therefore treat this clearing as a decision the acting administrator makes knowingly, not a silent side effect:

- Before revoking a user suite that has a usable (non-invalidated) emergency contact, the system MUST warn plainly that every secret becomes permanently unreadable and the vault is rebuilt from scratch, that the designated emergency access is **deleted** along with it, and that if an emergency accessor exists they MUST retrieve the old secrets first, while the old suite is still `active`.
- The system MUST refuse the revocation while a usable emergency contact exists, unless the caller supplies an explicit override. The refusal MUST surface the **count** of usable contacts so the administrator can choose — never their identities, which stay grantor-private. Today the deletion is silent and the count is not surfaced; that is the gap this closes.
- With the override, revocation proceeds and clears the envelopes as before. The ordering is enforceable, not merely documented.

Likewise, if a grantee's EncryptionSuite is revoked, envelopes encrypted to that grantee MUST be invalidated; this is unchanged.

#### Scenario: Suite rotation migrates a reachable contact
@e2e exclude Server-side re-point plus client-side envelope construction; verifying the migrated envelope opens requires the grantee's key in a second browser context. Covered by PHPUnit on the re-point endpoint and unit tests of the envelope builder.
- **GIVEN** A has an emergency contact B in state `granted` whose EncryptionSuite is active
- **AND** a recovery envelope escrowing A's current private key
- **WHEN** A performs compromise recovery, confirms that B is to be carried, and rotates their EncryptionSuite
- **THEN** the system MUST build a fresh recovery envelope escrowing A's new private key, sealed to B's current certificate
- **AND** re-point the contact to A's new suite with its state still `granted`
- **AND** MUST NOT prompt A to re-establish B

#### Scenario: A contact with a break-glass in flight is not carried
@e2e exclude Server-side state refusal and listener sweep; covered by PHPUnit on EmergencyEnvelopeInvalidationService and the completion sweep.
- **GIVEN** A has an emergency contact B whose break-glass is `requested` or `approved`
- **WHEN** A performs compromise recovery and rotates their EncryptionSuite
- **THEN** the system MUST NOT escrow A's new private key to B
- **AND** B MUST be invalidated at completion, and A MUST be warned about B rather than prompted to re-establish B

#### Scenario: An unconfirmed contact is not carried
@e2e exclude The confirmation list is component state; covered by vitest on CompromiseRecoveryForm and the store.
- **GIVEN** A has emergency contacts B and C, both `granted` with active suites
- **WHEN** A performs compromise recovery and confirms only B
- **THEN** only B MUST receive an envelope escrowing A's new private key
- **AND** C MUST be invalidated at completion, without a prompt to re-establish C
- **AND** the Emergency Access view MUST NOT offer to re-establish C afterwards

#### Scenario: The Emergency Access view warns about an in-flight contact
@e2e exclude The view renders server-recorded reasons; covered by vitest on EmergencyAccessView and PHPUnit on the completion sweep.
- **GIVEN** A's contact B was invalidated at completion because its break-glass was in flight
- **WHEN** A opens the Emergency Access view
- **THEN** B MUST be shown with a warning and without a Re-establish action

#### Scenario: Suite rotation invalidates envelopes
@e2e exclude Server-side listener sweep after the migration loop; covered by PHPUnit (contacts remaining on the old suite are invalidated) and the completion-summary assertion.
- **NOTE** kept under its original name so the archive replaces the old scenario: rotation now invalidates only the residual a rotation did not carry
- **GIVEN** A has emergency contacts B (active suite, confirmed by A for the carry) and C (no active suite)
- **WHEN** A performs compromise recovery and rotates their EncryptionSuite
- **THEN** B MUST be migrated to the new suite
- **AND** C MUST be invalidated
- **AND** A MUST be prompted to re-establish C specifically, on the recovery form's completion screen
- **AND** the Emergency Access view MUST NOT offer to re-establish C afterwards

#### Scenario: Retrying from the recovery form keeps the grantor's choices
@e2e exclude The residual list is component state; covered by vitest on CompromiseRecoveryForm.
- **GIVEN** A started compromise recovery from the recovery form, left contact C unticked, and a record failed
- **WHEN** A retries from the form and the rotation completes
- **THEN** the completion screen MUST name C as not confirmed, without a prompt to re-establish C
- **AND** MUST NOT describe the rotation as resumed

#### Scenario: A resumed rotation names the contacts it removed
@e2e exclude Client-side read-back after completion; covered by vitest on the store, CompromiseRecoveryForm, MigrationResumeBanner and EmergencyAccessView.
- **GIVEN** A's rotation was interrupted before any emergency contact was carried, and A has contact B, `granted`, on the old suite
- **WHEN** A resumes the rotation and it completes
- **THEN** B MUST be invalidated at completion
- **AND** A MUST be told that B was removed, on the completion screen or, from the resume banner, as a count pointing to Emergency Access
- **AND** A MUST NOT be prompted to re-establish B
- **AND** the Emergency Access view MUST show that a key rotation removed B, with no re-establish action

#### Scenario: A resumed rotation finished by accepting a loss names the contacts it removed
@e2e exclude Client-side read-back after the loss acknowledgement; covered by vitest on the store and CompromiseRecoveryForm.
- **GIVEN** A resumed an interrupted rotation, a record still failed, and A has contact B, `granted`, on the old suite
- **WHEN** A accepts the loss and the rotation completes
- **THEN** B MUST be invalidated at completion
- **AND** the recovery form's completion screen MUST name B, without a prompt to re-establish B

#### Scenario: Revocation refuses while a usable emergency contact exists
@e2e exclude Server-side guard on the revoke path; covered by PHPUnit asserting revocation is refused and the usable-contact count is returned. Live UI run deferred.
- **GIVEN** A has a usable (non-invalidated) emergency contact
- **WHEN** an administrator revokes A's suite without an override
- **THEN** the system MUST refuse and MUST report the count of usable emergency contacts
- **AND** MUST NOT clear any recovery envelope or change the suite status
- **AND** MUST NOT disclose the contact's identity

#### Scenario: Revocation proceeds with an explicit override and warns
@e2e exclude Server-side guard plus the destructive clear; covered by PHPUnit on the revoke path with the override flag. The warning copy is asserted in the settings-dialog component test.
- **GIVEN** A has a usable emergency contact and the administrator has been shown the destruction warning
- **WHEN** the administrator revokes A's suite with the explicit override
- **THEN** the suite MUST be revoked and the recovery envelopes cleared
- **AND** the warning MUST have stated that emergency access is deleted and that an accessor must retrieve secrets first while the suite is still active

#### Scenario: Suite revocation clears envelopes
@e2e exclude Server-side suite rotation/revocation listener contract — covered by PHPUnit (invalidateForGrantorRotation/clearForGrantorRevocation/invalidateForGranteeRevocation + invalidated audit). Live UI run deferred (worktree not deployed).
- **GIVEN** A has one or more emergency contacts with recovery envelopes
- **WHEN** A's EncryptionSuite is revoked
- **THEN** the recovery envelopes MUST be cleared

### Requirement: Emergency contact designation requires two-phase confirmation

Keepiq SHALL let a vault owner register an emergency contact with a configured wait period and access level, and SHALL require a separate client-side confirmation step (performed against the contact's then-current EncryptionSuite public key) before any key material is transmitted.

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

Keepiq SHALL let a designated, active emergency contact request access to an owner's vault, notify the owner immediately, and start a wait-period timer that the owner can cancel at any time before it elapses.

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

Keepiq SHALL run a background job that transitions an emergency access request from `requested` to `granted` once its configured wait period has elapsed without an owner rejection, and grant the configured access level (`view` or `takeover`).

#### Scenario: Expiry job grants access after the wait period

- **GIVEN** a `requested` emergency access request with an 24-hour wait period, requested at time T
- **WHEN** the background job runs at T+25h and the request has not been rejected or cancelled
- **THEN** the request status MUST become `granted` and the contact MUST subsequently be able to read the owner's secrets (for `view` level) via the existing secret-listing/read endpoints

#### Scenario: Expiry job does not touch requests still within their wait period

- **GIVEN** a `requested` emergency access request with a 48-hour wait period, requested 10 hours ago
- **WHEN** the background job runs
- **THEN** the request status MUST remain `requested`

### Requirement: Granted view access is scoped per-object, alongside owner access

Keepiq's secret-read authorization SHALL treat a `granted` emergency access request as an additional valid identity for the named owner's secrets, without introducing a parallel access-control mechanism.

#### Scenario: Contact with granted view access can read the owner's secrets

- **GIVEN** a `granted` emergency access request naming a contact for a specific owner, level `view`
- **WHEN** the contact calls the secret list/read endpoints
- **THEN** the response MUST include the owner's secrets, decrypted client-side using the key material wrapped for the contact at confirmation time

#### Scenario: Contact without a granted request cannot read another owner's secrets

- **GIVEN** no `granted` emergency access request exists between a given contact and owner
- **WHEN** the contact calls the secret read endpoint for one of that owner's secret ids
- **THEN** the response MUST be 403/forbidden, identical to the existing non-owner behavior

### Requirement: Every emergency-access state transition is auditable

Keepiq SHALL dispatch typed events for request, rejection, and grant so the (separately specced) audit-trail change can persist them.

#### Scenario: Grant dispatches a typed event with no key material

- **GIVEN** an emergency access request transitions to `granted`
- **WHEN** the transition occurs
- **THEN** an `EmergencyAccessGrantedEvent` MUST be dispatched carrying only owner id, contact id, request id, and timestamps — never key material or secret content
