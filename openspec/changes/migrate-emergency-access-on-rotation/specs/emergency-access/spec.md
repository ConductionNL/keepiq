## MODIFIED Requirements

### Requirement: Envelope Invalidation on Key Change
Because the recovery envelope escrows the grantor's private key as of designation, a change to that key MUST be reflected in the envelopes bound to it.

When the grantor's EncryptionSuite is rotated (compromise recovery), the system MUST migrate each affected recovery envelope where the grantee is reachable: it MUST build a fresh envelope escrowing the grantor's **new** private key, sealed to the grantee's current certificate, and re-point the contact to the new suite while preserving its `granted` state. A contact whose grantee has no active certificate to seal to (the grantee left the instance or revoked their suite) cannot be migrated; the system MUST invalidate that residual contact and MUST tell the grantor it was removed, as set out below. The grantor MUST NOT be required to open the old envelope to do any of this — building a new envelope needs only the new private key, which the grantor holds during rotation, and the grantee's public certificate.

Carrying a contact hands the grantor's **new** key to that grantee, and a compromise recovery runs precisely when someone else may have held the grantor's session. So the carry MUST be the grantor's explicit choice, not a side effect (keepiq#800):

- Before the rotation starts, the system MUST show the grantor the contacts that can be carried and MUST carry only the ones the grantor confirms. None MUST be preselected.
- Only a contact in state `granted` MUST be carried. A contact with a break-glass `requested` or `approved` MUST NOT be carried: the server MUST refuse it, and it is left on the old suite for the completion sweep to invalidate. The grantor re-designates it if they still want it.
- Each re-envelope MUST carry a verified key proof made with the migration's **new** key (see the `vault-key-proof` capability), because it overwrites the contact's envelope. Not the old key: every migration is a compromise recovery, and the old password may be the leaked one. The new key is held by the party who started the migration, so this rules out a leaked password used against a rotation the owner started, but not a rotation the holder of the session and old password started themselves: starting one is proven with the active key.
- A carry MUST be audited as its own event, distinct from a fresh designation, so a carry cannot be mistaken for a planted designation after an incident.

Contacts the grantor did not confirm, or that were refused, are invalidated at completion like any other residual contact. The completion sweep records `grantor_rotation_in_flight` for a contact whose break-glass was in flight and `grantor_rotation` for every other residual contact. It MUST NOT guess the grantor's choice from reachability. The grantor MUST be told about every contact a rotation removed, however the rotation reached completion:

- **A rotation run to completion from the recovery form** knows which contacts the grantor ticked. Its completion screen MUST prompt the grantor to re-establish an unreachable contact (no active certificate, or a failed re-envelope), and MUST name unconfirmed and in-flight contacts without that prompt.
- **A resumed rotation** carries no contact and doesn't know the ticks. After completion it MUST read back the contacts the sweep removed. The recovery form's completion screen MUST name them neutrally, without a prompt to re-establish, and the resume banner MUST say how many were removed and point to Emergency Access.
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

#### Scenario: Suite rotation invalidates only the unreachable residual
@e2e exclude Server-side listener sweep after the migration loop; covered by PHPUnit (contacts remaining on the old suite are invalidated) and the completion-summary assertion.
- **GIVEN** A has emergency contacts B (active suite) and C (no active suite)
- **WHEN** A performs compromise recovery and rotates their EncryptionSuite
- **THEN** B MUST be migrated to the new suite
- **AND** C MUST be invalidated
- **AND** A MUST be prompted to re-establish C specifically, on the recovery form's completion screen
- **AND** the Emergency Access view MUST NOT offer to re-establish C afterwards

#### Scenario: A resumed rotation names the contacts it removed
@e2e exclude Client-side read-back after completion; covered by vitest on the store, CompromiseRecoveryForm, MigrationResumeBanner and EmergencyAccessView.
- **GIVEN** A's rotation was interrupted before any emergency contact was carried, and A has contact B, `granted`, on the old suite
- **WHEN** A resumes the rotation and it completes
- **THEN** B MUST be invalidated at completion
- **AND** A MUST be told that B was removed, on the completion screen or, from the resume banner, as a count pointing to Emergency Access
- **AND** A MUST NOT be prompted to re-establish B
- **AND** the Emergency Access view MUST show that a key rotation removed B, with no re-establish action

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
