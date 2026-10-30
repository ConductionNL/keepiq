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
- **THEN** Bob's share MUST be Dave's, so Dave can revoke or suspend it; an owner share-save of the secret writes it, a save through Dave's own copy does not
- **AND** Bob's server MUST keep pulling the share

### Requirement: Remote copies are read-only

Read-only covers the value and the sharing, not where the recipient files the copy. The system MUST refuse, for its owner, any change of a read-only secret's value, login, additional fields, name, URL or type, a sync, onward sharing and link-share creation. The system MUST allow a change of only its folder. When the recipient declines a pending share, or moves an accepted copy to the trash or deletes it for good, the receiving server MUST mark the inbound share declined and send OCM `SHARE_DECLINED` to the owner's instance. The owner's share MUST then show as declined, and the sending server MUST NOT serve it or send further notifications for it. Two cases differ: a suspended share MUST stay suspended, and a share whose revocation is still waiting to reach the recipient, or was given up, MUST be removed, because the decline completes that revocation. When the recipient restores a copy whose share they declined by deleting it, the receiving server MUST send OCM `SHARE_ACCEPTED` to the owner's instance. When the owner's instance takes it, the inbound share MUST be accepted again, the owner's share MUST be live again, the copy MUST pull the value the share last received, and the owner's next save MUST reach it. When the owner revoked the share, suspended it, removed it, or shared the secret with the recipient again in the meantime, the copy MUST stay read-only with its last value, the restore MUST say that the share has ended, and the system MUST NOT give the recipient any access the owner did not grant.

#### Scenario: Bob cannot edit or pass it on

- **GIVEN** a read-only federated copy in Bob's vault
- **WHEN** Bob calls `PUT /api/v1/secrets/{id}` with a new name, value, login or fields, or tries to share it
- **THEN** the system MUST refuse the request with a forbidden response

#### Scenario: Bob files his copy in a folder

- **GIVEN** a read-only federated copy in Bob's vault and a folder of Bob's
- **WHEN** Bob moves the copy to that folder
- **THEN** the system MUST store the new folder and leave the value, name and sharing as the owner sent them

#### Scenario: Bob deletes his copy

- **GIVEN** a federated share accepted by Bob
- **WHEN** Bob moves his copy to the trash
- **THEN** Bob's server MUST mark the share declined and send `SHARE_DECLINED` to Alice's instance
- **AND** Alice's share MUST show as declined, and her later changes MUST NOT be sent to Bob

#### Scenario: Bob deletes his copy while Alice's revocation is waiting

- **GIVEN** a share Alice revoked, whose unshare hasn't reached Bob's server yet
- **WHEN** Bob moves his copy to the trash
- **THEN** Alice's share MUST be removed, and no unshare MUST be sent to Bob any more

#### Scenario: Bob declines a pending share

- **GIVEN** a pending federated share from Alice under Bob's "Incoming from other organisations"
- **WHEN** Bob declines it
- **THEN** Bob's server MUST send `SHARE_DECLINED` to Alice's instance
- **AND** Alice's share MUST show as declined at once and MUST NOT be retried

#### Scenario: Bob restores his copy

- **GIVEN** a copy Bob moved to the trash, whose share is declined on both sides
- **WHEN** Bob restores the copy from the trash
- **THEN** Bob's server MUST send `SHARE_ACCEPTED` to Alice's instance and mark the share accepted again
- **AND** Alice's share MUST leave "declined" and her later changes MUST reach Bob
- **AND** Bob's copy MUST show the value the share last received, and Alice's next save MUST reach it

#### Scenario: The owner revoked the share meanwhile

- **GIVEN** a copy Bob moved to the trash, and Alice revoked the share afterwards
- **WHEN** Bob restores the copy from the trash
- **THEN** the copy MUST come back read-only with the value it had
- **AND** the restore MUST tell Bob that the share has ended
- **AND** Alice's share MUST stay revoked

## ADDED Requirements

### Requirement: Partner copies change only with the owner's share-save

A partner share's ciphertext MUST change in the same transaction as the owner's row, through the owner's share-save; a write-grade copy editor MUST NOT write partner shares. Writing a partner share MUST store `SHARE_UPDATED` as its pending notification inside that transaction, and the sending server MUST deliver it only after the commit; a delivery that fails MUST stay queued for the retry job. A partner share revoked meanwhile MUST NOT be written, and its pending `SHARE_UNSHARED` MUST NOT be replaced. A partner share whose certificate fails verification in the browser MUST be suspended in the same transaction when the browser lists it under `suspend`.

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

### Requirement: An unreachable partner stops the save unless the user saves without them

When the partner certificate lookup for an active partner share answers that the partner can't be reached, the web app MUST save nothing and MUST tell the owner which partners it couldn't reach, offering to save without them. Saving without them MUST suspend those partner shares in the same share-save; a suspended share stays suspended until the owner revokes it or shares again, and the web app MUST say so before the owner confirms. A decline or accept from the recipient's server MUST NOT change a suspended share. A local server error or a lost connection MUST abort the save with an ordinary error and MUST NOT offer to suspend anything. `unknown_recipient`, `not_a_partner` and `bad_chain` count as the share being gone only with the status this server sends them with (404, 403 and 502); with any other status they are a local server error. When the lookup is rate-limited, the web app MUST wait as long as the server asks, keep what it already encrypted and continue, and MUST abort with its own error after five minutes of waiting in total. Certificates verified during a failed save MAY be reused for up to five minutes by the save that follows it, and MUST be forgotten when the vault locks.

#### Scenario: Bob's server is down

- **GIVEN** a secret shared with Bob on a partner instance that doesn't answer
- **WHEN** Alice saves a new password
- **THEN** nothing MUST be saved, and Alice MUST be told Bob's instance can't be reached
- **AND** Alice MUST be told that saving without Bob suspends his share until she revokes it or shares again
- **AND** when Alice chooses to save without Bob, the save MUST go through with Bob's share suspended

#### Scenario: Bob's server cannot end a suspension

- **GIVEN** Alice's share with Bob is suspended
- **WHEN** Bob's server sends `SHARE_DECLINED` for it, and later `SHARE_ACCEPTED`
- **THEN** Alice's share MUST stay suspended, and Bob's server MUST NOT be able to pull it
- **AND** Alice's audit trail MUST record Bob's decline, marked as keeping the share suspended

#### Scenario: A local error never suspends a partner

- **GIVEN** a secret shared with Bob on a partner instance
- **WHEN** Alice's own server answers the certificate lookup with an error
- **THEN** the save MUST fail with an ordinary error and Bob's share MUST stay active

#### Scenario: A gone reason with another status is a local error

- **GIVEN** a secret shared with Bob on a partner instance
- **WHEN** a proxy in front of Alice's server answers the certificate lookup with a 500 whose message is `not_a_partner`
- **THEN** the save MUST fail with an ordinary error and Bob's share MUST stay active

#### Scenario: Many partner shares wait out the lookup limit

- **GIVEN** a secret shared with 70 partner shares
- **WHEN** Alice saves a new password and the lookup is rate-limited after 60 partners
- **THEN** the dialog MUST say it is waiting, and the save MUST go through in one share-save once the limit allows the remaining lookups
