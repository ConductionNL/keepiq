## MODIFIED Requirements

### Requirement: Owner updates reach the remote copy and revocation removes it

When the owner updates a federated shared secret, the owner's browser MUST encrypt the new value for each federated recipient with a freshly verified certificate and send it in the owner's share-save, and the sending server MUST send an OCM `SHARE_UPDATED` notification after which the receiving server pulls the new ciphertext. When the owner changes only the plain name or URL, the sending server MUST send `SHARE_UPDATED` to each live federated recipient without new ciphertext, one notification per recipient, so the copy follows. Revoking MUST send `SHARE_UNSHARED`, after which the receiving server MUST delete the copy. A share whose partner was removed, or whose recipient certificate no longer verifies, MUST be suspended and shown to the owner.

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

### Requirement: An unreachable partner stops the save unless the user pauses it

When the partner certificate lookup for an active partner share answers that the partner can't be reached, the web app MUST save nothing and MUST tell the owner which partners it couldn't reach, offering to save without them. Saving without them MUST suspend those partner shares in the same share-save, and the owner resumes them later. A decline or accept from the recipient's server MUST NOT change a suspended share. A local server error or a lost connection MUST abort the save with an ordinary error and MUST NOT offer to suspend anything. When the lookup is rate-limited, the web app MUST wait as long as the server asks, keep what it already encrypted and continue, and MUST abort with its own error after five minutes of waiting in total. Certificates verified during a failed save MAY be reused for up to five minutes by the save that follows it, and MUST be forgotten when the vault locks.

#### Scenario: Bob's server is down

- **GIVEN** a secret shared with Bob on a partner instance that doesn't answer
- **WHEN** Alice saves a new password
- **THEN** nothing MUST be saved, and Alice MUST be told Bob's instance can't be reached
- **AND** when Alice chooses to save without Bob, the save MUST go through with Bob's share suspended

#### Scenario: Bob's server cannot end a suspension

- **GIVEN** Alice's share with Bob is suspended
- **WHEN** Bob's server sends `SHARE_DECLINED` for it, and later `SHARE_ACCEPTED`
- **THEN** Alice's share MUST stay suspended, and Bob's server MUST NOT be able to pull it

#### Scenario: A local error never pauses a partner

- **GIVEN** a secret shared with Bob on a partner instance
- **WHEN** Alice's own server answers the certificate lookup with an error
- **THEN** the save MUST fail with an ordinary error and Bob's share MUST stay active

#### Scenario: Many partner shares wait out the lookup limit

- **GIVEN** a secret shared with 70 partner shares
- **WHEN** Alice saves a new password and the lookup is rate-limited after 60 partners
- **THEN** the dialog MUST say it is waiting, and the save MUST go through in one share-save once the limit allows the remaining lookups
