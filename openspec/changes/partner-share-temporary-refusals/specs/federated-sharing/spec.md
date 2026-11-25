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

When the owner updates a federated shared secret, the owner's browser MUST encrypt the new value for each federated recipient with a freshly verified certificate and send it in the owner's share-save, and the sending server MUST send an OCM `SHARE_UPDATED` notification after which the receiving server pulls the new ciphertext. When the owner changes only the plain name or URL, the sending server MUST send `SHARE_UPDATED` to each live federated recipient without new ciphertext, one notification per recipient, so the copy follows. Revoking MUST send `SHARE_UNSHARED`, after which the receiving server MUST delete the copy. A share whose partner was removed, whose recipient certificate no longer verifies for a reason other than an untrusted root or a certificate not valid at the browser's current time, or whose lookup answers for another cloud id than the share's, MUST be suspended and shown to the owner. When the secret passes to a new owner, its federated shares MUST pass with it in the same transaction, and the sending server MUST NOT serve a federated share whose owner is not the secret's owner.

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

#### Scenario: A removed partner suspends the share

- **GIVEN** a secret shared with Bob on `cloud.partner.example`
- **WHEN** an administrator removes that partner and Alice then saves a new password
- **THEN** Alice's lookup MUST answer `not_a_partner` with 403
- **AND** the save MUST go through with Bob's share suspended

### Requirement: An unreachable partner stops the save unless the user saves without them

When the partner certificate lookup for an active partner share answers that the partner can't be reached, that sharing to that partner is turned off (`outbound_disabled`), or a certificate whose chain does not end at the pinned partner root (`untrusted_root`), the owner's browser, in the web app and in the browser extension, MUST save nothing and MUST tell the owner which partners it couldn't save for and why, offering to save without them. Saving without them MUST suspend those partner shares in the same share-save; a suspended share stays suspended until the owner revokes it or shares again, and the browser MUST say so before the owner confirms. When a partner certificate is not valid at the browser's current time (`not_valid_now`), the browser MUST save nothing, MUST ask the owner to check the device's date and time, and MUST NOT offer to save without that partner. A decline or accept from the recipient's server MUST NOT change a suspended share. A local server error or a lost connection MUST abort the save with an ordinary error and MUST NOT offer to suspend anything. `unknown_recipient`, `not_a_partner` and `bad_chain` count as the share being gone, and `outbound_disabled` as sharing to that partner being turned off, only with the status this server sends them with (404, 403, 502 and 403); with any other status they are a local server error. The lookup, the batch lookup and creating a new partner share MUST answer `outbound_disabled` with 403 when the recipient's host is a partner whose outbound sharing is off. For a host that is no partner, the lookups MUST answer `not_a_partner` with 403 and creating a share MUST answer it with 400. When the lookup is rate-limited, the browser MUST wait as long as the server asks, keep what it already encrypted and continue, and MUST abort with its own error after five minutes of waiting in total. Certificates verified during a failed save MAY be reused for up to five minutes by the save that follows it, and MUST be forgotten when the vault locks.

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
- **WHEN** Bob's server sends `SHARE_DECLINED` for it, and later `SHARE_ACCEPTED`
- **THEN** Alice's share MUST stay suspended, and Bob's server MUST NOT be able to pull it
- **AND** Alice's audit trail MUST record Bob's decline, marked as keeping the share suspended

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
