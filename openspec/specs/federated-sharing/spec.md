# federated-sharing Specification

## Purpose
TBD - created by archiving change sharing-federated-recipients. Update Purpose after archive.

## Requirements

### Requirement: Administrators approve and pin partner instances

The system MUST let an administrator, after Nextcloud password confirmation, add a partner instance by URL, MUST read and show the partner's Keepiq root certificate fingerprint from its discovery document for confirmation, and MUST store the pinned fingerprint with separate outbound and inbound permissions. With no partner, the system MUST NOT offer, send or accept any federated share. On a Nextcloud version below 33 the section MUST explain that federation needs Nextcloud 33 and MUST keep federation off.

#### Scenario: Administrator adds a partner

- **GIVEN** an administrator on the federation section of the Keepiq admin settings on Nextcloud 33
- **WHEN** they add `https://cloud.partner.example`, compare the shown fingerprint with the partner's administrator, and allow outbound and inbound
- **THEN** the partner MUST be stored with the pinned fingerprint and both permissions

#### Scenario: No partner, no federation

- **GIVEN** an instance with no partner
- **WHEN** a vault owner opens the share dialog
- **THEN** the dialog MUST NOT offer a federated recipient

### Requirement: Certificate lookup is signed, allowlisted and verified in the browser

The system MUST fetch a federated recipient's certificate only from an outbound partner, as a signed OCM request. A partner MUST answer only signed requests from its own inbound partners about users who allow receiving from other organisations, and MUST answer every other case as for an unknown user. The owner's browser MUST refuse a lookup answer for another cloud id than the one asked for, after applying the server's normalisation of backslashes, `/index.php` and trailing slashes to the requested id and ignoring the scheme and the host's case on both sides. It MUST refuse a certificate whose chain does not end at the pinned partner root, whose intermediate is self-issued, whose recipient certificate is marked as a CA, or whose common name is not the recipient's cloud id, ignoring only the scheme, a trailing slash and the host's case, and MUST show the certificate fingerprint before encrypting. The pinned root reaches the browser in the owner's own server's answer, so the browser trusts its own server: that server holds the data and serves the web app, so a pin it can't change would not protect against it.

#### Scenario: A verified remote certificate

- **GIVEN** a vault owner on an instance that pinned `cloud.partner.example`, and `bob@cloud.partner.example` who allows receiving
- **WHEN** the owner enters Bob's cloud id in the share dialog
- **THEN** the browser MUST verify Bob's certificate against the pinned root and show its fingerprint

#### Scenario: A certificate from another root is refused

- **GIVEN** a lookup answer whose chain ends at a root other than the pinned one
- **WHEN** the browser checks it
- **THEN** the share dialog MUST refuse to encrypt and say the certificate could not be verified

#### Scenario: An answer for another account is refused

- **GIVEN** the owner enters `bob@cloud.partner.example`
- **WHEN** the lookup answers with a verifying certificate for `mallory@cloud.partner.example`
- **THEN** the share dialog MUST refuse to encrypt and say the certificate could not be verified

#### Scenario: A CA certificate as the recipient's is refused

- **GIVEN** a lookup answer whose chain ends at the pinned root and whose recipient certificate names Bob but is marked `cA=TRUE`
- **WHEN** the browser checks it
- **THEN** the share dialog MUST refuse to encrypt and say the certificate could not be verified

#### Scenario: Directory probing learns nothing

- **GIVEN** an instance that is not an inbound partner of `cloud.partner.example`
- **WHEN** it asks the partner for the certificate of `bob@cloud.partner.example`
- **THEN** the partner MUST answer exactly as it does for a user who does not exist

### Requirement: Federated shares carry only browser-made ciphertext

The owner's browser MUST encrypt the value, login and additional fields, and the `totp` and `passkeys` fields where the partner stores them, for the verified recipient certificate and send only that ciphertext with the plain name and URL. The sending server MUST announce the share over OCM with resource type `keepiq-secret` without the ciphertext. The receiving server MUST store an inbound share as pending and notify the recipient, and only after acceptance MUST pull the ciphertext with the share's shared secret in a signed request and store it as a read-only secret owned by the recipient.

`totp` and `passkeys` are newer than the rest of this exchange, and a partner on an older Keepiq would drop them without a word. Each instance therefore advertises the value fields beyond `key`, `login` and `additionalFields` it stores as `valueFields`: in its certificate lookup answer, which the asking server records on the partner row, and in every pull request it sends. The answer's `valueFields` is not covered by the browser's certificate check, so a change of the recorded list MUST be audited as `federation_partner.value_fields_changed` with the field names only, a dropped field MUST be logged as a warning, and the admin partners API MUST return the recorded list.

#### Scenario: A partner that cannot store a TOTP seed is refused one

@e2e exclude A server-side refusal before any write; covered by PHPUnit (FederatedValueFieldsTest).
- **GIVEN** Bob's partner advertised no `valueFields`
- **WHEN** Alice shares, updates or share-saves a login whose entry for Bob carries a non-null `totp` or `passkeys`
- **THEN** the server MUST answer 409 `partner_upgrade_required` with `requiredVersion` (and `shareId` on a share-save) before anything is written
- **AND** an entry that carries those fields as null, or leaves them out, is accepted

#### Scenario: A new partner share must mirror the source's TOTP seed and passkeys

@e2e exclude A server-side refusal before any write; covered by PHPUnit (FederatedValueFieldsTest).
- **GIVEN** Alice's login holds a `totp` value and no `passkeys`, and Bob's partner stores both fields
- **WHEN** Alice shares it with Bob leaving out `totp`, or carrying a `passkeys` value, or updates the share with a `passkeys` value
- **THEN** the server MUST answer 400 `copy_fields_mismatch` before anything is written or announced
- **AND** for a partner that cannot store `totp`, leaving it out or sending null is accepted

#### Scenario: A pull that does not declare a held field gets the unknown answer

@e2e exclude Server-to-server pull over OCM; covered by PHPUnit (FederatedValueFieldsTest).
- **GIVEN** Alice's share to Bob holds a `totp` value
- **WHEN** Bob's server pulls it without declaring `totp` in `valueFields`
- **THEN** Alice's server MUST answer as for an unknown share, and log a warning naming the partner and the undeclared field

#### Scenario: A left-out field is kept under the same certificate and cleared under a new one

@e2e exclude Ciphertext bookkeeping on the share row; covered by PHPUnit (FederatedValueFieldsTest).
- **GIVEN** Alice's share to Bob holds a `totp` value
- **WHEN** an update for Bob leaves `totp` out
- **THEN** the share MUST keep its `totp` if the browser encrypted for the certificate the share already had
- **AND** it MUST clear it if the certificate changed, so nothing stays encrypted for a key Bob no longer holds
- **AND** if the certificate changed, Alice's login still holds a `totp` value and Bob's partner stores the field, the update MUST be refused with 400 `copy_fields_mismatch` before anything is written, as a new share is

#### Scenario: Bob accepts a shared login

- **GIVEN** a pending federated share from `alice@cloud.city.example` to Bob
- **WHEN** Bob accepts it under "Incoming from other organisations"
- **THEN** his server MUST pull the ciphertext and store a read-only secret in Bob's vault marked as coming from `alice@cloud.city.example`
- **AND** Bob's browser MUST decrypt it with Bob's own key

#### Scenario: A non-partner cannot deliver

- **GIVEN** an OCM share of type `keepiq-secret` from an instance that is not an inbound partner
- **WHEN** Bob's server receives it
- **THEN** it MUST reject the share and store nothing

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

### Requirement: Users opt in to receiving

Each user MUST have a personal setting "Receive secrets from other organisations", default off, and the certificate lookup MUST treat a user who has not opted in as unknown.

#### Scenario: Opted-out user cannot be found

- **GIVEN** `carol@cloud.partner.example` who has not opted in
- **WHEN** a partner looks up her certificate
- **THEN** the answer MUST be the unknown-user answer

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

When the partner certificate lookup for an active partner share answers that the partner can't be reached, the web app MUST save nothing and MUST tell the owner which partners it couldn't reach, offering to save without them. Saving without them MUST suspend those partner shares in the same share-save; a suspended share stays suspended until the owner resumes it, revokes it or shares again, and the web app MUST say before the owner confirms that the share stops. An accept from the recipient's server MUST NOT change a suspended share. A decline from the recipient's server MUST end a suspended share as declined and drop its ciphertext, so that neither a later accept nor a resume can make it live again (decision of 25 Nov 2026). A local server error or a lost connection MUST abort the save with an ordinary error and MUST NOT offer to suspend anything. `unknown_recipient`, `not_a_partner` and `bad_chain` count as the share being gone only with the status this server sends them with (404, 403 and 502); with any other status they are a local server error. When the lookup is rate-limited, the web app MUST wait as long as the server asks, keep what it already encrypted and continue, and MUST abort with its own error after five minutes of waiting in total. Certificates verified during a failed save MAY be reused for up to five minutes by the save that follows it, and MUST be forgotten when the vault locks.

#### Scenario: Bob's server is down

- **GIVEN** a secret shared with Bob on a partner instance that doesn't answer
- **WHEN** Alice saves a new password
- **THEN** nothing MUST be saved, and Alice MUST be told Bob's instance can't be reached
- **AND** Alice MUST be told that saving without Bob suspends his share until she revokes it or shares again
- **AND** when Alice chooses to save without Bob, the save MUST go through with Bob's share suspended

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
- **WHEN** a proxy in front of Alice's server answers the certificate lookup with a 500 whose message is `not_a_partner`
- **THEN** the save MUST fail with an ordinary error and Bob's share MUST stay active

#### Scenario: Many partner shares wait out the lookup limit

- **GIVEN** a secret shared with 250 partner shares, looked up in batches of 100
- **WHEN** Alice saves a new password and the lookup is rate-limited before the third batch
- **THEN** the dialog MUST say it is waiting, and the save MUST go through in one share-save once the limit allows the remaining batch

### Requirement: The owner resumes a suspended partner share

The owner MUST be able to resume their own suspended partner share from the sharing sidebar (keepiq#1262). A resume MUST carry the current value, encrypted in the owner's browser for the recipient certificate it looked up and verified again, and the version of the secret it was encrypted from. The server MUST take it only while the share is still suspended and only for the secret's current version, checked under the secret's row lock; it MUST make the share live and store the value in one transaction, record `federated_share.resumed`, and tell the recipient's server after the commit. A resume from an older version MUST be refused as `stale_source` with the current version, and the web app MUST read and encrypt the secret again, at most three times. A share that isn't the caller's, or isn't suspended, MUST be answered "share not found". A Resume action MUST be offered only on a suspended share.

#### Scenario: Alice resumes Bob's share once his server is back

- **GIVEN** Alice saved without Bob while his server was down, so his share is suspended with the old value
- **WHEN** Alice chooses Resume on Bob's share
- **THEN** her browser MUST verify Bob's certificate again and encrypt the current value for it
- **AND** Bob's share MUST be active with that value, and Bob's server MUST be told to pull it

#### Scenario: A save that lands during the resume wins

- **GIVEN** Alice resumes Bob's share
- **WHEN** a share-save of the secret commits after her browser read it
- **THEN** the resume MUST be refused as `stale_source` with nothing changed
- **AND** the web app MUST read the secret again and resume with the newer value

#### Scenario: A certificate that no longer verifies resumes nothing

- **GIVEN** Bob's share is suspended
- **WHEN** Alice chooses Resume and Bob's certificate does not chain to the pinned partner root
- **THEN** nothing MUST be encrypted or sent, and Alice MUST be told why

#### Scenario: Only a suspended share resumes

- **GIVEN** a partner share that is active, declined, failed or revoked
- **THEN** the sharing sidebar MUST offer no Resume for it
- **AND** a resume request for it MUST be answered "share not found" with nothing changed

### Requirement: The owner looks up their partner shares' certificates in one request

The system MUST offer the owner a batch certificate lookup over their own partner shares, `POST /api/v1/federation/recipient-certificates` with `{shareIds}`, at most 100 distinct share ids per request, counted once against the per-user lookup rate limit. It MUST answer, per share, the certificate, chain, partner root fingerprint and value fields the single lookup answers, asking each distinct recipient's partner once through the same signed, allowlisted lookup. A share id that is unknown, belongs to another user or is not active MUST be refused as `unknown_share` with status 404, the same for all three. A lookup the partner refuses MUST be refused for that recipient's shares only, with the single lookup's status and reason, and MUST NOT fail the other shares. The web app MUST look up the partner shares a share-save has to fill through this lookup, in batches of at most 100, MUST verify every answer in the browser against the pinned partner root exactly as a single answer, and MUST treat each refused share as the single lookup's refusal would have been treated. A share whose certificate a failed save verified MUST NOT be looked up again within the reuse window.

#### Scenario: Seventy partner shares take one lookup

- **GIVEN** a secret shared with 70 partner shares
- **WHEN** Alice saves a new password
- **THEN** the web app MUST look the 70 shares up in one request and MUST NOT wait on the lookup limit
- **AND** the save MUST go through in one share-save

#### Scenario: Another user's partner share is not looked up

- **GIVEN** Mallory names one of Alice's partner share ids, and a suspended share of her own, in a batch lookup
- **WHEN** the server answers
- **THEN** both MUST be refused as `unknown_share` with status 404, and no partner MUST be asked

#### Scenario: One unreachable partner does not fail the others

- **GIVEN** a secret shared with Bob, whose instance is down, and with Carol, whose instance answers
- **WHEN** Alice's browser looks up both shares in one request
- **THEN** Carol's certificate MUST be answered and Bob's share MUST be refused as `partner_unreachable` with status 502
- **AND** Alice MUST be offered to save without Bob, as for a single lookup
