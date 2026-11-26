## MODIFIED Requirements

### Requirement: Administrators approve and pin partner instances

The system MUST let an administrator, after Nextcloud password confirmation, add a partner instance by URL, MUST read and show the partner's Keepiq root certificate fingerprint from its discovery document for confirmation, and MUST store the pinned fingerprint with separate outbound and inbound permissions. The system MUST let an administrator re-pin a partner the same way: after Nextcloud password confirmation, it MUST read the partner's discovery document again, MUST show the published fingerprint next to the pinned one with a warning that it replaces the root this server trusts for that partner, MUST ask the administrator to confirm that the partner's administrator confirmed the new fingerprint out of band, and MUST store it only when it is still the one the administrator confirmed and the partner's host has not changed. Reading a discovery document MUST NOT follow a redirect; a redirect MUST be refused as a partner that could not be reached. With no partner, the system MUST NOT offer, send or accept any federated share. On a Nextcloud version below 33 the section MUST explain that federation needs Nextcloud 33 and MUST keep federation off.

#### Scenario: Administrator adds a partner

- **GIVEN** an administrator on the federation section of the Keepiq admin settings on Nextcloud 33
- **WHEN** they add `https://cloud.partner.example`, compare the shown fingerprint with the partner's administrator, and allow outbound and inbound
- **THEN** the partner MUST be stored with the pinned fingerprint and both permissions

#### Scenario: Administrator re-pins a renewed partner root

- **GIVEN** a partner `cloud.partner.example` whose administrator renewed its Keepiq root
- **WHEN** an administrator here chooses Re-pin on that partner, compares the shown new fingerprint with the partner's administrator, and confirms it with their password
- **THEN** the partner MUST be stored with the new fingerprint, keeping its outbound and inbound permissions
- **AND** the audit log MUST record the re-pin with the partner, the old and new fingerprint and the administrator
- **AND** the next save of a secret shared to a user there MUST verify that user's certificate against the new fingerprint

#### Scenario: The published root changed after the preview

- **GIVEN** an administrator who opened Re-pin on a partner and was shown fingerprint A
- **WHEN** the partner publishes fingerprint B before the administrator confirms A
- **THEN** the re-pin MUST be refused and the pinned fingerprint MUST stay as it was

#### Scenario: Nothing to re-pin

- **GIVEN** a partner that still publishes the pinned fingerprint
- **WHEN** an administrator chooses Re-pin on it
- **THEN** the system MUST say the partner still publishes the pinned root and MUST NOT offer to confirm

#### Scenario: No partner, no federation

- **GIVEN** an instance with no partner
- **WHEN** a vault owner opens the share dialog
- **THEN** the dialog MUST NOT offer a federated recipient

### Requirement: Owner updates reach the remote copy and revocation removes it

When the owner updates a federated shared secret, the owner's browser MUST encrypt the new value for each federated recipient with a freshly verified certificate and send it in the owner's share-save, and the sending server MUST send an OCM `SHARE_UPDATED` notification after which the receiving server pulls the new ciphertext. When the owner changes only the plain name or URL, the sending server MUST send `SHARE_UPDATED` to each live federated recipient without new ciphertext, one notification per recipient, so the copy follows. Revoking MUST send `SHARE_UNSHARED`, after which the receiving server MUST delete the copy. When the shared secret is deleted for good, by purging it from the trash, by the trash retention job or by deleting the owner's account, the sending server MUST revoke each of its partner shares the same way, and keep retrying the `SHARE_UNSHARED` for at least 30 days while the partner cannot be reached (no answer, a 5xx or a 429), with no more than 6 hours between attempts. Only after 30 days without success MAY it drop the share, recording the failure in the audit trail. When the partner refuses it outright, with a 4xx other than 429, at the 6th attempt or any later one, the sending server MUST drop the share at that attempt, recording the failure in the audit trail the same way, and MUST NOT send it again. The same holds for a user's copy of a secret shared with them that is deleted for good together with its share, because the owner revokes that share, trashes or purges the secret, ends the team-folder share or the user leaves the group it came through: the partner shares that user made of the copy MUST be revoked before the copy goes, each `SHARE_UNSHARED` left to the retry job. On such a deletion, a share the recipient declined, and a share whose partner was removed, MUST be removed without a notification and recorded as revoked in the audit trail, except a share already revoked, whose pending `SHARE_UNSHARED` is left to the retry job; a suspended share whose partner is still one is revoked and told like any other. A pending `SHARE_UNSHARED` whose partner was removed after it was queued MUST NOT be sent again: the retry job MUST drop the share and record the failure in the audit trail. When a `SHARE_UNSHARED` arrives for a share the receiving server has already ended, for example because the answer to an earlier one was lost, the receiving server MUST answer it as taken once the shared secret and the partner's signature check out, and MUST NOT count it as a failed request; any other notification for an ended share MUST still be refused. A share whose partner was removed, whose recipient certificate no longer verifies for a reason other than an untrusted root or a certificate not valid at the browser's current time, or whose lookup answers for another cloud id than the share's, MUST be suspended and shown to the owner. When the secret passes to a new owner, its federated shares MUST pass with it in the same transaction, and the sending server MUST NOT serve a federated share whose owner is not the secret's owner.

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

#### Scenario: A removed partner suspends the share

- **GIVEN** a secret shared with Bob on `cloud.partner.example`
- **WHEN** an administrator removes that partner and Alice then saves a new password
- **THEN** Alice's lookup MUST answer `not_a_partner` with 403
- **AND** the save MUST go through with Bob's share suspended

### Requirement: An unreachable partner stops the save unless the user saves without them

When the partner certificate lookup for an active partner share answers that the partner can't be reached, that sharing to that partner is turned off (`outbound_disabled`), or a certificate whose chain does not end at the pinned partner root (`untrusted_root`), the owner's browser, in the web app and in the browser extension, MUST save nothing and MUST tell the owner which partners it couldn't save for and why, offering to save without them. Saving without them MUST suspend those partner shares in the same share-save; a suspended share stays suspended until the owner resumes it, revokes it or shares again, and the browser MUST say before the owner confirms that the share stops. When a partner certificate is not valid at the browser's current time (`not_valid_now`), the browser MUST save nothing, MUST ask the owner to check the device's date and time, and MUST NOT offer to save without that partner. An accept from the recipient's server MUST NOT change a suspended share. A decline from the recipient's server MUST end a suspended share as declined and drop its ciphertext, so that neither a later accept nor a resume can make it live again (decision of 25 Nov 2026). A local server error or a lost connection MUST abort the save with an ordinary error and MUST NOT offer to suspend anything. `unknown_recipient`, `not_a_partner` and `bad_chain` count as the share being gone, and `outbound_disabled` as sharing to that partner being turned off, only with the status this server sends them with (404, 403, 502 and 403); with any other status they are a local server error. The lookup, the batch lookup and creating a new partner share MUST answer `outbound_disabled` with 403 when the recipient's host is a partner whose outbound sharing is off. For a host that is no partner, the lookups MUST answer `not_a_partner` with 403 and creating a share MUST answer it with 400. When the lookup is rate-limited, the browser MUST wait as long as the server asks, keep what it already encrypted and continue, and MUST abort with its own error after five minutes of waiting in total. Certificates verified during a failed save MAY be reused for up to five minutes by the save that follows it, and MUST be forgotten when the vault locks.

#### Scenario: Bob's server is down

- **GIVEN** a secret shared with Bob on a partner instance that doesn't answer
- **WHEN** Alice saves a new password
- **THEN** nothing MUST be saved, and Alice MUST be told Bob's instance can't be reached
- **AND** Alice MUST be told that saving without Bob suspends his share until she revokes it or shares again
- **AND** when Alice chooses to save without Bob, the save MUST go through with Bob's share suspended

#### Scenario: Bob's partner renewed its root

- **GIVEN** a secret shared with Bob on `cloud.partner.example`, whose administrator renewed its Keepiq root after it was pinned here
- **WHEN** Alice saves a new password, in the web app or in the browser extension
- **THEN** nothing MUST be saved, Bob's share MUST stay active, and Alice MUST be told that the partner's certificate no longer matches the one pinned here, that this can mean the partner renewed it, and that an administrator has to check it with the partner
- **AND** when an administrator has re-pinned the partner, Alice's next save MUST go through with Bob's share updated

#### Scenario: Sharing to Bob's partner is turned off

- **GIVEN** a secret shared with Bob on `cloud.partner.example`
- **WHEN** an administrator turns outbound sharing off for that partner and Alice then saves a new password
- **THEN** the lookup MUST answer `outbound_disabled` with 403, nothing MUST be saved, Bob's share MUST stay active, and Alice MUST be told that sharing to that organisation is turned off
- **AND** when the administrator turns outbound sharing on again, Alice's next save MUST go through with Bob's share updated

#### Scenario: A new share to a partner with outbound off

- **GIVEN** a partner `cloud.partner.example` whose outbound sharing is off, while another partner is outbound
- **WHEN** Alice shares a secret with Bob on `cloud.partner.example`
- **THEN** the server MUST answer `outbound_disabled` with 403, no share MUST be created, and the share form MUST say that sharing to that organisation is turned off

#### Scenario: Alice's clock is wrong

- **GIVEN** a secret shared with Bob on a partner instance, and Alice's device clock set a year ahead
- **WHEN** Alice saves a new password in the web app
- **THEN** nothing MUST be saved, Bob's share MUST stay active, and Alice MUST be asked to check her device's date and time
- **AND** Alice MUST NOT be offered to save without Bob

#### Scenario: Bob's server cannot end a suspension

- **GIVEN** Alice's share with Bob is suspended
- **WHEN** Bob's server sends `SHARE_ACCEPTED` for it
- **THEN** Alice's share MUST stay suspended, and Bob's server MUST NOT be able to pull it

#### Scenario: A decline while suspended ends the share

- **GIVEN** Alice's share with Bob is suspended
- **WHEN** Bob's server sends `SHARE_DECLINED` for it, and later `SHARE_ACCEPTED`
- **THEN** Alice's share MUST be declined and MUST hold no ciphertext any more
- **AND** the accept MUST be answered "share not found", and Bob's server MUST NOT be able to pull the share
- **AND** Alice MUST NOT be able to resume it; she shares again if Bob still needs it
- **AND** Alice's audit trail MUST record Bob's decline, marked as made while the share was suspended

#### Scenario: A local error never suspends a partner

- **GIVEN** a secret shared with Bob on a partner instance
- **WHEN** Alice's own server answers the certificate lookup with an error
- **THEN** the save MUST fail with an ordinary error and Bob's share MUST stay active

#### Scenario: A gone reason with another status is a local error

- **GIVEN** a secret shared with Bob on a partner instance
- **WHEN** a proxy in front of Alice's server answers the certificate lookup with a 500 whose message is `not_a_partner` or `outbound_disabled`
- **THEN** the save MUST fail with an ordinary error and Bob's share MUST stay active

#### Scenario: Many partner shares wait out the lookup limit

- **GIVEN** a secret shared with 250 partner shares, looked up in batches of 100
- **WHEN** Alice saves a new password and the lookup is rate-limited before the third batch
- **THEN** the dialog MUST say it is waiting, and the save MUST go through in one share-save once the limit allows the remaining batch
