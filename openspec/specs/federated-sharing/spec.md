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

When the owner updates a federated shared secret, the owner's browser MUST encrypt the new value for each federated recipient with a freshly verified certificate, and the sending server MUST send an OCM `SHARE_UPDATED` notification after which the receiving server pulls the new ciphertext. When the owner changes only the plain name or URL, the sending server MUST send `SHARE_UPDATED` to each live federated recipient without new ciphertext, one notification per recipient, so the copy follows. Revoking MUST send `SHARE_UNSHARED`, after which the receiving server MUST delete the copy. When the shared secret is deleted for good, by purging it from the trash, by the trash retention job or by deleting the owner's account, the sending server MUST revoke each of its partner shares the same way, and keep retrying the `SHARE_UNSHARED` for at least 30 days while the partner cannot be reached (no answer, a 5xx or a 429), with no more than 6 hours between attempts. Only after 30 days without success MAY it drop the share, recording the failure in the audit trail. When the partner refuses it outright, with a 4xx other than 429, at the 6th attempt or any later one, the sending server MUST drop the share at that attempt, recording the failure in the audit trail the same way, and MUST NOT send it again. The same holds for a user's copy of a secret shared with them that is deleted for good together with its share, because the owner revokes that share, trashes or purges the secret, ends the team-folder share or the user leaves the group it came through: the partner shares that user made of the copy MUST be revoked before the copy goes, each `SHARE_UNSHARED` left to the retry job. On such a deletion, a share the recipient declined, and a suspended share whose partner was removed, MUST be removed without a notification and recorded as revoked in the audit trail; a suspended share whose partner is still one is revoked and told like any other. When a `SHARE_UNSHARED` arrives for a share the receiving server has already ended, for example because the answer to an earlier one was lost, the receiving server MUST answer it as taken once the shared secret and the partner's signature check out, and MUST NOT count it as a failed request; any other notification for an ended share MUST still be refused. A share whose partner was removed, whose recipient certificate no longer verifies, or whose lookup answers for another cloud id than the share's, MUST be suspended and shown to the owner.

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

- **GIVEN** a federated share to Bob, suspended because the administrator removed Bob's organisation as a partner
- **WHEN** Alice's secret is deleted for good
- **THEN** Alice's server MUST remove the share and record it as revoked in the audit trail
- **AND** Alice's server MUST NOT queue or send a `SHARE_UNSHARED` to Bob's server

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

### Requirement: Remote copies are read-only

Read-only covers the value and the sharing, not where the recipient files the copy. The system MUST refuse, for its owner, any change of a read-only secret's value, login, additional fields, TOTP seed, passkeys, name, URL or type, a sync, onward sharing and link-share creation. The system MUST allow a change of only its folder. When the recipient declines a pending share, or moves an accepted copy to the trash or deletes it for good, the receiving server MUST mark the inbound share declined and send OCM `SHARE_DECLINED` to the owner's instance. The owner's share MUST then show as declined, and the sending server MUST NOT serve it or send further notifications for it. When the recipient restores a copy whose share they declined by deleting it, the receiving server MUST send OCM `SHARE_ACCEPTED` to the owner's instance. When the owner's instance takes it, the inbound share MUST be accepted again, the owner's share MUST be live again, and the copy MUST pull the current value. When the owner revoked the share, removed it, or shared the secret with the recipient again in the meantime, the copy MUST stay read-only with its last value, the restore MUST say that the share has ended, and the system MUST NOT give the recipient any access the owner did not grant.

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
- **AND** Bob's copy MUST show Alice's current value

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
