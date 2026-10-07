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

The system MUST fetch a federated recipient's certificate only from an outbound partner, as a signed OCM request. A partner MUST answer only signed requests from its own inbound partners about users who allow receiving from other organisations, and MUST answer every other case as for an unknown user. The owner's browser MUST refuse a certificate whose chain does not end at the pinned partner root or whose common name is not the recipient's cloud id, and MUST show the certificate fingerprint before encrypting.

#### Scenario: A verified remote certificate

- **GIVEN** a vault owner on an instance that pinned `cloud.partner.example`, and `bob@cloud.partner.example` who allows receiving
- **WHEN** the owner enters Bob's cloud id in the share dialog
- **THEN** the browser MUST verify Bob's certificate against the pinned root and show its fingerprint

#### Scenario: A certificate from another root is refused

- **GIVEN** a lookup answer whose chain ends at a root other than the pinned one
- **WHEN** the browser checks it
- **THEN** the share dialog MUST refuse to encrypt and say the certificate could not be verified

#### Scenario: Directory probing learns nothing

- **GIVEN** an instance that is not an inbound partner of `cloud.partner.example`
- **WHEN** it asks the partner for the certificate of `bob@cloud.partner.example`
- **THEN** the partner MUST answer exactly as it does for a user who does not exist

### Requirement: Federated shares carry only browser-made ciphertext

The owner's browser MUST encrypt the value, login and additional fields for the verified recipient certificate and send only that ciphertext with the plain name and URL. The sending server MUST announce the share over OCM with resource type `keepiq-secret` without the ciphertext. The receiving server MUST store an inbound share as pending and notify the recipient, and only after acceptance MUST pull the ciphertext with the share's shared secret in a signed request and store it as a read-only secret owned by the recipient.

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

When the owner updates a federated shared secret, the owner's browser MUST encrypt the new value for each federated recipient with a freshly verified certificate, and the sending server MUST send an OCM `SHARE_UPDATED` notification after which the receiving server pulls the new ciphertext. When the owner changes only the plain name or URL, the sending server MUST send `SHARE_UPDATED` to each live federated recipient without new ciphertext, one notification per recipient, so the copy follows. Revoking MUST send `SHARE_UNSHARED`, after which the receiving server MUST delete the copy. A share whose partner was removed, or whose recipient certificate no longer verifies, MUST be suspended and shown to the owner.

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

### Requirement: Remote copies are read-only

Read-only covers the value and the sharing, not where the recipient files the copy. The system MUST refuse, for its owner, any change of a read-only secret's value, login, additional fields, name, URL or type, a sync, onward sharing and link-share creation. The system MUST allow a change of only its folder. When the recipient declines a pending share, or moves an accepted copy to the trash or deletes it for good, the receiving server MUST mark the inbound share declined and send OCM `SHARE_DECLINED` to the owner's instance. The owner's share MUST then show as declined, and the sending server MUST NOT serve it or send further notifications for it. When the recipient restores a copy whose share they declined by deleting it, the receiving server MUST send OCM `SHARE_ACCEPTED` to the owner's instance. When the owner's instance takes it, the inbound share MUST be accepted again, the owner's share MUST be live again, and the copy MUST pull the current value. When the owner revoked the share, removed it, or shared the secret with the recipient again in the meantime, the copy MUST stay read-only with its last value, the restore MUST say that the share has ended, and the system MUST NOT give the recipient any access the owner did not grant.

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
