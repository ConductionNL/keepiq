## MODIFIED Requirements

### Requirement: Owner updates reach the remote copy and revocation removes it

When the owner updates a federated shared secret, the owner's browser MUST encrypt the new value for each federated recipient with a freshly verified certificate and send it in the owner's share-save, and the sending server MUST send an OCM `SHARE_UPDATED` notification after which the receiving server pulls the new ciphertext. When the owner changes only the plain name or URL, the sending server MUST send `SHARE_UPDATED` to each live federated recipient without new ciphertext, one notification per recipient, so the copy follows. Revoking MUST send `SHARE_UNSHARED`, after which the receiving server MUST delete the copy. When the shared secret is deleted for good, by purging it from the trash, by the trash retention job or by deleting the owner's account, the sending server MUST revoke each of its partner shares the same way, and keep retrying the `SHARE_UNSHARED` for at least 30 days while the partner cannot be reached (no answer, a 5xx or a 429), with no more than 6 hours between attempts. Only after 30 days without success MAY it drop the share, recording the failure in the audit trail. When the partner refuses it outright, with a 4xx other than 429, at the 6th attempt or any later one, the sending server MUST drop the share at that attempt, recording the failure in the audit trail the same way, and MUST NOT send it again. The same holds for a user's copy of a secret shared with them that is deleted for good together with its share, because the owner revokes that share, trashes or purges the secret, ends the team-folder share or the user leaves the group it came through: the partner shares that user made of the copy MUST be revoked before the copy goes, each `SHARE_UNSHARED` left to the retry job. On such a deletion, a share the recipient declined, and a share whose partner was removed, MUST be removed without a notification and recorded as revoked in the audit trail, except a share already revoked, whose pending `SHARE_UNSHARED` is left to the retry job; a suspended share whose partner is still one is revoked and told like any other. A pending `SHARE_UNSHARED` whose partner was removed after it was queued MUST NOT be sent again: the retry job MUST drop the share and record the failure in the audit trail. When a `SHARE_UNSHARED` arrives for a share the receiving server has already ended, for example because the answer to an earlier one was lost, the receiving server MUST answer it as taken once the shared secret and the partner's signature check out, and MUST NOT count it as a failed request; any other notification for an ended share MUST still be refused. A share whose partner was removed, whose recipient certificate no longer verifies, or whose lookup answers for another cloud id than the share's, MUST be suspended and shown to the owner. When the secret passes to a new owner, its federated shares MUST pass with it in the same transaction, and the sending server MUST NOT serve a federated share whose owner is not the secret's owner.

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

#### Scenario: Deleting the secret for good removes Bob's copy

- **GIVEN** a federated share accepted by Bob
- **WHEN** Alice's secret is deleted for good, because she purges it from the trash, the retention job purges it or her account is deleted
- **THEN** Alice's server MUST send `SHARE_UNSHARED` for Bob's share, and retry it for at least 30 days while Bob's server cannot be reached
- **AND** Bob's server MUST delete the copy from Bob's vault

#### Scenario: A removed partner is not told

- **GIVEN** a federated share to Bob, suspended or failed, whose organisation the administrator removed as a partner
- **WHEN** Alice's secret is deleted for good
- **THEN** Alice's server MUST remove the share and record it as revoked in the audit trail
- **AND** Alice's server MUST NOT queue or send a `SHARE_UNSHARED` to Bob's server
- **AND** when Bob's organisation is removed only after a `SHARE_UNSHARED` for one of its shares was queued, the retry job MUST drop that share without sending it, recording the failure in the audit trail

#### Scenario: A received copy deleted for good ends its partner shares

- **GIVEN** Carol shared a secret with Bob, and Bob shared his full-access copy with a partner recipient
- **WHEN** Bob's copy is deleted for good with its share, because Carol revokes the share, trashes or purges her secret, ends the team-folder share, or Bob leaves the group the share came through
- **THEN** Bob's partner share MUST be revoked before his copy goes, with `SHARE_UNSHARED` pending
- **AND** the retry job MUST send it, so the partner deletes its copy

#### Scenario: An unreachable partner is retried for 30 days

- **GIVEN** a federated share accepted by Bob, whose server is down for a day
- **WHEN** Alice's secret is deleted for good
- **THEN** Alice's server MUST keep retrying the `SHARE_UNSHARED`, at most 6 hours apart, and MUST NOT give it up after a fixed number of attempts
- **AND** once Bob's server is back and takes it, Bob's copy MUST be deleted
- **AND** only a share still not taken after 30 days MAY be dropped, with the failure recorded in the audit trail

#### Scenario: A partner that refuses the unshare is not retried for 30 days

- **GIVEN** a federated share accepted by Bob, whose server answers every `SHARE_UNSHARED` for it with a 400
- **WHEN** Alice's secret is deleted for good
- **THEN** Alice's server MUST drop the share after the 6th refused attempt, with the failure recorded in the audit trail
- **AND** Alice's server MUST NOT send that `SHARE_UNSHARED` again

#### Scenario: A repeated unshare is taken

- **GIVEN** Bob's server deleted his copy after a `SHARE_UNSHARED`, but its answer never reached Alice's server
- **WHEN** Alice's server sends the `SHARE_UNSHARED` again
- **THEN** Bob's server MUST answer it as taken, so Alice's server stops retrying
- **AND** Bob's server MUST NOT count it as a failed request against Alice's server

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

The system MUST refuse to create a partner share whose source is a recipient's copy of another secret, with 400 `not_shareable`, the refusal `register-batch` gives a received copy. This holds for a delegate's copy too. When the caller asks to share their own received copy of a secret they own, as the new owner after a team offboarding or an account deletion does, the system MUST create the partner share on the source that the trusted share row names, not on the copy; it MUST NOT take that source from the request. When the pending quarantine scan cannot judge the share row that would make the secret a copy, the system MUST answer 409 with `error: share_scan_pending` and create nothing. A share row the scan quarantines MUST NOT make its secret a copy.

#### Scenario: Bob may not send his copy to a partner

- **GIVEN** Bob holds a full copy of Alice's secret
- **WHEN** Bob shares that copy with a user on a partner instance
- **THEN** the system MUST refuse it and create no partner share

#### Scenario: The new owner shares their own copy with a partner

- **GIVEN** Dave became the owner of Alice's secret through a transfer and works through his copy of it
- **WHEN** Dave shares his copy with a user on a partner instance
- **THEN** the partner share MUST be created on the source, with the source as its source secret
- **AND** the partner share MUST be listed with the partner shares of Dave's copy, next to any made from that copy before such shares were refused, and Dave MUST be able to revoke it there
- **AND** a copy holder who does not own the source MUST see only the partner shares of their own copy
- **AND** when Dave's copy still has an active partner share to that recipient, made before such shares were refused, the system MUST refuse the new one with `already_shared` until Dave revokes it
- **AND** a copy holder who does not own the source MUST still be refused with `not_shareable`, and no partner share created

#### Scenario: The owner's original is still shared

- **GIVEN** Alice owns a secret that is not a copy of another secret
- **WHEN** Alice shares it with a user on a partner instance
- **THEN** the partner share MUST be created as before

### Requirement: Partner shares made from a copy are reported

A partner share whose source is a copy was made before such shares were refused, and no share-save writes it. A share-save MUST answer, as `federated.notReached`, the number of active partner shares whose source is a copy the save wrote. For the source's owner, on the owner path or through their own copy, that is every copy written, their own included. For a caller who does not own the source it is only their own copy: the partner shares of the other copies MUST NOT be counted for them, because those are other holders' to know, for the same reason the source's partner shares are reported to them only as `ownerOnly`. The source's own partner shares MUST NOT be counted. The answer MUST NOT name those partners or shares. The web app and the browser extension MUST tell the user, when the number is above zero, that this many recipients at partner organisations got the secret from a copy and did not get the change.

#### Scenario: A partner share hanging off Bob's copy

- **GIVEN** Bob shared his copy of Alice's secret with a partner before such shares were refused
- **WHEN** Alice share-saves a new password
- **THEN** the save MUST commit and answer `federated.notReached: 1`
- **AND** the partner share of Bob's copy MUST keep its old ciphertext
- **AND** Alice MUST be told that one recipient at a partner organisation got the secret from a copy and did not get the change

#### Scenario: A copy editor is told only about their own copy

- **GIVEN** Bob and Carol each shared their copy of Alice's secret with a partner before such shares were refused
- **AND** Bob may write Alice's secret but does not own it
- **WHEN** Bob share-saves a new password through his copy
- **THEN** the save MUST commit and answer `federated.notReached: 1`, for the partner share of Bob's own copy
- **AND** the partner share of Carol's copy MUST NOT be counted in Bob's answer

#### Scenario: The extension tells the user on every path that saves an item

- **GIVEN** Bob shared his copy of Alice's login with a partner before such shares were refused
- **WHEN** Alice updates the login's password from the notification bar, saves a passkey to the login, or signs in with a passkey of the login, whose counter save is a share-save
- **THEN** the bar or the passkey window MUST say that one recipient at a partner organisation got the login from a copy and did not get the change, in the words of the popup's item note
- **AND** it MUST stay open until Alice closes it, instead of closing on its own
- **AND** the bar and the passkey window MUST also show the partner shares the save suspended or paused

#### Scenario: A non-owner editor hears of the owner's next save from the extension, except on a sign-in

- **GIVEN** Carol is the delegate of Alice, and Alice's login is shared with a partner organisation
- **WHEN** Carol updates the login's password from the notification bar, or saves a passkey to the login
- **THEN** the bar or the passkey window MUST say that recipients at partner organisations get the change when the owner next saves it, and stay open until Carol closes it
- **AND** when Carol signs in with an imported passkey of the login, the window MUST NOT say so, since the counter save is no change of hers; it MUST show only a count of recipients who got the login from a copy and the partner shares the save suspended or paused, and close as before when there are none
