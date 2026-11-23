## MODIFIED Requirements

### Requirement: Owner updates reach the remote copy and revocation removes it

When the owner updates a federated shared secret, the owner's browser MUST encrypt the new value for each federated recipient with a freshly verified certificate and send it in the owner's share-save, and the sending server MUST send an OCM `SHARE_UPDATED` notification after which the receiving server pulls the new ciphertext. When the owner changes only the plain name or URL, the sending server MUST send `SHARE_UPDATED` to each live federated recipient without new ciphertext, one notification per recipient, so the copy follows. Revoking MUST send `SHARE_UNSHARED`, after which the receiving server MUST delete the copy. A share whose partner was removed, whose recipient certificate no longer verifies, or whose lookup answers for another cloud id than the share's, MUST be suspended and shown to the owner. When the secret passes to a new owner, its federated shares MUST pass with it in the same transaction, and the sending server MUST NOT serve a federated share whose owner is not the secret's owner.

#### Scenario: A password change reaches Bob

- **GIVEN** a federated share accepted by Bob
- **WHEN** Alice changes the password in her vault
- **THEN** Bob's copy MUST show the new password after his server pulls the update

#### Scenario: A new name reaches Bob

- **GIVEN** a federated share accepted by Bob
- **WHEN** Alice renames the secret or changes its URL, and nothing else
- **THEN** Alice's server MUST send one `SHARE_UPDATED` for Bob's share
- **AND** Bob's copy MUST show the new name or URL after his server pulls the update

#### Scenario: Revocation removes Bob's copy

- **GIVEN** a federated share accepted by Bob
- **WHEN** Alice revokes it
- **THEN** Bob's server MUST delete the copy from Bob's vault

#### Scenario: Bob's share follows the secret to a new owner

- **GIVEN** a federated share accepted by Bob
- **WHEN** Alice's secret passes to Dave, through Alice's delegation when her account is deleted or through team offboarding
- **THEN** Bob's share MUST be Dave's, so Dave can revoke or suspend it
- **AND** Dave's next share-save of the secret MUST write Bob's share, whether Dave saves the source or his own copy of it
- **AND** Bob's server MUST keep pulling the share

### Requirement: Partner copies change only with the owner's share-save

A partner share's ciphertext MUST change in the same transaction as the owner's row, through a share-save of the secret's owner. The owner's share-save is one made by the user who owns the source, whether they save the source or their own copy of it; a write-grade copy editor who does not own the source MUST NOT write partner shares. Writing a partner share MUST store `SHARE_UPDATED` as its pending notification inside that transaction, and the sending server MUST deliver it only after the commit; a delivery that fails MUST stay queued for the retry job. A partner share revoked meanwhile MUST NOT be written, and its pending `SHARE_UNSHARED` MUST NOT be replaced. A partner share whose certificate fails verification in the browser MUST be suspended in the same transaction when the browser lists it under `suspend`.

#### Scenario: A failed save tells the partner nothing

- **GIVEN** a secret shared with Bob on a partner instance
- **WHEN** Alice's share-save is rolled back
- **THEN** Bob's share MUST keep its old ciphertext and Bob's server MUST NOT be notified

#### Scenario: A delivery that fails is retried

- **GIVEN** Alice's share-save committed a new value for Bob's partner share
- **WHEN** the notification to Bob's server fails
- **THEN** the share MUST stay pending, and the retry job MUST send `SHARE_UPDATED` later

#### Scenario: A copy editor's save leaves partner shares to the owner

- **GIVEN** Carol holds a write grade on Alice's secret, which is also shared with Bob on a partner instance
- **WHEN** Carol saves a new password
- **THEN** Bob's share MUST keep its old ciphertext
- **AND** Carol MUST be told that partners receive the change with Alice's next save

#### Scenario: The owner's save through their own copy reaches partners

- **GIVEN** Dave owns a secret whose source is still encrypted for its previous owner, holds a copy of it, and the secret is shared with Bob on a partner instance
- **WHEN** Dave saves a new password through his copy
- **THEN** the share-save MUST require Bob's share, and Dave's browser MUST look up and verify Bob's certificate and send Bob's new ciphertext
- **AND** Bob's share MUST change in the same transaction as the source and every copy
- **AND** Dave MUST NOT be told that partners receive the change with the owner's next save

## ADDED Requirements

### Requirement: A partner share the owner does not own stops the save

On every share-save that writes partner shares, an active partner share of the source whose owner is not the source's owner MUST make the server answer 409 with `error: partner_share_owner_mismatch` and change nothing. The server MUST log a warning naming the secret and those shares. The web app and the browser extension MUST show the refusal as an error that says an administrator must repair the partner shares before the change can be saved. The `ReownTransferredPartnerShares` repair step hands such a share to the secret's owner.

#### Scenario: A partner share left with the previous owner

- **GIVEN** Dave owns a secret, and one of its active partner shares is still owned by Alice
- **WHEN** Dave share-saves a new password
- **THEN** the server MUST answer 409 `partner_share_owner_mismatch` and write nothing
- **AND** the server MUST log the secret id and the share id
- **AND** once the repair step handed the share to Dave, the same save MUST write it

### Requirement: A received copy is not shared with a partner

The system MUST refuse to create a partner share whose source is a recipient's copy of another secret, with 400 `not_shareable`, the refusal `register-batch` gives a received copy. This holds for a delegate's copy too. When the pending quarantine scan cannot judge the share row that would make the secret a copy, the system MUST answer 409 with `error: share_scan_pending` and create nothing. A share row the scan quarantines MUST NOT make its secret a copy.

#### Scenario: Bob may not send his copy to a partner

- **GIVEN** Bob holds a full copy of Alice's secret
- **WHEN** Bob shares that copy with a user on a partner instance
- **THEN** the system MUST refuse it and create no partner share

#### Scenario: The owner's original is still shared

- **GIVEN** Alice owns a secret that is not a copy of another secret
- **WHEN** Alice shares it with a user on a partner instance
- **THEN** the partner share MUST be created as before

### Requirement: Partner shares made from a copy are reported

A partner share whose source is a copy was made before such shares were refused, and no share-save writes it. A share-save MUST answer, as `federated.notReached`, the number of active partner shares whose source is a copy the save wrote: the editor's own copy on the copy-editor path, and every copy written. The source's own partner shares MUST NOT be counted. The answer MUST NOT name those partners or shares. The web app and the browser extension MUST tell the user, when the number is above zero, that this many recipients at partner organisations got the secret from a copy and did not get the change.

#### Scenario: A partner share hanging off Bob's copy

- **GIVEN** Bob shared his copy of Alice's secret with a partner before such shares were refused
- **WHEN** Alice share-saves a new password
- **THEN** the save MUST commit and answer `federated.notReached: 1`
- **AND** the partner share of Bob's copy MUST keep its old ciphertext
- **AND** Alice MUST be told that one recipient at a partner organisation got the secret from a copy and did not get the change
