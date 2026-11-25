## MODIFIED Requirements

### Requirement: Failed entries are kept, never dropped silently

A replay answered with 403 or 404, a share-save refused with 400, a share-save stopped by a partner (one that can't be reached, whose certificate no longer matches the root pinned here, or to which outbound sharing is turned off), by the partner lookup limit or by a partner certificate this device's clock rejects, or a share-save of a copy the user may only view (user-sharing, "View-Only Copies") MUST move the entry to a failed list where the user can copy their values (decrypted in the browser) or discard the entry, with the reason shown next to it, also after a reload. For a share-save stopped by partners, the entry MUST keep each partner's cloud id with its own reason and name each partner with that reason. Offline the web app doesn't know a copy's grade, so it lets the user edit any copy; the replay MUST refuse a value change of a view-only copy in the browser, before anything is sent. An entry stopped by a partner, the lookup limit or this device's clock MUST also offer to try again; one refused as view-only MUST NOT, as no retry can save it. A 423 or a network error MUST keep the entry queued. A failed entry MUST NOT stop the replay of the entries after it.

#### Scenario: Write grade removed while offline

- **GIVEN** a team-folder member who edited a folder secret offline, and whose grade was lowered to read meanwhile
- **WHEN** the replay runs
- **THEN** the replay MUST send nothing for the entry and write neither the member's copy, the source nor any other copy
- **AND** the entry MUST move to the failed list with the reason that the secret is shared with the member to view only, with copy and discard actions and without one to try again

#### Scenario: A refused share-save is kept

- **GIVEN** a queued edit of a shared secret
- **WHEN** the replay's share-save is refused with 403 or 400
- **THEN** the entry MUST appear under "Changes that could not sync" with copy and discard actions

#### Scenario: An unreachable partner doesn't hold up the queue

- **GIVEN** two queued edits, the first of a secret shared with a partner instance that doesn't answer
- **WHEN** the replay runs
- **THEN** the first entry MUST move to the failed list with the reason that the partner can't be reached
- **AND** the second entry MUST be replayed

#### Scenario: A partner certificate the clock rejects doesn't hold up the queue

- **GIVEN** two queued edits, the first of a secret shared with a partner whose certificate this device's clock places outside its validity
- **WHEN** the replay runs
- **THEN** the first entry MUST move to the failed list with the reason that the partner's certificate is not valid by this device's clock, with copy, discard and try-again actions
- **AND** the second entry MUST be replayed

#### Scenario: Each partner keeps its own reason

- **GIVEN** a queued edit of a secret shared with Bob on `cloud.partner.example`, whose certificate no longer matches the root pinned here, and with Carol on `cloud.other.example`, to which outbound sharing is turned off
- **WHEN** the replay runs
- **THEN** the entry MUST move to the failed list naming Bob's organisation with the certificate reason and Carol's with the reason that sharing to it is turned off
- **AND** after a reload the entry MUST still name both with their reasons
