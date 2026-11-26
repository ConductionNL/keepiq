## ADDED Requirements

### Requirement: A renewed root is announced to partners

When an instance renews its Keepiq root, it MUST sign the new root's DER with the previous root's key (RSA with SHA-256) in the same transaction, MUST publish in its discovery document the new root, the previous root, the time of the renewal and that signature, MUST add the same announcement to its certificate lookup answers next to the chain, and MUST send every partner with inbound sharing on a signed OCM notification of its own type, tied to no share, that carries only the fact that this server announced a renewal. The notification MUST be retried when it fails, as share notifications are. The announcement MUST be published until 30 days after the renewal; from then on the discovery document and the lookup answers MUST NOT carry it. An instance whose root was never renewed MUST publish no announcement. When the previous root's key can no longer be read, for example after the instance secret changed, the renewal MUST still go ahead: the new root MUST be stored without a signature, no announcement MUST be published and no notification sent, a warning MUST be logged, and the administrator who renewed MUST be told that every partner has to re-pin the new root by hand after comparing its fingerprint out of band.

#### Scenario: The partner renews its root

- **GIVEN** `cloud.partner.example` is a partner of `cloud.example`, which pinned its root
- **WHEN** the administrator of `cloud.partner.example` renews its Keepiq root
- **THEN** its discovery document and its certificate lookup answers MUST carry the new root, the previous root and the previous root's signature over the new root
- **AND** `cloud.example` MUST receive a notification that a renewal was announced, carrying no certificate

#### Scenario: A root whose key cannot be read is renewed unannounced

- **GIVEN** `cloud.partner.example`, whose current root key can no longer be decrypted
- **WHEN** its administrator renews the Keepiq root
- **THEN** a new root MUST be stored without a signature, and no announcement MUST be published or sent
- **AND** the administrator MUST be told that each partner, such as `cloud.example`, has to re-pin it by hand after comparing the fingerprint out of band

### Requirement: An announced renewal is offered to administrators after its signature checks out

On a renewal notification from a listed partner, whether outbound sharing is on or off, or a certificate lookup answer from it that carries an announcement not already offered, the system MUST read the partner's discovery document the same way as when the partner was added, and MUST offer the renewal only when the previous root is the pinned one, the previous root's signature over the new root verifies, the new root is the root the document publishes, the new root is a CA certificate valid now, it differs from the pin, and the renewal time is not in the future and less than 30 days ago. Each root MUST be read from exactly one certificate block, and every check MUST use that one certificate. Otherwise it MUST offer nothing and notify no one, and MUST withdraw an offered renewal for that partner, unless the discovery document could not be read. The system MUST NOT take any certificate or fingerprint from the notification or the lookup answer itself, and MUST NOT change a pin without an administrator's acceptance. An offered renewal MUST be shown on the partner in the federation admin settings with the new fingerprint and the note that the previous root signed it. Whoever may add or re-pin a partner may accept it: the members of the Nextcloud `admin` group and every user delegated Keepiq's general admin settings. Each of them MUST get a Nextcloud notification to accept the renewed certificate, once, also when they are in more than one of those groups, and the same for each reminder. The offer's notifications MUST be removed for everyone when the offer is accepted, withdrawn, replaced by a newer one or expires, when the partner is re-pinned by hand and when the partner is removed. A notification from a host that is not a listed partner MUST be ignored and logged. Accepting MUST require Nextcloud password confirmation, MUST read the discovery document again and repeat the checks before storing, and MUST store the pin through the administrator re-pin, writing its audit entry. A newer valid announcement MUST replace an offered one, also when it is found while accepting, and any re-pin MUST clear it. A partner that publishes no announcement MUST keep only the manual re-pin.

#### Scenario: An administrator accepts a verified renewal

- **GIVEN** `cloud.partner.example` renewed its root and announced it, and the previous root it publishes is the one pinned at `cloud.example`
- **WHEN** `cloud.example` receives the notification
- **THEN** its administrators MUST be notified to accept the renewed certificate
- **AND** when an administrator accepts it with their password, the partner MUST be stored with the new fingerprint and the audit log MUST record the re-pin

#### Scenario: A delegated administrator is told

- **GIVEN** Dana, who is not in the `admin` group, is in a group delegated Keepiq's general admin settings, and Erik is in both
- **WHEN** a renewal from `cloud.partner.example` is offered
- **THEN** Dana and Erik MUST each get one notification to accept it, and the same for each reminder

#### Scenario: The notifications go when the offer goes

- **GIVEN** a renewal offered for `cloud.partner.example`, about which the administrators were notified
- **WHEN** an administrator accepts it, the partner's discovery document stops checking out, a newer announcement replaces it, the 30 days end, or the partner is re-pinned by hand or removed
- **THEN** the notifications about that offer MUST be removed for every administrator
- **AND** a replacing offer MUST be notified anew

#### Scenario: A renewal signed by another root

- **GIVEN** a notification from `cloud.partner.example` whose discovery document publishes a renewal signed by a root that is not the pinned one
- **WHEN** `cloud.example` receives it
- **THEN** no renewal MUST be offered, no administrator MUST be notified, and the pin MUST stay as it was

#### Scenario: The announcement changes before it is accepted

- **GIVEN** a renewal offered for `cloud.partner.example` with fingerprint A
- **WHEN** the partner's discovery document publishes another announcement, or none, before an administrator accepts A
- **THEN** accepting A MUST be refused and the pin MUST stay as it was

#### Scenario: A partner without announcements renews

- **GIVEN** a partner on a Keepiq version that publishes no announcement
- **WHEN** it renews its root
- **THEN** no renewal MUST be offered here, and an administrator MUST re-pin it by hand after comparing the fingerprint out of band

### Requirement: An offered renewal expires after 30 days, with reminders

An offered renewal MUST stay acceptable until 30 days after the renewal time the partner publishes. While no administrator has accepted it, administrators MUST be reminded 7, 5, 3 and 1 day before the 30 days end, each reminder once. When the 30 days end, the offered renewal MUST be withdrawn, and the partner MUST offer only the manual re-pin with its out-of-band comparison.

#### Scenario: No one accepts in time

- **GIVEN** a renewal offered for `cloud.partner.example` that no administrator accepts
- **WHEN** 30 days pass after the renewal
- **THEN** administrators MUST have been reminded 7, 5, 3 and 1 day before the end, once each
- **AND** after the end, Accept MUST no longer be offered, and the manual re-pin MUST remain
